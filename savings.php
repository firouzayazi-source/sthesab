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
$goals = savingsGoalsWithProgress($userId, $view === 'archive');
if ($view === 'archive') {
    $goals = array_values(array_filter($goals, fn($g) => (int)$g['is_archived'] === 1));
} else {
    $goals = array_values(array_filter($goals, fn($g) => (int)$g['is_archived'] === 0));
}

$pageTitle = 'اهداف پس‌انداز';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="filter-bar">
        <a href="?view=active" class="filter-chip <?= $view === 'active' ? 'active' : '' ?>" style="text-decoration:none;">فعال</a>
        <a href="?view=archive" class="filter-chip <?= $view === 'archive' ? 'active' : '' ?>" style="text-decoration:none;">بایگانی</a>
    </div>
</div>

<?php if ($view === 'active'): ?>
<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">اهداف پس‌انداز</h2>
        <button type="button" class="btn btn-primary btn-sm js-add-goal">+ هدف جدید</button>
    </div>
</div>
<?php endif; ?>

<?php if (empty($goals)): ?>
    <div class="card">
        <p class="empty-row"><?= $view === 'archive' ? 'هدف بایگانی‌شده‌ای ندارید.' : 'هنوز هدفی تعریف نکرده‌اید.<br>مثلاً «سفر تابستان» یا «تعویض گوشی» را با یک مبلغ هدف بسازید.' ?></p>
    </div>
<?php else: ?>
    <?php foreach ($goals as $g): ?>
        <div class="card goal-card">
            <div class="goal-head">
                <div>
                    <div class="goal-title">
                        <?= h($g['title']) ?>
                        <?php if ($g['is_complete']): ?><span class="goal-done">🎉 تکمیل شد</span><?php endif; ?>
                    </div>
                    <?php if ($g['target_date']): ?>
                        <div class="goal-date">مهلت: <?= toJalali($g['target_date']) ?></div>
                    <?php endif; ?>
                    <?php /* ⚠ نامِ حساب فقط وقتی رندر می‌شود که هم ستون آمده
                             باشد هم حساب هنوز پاک نشده باشد — `ON DELETE SET
                             NULL` یعنی این مقدار می‌تواند خالی شود. */ ?>
                    <?php if (!empty($g['wallet_name'])): ?>
                        <div class="goal-date">حساب: <?= h($g['wallet_name']) ?></div>
                    <?php endif; ?>
                </div>
                <button type="button" class="wallet-menu js-edit-goal"
                    data-id="<?= (int)$g['id'] ?>"
                    data-title="<?= h($g['title']) ?>"
                    data-target="<?= (int)$g['target_amount'] ?>"
                    data-date="<?= h($g['target_date'] ?? '') ?>"
                    data-color="<?= h($g['color']) ?>"
                    data-wallet="<?= (int)($g['wallet_id'] ?? 0) ?>"
                    data-archived="<?= (int)$g['is_archived'] ?>"
                    aria-label="ویرایش">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/></svg>
                </button>
            </div>

            <div class="budget-bar-track" style="margin:11px 0 8px;">
                <div class="budget-bar" style="width:<?= $g['percent'] ?>%; background: <?= h($g['color']) ?>;"></div>
            </div>

            <div class="budget-numbers">
                <span class="budget-spent"><?= formatMoney($g['current_amount']) ?> <span class="budget-of">از <?= formatMoney($g['target_amount']) ?></span></span>
                <span class="budget-pct" style="color: <?= h($g['color']) ?>;"><?= toPersianDigits($g['percent']) ?>٪</span>
            </div>

            <div class="goal-actions">
                <button type="button" class="btn btn-secondary btn-sm js-goal-deposit" data-id="<?= (int)$g['id'] ?>" data-title="<?= h($g['title']) ?>">+ واریز</button>
                <button type="button" class="btn btn-secondary btn-sm js-goal-withdraw" data-id="<?= (int)$g['id'] ?>" data-title="<?= h($g['title']) ?>">− برداشت</button>
                <button type="button" class="btn btn-secondary btn-sm js-goal-history" data-id="<?= (int)$g['id'] ?>">تاریخچه</button>
            </div>

            <div class="goal-history" id="goalHistory<?= (int)$g['id'] ?>" hidden></div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- مودال هدف -->
<div class="modal-overlay" id="goalModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="goalModalTitle">هدف جدید</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form id="goalForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="goal_id" id="goal_id" value="">

            <div class="form-group">
                <label for="goal_title">عنوان</label>
                <input type="text" id="goal_title" name="title" required maxlength="150" placeholder="مثلاً: سفر تابستان">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="goal_target">مبلغ هدف</label>
                    <input type="text" inputmode="numeric" id="goal_target" name="target_amount" required placeholder="۰">
                </div>
                <div class="form-group">
                    <label for="goal_color">رنگ</label>
                    <input type="color" id="goal_color" name="color" value="#16794f">
                </div>
            </div>

            <?php /* ⛔ این پیوند **ارجاعی است، نه انتقالِ پول.** موجودیِ
                     حساب هیچ تغییری نمی‌کند و `walletBalances()` دست
                     نمی‌خورد — پس‌انداز یک «پاکتِ کنارگذاشته» روی پولی
                     است که از قبل در حساب هست، نه یک خرج. اگر روزی
                     موجودی را کم کند، موجودیِ هر کسی که تا امروز
                     پس‌انداز ثبت کرده بی‌صدا عوض می‌شود.
                     جوابِ این فیلد به یک سؤالِ واقعی است: «آن پولی که
                     کنار گذاشته‌ام کجاست؟» */ ?>
            <div class="form-group">
                <label for="goal_wallet">در کدام حساب؟ (اختیاری)</label>
                <select id="goal_wallet" name="wallet_id">
                    <option value="0">مشخص نشده</option>
                    <?php foreach (activeWallets($userId) as $w): ?>
                        <option value="<?= (int)$w['id'] ?>"><?= h($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">فقط برای اینکه بدانید این پول کجا کنار گذاشته شده — از موجودی حساب کم نمی‌شود.</p>
            </div>

            <?php /* پیش‌نمایشِ زنده: تا امروز کاربر رنگ را انتخاب می‌کرد و
                     تا **بعد از ذخیره** هیچ‌جا نمی‌دید که چه شکلی می‌شود. */ ?>
            <div class="goal-preview" id="goalPreview">
                <div class="goal-preview-title" id="goalPreviewTitle">عنوان هدف</div>
                <div class="budget-bar-track">
                    <div class="budget-bar" id="goalPreviewBar" style="width:45%;"></div>
                </div>
                <div class="goal-preview-foot">
                    <span id="goalPreviewWallet">مشخص نشده</span>
                    <span class="budget-pct" id="goalPreviewPct">۴۵٪</span>
                </div>
            </div>

            <div class="form-group">
                <label>مهلت (اختیاری)</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" id="goal_date_display" readonly placeholder="انتخاب کنید">
                    <input type="hidden" class="jdp-hidden" id="goal_date" name="target_date" value="">
                </div>
            </div>

            <div id="goalMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="goalSubmitBtn">ذخیره</button>
            </div>

            <div id="goalExtraActions" class="wallet-extra" hidden>
                <button type="button" class="btn btn-secondary btn-sm" id="goalArchiveBtn"></button>
                <button type="button" class="delete-btn" id="goalDeleteBtn">حذف هدف</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال واریز/برداشت -->
<div class="modal-overlay" id="goalEntryModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="goalEntryTitle">واریز</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form id="goalEntryForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="goal_id" id="entry_goal_id">
            <input type="hidden" name="direction" id="entry_direction" value="deposit">

            <div class="form-group">
                <label for="entry_amount">مبلغ</label>
                <input type="text" inputmode="numeric" id="entry_amount" name="amount" required class="amount-input" placeholder="۰">
            </div>

            <div class="form-group">
                <label>تاریخ</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" id="entry_date_display" readonly value="<?= toJalali($todayStr) ?>">
                    <input type="hidden" class="jdp-hidden" id="entry_date" name="entry_date" value="<?= h($todayStr) ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="entry_note">توضیح (اختیاری)</label>
                <textarea id="entry_note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <div id="goalEntryMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="goalEntrySubmitBtn">ثبت</button>
            </div>
        </form>
    </div>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php include __DIR__ . '/includes/footer.php'; ?>
