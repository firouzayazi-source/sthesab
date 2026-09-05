<?php
/**
 * سررسیدها — **تنها** مقصدِ «چه چیزی در راه است؟».
 *
 * ⛔ پیش از این پنج قلمِ جدا در منو بودند و همه یک سؤال را جواب می‌دادند:
 *    «آینده مالی»، «تقویم مالی»، «سررسیدها»، «یادآورها» و «تراکنش
 *    دوره‌ای». هر کدام وقتی اضافه شد درست بود، ولی حاصلِ جمعشان یک منوی
 *    ۲۲تایی شد که کاربر کاملش را نمی‌خواند — و همین‌جا بود که مجبور
 *    شدیم منوی کناری را اسکرول‌شدنی کنیم. آن رفعِ **نشانه** بود؛ این
 *    رفعِ علت است.
 *
 * ⛔ و یک باگِ واقعی را هم می‌بندد: «سررسیدها» و «یادآورها» در
 *    `sidebar.php` **داخلِ بلوکِ `Auth::isAdmin()`** افتاده بودند، پس
 *    کاربر عادی روی دسکتاپ هیچ راهی به آن‌ها نداشت — بی‌هیچ خطایی، فقط
 *    نبودند. (روی موبایل از شیتِ «بیشتر» پیدا می‌شدند، پس «روی گوشی
 *    درست است» اینجا هم مدرک نبود.)
 *
 * ⛔ زبانه‌ها **لینک**اند و هر بار فقط یکی از سرور می‌آید. با رندر کردنِ
 *    هر سه و پنهان کردنِ دو تا، هر بارگذاری کوئریِ تقویم و یادآور و
 *    سررسید را با هم می‌زد — یعنی سه برابر کار برای چیزی که کاربر
 *    یکی‌اش را می‌بیند.
 *
 * ⛔ «تراکنش دوره‌ای» عمداً زبانه نشد و صفحه‌ی خودش ماند: آن یکی **پول
 *    جابه‌جا می‌کند** و در گزارش می‌نشیند، پس با سه‌تای دیگر هم‌جنس
 *    نیست. فقط از منوی اصلی برداشته شد و لینکش پایینِ همین صفحه است.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notify.php';
require_once __DIR__ . '/includes/schedule.php';

Auth::initSession();
Auth::requireLogin();

$userId = Auth::userId();
$pdo    = Database::getConnection();
$today  = today();

/**
 * ⛔ تنها مرجعِ زبانه‌ها (مثل `Auth::SESSION_WINDOWS`). هم نوارِ بالا از
 *    همین رندر می‌شود هم اعتبارسنجیِ `?t=` — با فهرستِ دوم، زبانه‌ای که
 *    کاربر می‌بیند بی‌صدا به پیش‌فرض برمی‌گشت.
 */
const DUE_TABS = [
    'list'      => 'سررسیدها',
    'calendar'  => 'تقویم',
    'reminders' => 'یادآورهای من',
];

/** ⛔ و همین‌طور صافی‌های زبانه‌ی فهرست — یک فهرست، نه دو تا. */
const DUE_FILTERS = [
    'today'   => 'امروز',
    'week'    => 'این هفته',
    'month'   => 'این ماه',
    'overdue' => 'عقب‌افتاده',
    'all'     => 'همه',
];

$tab = getParam('t', 'list');
if (!isset(DUE_TABS[$tab])) { $tab = 'list'; }

$ready = Schedule::available();

// ---- داده‌ی زبانه‌ی فهرست (فقط وقتی همان زبانه باز است) ----
$filter = getParam('f', 'week');
if (!isset(DUE_FILTERS[$filter])) { $filter = 'week'; }

$rows   = [];
$counts = array_fill_keys(array_keys(DUE_FILTERS), 0);

if ($ready && $tab === 'list') {
    // ⚠ اول قانون‌ها با جدول‌های دامنه هم‌تراز، بعد سررسیدها ساخته
    //   می‌شوند — وگرنه چکی که همین حالا ثبت شده در فهرست نبود.
    syncScheduleRules($userId);
    Schedule::materializeAll($userId, $today);

    $sql = "
        SELECT o.id, o.due_date, o.status, o.note,
               r.title, r.amount, r.source_type, r.source_id, r.recurrence_type
          FROM reminder_occurrences o
          JOIN reminders r ON r.id = o.reminder_id AND r.user_id = o.user_id
         WHERE o.user_id = :u AND o.status IN ('pending','overdue')
    ";
    $params = ['u' => $userId];

    if ($filter === 'today') {
        $sql .= ' AND o.due_date = :d';       $params['d'] = $today;
    } elseif ($filter === 'week') {
        $sql .= ' AND o.due_date <= :d';      $params['d'] = date('Y-m-d', strtotime($today . ' +7 day'));
    } elseif ($filter === 'month') {
        $sql .= ' AND o.due_date <= :d';      $params['d'] = date('Y-m-d', strtotime($today . ' +30 day'));
    } elseif ($filter === 'overdue') {
        $sql .= ' AND o.due_date < :d';       $params['d'] = $today;
    }
    $sql .= ' ORDER BY o.due_date ASC, o.id ASC LIMIT 200';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    // شمارشِ هر صافی برای نشانِ کنارِ دکمه‌ها.
    $cnt = $pdo->prepare("
        SELECT SUM(due_date = :t) AS c_today,
               SUM(due_date <= :w) AS c_week,
               SUM(due_date <= :m) AS c_month,
               SUM(due_date <  :t2) AS c_over,
               COUNT(*) AS c_all
          FROM reminder_occurrences
         WHERE user_id = :u AND status IN ('pending','overdue')
    ");
    $cnt->execute(['t' => $today, 't2' => $today, 'u' => $userId,
                   'w' => date('Y-m-d', strtotime($today . ' +7 day')),
                   'm' => date('Y-m-d', strtotime($today . ' +30 day'))]);
    $c = $cnt->fetch() ?: [];
    $counts = ['today' => (int)($c['c_today'] ?? 0), 'week' => (int)($c['c_week'] ?? 0),
               'month' => (int)($c['c_month'] ?? 0), 'overdue' => (int)($c['c_over'] ?? 0),
               'all' => (int)($c['c_all'] ?? 0)];
}

/* نشانِ کنارِ زبانه‌ی «سررسیدها» — فقط عقب‌افتاده‌ها، چون تنها چیزی
   هستند که همین حالا هزینه دارند. عددِ کلِ سررسیدها همیشه بزرگ است و
   نشانِ دائمی خوانده نمی‌شود. */
$lateCount = 0;
if ($ready) {
    try {
        $lc = $pdo->prepare("SELECT COUNT(*) FROM reminder_occurrences
                              WHERE user_id = :u AND status IN ('pending','overdue')
                                AND due_date < :d");
        $lc->execute(['u' => $userId, 'd' => $today]);
        $lateCount = (int)$lc->fetchColumn();
    } catch (PDOException $e) { /* جدول هنوز نیامده */ }
}

$pageTitle = 'سررسیدها';
include __DIR__ . '/includes/header.php';
?>

<a href="<?= APP_BASE_PATH ?>/index.php" class="page-back js-page-back">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M11 6l-6 6 6 6"/></svg>
    <span>بازگشت</span>
</a>

<nav class="page-tabs">
    <a href="?t=list" class="page-tab <?= $tab === 'list' ? 'is-active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
        <span><?= h(DUE_TABS['list']) ?></span>
        <?php if ($lateCount > 0): ?>
            <span class="page-tab-n"><?= toPersianDigits((string)$lateCount) ?></span>
        <?php endif; ?>
    </a>
    <a href="?t=calendar" class="page-tab <?= $tab === 'calendar' ? 'is-active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
        <span><?= h(DUE_TABS['calendar']) ?></span>
    </a>
    <a href="?t=reminders" class="page-tab <?= $tab === 'reminders' ? 'is-active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0"/></svg>
        <span><?= h(DUE_TABS['reminders']) ?></span>
    </a>
</nav>

<?php
// ⛔ فقط زبانه‌ی باز از سرور می‌آید — نه هر سه و پنهان کردنِ دو تا.
include __DIR__ . '/includes/due_tab_' . $tab . '.php';
?>

<?php /* «تراکنش دوره‌ای» زبانه نشد چون پول جابه‌جا می‌کند، ولی از منوی
         اصلی هم برداشته شد — پس راهِ رسیدن به آن باید همین‌جا باشد،
         وگرنه فقط از شیتِ «بیشتر» پیدا می‌شد و روی دسکتاپ گم می‌شد. */ ?>
<a href="<?= APP_BASE_PATH ?>/recurring.php" class="due-more-link">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 2l4 4-4 4M3 11V9a4 4 0 014-4h14M7 22l-4-4 4-4M21 13v2a4 4 0 01-4 4H3"/></svg>
    <span>تراکنش‌های دوره‌ای — آن‌هایی که خودشان پول جابه‌جا می‌کنند</span>
    <svg class="due-more-go" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
</a>

<?php include __DIR__ . '/includes/footer.php'; ?>
