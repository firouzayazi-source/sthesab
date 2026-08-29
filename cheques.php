<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();
$todayStr = today();

seedUserDefaults($userId);

$view = getParam('view', 'active');
if (!in_array($view, ['active', 'archive'], true)) {
    $view = 'active';
}
$search = getParam('search', '');

$conditions = ['ch.user_id = :user_id', 'ch.is_settled = :settled'];
$params = ['user_id' => $userId, 'settled' => $view === 'archive' ? 1 : 0];

if ($search !== '') {
    $conditions[] = '(ch.counterparty_name LIKE :s_name OR CAST(ch.amount AS CHAR) LIKE :s_amount OR ch.sayadi_number LIKE :s_sayadi OR ch.cheque_number LIKE :s_num)';
    $params['s_name']   = '%' . $search . '%';
    $params['s_amount'] = '%' . toLatinDigits($search) . '%';
    $params['s_sayadi'] = '%' . toLatinDigits($search) . '%';
    $params['s_num']    = '%' . toLatinDigits($search) . '%';
}

$whereClause = 'WHERE ' . implode(' AND ', $conditions);

// نام حسابِ وصول فقط وقتی خوانده می‌شود که migration_money_links اجرا
// شده باشد؛ وگرنه کوئری روی ستون ناموجود می‌شکست.
$walletJoin = tableHasColumn('cheques', 'settle_wallet_id')
    ? ', w.name AS settle_wallet_name'
    : ', NULL AS settle_wallet_name';
$walletJoinSql = tableHasColumn('cheques', 'settle_wallet_id')
    ? 'LEFT JOIN wallets w ON w.id = ch.settle_wallet_id'
    : '';

$stmt = $pdo->prepare("
    SELECT ch.*, b.name AS bank_name $walletJoin
    FROM cheques ch
    LEFT JOIN banks b ON b.id = ch.bank_id
    $walletJoinSql
    $whereClause
    ORDER BY (ch.due_date IS NULL), ch.due_date ASC
");
$stmt->execute($params);
$allCheques = $stmt->fetchAll();

$received = array_values(array_filter($allCheques, fn($c) => $c['direction'] === 'received'));
$issued   = array_values(array_filter($allCheques, fn($c) => $c['direction'] === 'issued'));

// مجموع باقیمانده — مستقل از جستجو
$totalsStmt = $pdo->prepare('
    SELECT direction, COALESCE(SUM(amount), 0) AS total
    FROM cheques
    WHERE user_id = :user_id AND is_settled = 0
    GROUP BY direction
');
$totalsStmt->execute(['user_id' => $userId]);
$pendingReceived = 0;
$pendingIssued = 0;
foreach ($totalsStmt->fetchAll() as $row) {
    if ($row['direction'] === 'received') $pendingReceived = (int)$row['total'];
    if ($row['direction'] === 'issued')   $pendingIssued   = (int)$row['total'];
}

// بانک‌ها — دو لیست کاملاً جدا
$myBanksStmt = $pdo->prepare('SELECT id, name FROM banks WHERE user_id = :user_id AND scope = "mine" ORDER BY name');
$myBanksStmt->execute(['user_id' => $userId]);
$myBanks = $myBanksStmt->fetchAll();

$extBanksStmt = $pdo->prepare('SELECT id, name FROM banks WHERE user_id = :user_id AND scope = "external" ORDER BY name');
$extBanksStmt->execute(['user_id' => $userId]);
$externalBanks = $extBanksStmt->fetchAll();

// حساب‌ها — برای اینکه هنگام پاس شدن چک بپرسیم پول به/از کدام حساب رفت
$walletList = activeWallets($userId);
$defaultWallet = defaultWalletId($userId);

$pageTitle = 'چک‌ها';
include __DIR__ . '/includes/header.php';

function renderChequeCard(array $c, string $todayStr): void
{
    $isOverdue = !(int)$c['is_settled'] && $c['due_date'] !== null && $c['due_date'] < $todayStr;
    $cardClass = 'debt-card';
    if ((int)$c['is_settled']) {
        $cardClass .= ' debt-settled';
    } elseif ($isOverdue) {
        $cardClass .= ' debt-overdue';
    }
    ?>
    <div class="<?= $cardClass ?>">
        <div class="debt-card-main">
            <label class="debt-check">
                <input type="checkbox" class="js-toggle-cheque" data-id="<?= (int)$c['id'] ?>" <?= (int)$c['is_settled'] ? 'checked' : '' ?>>
                <span class="debt-check-mark"></span>
            </label>
            <div class="debt-info">
                <div class="debt-name">
                    <?= h($c['counterparty_name']) ?>
                    <?php if ((int)$c['is_settled']): ?>
                        <span class="type-tag type-tag-<?= $c['direction'] === 'received' ? 'income' : 'expense' ?>" style="font-size:10.5px;">
                            <?= $c['direction'] === 'received' ? 'دریافتی' : 'صادره' ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="debt-dates">
                    <?php if ($c['due_date']): ?>سررسید: <?= toJalali($c['due_date']) ?><?php else: ?><span style="opacity:.7;">بدون سررسید</span><?php endif; ?>
                    <?php if (!empty($c['bank_name'])): ?> &nbsp;·&nbsp; بانک <?= h($c['bank_name']) ?><?php endif; ?>
                </div>
                <?php if (!empty($c['sayadi_number']) || !empty($c['cheque_number'])): ?>
                    <div class="debt-dates">
                        <?php if (!empty($c['sayadi_number'])): ?>صیادی: <?= toPersianDigits($c['sayadi_number']) ?><?php endif; ?>
                        <?php if (!empty($c['cheque_number'])): ?> &nbsp;·&nbsp; شماره چک: <?= toPersianDigits($c['cheque_number']) ?><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($c['note'])): ?><div class="debt-note"><?= h($c['note']) ?></div><?php endif; ?>
            </div>
            <div class="debt-amount"><?= formatMoney($c['amount']) ?><small> تومان</small></div>
        </div>
        <div class="debt-card-footer">
            <?php if ((int)$c['is_settled']): ?>
                <span class="status-badge status-active">پاس شد<?= !empty($c['settle_wallet_name']) ? ' · ' . h($c['settle_wallet_name']) : '' ?></span>
            <?php elseif ($isOverdue): ?>
                <span class="status-badge debt-badge-overdue">سررسید گذشته</span>
            <?php else: ?>
                <span class="status-badge debt-badge-pending">در انتظار</span>
            <?php endif; ?>
            <div class="debt-actions">
                <button type="button" class="btn btn-secondary btn-sm js-edit-cheque"
                    data-id="<?= (int)$c['id'] ?>"
                    data-direction="<?= h($c['direction']) ?>"
                    data-counterparty="<?= h($c['counterparty_name']) ?>"
                    data-amount="<?= (int)$c['amount'] ?>"
                    data-bank-id="<?= (int)($c['bank_id'] ?? 0) ?>"
                    data-sayadi="<?= h($c['sayadi_number'] ?? '') ?>"
                    data-cheque-number="<?= h($c['cheque_number'] ?? '') ?>"
                    data-note="<?= h($c['note'] ?? '') ?>"
                    data-due-date="<?= h($c['due_date'] ?? '') ?>">ویرایش</button>
                <button class="delete-btn js-delete-cheque" data-id="<?= (int)$c['id'] ?>">حذف</button>
            </div>
        </div>
    </div>
    <?php
}
?>

<div class="summary-grid" style="grid-template-columns: repeat(2, 1fr);">
    <div class="summary-box">
        <div class="label">چک‌های دریافتی در جریان</div>
        <div class="value stats-income"><?= formatMoney($pendingReceived) ?></div>
    </div>
    <div class="summary-box">
        <div class="label">چک‌های صادره در جریان</div>
        <div class="value stats-expense"><?= formatMoney($pendingIssued) ?></div>
    </div>
</div>

<div class="card">
    <form method="GET" id="filterForm">
        <input type="hidden" name="view" value="<?= h($view) ?>">
        <div class="filter-bar">
            <div class="filter-chip <?= $view === 'active' ? 'active' : '' ?>" data-group="view" data-filter="active">در جریان</div>
            <div class="filter-chip <?= $view === 'archive' ? 'active' : '' ?>" data-group="view" data-filter="archive">بایگانی (پاس‌شده)</div>
        </div>
        <div class="debt-search-bar" style="display:flex; gap:8px; margin-top:10px;">
            <input type="text" name="search" placeholder="جستجو: نام، مبلغ، شماره صیادی..." value="<?= h($search) ?>">
            <button type="submit" class="btn btn-secondary btn-sm">جستجو</button>
        </div>
    </form>
</div>

<?php if ($view === 'archive'): ?>

<div class="card">
    <h2 class="card-title">بایگانی چک‌ها</h2>
    <?php if (empty($allCheques)): ?>
        <p class="empty-row">موردی یافت نشد.</p>
    <?php else: ?>
        <?php foreach ($allCheques as $c): renderChequeCard($c, $todayStr); endforeach; ?>
    <?php endif; ?>
</div>

<?php else: ?>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">چک‌های دریافتی (از دیگران)</h2>
        <button type="button" class="btn btn-primary btn-sm js-add-cheque" data-direction="received">+ ثبت چک دریافتی</button>
    </div>
    <?php if (empty($received)): ?>
        <p class="empty-row">چک دریافتی ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($received as $c): renderChequeCard($c, $todayStr); endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">چک‌های صادره (خودم دادم)</h2>
        <button type="button" class="btn btn-primary btn-sm js-add-cheque" data-direction="issued">+ ثبت چک صادره</button>
    </div>
    <?php if (empty($issued)): ?>
        <p class="empty-row">چک صادره ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($issued as $c): renderChequeCard($c, $todayStr); endforeach; ?>
    <?php endif; ?>
</div>

<?php endif; ?>

<!-- مدیریت بانک‌ها -->
<div class="card collapsible-card collapsed">
    <div class="collapsible-header">
        <h2 class="card-title" style="margin-bottom:0;">مدیریت بانک‌ها</h2>
        <span class="collapse-chevron">▾</span>
    </div>
    <div class="collapsible-body">
        <p style="font-size:12.5px; color:var(--color-gray-500); margin-bottom:14px;">
            این دو لیست کاملاً از هم جدا هستند: «بانک‌های من» فقط برای چک‌هایی که خودم صادر می‌کنم، و «بانک‌های طرف مقابل» برای چک‌هایی که دریافت می‌کنم.
        </p>

        <div class="ref-manager">
            <h3 class="ref-manager-title">بانک‌های من (دسته‌چک خودم)</h3>
            <div class="ref-add-row">
                <input type="text" id="newMyBank" placeholder="مثلاً: ملت" maxlength="100">
                <button type="button" class="btn btn-secondary btn-sm js-ref-add" data-kind="bank_mine" data-input="newMyBank">افزودن</button>
            </div>
            <div class="ref-chip-list" id="myBankList">
                <?php if (empty($myBanks)): ?>
                    <span class="ref-empty">هنوز بانکی اضافه نکرده‌اید.</span>
                <?php else: ?>
                    <?php foreach ($myBanks as $b): ?>
                        <span class="ref-chip"><?= h($b['name']) ?><button type="button" class="ref-chip-x js-ref-delete" data-kind="bank_mine" data-id="<?= (int)$b['id'] ?>">&times;</button></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="ref-manager">
            <h3 class="ref-manager-title">بانک‌های طرف مقابل (چک‌های دریافتی)</h3>
            <div class="ref-add-row">
                <input type="text" id="newExtBank" placeholder="مثلاً: سامان" maxlength="100">
                <button type="button" class="btn btn-secondary btn-sm js-ref-add" data-kind="bank_external" data-input="newExtBank">افزودن</button>
            </div>
            <div class="ref-chip-list" id="extBankList">
                <?php foreach ($externalBanks as $b): ?>
                    <span class="ref-chip"><?= h($b['name']) ?><button type="button" class="ref-chip-x js-ref-delete" data-kind="bank_external" data-id="<?= (int)$b['id'] ?>">&times;</button></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- مودال افزودن چک -->
<div class="modal-overlay" id="addChequeModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="addChequeTitle">ثبت چک</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>

        <form id="addChequeForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="direction" id="add_cheque_direction" value="received">

            <div class="form-group">
                <label for="add_cheque_counterparty">نام شخص <span class="req">*</span></label>
                <input type="text" id="add_cheque_counterparty" name="counterparty_name" required maxlength="150" placeholder="از چه کسی / به چه کسی">
            </div>

            <div class="form-group">
                <label for="add_cheque_amount">مبلغ (تومان) <span class="req">*</span></label>
                <input type="text" inputmode="numeric" id="add_cheque_amount" name="amount" required autocomplete="off">
            </div>

            <div class="form-group">
                <label for="add_cheque_bank">بانک (اختیاری)</label>
                <select id="add_cheque_bank" name="bank_id"><option value="">انتخاب نشده</option></select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="add_sayadi">شماره صیادی (اختیاری)</label>
                    <input type="text" inputmode="numeric" id="add_sayadi" name="sayadi_number" maxlength="30">
                </div>
                <div class="form-group">
                    <label for="add_cheque_number">شماره چک (اختیاری)</label>
                    <input type="text" inputmode="numeric" id="add_cheque_number" name="cheque_number" maxlength="30">
                </div>
            </div>

            <div class="form-group">
                <label>تاریخ سررسید (اختیاری)</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" readonly placeholder="انتخاب کنید">
                    <input type="hidden" class="jdp-hidden" id="add_cheque_due" name="due_date" value="">
                </div>
            </div>

            <div class="form-group">
                <label for="add_cheque_note">توضیح (اختیاری)</label>
                <textarea id="add_cheque_note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <div id="addChequeMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="addChequeSubmitBtn">ثبت</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال ویرایش چک -->
<div class="modal-overlay" id="editChequeModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>ویرایش چک</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>

        <form id="editChequeForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="cheque_id" id="edit_cheque_id">

            <div class="form-group">
                <label for="edit_cheque_counterparty">نام شخص <span class="req">*</span></label>
                <input type="text" id="edit_cheque_counterparty" name="counterparty_name" required maxlength="150">
            </div>

            <div class="form-group">
                <label for="edit_cheque_amount">مبلغ (تومان) <span class="req">*</span></label>
                <input type="text" inputmode="numeric" id="edit_cheque_amount" name="amount" required autocomplete="off">
            </div>

            <div class="form-group">
                <label for="edit_cheque_bank">بانک (اختیاری)</label>
                <select id="edit_cheque_bank" name="bank_id"><option value="">انتخاب نشده</option></select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="edit_sayadi">شماره صیادی (اختیاری)</label>
                    <input type="text" inputmode="numeric" id="edit_sayadi" name="sayadi_number" maxlength="30">
                </div>
                <div class="form-group">
                    <label for="edit_cheque_number">شماره چک (اختیاری)</label>
                    <input type="text" inputmode="numeric" id="edit_cheque_number" name="cheque_number" maxlength="30">
                </div>
            </div>

            <div class="form-group">
                <label>تاریخ سررسید (اختیاری)</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" id="edit_cheque_due_display" readonly placeholder="انتخاب کنید">
                    <input type="hidden" class="jdp-hidden" id="edit_cheque_due" name="due_date">
                </div>
            </div>

            <div class="form-group">
                <label for="edit_cheque_note">توضیح (اختیاری)</label>
                <textarea id="edit_cheque_note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <div id="editChequeMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="editChequeSubmitBtn">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>


<!-- ---------- انتخاب حساب هنگام پاس شدن چک ----------
     پول واقعاً جابه‌جا می‌شود، پس باید معلوم باشد از/به کدام حساب.
     اگر دست نزنید، «کیف پول» پیش‌فرض است. -->
<div class="modal-overlay" id="chequeSettle">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="chequeSettleTitle">پاس شدن چک</h3>
            <button type="button" class="modal-close" data-modal-close="chequeSettle">&times;</button>
        </div>
        <form id="chequeSettleForm" autocomplete="off">
            <input type="hidden" id="chequeSettleRecordId" value="">

            <p class="hint" id="chequeSettleHint" style="margin-bottom:14px;"></p>

            <div class="form-group">
                <label for="chequeSettleWallet">واریز به / برداشت از حساب</label>
                <select id="chequeSettleWallet" name="wallet_id">
                    <?php foreach ($walletList as $w): ?>
                        <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $defaultWallet ? 'selected' : '' ?>><?= h($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">این مبلغ در موجودی همان حساب اعمال می‌شود. در گزارش درآمد و هزینه شمرده نمی‌شود.</p>
            </div>

            <div id="chequeSettleMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close="chequeSettle">انصراف</button>
                <button type="submit" class="btn btn-primary" id="chequeSettleSubmitBtn">تأیید</button>
            </div>
        </form>
    </div>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<script>
    window.BANK_DATA = {
        mine: <?= json_encode(array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name']], $myBanks), JSON_UNESCAPED_UNICODE) ?>,
        external: <?= json_encode(array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name']], $externalBanks), JSON_UNESCAPED_UNICODE) ?>
    };
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
