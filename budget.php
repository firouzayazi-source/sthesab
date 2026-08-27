<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();
$todayStr = today();

$budgets = budgetStatuses($userId);

// یادداشت: دسته‌بندی‌ها سراسری‌اند (نه به‌ازای کاربر)، مطابق معماری فعلی
$expenseCatsStmt = $pdo->prepare('SELECT id, name, icon, color FROM categories WHERE type = "expense" AND is_active = 1 ORDER BY name');
$expenseCatsStmt->execute();
$expenseCategories = $expenseCatsStmt->fetchAll();

$pageTitle = 'بودجه‌بندی';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">بودجه‌های من</h2>
        <button type="button" class="btn btn-primary btn-sm js-add-budget" <?= empty($expenseCategories) ? 'disabled' : '' ?>>+ بودجه جدید</button>
    </div>

    <?php if (empty($budgets)): ?>
        <p class="empty-row">هنوز بودجه‌ای تعریف نکرده‌اید.<br>برای هر دسته‌بندی هزینه یک سقف تعیین کنید تا مصرفتان را کنترل کنید.</p>
    <?php else: ?>
        <?php foreach ($budgets as $b): ?>
            <div class="budget-item">
                <div class="budget-head">
                    <span class="cat-icon cat-icon-sm" style="background: <?= h($b['cat_color']) ?>1f; color: <?= h($b['cat_color']) ?>;">
                        <?= categoryIconSvg($b['cat_icon'], 15) ?>
                    </span>
                    <div class="budget-meta">
                        <div class="budget-name"><?= h($b['category_name']) ?></div>
                        <div class="budget-period"><?= budgetPeriodLabel($b['period_type']) ?></div>
                    </div>
                    <button type="button" class="wallet-menu js-edit-budget"
                        data-id="<?= (int)$b['id'] ?>"
                        data-category="<?= (int)$b['category_id'] ?>"
                        data-period="<?= h($b['period_type']) ?>"
                        data-amount="<?= (int)$b['amount'] ?>"
                        data-start="<?= h($b['start_date'] ?? '') ?>"
                        data-end="<?= h($b['end_date'] ?? '') ?>"
                        data-active="<?= (int)$b['is_active'] ?>"
                        aria-label="ویرایش">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/></svg>
                    </button>
                </div>

                <div class="budget-bar-track">
                    <div class="budget-bar budget-bar-<?= h($b['status']) ?>" style="width:<?= min(100, $b['percent']) ?>%;"></div>
                </div>

                <div class="budget-numbers">
                    <span class="budget-spent">
                        <?= formatMoney($b['spent']) ?>
                        <span class="budget-of">از <?= formatMoney($b['amount']) ?></span>
                    </span>
                    <span class="budget-pct budget-pct-<?= h($b['status']) ?>"><?= toPersianDigits($b['percent']) ?>٪</span>
                </div>

                <?php if ($b['status'] === 'over'): ?>
                    <div class="budget-note budget-note-over"><?= formatMoney(abs($b['remaining'])) ?> تومان بیشتر از بودجه خرج شده</div>
                <?php else: ?>
                    <div class="budget-note"><?= formatMoney($b['remaining']) ?> تومان باقیمانده</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- مودال بودجه -->
<div class="modal-overlay" id="budgetModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="budgetModalTitle">بودجه جدید</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form id="budgetForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="budget_id" id="budget_id" value="">

            <div class="form-group">
                <label for="budget_category">دسته‌بندی هزینه</label>
                <select id="budget_category" name="category_id" required>
                    <?php foreach ($expenseCategories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="budget_period">دوره</label>
                    <select id="budget_period" name="period_type">
                        <option value="monthly">ماهانه</option>
                        <option value="weekly">هفتگی</option>
                        <option value="yearly">سالانه</option>
                        <option value="custom">بازه دلخواه</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="budget_amount">مبلغ سقف</label>
                    <input type="text" inputmode="numeric" id="budget_amount" name="amount" required placeholder="۰">
                </div>
            </div>

            <div class="form-row" id="budgetCustomDates" hidden>
                <div class="form-group">
                    <label>از تاریخ</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" id="budget_start_display" readonly>
                        <input type="hidden" class="jdp-hidden" id="budget_start" name="start_date">
                    </div>
                </div>
                <div class="form-group">
                    <label>تا تاریخ</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" id="budget_end_display" readonly>
                        <input type="hidden" class="jdp-hidden" id="budget_end" name="end_date">
                    </div>
                </div>
            </div>

            <div id="budgetMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="budgetSubmitBtn">ذخیره</button>
            </div>

            <div id="budgetExtraActions" class="wallet-extra" hidden>
                <button type="button" class="btn btn-secondary btn-sm" id="budgetToggleBtn"></button>
                <button type="button" class="delete-btn" id="budgetDeleteBtn">حذف بودجه</button>
            </div>
        </form>
    </div>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php include __DIR__ . '/includes/footer.php'; ?>
