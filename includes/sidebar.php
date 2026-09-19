<?php require_once __DIR__ . '/plan_gate.php'; ?>
<?php
$__currentPage = basename($_SERVER['PHP_SELF']);

// ⚠ `support.php` **دو بار** وجود دارد — یکی در ریشه (کاربر) و یکی در
//   `admin/` — و `basename()` هر دو را یک چیز می‌بیند. بدونِ این پرچم،
//   باز کردنِ پنلِ تیکت قلمِ «پشتیبانی و راهنما»ی کاربر را هم روشن
//   نشان می‌داد و برعکس: دو قلمِ فعال در یک منو، بی‌هیچ خطایی.
$__inAdmin = strpos((string)($_SERVER['PHP_SELF'] ?? ''), '/admin/') !== false;

// هر دو شمارش در یک رفت‌وبرگشت به دیتابیس — نه دو تا
$__debtReminderCount = 0;
$__chequeReminderCount = 0;
if (Auth::isLoggedIn()) {
    $__soon = date('Y-m-d', strtotime('+7 days'));
    $__uid  = Auth::userId();

    try {
        $__stmt = Database::getConnection()->prepare('
            SELECT
                (SELECT COUNT(*) FROM debts
                 WHERE user_id = :u1 AND is_settled = 0 AND due_date <= :s1) AS debt_cnt,
                (SELECT COUNT(*) FROM cheques
                 WHERE user_id = :u2 AND ' . chequeActiveSql() . ' AND due_date IS NOT NULL AND due_date <= :s2) AS cheque_cnt
        ');
        $__stmt->execute(['u1' => $__uid, 's1' => $__soon, 'u2' => $__uid, 's2' => $__soon]);
        $__row = $__stmt->fetch();
        $__debtReminderCount   = (int)($__row['debt_cnt'] ?? 0);
        $__chequeReminderCount = (int)($__row['cheque_cnt'] ?? 0);
    } catch (PDOException $e) {
        // جدول چک هنوز ساخته نشده — فقط بدهی را بشمار
        try {
            $__s2 = Database::getConnection()->prepare('
                SELECT COUNT(*) AS cnt FROM debts
                WHERE user_id = :u AND is_settled = 0 AND due_date <= :s
            ');
            $__s2->execute(['u' => $__uid, 's' => $__soon]);
            $__debtReminderCount = (int)$__s2->fetch()['cnt'];
        } catch (PDOException $e2) { /* ignore */ }
    }
}
?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<nav class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="<?= iconUrl('icon-192.png') ?>" alt="" class="brand-icon brand-icon-img" width="24" height="24">
        <span class="brand-text"><?= h(APP_NAME) ?></span>
    </div>

    <ul class="sidebar-nav">
        <?php /* ⛔ این **دکمه** است نه لینک، و شیت را همان‌جا باز می‌کند.
                 پیش از این یک `<a>` به `index.php` بود: شکلش دقیقاً
                 «ثبت تراکنش» بود ولی کارش فقط رفتن به خانه — یعنی روی
                 دسکتاپ که نوارِ پایین پنهان است، تنها چیزی که شبیه راهِ
                 ثبت تراکنش بود، ثبت تراکنش نمی‌کرد. */ ?>
        <li>
            <button type="button" class="sidebar-action js-add-tx">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span>ثبت تراکنش</span>
            </button>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/dashboard.php" class="<?= $__currentPage === 'dashboard.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 9L12 2L21 9V20A2 2 0 0119 22H5A2 2 0 013 20V9Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
                <span>داشبورد</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/wallets.php" class="<?= $__currentPage === 'wallets.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 7.5h15a2.5 2.5 0 012.5 2.5v7a2.5 2.5 0 01-2.5 2.5H5.5A2.5 2.5 0 013 17V7.5z" stroke="currentColor" stroke-width="2"/><path d="M3 7.5l12-3v3" stroke="currentColor" stroke-width="2"/><circle cx="17" cy="13.5" r="1.3" fill="currentColor"/></svg>
                <span>حساب‌ها</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/transactions.php" class="<?= $__currentPage === 'transactions.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>تراکنش‌های من</span>
            </a>
        </li>
        <?php /* ⛔ یک قلم به‌جای چهار تا. «آینده مالی»، «تقویم مالی»،
                 «سررسیدها» و «یادآورها» همه یک سؤال را جواب می‌دادند
                 («چه چیزی در راه است؟») و حالا زبانه‌های یک صفحه‌اند.
                 «تراکنش دوره‌ای» هم چون پول جابه‌جا می‌کند صفحه‌ی خودش
                 ماند ولی لینکش رفت داخلِ همان صفحه. دلیلِ کامل بالای
                 `due.php` نوشته شده.

                 ⛔ و عمداً **بیرون** از بلوکِ `Auth::isAdmin()` است:
                 «سررسیدها» و «یادآورها» تا دیروز داخلِ آن بلوک افتاده
                 بودند، پس کاربر عادی روی دسکتاپ هیچ راهی به آن‌ها
                 نداشت — بی‌هیچ خطایی، فقط نبودند. */ ?>
        <li>
            <a href="<?= APP_BASE_PATH ?>/due.php" class="<?= $__currentPage === 'due.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M9 15l2 2 4-4"/></svg>
                <?php /* ⚠ همان برچسبِ شیتِ «بیشتر» در `includes/footer.php`.
                         هر دو یک مقصد را باز می‌کنند، پس هر تغییری باید در
                         هر دو با هم انجام شود. */ ?>
                <span>یادآوری‌های من</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/budget.php" class="<?= $__currentPage === 'budget.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="16" rx="2" stroke="currentColor" stroke-width="2"/><path d="M7 9h10M7 13h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span>بودجه‌بندی</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/savings.php" class="<?= $__currentPage === 'savings.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2v4M12 18v4M4 12h4M16 12h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/></svg>
                <span>اهداف پس‌انداز</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/category-report.php" class="<?= $__currentPage === 'category-report.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M12 3v9l6 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>گزارش دسته‌بندی</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/debts.php" class="<?= $__currentPage === 'debts.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>طلب و بدهی</span>
                <?= planReadOnly('debts') ? '<span class="lock-badge" title="فقط خواندنی — ثبتِ مورد تازه با اشتراک باز می‌شود">&#128274;</span>' : '' ?>
                <?php if ($__debtReminderCount > 0): ?><span class="nav-badge"><?= toPersianDigits($__debtReminderCount) ?></span><?php endif; ?>
            </a>
        </li>
        <?php if (tradesEnabled(Database::getConnection(), (int)Auth::userId())): ?>
        <li>
            <a href="<?= APP_BASE_PATH ?>/trades.php" class="<?= $__currentPage === 'trades.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4v16M7 4L3.5 7.5M7 4l3.5 3.5M17 20V4M17 20l3.5-3.5M17 20l-3.5-3.5"/></svg>
                <span>معاملات</span>
                <?= planReadOnly('trades') ? '<span class="lock-badge" title="فقط خواندنی — ثبتِ مورد تازه با اشتراک باز می‌شود">&#128274;</span>' : '' ?>
            </a>
        </li>
        <?php endif; ?>
        <li>
            <a href="<?= APP_BASE_PATH ?>/cheques.php" class="<?= $__currentPage === 'cheques.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="2" y="5" width="20" height="14" rx="2" stroke="currentColor" stroke-width="2"/><path d="M2 10h20M6 15h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span>چک‌ها</span>
                <?= planReadOnly('cheques') ? '<span class="lock-badge" title="فقط خواندنی — ثبتِ مورد تازه با اشتراک باز می‌شود">&#128274;</span>' : '' ?>
                <?php if ($__chequeReminderCount > 0): ?><span class="nav-badge"><?= toPersianDigits($__chequeReminderCount) ?></span><?php endif; ?>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/my-assets.php" class="<?= $__currentPage === 'my-assets.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><ellipse cx="12" cy="6" rx="8" ry="3" stroke="currentColor" stroke-width="2"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6" stroke="currentColor" stroke-width="2"/></svg>
                <span>دارایی‌ها</span>
            </a>
        </li>
        <?php
        /* ⛔ پشتیبانی **بیرون** از بلوکِ `Auth::isAdmin()` است و این
           عمدی: یک بار «سررسیدها» و «یادآورها» اشتباهاً داخلِ آن بلوک
           افتادند و کاربر عادی روی دسکتاپ هیچ راهی به آن‌ها نداشت — نه
           خطایی، نه بریدگی‌ای؛ فقط نبودند. (روی موبایل از شیتِ ابزارها
           پیدا می‌شدند، پس «روی گوشی درست است» مدرک نبود.) */
        ?>
        <li>
            <a href="<?= APP_BASE_PATH ?>/support.php" class="<?= $__currentPage === 'support.php' && !$__inAdmin ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 015.8 1c0 2-3 2.5-3 4"/><circle cx="12" cy="17.5" r=".8" fill="currentColor" stroke="none"/></svg>
                <span>پشتیبانی و راهنما</span>
            </a>
        </li>
        <?php
        /* ⛔ سرتیتر از `hasAnyCap()` می‌آید، نه از `isAdmin()`.
           «پشتیبان» باید بخشِ مدیریت را ببیند ولی **فقط** قلمِ پشتیبانی
           را؛ با گیتِ `isAdmin()` روی کلِ بلوک، کاربری که نقشش برای
           همین کار ساخته شده روی دسکتاپ هیچ راهی به پنلِ تیکت نداشت —
           دقیقاً همان باگی که یک بار سرِ «سررسیدها» افتاد. */
        ?>
        <?php if (Auth::hasAnyCap()): ?>
        <li class="nav-divider">مدیریت</li>
        <?php endif; ?>
        <?php if (Auth::can('admin')): ?>
        <li>
            <a href="<?= APP_BASE_PATH ?>/admin/users.php" class="<?= $__currentPage === 'users.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>کاربران</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/admin/access.php" class="<?= $__currentPage === 'access.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                <span>ورود و پیامک</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/admin/billing.php" class="<?= $__currentPage === 'billing.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                <span>اشتراک و پرداخت</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/admin/categories.php" class="<?= $__currentPage === 'categories.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h16M4 18h7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span>دسته‌بندی‌ها</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/admin/insights.php" class="<?= $__currentPage === 'insights.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
                <span>آمار استفاده</span>
            </a>
        </li>
        <?php endif; ?>
        <?php
        /* ⚠ پنلِ پشتیبانی تا امروز **هیچ** لینکی در نوارِ کناری نداشت و
           فقط از شیتِ ابزارهای موبایل پیدا می‌شد — پس «روی گوشی درست
           است» اینجا هم مدرک نبود. */
        ?>
        <?php if (Auth::can('support')): ?>
        <li>
            <a href="<?= APP_BASE_PATH ?>/admin/support.php" class="<?= $__currentPage === 'support.php' && $__inAdmin ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                <span>پشتیبانی</span>
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <div class="sidebar-footer">
        <a href="<?= APP_BASE_PATH ?>/profile.php" class="logout-link">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="2"/><path d="M4 21v-1a6 6 0 016-6h4a6 6 0 016 6v1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            <span>حساب کاربری من</span>
        </a>
        <a href="<?= APP_BASE_PATH ?>/logout.php" class="logout-link" onclick="return confirm('آیا مطمئن هستید که می‌خواهید خارج شوید؟')">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span>خروج از حساب</span>
        </a>
    </div>
</nav>
