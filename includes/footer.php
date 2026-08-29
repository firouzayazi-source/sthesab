<?php
/**
 * فوتر مشترک صفحات داخلی — شامل ناوبری پایین صفحه
 */
$bottomPage = basename($_SERVER['PHP_SELF']);
$morePages = ['profile.php', 'wallets.php', 'budget.php', 'savings.php', 'recurring.php', 'upcoming.php', 'calendar.php', 'search.php', 'data.php', 'import.php', 'cheques.php', 'debts.php', 'my-assets.php', 'category-report.php', 'users.php', 'categories.php', 'all-transactions.php'];
$moreActive = in_array($bottomPage, $morePages, true);
?>
        </div>
    </div>
</div>

<!-- ناوبری پایین صفحه -->
<nav class="bottom-nav">
    <a href="<?= APP_BASE_PATH ?>/index.php" class="bottom-nav-item <?= $bottomPage === 'index.php' ? 'active' : '' ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M3 9L12 2L21 9V20A2 2 0 0119 22H5A2 2 0 013 20V9Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
        <span>خانه</span>
    </a>

    <a href="<?= APP_BASE_PATH ?>/transactions.php" class="bottom-nav-item <?= $bottomPage === 'transactions.php' ? 'active' : '' ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        <span>تراکنش‌ها</span>
    </a>

    <button type="button" class="bottom-nav-center" id="addTxBtn" aria-label="ثبت تراکنش جدید">
        <span class="bottom-nav-fab">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
        </span>
    </button>

    <a href="<?= APP_BASE_PATH ?>/dashboard.php" class="bottom-nav-item <?= $bottomPage === 'dashboard.php' ? 'active' : '' ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M18 20V10M12 20V4M6 20v-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span>گزارش</span>
    </a>

    <button type="button" class="bottom-nav-item <?= $moreActive ? 'active' : '' ?>" id="moreTabBtn">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="5" cy="12" r="1.7" fill="currentColor"/><circle cx="12" cy="12" r="1.7" fill="currentColor"/><circle cx="19" cy="12" r="1.7" fill="currentColor"/></svg>
        <span>بیشتر</span>
    </button>
</nav>

<!-- شیت ابزارها -->
<div class="more-sheet-overlay" id="moreSheet">
    <div class="more-sheet">
        <div class="more-sheet-handle"></div>
        <h3 class="more-sheet-title">ابزارها</h3>
        <div class="tools-grid">
            <?php if (tradesEnabled(Database::getConnection(), (int)Auth::userId())): ?>
            <a href="<?= APP_BASE_PATH ?>/trades.php" class="tool-card tool-card-wide" style="--tc1:#b8862f; --tc2:#94620d;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4v16M7 4L3.5 7.5M7 4l3.5 3.5M17 20V4M17 20l3.5-3.5M17 20l-3.5-3.5"/></svg>
                <span>معاملات — خرید و فروش</span>
            </a>
            <?php endif; ?>
            <a href="<?= APP_BASE_PATH ?>/wallets.php" class="tool-card" style="--tc1:#16794f; --tc2:#0f766e;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7.5h15a2.5 2.5 0 012.5 2.5v7a2.5 2.5 0 01-2.5 2.5H5.5A2.5 2.5 0 013 17V7.5z"/><path d="M3 7.5l12-3v3"/><circle cx="17" cy="13.5" r="1.3" fill="currentColor"/></svg>
                <span>حساب‌ها و انتقال</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/debts.php" class="tool-card" style="--tc1:#f43f5e; --tc2:#ec4899;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>طلب و بدهی</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/cheques.php" class="tool-card" style="--tc1:#8b5cf6; --tc2:#6366f1;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><rect x="2" y="5" width="20" height="14" rx="2" stroke="currentColor" stroke-width="2"/><path d="M2 10h20M6 15h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span>چک‌ها</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/my-assets.php" class="tool-card" style="--tc1:#f59e0b; --tc2:#eab308;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><ellipse cx="12" cy="6" rx="8" ry="3" stroke="currentColor" stroke-width="2"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6" stroke="currentColor" stroke-width="2"/></svg>
                <span>دارایی‌ها</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/upcoming.php" class="tool-card" style="--tc1:#0891b2; --tc2:#0e7490;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>آینده مالی</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/calendar.php" class="tool-card" style="--tc1:#7c3aed; --tc2:#6d28d9;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4" stroke-linecap="round"/></svg>
                <span>تقویم مالی</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/budget.php" class="tool-card" style="--tc1:#d97706; --tc2:#ca8a04;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 13h6" stroke-linecap="round"/></svg>
                <span>بودجه‌بندی</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/savings.php" class="tool-card" style="--tc1:#0ea5e9; --tc2:#0284c7;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4 12h4M16 12h4" stroke-linecap="round"/><circle cx="12" cy="12" r="5"/></svg>
                <span>اهداف پس‌انداز</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/recurring.php" class="tool-card" style="--tc1:#6366f1; --tc2:#4f46e5;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 2l4 4-4 4M3 11V9a4 4 0 014-4h14M7 22l-4-4 4-4M21 13v2a4 4 0 01-4 4H3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>تراکنش دوره‌ای</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/category-report.php" class="tool-card" style="--tc1:#10b981; --tc2:#14b8a6;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M12 3v9l6 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>گزارش دسته‌بندی</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/data.php" class="tool-card" style="--tc1:#0ea5e9; --tc2:#0891b2;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>خروجی و ورودی</span>
            </a>
            <?php if (Auth::isAdmin()): ?>
            <a href="<?= APP_BASE_PATH ?>/admin/users.php" class="tool-card" style="--tc1:#64748b; --tc2:#475569;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>کاربران</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/admin/categories.php" class="tool-card" style="--tc1:#78716c; --tc2:#57534e;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h16M4 18h7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span>دسته‌بندی‌ها</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/admin/all-transactions.php" class="tool-card" style="--tc1:#525252; --tc2:#404040;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>
                <span>تراکنش همه</span>
            </a>
            <?php endif; ?>
            <a href="<?= APP_BASE_PATH ?>/profile.php" class="tool-card" style="--tc1:#334155; --tc2:#1e293b;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 016-6h4a6 6 0 016 6v1"/></svg>
                <span>حساب کاربری من</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/logout.php" class="tool-card" style="--tc1:#ef4444; --tc2:#dc2626;" onclick="return confirm('از حساب خارج می‌شوید؟')">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>خروج</span>
            </a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/add_tx_sheet.php'; ?>

<script>window.APP_BASE = '<?= APP_BASE_PATH ?>';</script>
<script src="<?= APP_BASE_PATH ?>/assets/serve.php?f=js/jalali-datepicker.js,js/app.js&v=<?= assetVersion(['js/jalali-datepicker.js', 'js/app.js']) ?>"></script>
</body>
</html>
