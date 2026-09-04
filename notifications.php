<?php
/**
 * مرکز اعلان — همه‌ی خبرهایی که برنامه برای این کاربر ساخته.
 *
 * ⛔ باز کردنِ همین صفحه یعنی «دیدم»، پس همه‌جا خوانده علامت می‌خورند.
 *    نگه داشتنِ نشانِ نخوانده بعد از اینکه کاربر فهرست را دیده، فقط
 *    باعث می‌شود آن نشان بی‌معنا شود و دیگر کسی جدی‌اش نگیرد.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notify.php';

Auth::initSession();
Auth::requireLogin();

$userId = Auth::userId();

// ⚠ اول تولید، بعد خواندن — وگرنه اعلانی که همین حالا ساخته می‌شود
//   تا بارگذاری بعدی دیده نمی‌شد.
Notify::generateFor($userId, true);
$items = Notify::recent($userId);
Notify::markRead($userId);

$pageTitle = 'اعلان‌ها';
include __DIR__ . '/includes/header.php';
?>

<a href="<?= APP_BASE_PATH ?>/index.php" class="page-back js-page-back">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M11 6l-6 6 6 6"/></svg>
    <span>بازگشت</span>
</a>

<?php if (!Notify::available()): ?>
    <div class="card">
        <p class="hint">
            جدولِ اعلان‌ها هنوز ساخته نشده است. ابتدا migration ها را اعمال کنید
            (<code>deploy/migrate.sh --apply</code>).
        </p>
    </div>
<?php elseif (!$items): ?>
    <div class="card">
        <p class="hint">فعلاً خبری نیست. سررسیدهای نزدیک و یادآورهای شما اینجا می‌آیند.</p>
        <a href="<?= APP_BASE_PATH ?>/reminders.php" class="btn btn-secondary btn-sm" style="margin-top:10px;">
            ثبت یادآور تازه
        </a>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-head-row">
            <h2 class="card-title">اعلان‌ها</h2>
            <form method="POST" action="<?= APP_BASE_PATH ?>/api/notifications.php" class="js-notif-clear">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="clear">
                <button type="submit" class="btn btn-secondary btn-sm">پاک کردن همه</button>
            </form>
        </div>

        <ul class="notif-list">
            <?php foreach ($items as $n): ?>
                <?php $fresh = $n['read_at'] === null; ?>
                <li class="notif-row <?= $fresh ? 'is-fresh' : '' ?>">
                    <span class="notif-dot notif-<?= h($n['kind']) ?>"></span>
                    <div class="notif-main">
                        <div class="notif-title"><?= h($n['title']) ?></div>
                        <?php if (!empty($n['body'])): ?>
                            <div class="notif-body"><?= h($n['body']) ?></div>
                        <?php endif; ?>
                        <div class="notif-time"><?= toPersianDigits(jalaliWithWeekday(substr($n['created_at'], 0, 10))) ?></div>
                    </div>
                    <?php if (!empty($n['link'])): ?>
                        <a class="notif-go" href="<?= APP_BASE_PATH ?>/<?= h($n['link']) ?>" aria-label="رفتن">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
