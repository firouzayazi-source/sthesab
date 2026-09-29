<?php
/**
 * یک دریافت/پرداخت/هزینه/انتقال — ثبتِ تازه (`?k=`، و از صفحه‌ی فاکتور
 * `&inv=`) یا نمایش و باطل (`?id=`).
 *
 * ⛔ دریافتی که با `inv` ثبت شود **اول** به همان فاکتور می‌خورد و بقیه‌اش
 *    به قدیمی‌ترین فاکتورِ بازِ همان طرف‌حساب — تصمیمش فقط در
 *    `BizPay::reallocateTx()` است.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_print.php';

$userId = (int)Auth::userId();
$id     = (int)getParam('id', '0');

if ($id > 0) {
    $pay = BizPay::get($userId, $id);
    if (!$pay) { redirectWithMessage(Biz::url('payments.php'), 'error', 'سند پیدا نشد.'); }
    $self = Biz::url('payment.php?id=' . $id);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::verifyOrFail(postParam('csrf_token'));
        $r = postParam('action') === 'void' ? BizPay::void($userId, $id) : ['ok' => false, 'message' => 'درخواست نامعتبر است.'];
        redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
    }
    $pageTitle = (BizPay::KINDS[$pay['kind']] ?? '') . ' ' . toPersianDigits((string)$pay['number']);
    require __DIR__ . '/../includes/biz_head.php';
    ?>
<div class="st-page-head">
    <div>
        <a class="st-back" href="<?= h(Biz::url('payments.php')) ?>">‹ دریافت و پرداخت</a>
        <h1 class="st-h1"><?= h($pageTitle) ?> <?php if ($pay['status'] === 'void'): ?><span class="st-pill is-void">باطل</span><?php endif; ?></h1>
    </div>
    <div class="st-head-actions"><a class="st-btn st-btn-ghost" href="<?= h(BizPrint::url('payment', ['id' => $id])) ?>">چاپِ رسید</a></div>
</div>
<article class="st-card st-docview">
    <dl class="st-docmeta">
        <div><dt>مبلغ</dt><dd><?= BizDocView::money((int)$pay['amount']) ?> تومان</dd></div>
        <div><dt>تاریخ</dt><dd class="st-num"><?= h(toJalali((string)$pay['pay_date'])) ?></dd></div>
        <?php if ($pay['kind'] === 'transfer'): ?>
            <div><dt>از</dt><dd><?= h((string)$pay['account_name']) ?></dd></div>
            <div><dt>به</dt><dd><?= h((string)$pay['to_account_name']) ?></dd></div>
        <?php else: ?>
            <div><dt>صندوق</dt><dd><?= h((string)$pay['account_name']) ?> · <?= h(BizPay::METHODS[$pay['method']] ?? '') ?></dd></div>
        <?php endif; ?>
        <?php if ($pay['party_id'] !== null): ?><div><dt>طرف‌حساب</dt><dd><a href="<?= h(Biz::url('party.php?id=' . (int)$pay['party_id'])) ?>"><?= h((string)$pay['party_name']) ?></a></dd></div><?php endif; ?>
        <?php if ((string)$pay['title'] !== ''): ?><div><dt>شرح</dt><dd><?= h((string)$pay['title']) ?></dd></div><?php endif; ?>
        <?php if (($pay['cheque_status'] ?? null) !== null): ?>
            <div><dt>چک</dt><dd><?= h(BizCheques::label($pay)) ?> · سررسید <span class="st-num"><?= h(toJalali((string)$pay['cheque_due'])) ?></span></dd></div>
            <div><dt>وضعیتِ چک</dt><dd><a href="<?= h(Biz::url('cheques.php?f=all')) ?>"><?= h(BizCheques::STATUSES[$pay['cheque_status']] ?? '') ?></a></dd></div>
        <?php endif; ?>
    </dl>
    <?php if (in_array($pay['kind'], ['receipt', 'payment'], true)): ?>
    <h2 class="st-h3">تسویه‌ی اسناد</h2>
    <?php if (!$pay['allocations']): ?>
        <p class="st-muted">به هیچ فاکتوری نخورده؛ <?= $pay['status'] === 'ok' ? 'به‌صورتِ پیش‌پرداخت روی حسابِ طرف‌حساب نشسته است.' : 'باطل است.' ?></p>
    <?php else: ?>
    <ul class="st-list">
        <?php foreach ($pay['allocations'] as $al): ?>
        <li class="st-list-row"><a href="<?= h(Biz::url('invoice.php?id=' . (int)$al['id'])) ?>"><?= h(BizInvoices::title($al)) ?></a><?= BizDocView::money((int)$al['amount']) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if ((int)$pay['allocated'] < (int)$pay['amount'] && $pay['status'] === 'ok'): ?>
        <p class="st-muted"><?= formatMoney((int)$pay['amount'] - (int)$pay['allocated']) ?> تومان پیش‌پرداخت مانده است.</p>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ((string)$pay['note'] !== ''): ?><p class="st-muted st-docnote"><?= nl2br(h((string)$pay['note'])) ?></p><?php endif; ?>
</article>
<?php if ($pay['status'] === 'ok'): ?>
<section class="st-card st-danger">
    <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('این سند باطل شود؟ پولش از موجودیِ صندوق و ماندهٔ طرف‌حساب برمی‌گردد.');">
        <?= Csrf::field() ?><input type="hidden" name="action" value="void">
        <button type="submit" class="st-btn st-btn-danger">باطل کردن</button>
    </form>
</section>
<?php endif; ?>
    <?php
    require __DIR__ . '/../includes/biz_foot.php';
    exit;
}

// ---------- ثبتِ تازه ----------
$kind = getParam('k');
if (!isset(BizPay::KINDS[$kind])) { $kind = 'receipt'; }
$invId = (int)getParam('inv', '0');
$inv   = $invId > 0 ? BizInvoices::get($userId, $invId) : null;
if ($inv && ($inv['status'] !== 'issued' || BizInvoices::SETTLED_BY[$inv['kind']] !== $kind)) { $inv = null; $invId = 0; }
$accounts = BizDocView::accounts($userId);
$self = Biz::url('payment.php?k=' . $kind . ($invId ? '&inv=' . $invId : ''));
// ⛔ پیش‌پر کردن از آدرس (فرمانِ سریع «دریافت ۵ میلیون»، دکمه‌ی «افزایش» روی
//    صندوقِ داشبورد، «ثبتِ چک» در منوی «+ ثبت») فقط **پیشنهاد** است: هر عدد و
//    شناسه‌ای که از آدرس می‌آید دوباره از `BizPay::create()` و سنجشِ مالکیتِ
//    صندوق رد می‌شود؛ صندوقی که مالِ این فروشگاه نیست اصلاً در فهرست نیست.
$preAcc = (int)getParam('acc', '0');
$preAcc = in_array($preAcc, array_map(fn($a) => (int)$a['id'], $accounts), true) ? $preAcc : (int)($accounts[0]['id'] ?? 0);
$preAmt = sanitizeAmount(getParam('amount'));
$form = [
    'party_id' => $inv ? (int)$inv['party_id'] : (int)getParam('party', '0'),
    'amount' => $inv ? (string)BizInvoices::remaining($inv) : ($preAmt > 0 ? (string)$preAmt : ''), 'account_id' => $preAcc,
    'to_account_id' => (int)($accounts[1]['id'] ?? 0),
    'method' => getParam('method') === 'cheque' && in_array($kind, ['receipt', 'payment'], true) ? 'cheque' : 'cash',
    'pay_date' => BizDocView::jDate(date('Y-m-d')), 'title' => '', 'note' => '',
    'cheque_no' => '', 'cheque_bank' => '', 'cheque_due' => '',
];
$chequeReady = in_array($kind, ['receipt', 'payment'], true) && BizCheques::ready();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    foreach ($form as $k => $_) { $form[$k] = postParam($k); }
    $r = BizPay::create($userId, [
        'kind' => $kind, 'party_id' => (int)$form['party_id'], 'invoice_id' => $invId, 'amount' => $form['amount'],
        'account_id' => (int)$form['account_id'], 'to_account_id' => (int)$form['to_account_id'], 'method' => $form['method'],
        'pay_date' => BizDocView::gDate((string)$form['pay_date']), 'title' => $form['title'], 'note' => $form['note'],
        'cheque_no' => $form['cheque_no'], 'cheque_bank' => $form['cheque_bank'],
        'cheque_due' => trim((string)$form['cheque_due']) === '' ? '' : BizDocView::gDate((string)$form['cheque_due']),
    ]);
    if ($r['ok']) {
        redirectWithMessage($invId ? Biz::url('invoice.php?id=' . $invId) : Biz::url('payment.php?id=' . (int)$r['id']), 'success', $r['message']);
    }
    $error = $r['message'];
}
$parties = in_array($kind, ['receipt', 'payment'], true) && !$inv ? BizDocView::parties($userId) : [];
$open = ($inv === null && (int)$form['party_id'] > 0 && in_array($kind, ['receipt', 'payment'], true))
    ? BizInvoices::openFor($userId, (int)$form['party_id'], $kind) : [];

$pageTitle = BizPay::KINDS[$kind];
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <a class="st-back" href="<?= h($inv ? Biz::url('invoice.php?id=' . $invId) : Biz::url('payments.php')) ?>">‹ <?= $inv ? h(BizInvoices::title($inv)) : 'دریافت و پرداخت' ?></a>
        <h1 class="st-h1"><?= h(BizPay::KINDS[$kind]) ?></h1>
    </div>
</div>
<nav class="st-segtabs" aria-label="نوع">
    <?php foreach (BizPay::KINDS as $kk => $kl): if ($inv && $kk !== $kind) { continue; } ?>
        <a class="<?= $kk === $kind ? 'is-active' : '' ?>" href="<?= h(Biz::url('payment.php?k=' . $kk)) ?>"><?= h($kl) ?></a>
    <?php endforeach; ?>
</nav>
<?php if ($error !== ''): ?><div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div><?php endif; ?>

<form method="post" action="<?= h($self) ?>" class="st-card st-form st-form-narrow">
    <?= Csrf::field() ?>
    <?php if ($inv): ?>
        <p class="st-muted">برای <?= h(BizInvoices::title($inv)) ?> — <?= $inv['party_name'] !== null ? h((string)$inv['party_name']) : 'گذری' ?>؛ مانده <?= BizDocView::money(BizInvoices::remaining($inv)) ?> تومان.</p>
    <?php elseif ($parties): ?>
        <label class="st-field"><span><?= $kind === 'receipt' ? 'از چه کسی' : 'به چه کسی' ?></span>
            <select name="party_id" required>
                <option value="">— انتخاب کنید —</option>
                <?php foreach ($parties as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === (int)$form['party_id'] ? ' selected' : '' ?>><?= h($p['name']) ?> (مانده <?= h(formatMoney(abs((int)$p['balance']))) ?> <?= (int)$p['balance'] > 0 ? 'بد' : ((int)$p['balance'] < 0 ? 'بس' : '') ?>)</option><?php endforeach; ?>
            </select>
        </label>
        <?php if ($open): ?>
        <div class="st-open-list">
            <p class="st-muted">اسنادِ بازِ این طرف‌حساب — دریافت به ترتیب از قدیمی‌ترین تسویه می‌کند:</p>
            <ul class="st-list"><?php foreach ($open as $o): ?><li class="st-list-row"><span><?= h(BizInvoices::title($o)) ?> <span class="st-muted-i"><?= h(toJalali((string)$o['inv_date'])) ?></span></span><?= BizDocView::money((int)$o['total'] - (int)$o['paid']) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (in_array($kind, ['expense', 'income'], true)): ?>
        <label class="st-field"><span>شرح</span><input type="text" name="title" required maxlength="<?= BizPay::TITLE_MAX ?>" value="<?= h((string)$form['title']) ?>" placeholder="<?= $kind === 'expense' ? 'مثلاً اجاره‌ی مغازه، قبضِ برق، حقوق' : 'مثلاً سودِ بانکی' ?>" list="bizExpenseTitles"></label>
    <?php endif; ?>
    <div class="st-row2">
        <label class="st-field"><span>مبلغ (تومان)</span><input type="text" name="amount" required inputmode="numeric" dir="ltr" value="<?= h((string)$form['amount']) ?>" autofocus></label>
        <label class="st-field"><span>تاریخ</span><input type="text" name="pay_date" dir="ltr" inputmode="numeric" value="<?= h((string)$form['pay_date']) ?>"></label>
    </div>
    <div class="st-row2">
        <label class="st-field"><span><?= $kind === 'transfer' ? 'از صندوق' : 'صندوق' ?></span>
            <select name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$a['id'] === (int)$form['account_id'] ? ' selected' : '' ?>><?= h($a['name']) ?> — <?= h(formatMoney((int)$a['balance'])) ?></option><?php endforeach; ?></select>
        </label>
        <?php if ($kind === 'transfer'): ?>
        <label class="st-field"><span>به صندوق</span>
            <select name="to_account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$a['id'] === (int)$form['to_account_id'] ? ' selected' : '' ?>><?= h($a['name']) ?></option><?php endforeach; ?></select>
        </label>
        <?php else: ?>
        <label class="st-field"><span>روش</span>
            <select name="method" data-cheque-method><?php foreach (BizPay::METHODS as $mk => $ml): ?><option value="<?= h($mk) ?>"<?= $mk === $form['method'] ? ' selected' : '' ?>><?= h($ml) ?></option><?php endforeach; ?></select>
        </label>
        <?php endif; ?>
    </div>
    <?php if ($chequeReady): /* بی‌جاوااسکریپت همیشه دیده می‌شود؛ store.js برای روشِ غیرِچک پنهانش می‌کند */ ?>
    <fieldset class="st-fieldset" data-cheque-fields>
        <legend>مشخصاتِ چک <small class="st-muted">(فقط وقتی روش «چک» است)</small></legend>
        <div class="st-row3">
            <label class="st-field"><span>سررسید</span><input type="text" name="cheque_due" dir="ltr" inputmode="numeric" placeholder="۱۴۰۵/۰۸/۱۵" value="<?= h((string)$form['cheque_due']) ?>"></label>
            <label class="st-field"><span>شماره‌ی چک</span><input type="text" name="cheque_no" dir="ltr" inputmode="numeric" maxlength="30" value="<?= h((string)$form['cheque_no']) ?>"></label>
            <label class="st-field"><span>بانک</span><input type="text" name="cheque_bank" maxlength="60" value="<?= h((string)$form['cheque_bank']) ?>" placeholder="مثلاً ملت"></label>
        </div>
        <p class="st-muted">چک تا وصول نشده در صندوقِ «<?= h(BizCash::KINDS[$kind === 'receipt' ? 'cheque_in' : 'cheque_out']) ?>» می‌ماند و در جمعِ نقدِ فروشگاه نیست؛ وصول و برگشت از صفحه‌ی <a href="<?= h(Biz::url('cheques.php')) ?>">چک‌ها</a>.</p>
    </fieldset>
    <?php endif; ?>
    <?php if ($kind === 'transfer' && count($accounts) < 2): ?>
        <p class="st-flash st-flash-warn">برای انتقال دست‌کم دو صندوقِ فعال لازم است — <a href="<?= h(Biz::url('accounts.php')) ?>">افزودنِ صندوق</a>.</p>
    <?php endif; ?>
    <label class="st-field"><span>توضیح <small class="st-muted">(اختیاری)</small></span><textarea name="note" rows="2" maxlength="300"><?= h((string)$form['note']) ?></textarea></label>
    <button type="submit" class="st-btn">ثبتِ <?= h(BizPay::KINDS[$kind]) ?></button>
</form>
<?php if (in_array($kind, ['expense', 'income'], true)):
    $st = Database::getConnection()->prepare("SELECT title FROM biz_payments WHERE user_id = :u AND kind = :k AND title IS NOT NULL GROUP BY title ORDER BY MAX(id) DESC LIMIT 40");
    $st->execute(['u' => $userId, 'k' => $kind]); ?>
<datalist id="bizExpenseTitles"><?php foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t): ?><option value="<?= h((string)$t) ?>"></option><?php endforeach; ?></datalist>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
