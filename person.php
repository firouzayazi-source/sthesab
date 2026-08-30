<?php
/**
 * گردش حساب یک شخص — همه‌ی چک، طلب و بدهی، و معامله‌های او یک‌جا.
 *
 * کلید، *نام* است نه شناسه، چون همه‌ی جدول‌ها `counterparty_name` را
 * ذخیره می‌کنند (توضیحش در CLAUDE.md، بخش «اشخاص»). فایده‌اش این است
 * که رکوردهای قدیمی — که پیش از ساخته شدنِ فهرست اشخاص ثبت شده‌اند —
 * هم اینجا دیده می‌شوند، حتی اگر آن نام در فهرست نباشد.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$pdo    = Database::getConnection();
$userId = Auth::userId();

$name = trim(getParam('name'));

$pageTitle = $name !== '' ? 'گردش حساب ' . $name : 'گردش حساب شخص';
require_once __DIR__ . '/includes/header.php';

/** یک کوئری امن که اگر جدول یا ستون نبود، خالی برمی‌گردد. */
$safeAll = function (string $sql, array $params) use ($pdo): array {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
};

$cheques = $debts = $trades = $sales = [];
$netCheques = $netDebts = 0;

if ($name !== '') {
    $p = ['u' => $userId, 'n' => $name];

    $cheques = $safeAll(
        'SELECT id, direction, amount, due_date, is_settled
         FROM cheques WHERE user_id = :u AND counterparty_name = :n
         ORDER BY due_date DESC, id DESC', $p);

    $debts = $safeAll(
        'SELECT id, direction, amount, paid_amount, due_date, is_settled
         FROM debts WHERE user_id = :u AND counterparty_name = :n
         ORDER BY due_date DESC, id DESC', $p);

    if (tableHasColumn('trades', 'counterparty_name')) {
        $trades = $safeAll(
            'SELECT id, title, qty, buy_total, buy_date
             FROM trades WHERE user_id = :u AND counterparty_name = :n
             ORDER BY buy_date DESC, id DESC', $p);
    }
    if (tableHasColumn('trade_sales', 'counterparty_name')) {
        $sales = $safeAll(
            'SELECT s.id, s.qty, s.sale_total, s.sale_date, t.title
             FROM trade_sales s JOIN trades t ON t.id = s.trade_id
             WHERE s.user_id = :u AND s.counterparty_name = :n
             ORDER BY s.sale_date DESC, s.id DESC', $p);
    }

    // خالص‌ها فقط از موارد تسویه‌نشده — همان قاعده‌ی صفحه‌ی دارایی،
    // که پولِ جابه‌جاشده دوبار شمرده نشود.
    foreach ($cheques as $c) {
        if ((int)$c['is_settled'] === 1) { continue; }
        $netCheques += ($c['direction'] === 'received' ? 1 : -1) * (int)$c['amount'];
    }
    foreach ($debts as $d) {
        if ((int)$d['is_settled'] === 1) { continue; }
        $rem = debtRemaining($d);
        $netDebts += ($d['direction'] === 'receivable' ? 1 : -1) * $rem;
    }
}

$people   = peopleList($userId);
$rowCount = count($cheques) + count($debts) + count($trades) + count($sales);
?>

<button type="button" class="page-back js-page-back">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
    بازگشت
</button>

<div class="card">
    <h2 class="card-title">گردش حساب شخص</h2>
    <form method="GET" class="ref-add-row" style="margin-top:10px;">
        <input type="text" name="name" value="<?= h($name) ?>" list="peopleList"
               placeholder="نام شخص" maxlength="150" autocomplete="off">
        <button type="submit" class="btn btn-secondary btn-sm">نمایش</button>
    </form>
    <?php if (!empty($people)): ?>
        <div class="ref-chip-list" style="margin-top:10px;">
            <?php foreach ($people as $p): ?>
                <a class="ref-chip" style="text-decoration:none;"
                   href="<?= APP_BASE_PATH ?>/person.php?name=<?= urlencode($p['name']) ?>"><?= h($p['name']) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($name === ''): ?>
    <div class="card"><p class="hint">یک نام انتخاب یا وارد کنید.</p></div>
<?php elseif ($rowCount === 0): ?>
    <div class="card"><p class="hint">برای «<?= h($name) ?>» هیچ چک، طلب، بدهی یا معامله‌ای ثبت نشده.</p></div>
<?php else: ?>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">خلاصه</h2>
        <span class="ref-chip"><?= toPersianDigits((string)$rowCount) ?> مورد</span>
    </div>
    <div class="asset-total-row">
        <span class="asset-total-label">خالص چک‌های پاس‌نشده</span>
        <b class="asset-total-value<?= $netCheques < 0 ? ' asset-amount-neg' : '' ?>">
            <?= $netCheques < 0 ? '−' : '' ?><?= formatMoney(abs($netCheques)) ?>
        </b>
    </div>
    <div class="asset-total-row">
        <span class="asset-total-label">خالص طلب و بدهی</span>
        <b class="asset-total-value<?= $netDebts < 0 ? ' asset-amount-neg' : '' ?>">
            <?= $netDebts < 0 ? '−' : '' ?><?= formatMoney(abs($netDebts)) ?>
        </b>
    </div>
    <p class="hint">عدد مثبت یعنی او به شما بدهکار است، منفی یعنی شما به او.</p>
</div>

<?php if ($cheques): ?>
<div class="card">
    <h2 class="card-title">چک‌ها</h2>
    <?php foreach ($cheques as $c): ?>
        <div class="cat-breakdown-item">
            <div class="cat-breakdown-summary">
                <span class="cat-breakdown-name">
                    چک <?= $c['direction'] === 'received' ? 'دریافتی' : 'صادره' ?>
                    <?php if ((int)$c['is_settled'] === 1): ?><span class="asset-tag">پاس شده</span><?php endif; ?>
                </span>
                <span class="cat-breakdown-amount"><?= formatMoney((int)$c['amount']) ?></span>
            </div>
            <div class="asset-item-qty">سررسید <?= toJalali($c['due_date']) ?></div>
        </div>
    <?php endforeach; ?>
    <a class="ribbon-link" href="<?= APP_BASE_PATH ?>/cheques.php">رفتن به بخش چک‌ها ←</a>
</div>
<?php endif; ?>

<?php if ($debts): ?>
<div class="card">
    <h2 class="card-title">طلب و بدهی</h2>
    <?php foreach ($debts as $d): ?>
        <?php $rem = debtRemaining($d); ?>
        <div class="cat-breakdown-item">
            <div class="cat-breakdown-summary">
                <span class="cat-breakdown-name">
                    <?= $d['direction'] === 'receivable' ? 'طلب از او' : 'بدهی به او' ?>
                    <?php if ((int)$d['is_settled'] === 1): ?><span class="asset-tag">تسویه شده</span><?php endif; ?>
                </span>
                <span class="cat-breakdown-amount"><?= formatMoney((int)$d['amount']) ?></span>
            </div>
            <div class="asset-item-qty">
                باقیمانده <?= formatMoney($rem) ?> — سررسید <?= toJalali($d['due_date']) ?>
            </div>
        </div>
    <?php endforeach; ?>
    <a class="ribbon-link" href="<?= APP_BASE_PATH ?>/debts.php">رفتن به بخش طلب و بدهی ←</a>
</div>
<?php endif; ?>

<?php if ($trades || $sales): ?>
<div class="card">
    <h2 class="card-title">معاملات</h2>
    <?php foreach ($trades as $t): ?>
        <div class="cat-breakdown-item">
            <div class="cat-breakdown-summary">
                <span class="cat-breakdown-name">خرید <?= h($t['title']) ?></span>
                <span class="cat-breakdown-amount"><?= formatMoney((int)$t['buy_total']) ?></span>
            </div>
            <div class="asset-item-qty"><?= formatQuantity((float)$t['qty']) ?> واحد — <?= toJalali($t['buy_date']) ?></div>
        </div>
    <?php endforeach; ?>
    <?php foreach ($sales as $s): ?>
        <div class="cat-breakdown-item">
            <div class="cat-breakdown-summary">
                <span class="cat-breakdown-name">فروش <?= h($s['title']) ?></span>
                <span class="cat-breakdown-amount"><?= formatMoney((int)$s['sale_total']) ?></span>
            </div>
            <div class="asset-item-qty"><?= formatQuantity((float)$s['qty']) ?> واحد — <?= toJalali($s['sale_date']) ?></div>
        </div>
    <?php endforeach; ?>
    <a class="ribbon-link" href="<?= APP_BASE_PATH ?>/trades.php">رفتن به بخش معاملات ←</a>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
