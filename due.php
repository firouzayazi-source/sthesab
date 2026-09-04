<?php
/**
 * سررسیدها — یک فهرست برای هر چهار بخش.
 *
 * ⛔ این صفحه **اضافه** است، نه جایگزین: صفحه‌ی چک، طلب و بدهی، تراکنش
 *    دوره‌ای و یادآورها همه سرِ جایشان می‌مانند و دکمه‌هایشان دست‌نخورده
 *    است. اینجا فقط همه‌ی سررسیدها کنارِ هم دیده می‌شوند و سه اقدامِ
 *    مشترک دارند.
 *
 * ⛔ و هیچ پولی اینجا ثبت نمی‌شود: «تسویه» به صفحه‌ی همان بخش می‌برد که
 *    منطقِ پولش آنجاست. آنچه اینجا عوض می‌شود فقط وضعیتِ خودِ سررسید
 *    است. با ثبتِ پول از اینجا، دو جا یک پرداخت را می‌نوشتند.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/schedule.php';

Auth::initSession();
Auth::requireLogin();

$userId = Auth::userId();
$ready  = Schedule::available();

$filter = getParam('f', 'week');
$allowed = ['today' => 'امروز', 'week' => 'این هفته', 'month' => 'این ماه',
            'overdue' => 'عقب‌افتاده', 'all' => 'همه'];
if (!isset($allowed[$filter])) { $filter = 'week'; }

$today = today();
$rows  = [];
$counts = array_fill_keys(array_keys($allowed), 0);

if ($ready) {
    // ⚠ اول قانون‌ها با جدول‌های دامنه هم‌تراز، بعد سررسیدها ساخته
    //   می‌شوند — وگرنه چکی که همین حالا ثبت شده در فهرست نبود.
    syncScheduleRules($userId);
    Schedule::materializeAll($userId, $today);

    $pdo = Database::getConnection();
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

/** رنگ و آیکون و مقصدِ هر نوع — تنها جای این نگاشت. */
function dueMeta(string $type): array
{
    return match ($type) {
        'cheque'       => ['چک', 'cheques.php', 'due-cheque'],
        'debt'         => ['طلب و بدهی', 'debts.php', 'due-debt'],
        'recurring_tx' => ['تراکنش دوره‌ای', 'recurring.php', 'due-recurring'],
        default        => ['یادآور', 'reminders.php', 'due-custom'],
    };
}

$pageTitle = 'سررسیدها';
include __DIR__ . '/includes/header.php';
?>

<a href="<?= APP_BASE_PATH ?>/index.php" class="page-back js-page-back">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M11 6l-6 6 6 6"/></svg>
    <span>بازگشت</span>
</a>

<?php if (!$ready): ?>
    <div class="card">
        <p class="hint">
            جدولِ سررسیدها هنوز ساخته نشده است. ابتدا migration ها را اعمال کنید
            (<code>deploy/migrate.sh --apply</code>).
        </p>
    </div>
<?php else: ?>

<nav class="due-filters">
    <?php foreach ($allowed as $key => $label): ?>
        <a href="?f=<?= h($key) ?>" class="due-filter <?= $filter === $key ? 'is-active' : '' ?>">
            <?= h($label) ?>
            <?php if ($counts[$key] > 0): ?>
                <span class="due-count"><?= toPersianDigits((string)$counts[$key]) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<div class="card">
    <?php if (!$rows): ?>
        <p class="hint">در این بازه سررسیدی نیست.</p>
    <?php else: ?>
        <ul class="due-list">
            <?php foreach ($rows as $o): ?>
                <?php
                    [$typeLabel, $url, $cls] = dueMeta((string)$o['source_type']);
                    $late = $o['due_date'] < $today;
                    $days = (int)floor((strtotime($o['due_date']) - strtotime($today)) / 86400);
                ?>
                <li class="due-row">
                    <span class="due-dot <?= h($cls) ?>" title="<?= h($typeLabel) ?>"></span>

                    <a class="due-main" href="<?= APP_BASE_PATH ?>/<?= h($url) ?>">
                        <div class="due-title"><?= h($o['title']) ?></div>
                        <div class="due-meta">
                            <span class="due-type"><?= h($typeLabel) ?></span>
                            <span class="<?= $late ? 'is-late' : ($days === 0 ? 'is-today' : '') ?>">
                                <?= toPersianDigits(toJalali($o['due_date'])) ?>
                                ·
                                <?php if ($late): ?>
                                    <?= toPersianDigits((string)abs($days)) ?> روز گذشته
                                <?php elseif ($days === 0): ?>
                                    امروز
                                <?php else: ?>
                                    <?= toPersianDigits((string)$days) ?> روز مانده
                                <?php endif; ?>
                            </span>
                            <?php if (!empty($o['amount'])): ?>
                                <span class="due-amount ltr-num"><?= formatMoney((int)$o['amount']) ?></span>
                            <?php endif; ?>
                        </div>
                    </a>

                    <?php /* سه اقدامِ ثابت برای همه‌ی انواع. «تسویه» فقط
                             وضعیت را می‌بندد و پول را همان بخش ثبت
                             می‌کند — لینکِ بالا به آنجا می‌رود. */ ?>
                    <div class="due-actions">
                        <form method="POST" action="<?= APP_BASE_PATH ?>/api/due_action.php" class="js-due-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                            <input type="hidden" name="action" value="done">
                            <button type="submit" class="due-btn due-btn-ok" title="تسویه شد">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                            </button>
                        </form>
                        <form method="POST" action="<?= APP_BASE_PATH ?>/api/due_action.php" class="js-due-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                            <input type="hidden" name="action" value="snooze">
                            <input type="hidden" name="days" value="7">
                            <button type="submit" class="due-btn" title="تعویق ۷ روز">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            </button>
                        </form>
                        <form method="POST" action="<?= APP_BASE_PATH ?>/api/due_action.php" class="js-due-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                            <input type="hidden" name="action" value="skip">
                            <button type="submit" class="due-btn due-btn-mute" title="رد کردن">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
                            </button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
