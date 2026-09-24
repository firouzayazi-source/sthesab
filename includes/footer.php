<?php
require_once __DIR__ . '/plan_gate.php';
/**
 * فوتر مشترک صفحات داخلی — شامل ناوبری پایین صفحه
 */
$__bottomPage = basename($_SERVER['PHP_SELF']);
$__morePages = ['profile.php', 'wallets.php', 'budget.php', 'savings.php', 'recurring.php', 'due.php', 'search.php', 'data.php', 'cheques.php', 'debts.php', 'my-assets.php', 'category-report.php', 'references.php', 'person.php', 'users.php', 'categories.php', 'insights.php', 'backup.php', 'support.php'];
$__moreActive = in_array($__bottomPage, $__morePages, true);
?>
        </div>
    </div>
</div>

<!-- ناوبری پایین صفحه -->
<nav class="bottom-nav">
    <a href="<?= APP_BASE_PATH ?>/index.php" class="bottom-nav-item <?= $__bottomPage === 'index.php' ? 'active' : '' ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M3 9L12 2L21 9V20A2 2 0 0119 22H5A2 2 0 013 20V9Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
        <span>خانه</span>
    </a>

    <a href="<?= APP_BASE_PATH ?>/transactions.php" class="bottom-nav-item <?= $__bottomPage === 'transactions.php' ? 'active' : '' ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        <span>تراکنش‌ها</span>
    </a>

    <button type="button" class="bottom-nav-center js-add-tx" id="addTxBtn" aria-label="ثبت تراکنش جدید">
        <span class="bottom-nav-fab">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
        </span>
    </button>

    <a href="<?= APP_BASE_PATH ?>/dashboard.php" class="bottom-nav-item <?= $__bottomPage === 'dashboard.php' ? 'active' : '' ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M18 20V10M12 20V4M6 20v-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span>گزارش</span>
    </a>

    <button type="button" class="bottom-nav-item <?= $__moreActive ? 'active' : '' ?>" id="moreTabBtn">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="5" cy="12" r="1.7" fill="currentColor"/><circle cx="12" cy="12" r="1.7" fill="currentColor"/><circle cx="19" cy="12" r="1.7" fill="currentColor"/></svg>
        <span>بیشتر</span>
    </button>
</nav>

<!-- شیت ابزارها -->
<div class="more-sheet-overlay" id="moreSheet">
    <div class="more-sheet">
        <div class="more-sheet-grab"><div class="more-sheet-handle"></div></div>
        <div class="more-sheet-head">
            <h3 class="more-sheet-title">ابزارها</h3>
            <button type="button" class="modal-close js-close-more" aria-label="بستن">&times;</button>
        </div>
        <div class="tools-grid">
            <?php if (tradesEnabled(Database::getConnection(), (int)Auth::userId())): ?>
            <a href="<?= APP_BASE_PATH ?>/trades.php" class="tool-card tool-card-wide" style="--tc1:#b8862f; --tc2:#94620d;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4v16M7 4L3.5 7.5M7 4l3.5 3.5M17 20V4M17 20l3.5-3.5M17 20l-3.5-3.5"/></svg>
                <span>معاملات خرید و فروش <?= planReadOnly('trades') ? '<span class="lock-badge" title="فقط خواندنی — ثبتِ مورد تازه با اشتراک باز می‌شود">&#128274;</span>' : '' ?></span>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4v16M7 4L3.5 7.5M7 4l3.5 3.5M17 20V4M17 20l3.5-3.5M17 20l-3.5-3.5"/></svg>
            </a>
            <?php endif; ?>
            <a href="<?= APP_BASE_PATH ?>/wallets.php" class="tool-card" style="--tc1:#16794f; --tc2:#0f766e;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7.5h15a2.5 2.5 0 012.5 2.5v7a2.5 2.5 0 01-2.5 2.5H5.5A2.5 2.5 0 013 17V7.5z"/><path d="M3 7.5l12-3v3"/><circle cx="17" cy="13.5" r="1.3" fill="currentColor"/></svg>
                <span>حساب‌ها و انتقال</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/debts.php" class="tool-card" style="--tc1:#f43f5e; --tc2:#ec4899;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>طلب و بدهی <?= planReadOnly('debts') ? '<span class="lock-badge" title="فقط خواندنی — ثبتِ مورد تازه با اشتراک باز می‌شود">&#128274;</span>' : '' ?></span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/cheques.php" class="tool-card" style="--tc1:#8b5cf6; --tc2:#6366f1;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><rect x="2" y="5" width="20" height="14" rx="2" stroke="currentColor" stroke-width="2"/><path d="M2 10h20M6 15h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span>چک‌ها <?= planReadOnly('cheques') ? '<span class="lock-badge" title="فقط خواندنی — ثبتِ مورد تازه با اشتراک باز می‌شود">&#128274;</span>' : '' ?></span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/my-assets.php" class="tool-card" style="--tc1:#f59e0b; --tc2:#eab308;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><ellipse cx="12" cy="6" rx="8" ry="3" stroke="currentColor" stroke-width="2"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6" stroke="currentColor" stroke-width="2"/></svg>
                <span>دارایی‌ها</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/due.php" class="tool-card" style="--tc1:#0ea5e9; --tc2:#0369a1;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M9 15l2 2 4-4"/></svg>
                <?php /* ⚠ برچسبِ منو با عنوانِ خودِ صفحه («سررسیدها») عمداً
                         یکی نیست و این خواسته‌ی صاحبِ محصول است. اگر عوضش
                         کردید، `includes/sidebar.php` را هم همان لحظه عوض
                         کنید: یک مقصد با دو نامِ مختلف روی موبایل و دسکتاپ،
                         خرابیِ بی‌صدایی است که فقط کاربر می‌بیند. */ ?>
                <span>یادآوری‌های من</span>
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
                <span>تراکنش دوره‌ای <?= planReadOnly('recurring') ? '<span class="lock-badge" title="فقط خواندنی — ثبتِ مورد تازه با اشتراک باز می‌شود">&#128274;</span>' : '' ?></span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/category-report.php" class="tool-card" style="--tc1:#10b981; --tc2:#14b8a6;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M12 3v9l6 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>گزارش دسته‌بندی</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/references.php" class="tool-card" style="--tc1:#8b5cf6; --tc2:#7c3aed;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1.3" fill="currentColor" stroke="none"/><circle cx="3.5" cy="12" r="1.3" fill="currentColor" stroke="none"/><circle cx="3.5" cy="18" r="1.3" fill="currentColor" stroke="none"/></svg>
                <span>فهرست‌های من</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/person.php" class="tool-card" style="--tc1:#e11d48; --tc2:#be123c;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 00-3-3.87"/></svg>
                <span>گردش حساب اشخاص</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/data.php" class="tool-card" style="--tc1:#0ea5e9; --tc2:#0891b2;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>خروجی و ورودی</span>
            </a>
            <?php if (Auth::hasAnyCap()): ?>
            <?php /* کارهای مدیریتی زیر یک در جمع شده‌اند تا این شیت برای
                     مدیر و کاربر عادی یک شکل باشد و ابزارهای روزمره میان
                     گزینه‌های مدیریتی گم نشوند.

                     ⛔ گیتِ بیرونی `hasAnyCap()` است نه `isAdmin()`: نقشِ
                     «پشتیبان» باید راهی به پنلِ تیکت داشته باشد. ولی آن
                     راه **کارتِ مستقیمِ** خودش است، نه درِ «مدیریت». */ ?>
            <?php if (Auth::can('admin')): ?>
            <button type="button" class="tool-card js-open-admin" style="--tc1:#64748b; --tc2:#475569;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15a3 3 0 100-6 3 3 0 000 6z"/><path d="M19.4 15a1.7 1.7 0 00.34 1.87l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.7 1.7 0 00-1.87-.34 1.7 1.7 0 00-1 1.55V21a2 2 0 11-4 0v-.09a1.7 1.7 0 00-1.11-1.55 1.7 1.7 0 00-1.87.34l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.7 1.7 0 00.34-1.87 1.7 1.7 0 00-1.55-1H3a2 2 0 110-4h.09A1.7 1.7 0 004.6 8.6a1.7 1.7 0 00-.34-1.87l-.06-.06a2 2 0 112.83-2.83l.06.06a1.7 1.7 0 001.87.34H9a1.7 1.7 0 001-1.55V3a2 2 0 114 0v.09a1.7 1.7 0 001 1.55 1.7 1.7 0 001.87-.34l.06-.06a2 2 0 112.83 2.83l-.06.06a1.7 1.7 0 00-.34 1.87V9a1.7 1.7 0 001.55 1H21a2 2 0 110 4h-.09a1.7 1.7 0 00-1.55 1z"/></svg>
                <span>مدیریت</span>
            </button>
            <?php else: ?>
            <?php /* ⛔ نقشِ «پشتیبان» دیگر یک درِ «مدیریت» با **یک** قلمِ
                     پشتش نمی‌بیند — مستقیم همان کارِ خودش را.
                     **گزارشِ مالکِ نصب (با اسکرین‌شات):** کاربری که نقشش
                     پشتیبان بود «مدیریت» را کنارِ «پشتیبانی و راهنما»
                     می‌دید و پشتش فقط «پشتیبانی» — دو کارتِ هم‌نام که
                     کسی نمی‌فهمید فرقشان چیست، و یک «مدیریت» که چیزی را
                     مدیریت نمی‌کند. «مدیریت» فقط مالِ مدیر است. */ ?>
            <a href="<?= APP_BASE_PATH ?>/admin/support.php" class="tool-card" style="--tc1:#0d9488; --tc2:#0f766e;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                <span>پاسخ به تیکت‌ها</span>
            </a>
            <?php endif; ?>
            <?php endif; ?>
            <?php
            /* پشتیبانی — راهنما و تیکت. نشانِ کنارش فقط وقتی می‌آید که
               پاسخِ خوانده‌نشده‌ای باشد (همان قاعده‌ی نشانِ خطاها: نشانِ
               صفر آدم را عادت می‌دهد نگاهش نکند).

               ⚠ شمارشش یک `COUNT` روی فوترِ **هر** صفحه بود، پس از
                 `Notify` استفاده می‌کنیم نه از کوئریِ تازه: پاسخِ تازه
                 همان لحظه یک اعلان می‌سازد و زنگوله‌ی بالای صفحه که از
                 قبل شمرده می‌شود خودش عدد را نشان می‌دهد. */
            ?>
            <a href="<?= APP_BASE_PATH ?>/support.php" class="tool-card" style="--tc1:#0d9488; --tc2:#0f766e;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 015.8 1c0 2-3 2.5-3 4"/><circle cx="12" cy="17.5" r=".8" fill="currentColor" stroke="none"/></svg>
                <span>پشتیبانی و راهنما</span>
            </a>
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

<?php if (Auth::can('admin')): ?>
<!-- زیرشیت مدیریت — فقط مدیر (پشتیبان کارتِ مستقیمِ خودش را دارد، بالا) -->
<div class="more-sheet-overlay" id="adminSheet">
    <div class="more-sheet">
        <div class="more-sheet-grab"><div class="more-sheet-handle"></div></div>
        <div class="more-sheet-head">
            <h3 class="more-sheet-title">مدیریت</h3>
            <button type="button" class="modal-close js-close-admin" aria-label="بستن">&times;</button>
        </div>
        <div class="tools-grid">
            <?php /* ⚠ هر کارت گیتِ **خودش** را دارد، نه یک `if` دور کلِ
                     شبکه: «پشتیبان» باید همین شیت را باز کند و فقط
                     آخرین کارت را ببیند. */ ?>
            <?php if (Auth::can('admin')): ?>
            <a href="<?= APP_BASE_PATH ?>/admin/users.php" class="tool-card" style="--tc1:#64748b; --tc2:#475569;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>کاربران</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/admin/access.php" class="tool-card" style="--tc1:#6366f1; --tc2:#4f46e5;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                <span>ورود و پیامک</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/admin/categories.php" class="tool-card" style="--tc1:#78716c; --tc2:#57534e;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h16M4 18h7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span>دسته‌بندی‌ها</span>
            </a>
            <?php /* ⚠ زیرِ «دسته‌بندی‌ها»، هم‌ترتیب با `admin/_nav.php` —
                     وگرنه کاربر روی گوشی و روی دسکتاپ دو ترتیبِ متفاوت
                     می‌بیند و دنبالِ قلمی می‌گردد که جایش عوض شده. */ ?>
            <a href="<?= APP_BASE_PATH ?>/admin/billing.php" class="tool-card" style="--tc1:#0ea5e9; --tc2:#0284c7;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                <span>اشتراک و پرداخت</span>
            </a>
            <a href="<?= APP_BASE_PATH ?>/admin/insights.php" class="tool-card" style="--tc1:#525252; --tc2:#404040;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
                <span>آمار استفاده</span>
            </a>
            <?php endif; ?>
            <?php /* ⚠ هم‌ترتیب با `admin/_nav.php` — وگرنه کاربر روی گوشی
                     و روی دسکتاپ دو ترتیبِ متفاوت می‌بیند. */ ?>
            <?php if (Auth::can('support')): ?>
            <a href="<?= APP_BASE_PATH ?>/admin/support.php" class="tool-card" style="--tc1:#0d9488; --tc2:#0f766e;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                <span>پاسخ به تیکت‌ها</span>
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/add_tx_sheet.php'; ?>

<?php
// فهرست اشخاص برای هر ورودیِ «نام طرف مقابل» که `list="peopleList"` دارد
// (فرم چک، طلب و بدهی، و معامله).
//
// اینجا در فوتر است تا یک نسخه برای همه‌ی فرم‌های همان صفحه بس باشد —
// چه فرم افزودن و چه فرم ویرایش. `peopleList()` نتیجه را در همان
// درخواست کش می‌کند، پس این خط کوئری اضافه‌ای نمی‌زند اگر صفحه از قبل
// فهرست را خوانده باشد.
if (class_exists('Auth') && Auth::isLoggedIn()) {
    echo peopleDatalist((int)Auth::userId());
    // عنوان‌های اخیر — یک بار برای همه‌ی فرم‌های همان صفحه، مثل بالا.
    echo recentTitlesDatalist((int)Auth::userId());
}
?>

</body>
</html>
