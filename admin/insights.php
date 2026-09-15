<?php
/**
 * آمار استفاده — جای «تراکنش همه کاربران».
 *
 * ⛔ اینجا هیچ محتوایی از تراکنشِ کسی دیده نمی‌شود؛ فقط شمارش و تاریخ.
 *    این مرز عمدی است و نباید شکسته شود: مدیر برای تصمیمِ محصولی به
 *    «چند تا» نیاز دارد، نه به «چه چیزی».
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_insights.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/plan.php';
require_once __DIR__ . '/../includes/cron_health.php';

Auth::initSession();
Auth::requireAdmin();

// پاک کردنِ فهرستِ خطا — تنها نوشتنِ این صفحه، پس تنها جایی که
// `Csrf::verifyOrFail()` لازم دارد (قاعده ۳ در راهنما).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_errors'])) {
    Csrf::verifyOrFail($_POST['csrf_token'] ?? '');
    AppErrors::clear();
    header('Location: ' . APP_BASE_PATH . '/admin/insights.php');
    exit;
}

$activity = userActivity();
$total    = count($activity);
$funnel   = onboardingFunnel($activity);
$features = featureAdoption($total);
$growth   = userGrowthByJalaliMonth();
$health   = healthChecks();
$cron     = CronHealth::status();
$errors   = AppErrors::recent(8);
$errWeek  = AppErrors::countSince(7);

$never  = array_values(array_filter($activity, fn($r) => $r['state'] === 'never'));
$stale  = array_values(array_filter($activity, fn($r) => $r['state'] === 'stale'));

$pageTitle = 'آمار استفاده';
include __DIR__ . '/../includes/header.php';
?>

<?php include __DIR__ . '/_nav.php'; ?>

<div class="card">
    <h2 class="card-title">از حساب ساختن تا عادت شدن</h2>
    <?php /* مهم‌ترین کارتِ صفحه، و عمداً اولین: اگر عددِ دوم خیلی کمتر از
             اولی باشد، مسئله‌ی اپ «امکانات» نیست — کاربر در همان دقیقه‌ی
             اول گیر می‌کند. */ ?>
    <?php foreach ($funnel as $f): ?>
        <div class="funnel-row">
            <div class="funnel-head">
                <span><?= h($f['label']) ?></span>
                <strong><?= toPersianDigits($f['n']) ?> نفر · <?= toPersianDigits($f['pc']) ?>٪</strong>
            </div>
            <div class="cat-breakdown-bar-track">
                <div class="cat-breakdown-bar" style="width:<?= (int)$f['pc'] ?>%; background:var(--brand);"></div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php /* ⛔ تفسیر **زیرِ** میله‌ها، نه بالای آن‌ها: اول عددها دیده
             شوند و بعد نتیجه، وگرنه حکمی می‌خوانید که هنوز پشتوانه‌اش
             را ندیده‌اید. */ ?>
    <?php $verdict = funnelVerdict($funnel); ?>
    <div class="verdict verdict-<?= h($verdict['tone']) ?>">
        <div class="verdict-head"><?= h($verdict['headline']) ?></div>
        <div class="verdict-body"><?= h($verdict['advice']) ?></div>
    </div>
</div>

<div class="card">
    <h2 class="card-title">کدام بخش‌ها استفاده می‌شوند</h2>
    <p class="hint" style="margin-bottom:14px;">
        «چند نفر» یعنی چند کاربر دست‌کم یک بار از آن بخش استفاده کرده‌اند.
        بخشی که پایینِ این فهرست است یا کسی پیدایش نمی‌کند، یا به کارش نمی‌آید.
    </p>
    <?php foreach ($features as $f): ?>
        <div class="funnel-row">
            <div class="funnel-head">
                <span><?= h($f['label']) ?></span>
                <strong>
                    <?= toPersianDigits($f['users']) ?> نفر
                    <span class="hint">(<?= toPersianDigits(formatMoney($f['rows'])) ?> ردیف)</span>
                </strong>
            </div>
            <div class="cat-breakdown-bar-track">
                <div class="cat-breakdown-bar" style="width:<?= (int)$f['share'] ?>%;
                     background:<?= $f['share'] >= 50 ? 'var(--in)' : ($f['share'] >= 20 ? 'var(--gold)' : 'var(--out)') ?>;"></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($never || $stale): ?>
<div class="card">
    <h2 class="card-title">کسانی که کمک لازم دارند</h2>

    <?php if ($never): ?>
        <h3 class="danger-title" style="color:var(--out);">
            هرگز شروع نکرده‌اند (<?= toPersianDigits(count($never)) ?>)
        </h3>
        <p class="hint" style="margin-bottom:10px;">
            حساب ساخته‌اند ولی هیچ رکوردی ثبت نکرده‌اند — نه تراکنش، نه چک،
            نه طلب و بدهی، نه هیچ چیزِ دیگر.
        </p>
        <?php foreach (array_slice($never, 0, 20) as $u): ?>
            <div class="pay-row">
                <div>
                    <strong><?= h($u['full_name']) ?></strong>
                    <span class="hint">(<?= h($u['username']) ?>)</span>
                </div>
                <span class="hint">از <?= toPersianDigits(toJalali(substr($u['created_at'], 0, 10))) ?></span>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($stale): ?>
        <?php /* ⚠ عدد از `ACTIVE_DAYS` می‌آید، نه سخت‌کد: پیش از این در سه
                 جا نوشته شده بود و عوض کردنِ آستانه دو تای دیگر را
                 بی‌صدا دروغ‌گو می‌کرد. */ ?>
        <h3 class="danger-title" style="color:var(--warn-ink); margin-top:18px;">
            بیش از <?= toPersianDigits((string)ACTIVE_DAYS) ?> روز است چیزی ثبت نکرده‌اند
            (<?= toPersianDigits(count($stale)) ?>)
        </h3>
        <?php foreach (array_slice($stale, 0, 20) as $u): ?>
            <div class="pay-row">
                <div>
                    <strong><?= h($u['full_name']) ?></strong>
                    <span class="hint">(<?= h($u['username']) ?>)</span>
                </div>
                <span class="hint">
                    <?= toPersianDigits((int)$u['records']) ?> رکورد ·
                    <?= $u['days_since'] === null ? '—' : toPersianDigits($u['days_since']) . ' روز پیش' ?>
                </span>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">همه‌ی کاربران</h2>
    <?php foreach ($activity as $u): ?>
        <div class="pay-row">
            <div>
                <strong><?= h($u['full_name']) ?></strong>
                <span class="hint">(<?= h($u['username']) ?>)</span>
                <?php if ($u['role'] === 'admin'): ?><span class="asset-tag">مدیر</span><?php endif; ?>
                <?php if (!$u['is_active']): ?><span class="status-badge status-badge-out">غیرفعال</span><?php endif; ?>
                <br>
                <?php /* ⚠ «رکورد» جدا از «تراکنش» نوشته می‌شود و فقط وقتی
                         که فرق داشته باشند. بدونِ آن، کاربری که فقط چک و
                         طلب ثبت کرده «۰ تراکنش · آخرین ثبت ۳ روز پیش»
                         می‌شد — دو عددِ درست که کنارِ هم بی‌معنا بودند. */ ?>
                <span class="hint">
                    <?= toPersianDigits((int)$u['tx']) ?> تراکنش
                    <?php if ((int)$u['records'] !== (int)$u['tx']): ?>
                        · <?= toPersianDigits((int)$u['records']) ?> رکورد
                    <?php endif; ?>
                    ·
                    <?php if ($u['days_since'] === null): ?>
                        هنوز شروع نکرده
                    <?php elseif ($u['days_since'] === 0): ?>
                        امروز
                    <?php else: ?>
                        آخرین ثبت <?= toPersianDigits($u['days_since']) ?> روز پیش
                    <?php endif; ?>
                </span>
            </div>
            <span class="status-badge <?= $u['state'] === 'active' ? 'status-badge-in'
                : ($u['state'] === 'never' ? 'status-badge-out' : 'status-badge-muted') ?>">
                <?= $u['state'] === 'active' ? 'فعال' : ($u['state'] === 'never' ? 'شروع نکرده' : 'کم‌فعال') ?>
            </span>
        </div>
    <?php endforeach; ?>
</div>

<?php if (count($growth) > 1): ?>
<div class="card">
    <h2 class="card-title">کاربر تازه در هر ماه</h2>
    <?php $max = max($growth); foreach ($growth as $key => $n): ?>
        <div class="funnel-row">
            <div class="funnel-head">
                <span><?= toPersianDigits($key) ?></span>
                <strong><?= toPersianDigits($n) ?></strong>
            </div>
            <div class="cat-breakdown-bar-track">
                <div class="cat-breakdown-bar" style="width:<?= $max > 0 ? round($n*100/$max) : 0 ?>%; background:var(--brand);"></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">سلامت نصب</h2>
    <?php foreach ($health as $h): ?>
        <div class="pay-row">
            <div>
                <strong><?= h($h['label']) ?></strong><br>
                <span class="hint"><?= h($h['note']) ?></span>
            </div>
            <span class="status-badge <?= $h['ok'] ? 'status-badge-in' : 'status-badge-out' ?>">
                <?= $h['ok'] ? 'درست' : 'رسیدگی' ?>
            </span>
        </div>
    <?php endforeach; ?>
    <p class="hint version-line">نسخه: <?= h(appVersion()) ?></p>
</div>

<div class="card">
    <h2 class="card-title">کارهای زمان‌بندی‌شده</h2>
    <?php /* ⛔ cronِ مرده هیچ صدایی ندارد. پنج کار با cron اجرا می‌شوند و
             تا امروز هیچ‌کدام ردی از خودشان نمی‌گذاشتند — یعنی بکاپی که
             سه هفته نگرفته شده دقیقاً همان‌قدر ساکت است که بکاپِ سالم.
             توضیحِ کامل کنارِ `CronHealth`. */ ?>
    <?php foreach ($cron as $c): ?>
        <div class="pay-row">
            <div>
                <strong><?= h($c['label']) ?></strong><br>
                <span class="hint">
                    <?php if ($c['state'] === 'never'): ?>
                        تا امروز اجرا نشده — <?= h($c['script']) ?> --install-cron
                    <?php else: ?>
                        آخرین اجرا: <?= h(CronHealth::ago($c['age'])) ?>
                        <?php if ($c['state'] === 'stale'): ?>
                            — انتظار می‌رفت هر <?= h(toPersianDigits((string)$c['hours'])) ?> ساعت
                        <?php endif; ?>
                    <?php endif; ?>
                </span>
            </div>
            <?php
            // ⚠ سه حالت، سه رنگ — و «هرگز» عمداً از «کهنه» جداست: اولی
            //   یعنی نصب نشده، دومی یعنی نصب شده و از کار افتاده.
            $badge = ['ok' => ['status-badge-in', 'سالم'],
                      'stale' => ['status-badge-out', 'کهنه'],
                      'never' => ['status-badge-warn', 'اجرا نشده']][$c['state']];
            ?>
            <span class="status-badge <?= $badge[0] ?>"><?= $badge[1] ?></span>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <h2 class="card-title">خطاهای برنامه</h2>
    <?php /* ⛔ ۶۱ فایل `error_log()` صدا می‌زنند و تا امروز هیچ صفحه‌ای
             آن‌ها را نشان نمی‌داد: هر ۵۰۰ بی‌صدا در لاگِ FPM می‌ماند و
             مالکِ نصب فقط با شکایتِ کاربر می‌فهمید — و بیشترِ کاربرها
             شکایت نمی‌کنند، فقط اپ را می‌بندند. */ ?>
    <?php if (!tableExists('app_errors')): ?>
        <p class="hint">migration_app_errors اجرا نشده.</p>
    <?php elseif (!$errors): ?>
        <p class="hint">هیچ خطایی ثبت نشده است.</p>
    <?php else: ?>
        <p class="hint" style="margin-top:0;">
            <?= h(toPersianDigits((string)$errWeek)) ?> خطای متمایز در هفته‌ی گذشته.
        </p>
        <?php foreach ($errors as $e): ?>
            <div class="pay-row">
                <div>
                    <strong><?= h($e['file']) ?>:<?= h(toPersianDigits((string)$e['line'])) ?></strong><br>
                    <span class="hint"><?= h($e['message']) ?></span>
                </div>
                <span class="status-badge status-badge-out">
                    ×<?= h(toPersianDigits((string)$e['hits'])) ?>
                </span>
            </div>
        <?php endforeach; ?>
        <form method="post" style="margin-top:10px;">
            <?= Csrf::field() ?>
            <button type="submit" name="clear_errors" value="1" class="btn btn-sm">پاک کردن فهرست</button>
        </form>
    <?php endif; ?>
    <p class="hint">
        ⛔ اینجا نه شناسه‌ی کاربر ثبت می‌شود نه آدرسِ صفحه — این فهرست
        درباره‌ی <strong>کد</strong> است، نه رفتارِ کاربر. عدد و ایمیلِ
        داخلِ متنِ خطا هم پیش از ذخیره پوشانده می‌شوند.
    </p>
</div>

<div class="card">
    <p class="hint" style="margin:0;">
        ⛔ در این صفحه هیچ محتوایی از تراکنش، مبلغ، یا طرفِ حسابِ کاربران
        دیده نمی‌شود — فقط شمارش و تاریخ. صفحه‌ی قبلی («تراکنش همه
        کاربران») که همه چیز را نشان می‌داد حذف شد.
    </p>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
