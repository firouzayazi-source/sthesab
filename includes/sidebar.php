<?php
$currentPage = basename($_SERVER['PHP_SELF']);

// هر دو شمارش در یک رفت‌وبرگشت به دیتابیس — نه دو تا
$debtReminderCount = 0;
$chequeReminderCount = 0;
if (Auth::isLoggedIn()) {
    $soon = date('Y-m-d', strtotime('+7 days'));
    $uid  = Auth::userId();

    try {
        $stmt = Database::getConnection()->prepare('
            SELECT
                (SELECT COUNT(*) FROM debts
                 WHERE user_id = :u1 AND is_settled = 0 AND due_date <= :s1) AS debt_cnt,
                (SELECT COUNT(*) FROM cheques
                 WHERE user_id = :u2 AND is_settled = 0 AND due_date IS NOT NULL AND due_date <= :s2) AS cheque_cnt
        ');
        $stmt->execute(['u1' => $uid, 's1' => $soon, 'u2' => $uid, 's2' => $soon]);
        $row = $stmt->fetch();
        $debtReminderCount   = (int)($row['debt_cnt'] ?? 0);
        $chequeReminderCount = (int)($row['cheque_cnt'] ?? 0);
    } catch (PDOException $e) {
        // جدول چک هنوز ساخته نشده — فقط بدهی را بشمار
        try {
            $s2 = Database::getConnection()->prepare('
                SELECT COUNT(*) AS cnt FROM debts
                WHERE user_id = :u AND is_settled = 0 AND due_date <= :s
            ');
            $s2->execute(['u' => $uid, 's' => $soon]);
            $debtReminderCount = (int)$s2->fetch()['cnt'];
        } catch (PDOException $e2) { /* ignore */ }
    }
}
?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<nav class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="<?= APP_BASE_PATH ?>/assets/icons/icon-192.png" alt="" class="brand-icon brand-icon-img" width="24" height="24">
        <span class="brand-text"><?= h(APP_NAME) ?></span>
    </div>

    <ul class="sidebar-nav">
        <li>
            <a href="<?= APP_BASE_PATH ?>/index.php" class="<?= $currentPage === 'index.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span>ثبت تراکنش</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/dashboard.php" class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 9L12 2L21 9V20A2 2 0 0119 22H5A2 2 0 013 20V9Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
                <span>داشبورد</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/wallets.php" class="<?= $currentPage === 'wallets.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 7.5h15a2.5 2.5 0 012.5 2.5v7a2.5 2.5 0 01-2.5 2.5H5.5A2.5 2.5 0 013 17V7.5z" stroke="currentColor" stroke-width="2"/><path d="M3 7.5l12-3v3" stroke="currentColor" stroke-width="2"/><circle cx="17" cy="13.5" r="1.3" fill="currentColor"/></svg>
                <span>حساب‌ها</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/transactions.php" class="<?= $currentPage === 'transactions.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>تراکنش‌های من</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/upcoming.php" class="<?= $currentPage === 'upcoming.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>آینده مالی</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/calendar.php" class="<?= $currentPage === 'calendar.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="2"/><path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span>تقویم مالی</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/budget.php" class="<?= $currentPage === 'budget.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="16" rx="2" stroke="currentColor" stroke-width="2"/><path d="M7 9h10M7 13h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span>بودجه‌بندی</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/savings.php" class="<?= $currentPage === 'savings.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2v4M12 18v4M4 12h4M16 12h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/></svg>
                <span>اهداف پس‌انداز</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/recurring.php" class="<?= $currentPage === 'recurring.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M17 2l4 4-4 4M3 11V9a4 4 0 014-4h14M7 22l-4-4 4-4M21 13v2a4 4 0 01-4 4H3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>تراکنش دوره‌ای</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/category-report.php" class="<?= $currentPage === 'category-report.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M12 3v9l6 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>گزارش دسته‌بندی</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/debts.php" class="<?= $currentPage === 'debts.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>طلب و بدهی</span>
                <?php if ($debtReminderCount > 0): ?><span class="nav-badge"><?= toPersianDigits($debtReminderCount) ?></span><?php endif; ?>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/cheques.php" class="<?= $currentPage === 'cheques.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="2" y="5" width="20" height="14" rx="2" stroke="currentColor" stroke-width="2"/><path d="M2 10h20M6 15h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span>چک‌ها</span>
                <?php if ($chequeReminderCount > 0): ?><span class="nav-badge"><?= toPersianDigits($chequeReminderCount) ?></span><?php endif; ?>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/my-assets.php" class="<?= $currentPage === 'my-assets.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><ellipse cx="12" cy="6" rx="8" ry="3" stroke="currentColor" stroke-width="2"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6" stroke="currentColor" stroke-width="2"/></svg>
                <span>دارایی‌ها</span>
            </a>
        </li>
        <?php if (Auth::isAdmin()): ?>
        <li class="nav-divider">مدیریت</li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/admin/users.php" class="<?= $currentPage === 'users.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>مدیریت کاربران</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/admin/categories.php" class="<?= $currentPage === 'categories.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h16M4 18h7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span>دسته‌بندی‌ها</span>
            </a>
        </li>
        <li>
            <a href="<?= APP_BASE_PATH ?>/admin/all-transactions.php" class="<?= $currentPage === 'all-transactions.php' ? 'active' : '' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>
                <span>تراکنش همه کاربران</span>
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
