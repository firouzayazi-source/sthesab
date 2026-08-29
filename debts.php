<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();
$todayStr = today();

$view = getParam('view', 'active');
if (!in_array($view, ['active', 'archive'], true)) {
    $view = 'active';
}
$search = getParam('search', '');

$conditions = ['user_id = :user_id', 'is_settled = :settled'];
$params = ['user_id' => $userId, 'settled' => $view === 'archive' ? 1 : 0];

if ($search !== '') {
    $conditions[] = '(counterparty_name LIKE :search_name OR CAST(amount AS CHAR) LIKE :search_amount)';
    $params['search_name'] = '%' . $search . '%';
    $params['search_amount'] = '%' . toLatinDigits($search) . '%';
}

$whereClause = 'WHERE ' . implode(' AND ', $conditions);

// اگر migration_p1.sql هنوز اجرا نشده (ستون paid_amount موجود نیست)،
// همان رفتار قبلی حفظ می‌شود تا این صفحه‌ی موجود خراب نشود
try {
    $stmt = $pdo->prepare("
        SELECT id, direction, counterparty_name, amount, paid_amount, note, entry_date, due_date, is_settled, settled_at
        FROM debts
        $whereClause
        ORDER BY due_date ASC
    ");
    $stmt->execute($params);
    $allDebts = $stmt->fetchAll();
} catch (PDOException $e) {
    $stmt = $pdo->prepare("
        SELECT id, direction, counterparty_name, amount, note, entry_date, due_date, is_settled, settled_at
        FROM debts
        $whereClause
        ORDER BY due_date ASC
    ");
    $stmt->execute($params);
    $allDebts = $stmt->fetchAll();
    foreach ($allDebts as &$__d) { $__d['paid_amount'] = 0; }
    unset($__d);
}

// حسابی که تسویه از/به آن انجام شده — تا در کارت بایگانی معلوم باشد پول
// کجا رفت. جدا از کوئری اصلی پرسیده می‌شود تا آن کوئریِ حساس دست نخورد.
$settleWalletNames = [];
if ($allDebts && tableHasColumn('debt_payments', 'wallet_id')) {
    $ids = array_column($allDebts, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    try {
        $swStmt = $pdo->prepare(
            "SELECT dp.debt_id, w.name
             FROM debt_payments dp
             JOIN wallets w ON w.id = dp.wallet_id
             WHERE dp.user_id = ? AND dp.debt_id IN ($ph)
             ORDER BY dp.id"
        );
        $swStmt->execute(array_merge([$userId], $ids));
        foreach ($swStmt->fetchAll() as $row) {
            $settleWalletNames[(int)$row['debt_id']] = $row['name'];   // آخرین پرداخت می‌ماند
        }
    } catch (PDOException $e) {
        $settleWalletNames = [];
    }
}

$receivables = array_values(array_filter($allDebts, fn($d) => $d['direction'] === 'receivable'));
$payables    = array_values(array_filter($allDebts, fn($d) => $d['direction'] === 'payable'));

// مجموع باقیمانده همیشه بدون در نظر گرفتن جستجو/فیلتر محاسبه می‌شود
try {
    $totalsStmt = $pdo->prepare("
        SELECT direction, COALESCE(SUM(amount - paid_amount), 0) AS total
        FROM debts
        WHERE user_id = :user_id AND is_settled = 0
        GROUP BY direction
    ");
    $totalsStmt->execute(['user_id' => $userId]);
} catch (PDOException $e) {
    $totalsStmt = $pdo->prepare("
        SELECT direction, COALESCE(SUM(amount), 0) AS total
        FROM debts
        WHERE user_id = :user_id AND is_settled = 0
        GROUP BY direction
    ");
    $totalsStmt->execute(['user_id' => $userId]);
}
$pendingReceivable = 0;
$pendingPayable = 0;
foreach ($totalsStmt->fetchAll() as $row) {
    if ($row['direction'] === 'receivable') $pendingReceivable = (int)$row['total'];
    if ($row['direction'] === 'payable') $pendingPayable = (int)$row['total'];
}

// حساب‌ها — برای اینکه هنگام پرداخت یا تسویه بپرسیم پول از/به کجا رفت
$walletList = [];
try {
    $wStmt = $pdo->prepare('SELECT id, name FROM wallets WHERE user_id = :u AND is_active = 1 ORDER BY sort_order, name');
    $wStmt->execute(['u' => $userId]);
    $walletList = $wStmt->fetchAll();
} catch (PDOException $e) {
    $walletList = [];
}
$defaultWallet = defaultWalletId($userId);

$pageTitle = 'طلب و بدهی';
include __DIR__ . '/includes/header.php';

function renderDebtCard(array $d, string $todayStr, array $settleWalletNames = []): void
{
    $settleWallet = $settleWalletNames[(int)$d['id']] ?? '';
    $isOverdue = !(int)$d['is_settled'] && $d['due_date'] < $todayStr;
    $cardClass = 'debt-card';
    if ((int)$d['is_settled']) {
        $cardClass .= ' debt-settled';
    } elseif ($isOverdue) {
        $cardClass .= ' debt-overdue';
    }
    ?>
    <div class="<?= $cardClass ?>">
        <div class="debt-card-main">
            <label class="debt-check">
                <input type="checkbox" class="js-toggle-debt" data-id="<?= (int)$d['id'] ?>" <?= (int)$d['is_settled'] ? 'checked' : '' ?>>
                <span class="debt-check-mark"></span>
            </label>
            <div class="debt-info">
                <div class="debt-name">
                    <?= h($d['counterparty_name']) ?>
                    <?php if ((int)$d['is_settled']): ?>
                        <span class="type-tag type-tag-<?= $d['direction'] === 'receivable' ? 'income' : 'expense' ?>" style="font-size:10.5px;"><?= $d['direction'] === 'receivable' ? 'طلب' : 'بدهی' ?></span>
                    <?php endif; ?>
                </div>
                <div class="debt-dates">ثبت: <?= toJalali($d['entry_date']) ?> &nbsp;·&nbsp; سررسید: <?= toJalali($d['due_date']) ?></div>
                <?php if (!empty($d['note'])): ?><div class="debt-note"><?= h($d['note']) ?></div><?php endif; ?>
            </div>
            <div class="debt-amount"><?= formatMoney($d['amount']) ?><small> تومان</small></div>
        </div>

        <?php $paid = (int)($d['paid_amount'] ?? 0); ?>
        <?php if ($paid > 0 && !(int)$d['is_settled']): ?>
            <?php $pct = min(100, round(($paid / max(1, (int)$d['amount'])) * 100)); ?>
            <div class="budget-bar-track" style="margin:9px 0 5px;">
                <div class="budget-bar budget-bar-good" style="width:<?= $pct ?>%;"></div>
            </div>
            <div class="debt-partial-note">
                <?= formatMoney($paid) ?> پرداخت شده — <?= formatMoney(debtRemaining($d)) ?> باقیمانده (<?= toPersianDigits($pct) ?>٪)
            </div>
        <?php endif; ?>

        <div class="debt-card-footer">
            <?php if ((int)$d['is_settled']): ?>
                <span class="status-badge status-active">تسویه شد<?= $settleWallet !== '' ? ' · ' . h($settleWallet) : '' ?></span>
            <?php elseif ($isOverdue): ?>
                <span class="status-badge debt-badge-overdue">سررسید گذشته</span>
            <?php else: ?>
                <span class="status-badge debt-badge-pending">در انتظار</span>
            <?php endif; ?>
            <div class="debt-actions">
                <?php if (!(int)$d['is_settled']): ?>
                    <button type="button" class="btn btn-secondary btn-sm js-debt-payment"
                        data-id="<?= (int)$d['id'] ?>"
                        data-name="<?= h($d['counterparty_name']) ?>"
                        data-remaining="<?= debtRemaining($d) ?>">ثبت پرداخت</button>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary btn-sm js-edit-debt"
                    data-id="<?= (int)$d['id'] ?>"
                    data-counterparty="<?= h($d['counterparty_name']) ?>"
                    data-amount="<?= (int)$d['amount'] ?>"
                    data-note="<?= h($d['note'] ?? '') ?>"
                    data-entry-date="<?= h($d['entry_date']) ?>"
                    data-due-date="<?= h($d['due_date']) ?>">ویرایش</button>
                <button class="delete-btn js-delete-debt" data-id="<?= (int)$d['id'] ?>">حذف</button>
            </div>
        </div>
    </div>
    <?php
}
?>

<div class="summary-grid" style="grid-template-columns: repeat(2, 1fr);">
    <div class="summary-box">
        <div class="label">مجموع طلب باقیمانده</div>
        <div class="value stats-income"><?= formatMoney($pendingReceivable) ?></div>
    </div>
    <div class="summary-box">
        <div class="label">مجموع بدهی باقیمانده</div>
        <div class="value stats-expense"><?= formatMoney($pendingPayable) ?></div>
    </div>
</div>

<div class="card">
    <form method="GET" id="filterForm">
        <input type="hidden" name="view" value="<?= h($view) ?>">
        <div class="filter-bar">
            <div class="filter-chip <?= $view === 'active' ? 'active' : '' ?>" data-group="view" data-filter="active">در انتظار</div>
            <div class="filter-chip <?= $view === 'archive' ? 'active' : '' ?>" data-group="view" data-filter="archive">تسویه شده</div>
        </div>
        <div class="debt-search-bar" style="display:flex; gap:8px; margin-top:10px;">
            <input type="text" name="search" placeholder="جستجو بر اساس نام یا مبلغ..." value="<?= h($search) ?>">
            <button type="submit" class="btn btn-secondary btn-sm">جستجو</button>
        </div>
    </form>
</div>

<?php if ($view === 'archive'): ?>

<div class="card">
    <h2 class="card-title">تسویه شده</h2>
    <?php if (empty($allDebts)): ?>
        <p class="empty-row">موردی یافت نشد.</p>
    <?php else: ?>
        <?php foreach ($allDebts as $d): renderDebtCard($d, $todayStr, $settleWalletNames); endforeach; ?>
    <?php endif; ?>
</div>

<?php else: ?>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">طلب‌های من (از دیگران)</h2>
        <button type="button" class="btn btn-primary btn-sm js-add-debt" data-direction="receivable">+ افزودن طلب</button>
    </div>
    <?php if (empty($receivables)): ?>
        <p class="empty-row">هنوز طلبی ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($receivables as $d): renderDebtCard($d, $todayStr, $settleWalletNames); endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">بدهی‌های من (به دیگران)</h2>
        <button type="button" class="btn btn-primary btn-sm js-add-debt" data-direction="payable">+ افزودن بدهی</button>
    </div>
    <?php if (empty($payables)): ?>
        <p class="empty-row">هنوز بدهی‌ای ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($payables as $d): renderDebtCard($d, $todayStr, $settleWalletNames); endforeach; ?>
    <?php endif; ?>
</div>

<?php endif; ?>

<!-- مودال افزودن طلب/بدهی -->
<div class="modal-overlay" id="addDebtModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>افزودن طلب / بدهی</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>

        <div class="type-toggle" id="addDebtToggle">
            <button type="button" class="type-btn type-btn-receivable active" data-type="receivable">طلب از دیگران</button>
            <button type="button" class="type-btn type-btn-payable" data-type="payable">بدهی به دیگران</button>
        </div>

        <form id="addDebtForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="direction" id="add_debt_direction" value="receivable">

            <div class="form-group">
                <label for="add_counterparty">نام طرف حساب</label>
                <input type="text" id="add_counterparty" name="counterparty_name" required maxlength="150" placeholder="مثلاً: علی رضایی">
            </div>

            <div class="form-group">
                <label for="add_debt_amount">مبلغ (تومان)</label>
                <input type="text" inputmode="numeric" id="add_debt_amount" name="amount" required placeholder="مثلاً ۲,۰۰۰,۰۰۰" autocomplete="off">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>تاریخ ثبت</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" readonly value="<?= toJalali($todayStr) ?>">
                        <input type="hidden" class="jdp-hidden" id="add_entry_date" name="entry_date" value="<?= h($todayStr) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>تاریخ سررسید</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" readonly placeholder="انتخاب کنید">
                        <input type="hidden" class="jdp-hidden" id="add_due_date" name="due_date" value="">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="add_debt_note">توضیح (اختیاری)</label>
                <textarea id="add_debt_note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <div id="addDebtMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="addDebtSubmitBtn">ثبت</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال ویرایش طلب/بدهی -->
<div class="modal-overlay" id="editDebtModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>ویرایش طلب / بدهی</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>

        <form id="editDebtForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="debt_id" id="edit_debt_id">

            <div class="form-group">
                <label for="edit_counterparty">نام طرف حساب</label>
                <input type="text" id="edit_counterparty" name="counterparty_name" required maxlength="150">
            </div>

            <div class="form-group">
                <label for="edit_debt_amount">مبلغ (تومان)</label>
                <input type="text" inputmode="numeric" id="edit_debt_amount" name="amount" required autocomplete="off">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>تاریخ ثبت</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" id="edit_entry_date_display" readonly>
                        <input type="hidden" class="jdp-hidden" id="edit_entry_date" name="entry_date">
                    </div>
                </div>
                <div class="form-group">
                    <label>تاریخ سررسید</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" id="edit_due_date_display" readonly>
                        <input type="hidden" class="jdp-hidden" id="edit_due_date" name="due_date">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="edit_debt_note">توضیح (اختیاری)</label>
                <textarea id="edit_debt_note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <div id="editDebtMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="editDebtSubmitBtn">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال پرداخت جزئی -->
<div class="modal-overlay" id="debtPaymentModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="debtPaymentTitle">ثبت پرداخت</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form id="debtPaymentForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="debt_id" id="payment_debt_id">

            <p class="hint" id="paymentRemainingHint"></p>

            <div class="form-group">
                <label for="payment_amount">مبلغ پرداخت</label>
                <input type="text" inputmode="numeric" id="payment_amount" name="amount" required class="amount-input" placeholder="۰">
            </div>

            <div class="form-group">
                <label>تاریخ</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" readonly value="<?= toJalali($todayStr) ?>">
                    <input type="hidden" class="jdp-hidden" id="payment_date" name="payment_date" value="<?= h($todayStr) ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="payment_wallet">از / به حساب</label>
                <select id="payment_wallet" name="wallet_id">
                    <?php foreach ($walletList as $w): ?>
                        <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $defaultWallet ? 'selected' : '' ?>><?= h($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">موجودی همان حساب کم/زیاد می‌شود؛ در گزارش درآمد و هزینه نمی‌آید.</p>
            </div>

            <div class="form-group">
                <label for="payment_note">توضیح (اختیاری)</label>
                <textarea id="payment_note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <div id="debtPaymentMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="debtPaymentSubmitBtn">ثبت پرداخت</button>
            </div>
        </form>
    </div>
</div>


<!-- ---------- انتخاب حساب هنگام تسویه کامل ----------
     پول واقعاً جابه‌جا می‌شود، پس باید معلوم باشد از/به کدام حساب.
     اگر دست نزنید، «کیف پول» پیش‌فرض است. -->
<div class="modal-overlay" id="debtSettle">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="debtSettleTitle">تسویه کامل</h3>
            <button type="button" class="modal-close" data-modal-close="debtSettle">&times;</button>
        </div>
        <form id="debtSettleForm" autocomplete="off">
            <input type="hidden" id="debtSettleRecordId" value="">

            <p class="hint" id="debtSettleHint" style="margin-bottom:14px;"></p>

            <div class="form-group">
                <label for="debtSettleWallet">واریز به / پرداخت از حساب</label>
                <select id="debtSettleWallet" name="wallet_id">
                    <?php foreach ($walletList as $w): ?>
                        <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $defaultWallet ? 'selected' : '' ?>><?= h($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">این مبلغ در موجودی همان حساب اعمال می‌شود. در گزارش درآمد و هزینه شمرده نمی‌شود.</p>
            </div>

            <div id="debtSettleMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close="debtSettle">انصراف</button>
                <button type="submit" class="btn btn-primary" id="debtSettleSubmitBtn">تأیید</button>
            </div>
        </form>
    </div>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php include __DIR__ . '/includes/footer.php'; ?>
