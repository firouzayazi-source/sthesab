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
require_once __DIR__ . '/../includes/paged_list.php';

Auth::initSession();
Auth::requireAdmin();

$activity = userActivity();
$total    = count($activity);
$funnel   = onboardingFunnel($activity);
$features = featureAdoption($total);
$growth   = userGrowthByJalaliMonth();
$health   = healthChecks();
$cron     = CronHealth::status();
$audit    = Audit::recent(12);
$errWeek  = AppErrors::countSince(7);

$never  = array_values(array_filter($activity, fn($r) => $r['state'] === 'never'));
$stale  = array_values(array_filter($activity, fn($r) => $r['state'] === 'stale'));

// ⛔ عرضِ خواندنِ ۷۲۰ پیکسل برای صفحه‌ی متنی درست است، ولی فهرستِ توری
//    را روی دسکتاپ به **دو** ستون می‌بندد در حالی که ۴۰۰ پیکسل کنارش
//    خالی است (اندازه‌گیری شد: ۲ ستون در برابر ۳). همان استدلالِ
//    `admin/users.php` در قاعده ۳۱. پیش‌فرض همچنان باریک است و فقط
//    صفحه‌های مدیر این را می‌گذارند.
$pageWide  = true;
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
                <?php /* ⚠ پله‌ی میانی `--warn-ink` است نه `--gold`: پالتِ
                         دومِ `style.css` مقدارِ `--gold` را به **آبی**
                         (`#2563eb`) بازتعریف می‌کند، پس روی آن پالت
                         چراغِ سه‌مرحله‌ای پله‌ی وسطش را از دست می‌داد و
                         «۲۰ تا ۵۰ درصد» رنگی می‌گرفت که هیچ هشداری
                         نمی‌رساند — همان چیزی که `funnelVerdict()` هم
                         یک بار سرش خورد و در راهنما نوشته شده. */ ?>
                     background:<?= $f['share'] >= 50 ? 'var(--in)' : ($f['share'] >= 20 ? 'var(--warn-ink)' : 'var(--out)') ?>;"></div>
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
        <?php /* ⛔ پیش از این `array_slice($never, 0, 20)` بود: بیست‌تای
                 اول را نشان می‌داد و **هیچ‌جا نمی‌گفت بقیه کجا رفتند**.
                 همان «سقفِ بی‌صدا» که راهنما ممنوعش کرده — و بدترین
                 حالتش این است که مالکِ نصب فکر کند فهرست کامل است. */ ?>
        <?php $pNever = pagedSlice($never, 'never'); ?>
        <div class="grid-list">
            <?php foreach ($pNever['rows'] as $u): ?>
                <div class="grid-card">
                    <div class="grid-card-head">
                        <span class="grid-card-name"><?= h($u['full_name']) ?></span>
                        <span class="hint">(<?= h($u['username']) ?>)</span>
                    </div>
                    <span class="hint grid-card-sub">از <?= toPersianDigits(toJalali(substr($u['created_at'], 0, 10))) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php pagedNav($pNever); ?>
    <?php endif; ?>

    <?php if ($stale): ?>
        <?php /* ⚠ عدد از `ACTIVE_DAYS` می‌آید، نه سخت‌کد: پیش از این در سه
                 جا نوشته شده بود و عوض کردنِ آستانه دو تای دیگر را
                 بی‌صدا دروغ‌گو می‌کرد. */ ?>
        <h3 class="danger-title" style="color:var(--warn-ink); margin-top:18px;">
            بیش از <?= toPersianDigits((string)ACTIVE_DAYS) ?> روز است چیزی ثبت نکرده‌اند
            (<?= toPersianDigits(count($stale)) ?>)
        </h3>
        <?php $pStale = pagedSlice($stale, 'stale'); ?>
        <div class="grid-list">
            <?php foreach ($pStale['rows'] as $u): ?>
                <div class="grid-card">
                    <div class="grid-card-head">
                        <span class="grid-card-name"><?= h($u['full_name']) ?></span>
                        <span class="hint">(<?= h($u['username']) ?>)</span>
                    </div>
                    <span class="hint grid-card-sub">
                        <?= toPersianDigits((int)$u['records']) ?> رکورد ·
                        <?= $u['days_since'] === null ? '—' : toPersianDigits($u['days_since']) . ' روز پیش' ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php pagedNav($pStale); ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <?php /* ⛔ توری و صفحه‌بندی‌شده — منطقش در `paged_list.php` است، نه
             اینجا. با فهرستِ یک‌ستونه‌ی بی‌انتها، کارت‌های تحلیلیِ
             **پایینِ** همین صفحه (رشد ماهانه، سلامتِ cron، خطاها) با
             زیاد شدنِ کاربرها عملاً نامرئی می‌شدند. */ ?>
    <?php $pAll = pagedSlice($activity, 'users'); ?>
    <h2 class="card-title">همه‌ی کاربران</h2>
    <div class="grid-list">
    <?php foreach ($pAll['rows'] as $u): ?>
        <div class="grid-card">
                <div class="grid-card-head">
                    <span class="grid-card-name"><?= h($u['full_name']) ?></span>
                    <span class="hint">(<?= h($u['username']) ?>)</span>
                    <?php if ($u['role'] === 'admin'): ?><span class="asset-tag">مدیر</span><?php endif; ?>
                    <?php if (!$u['is_active']): ?><span class="status-badge status-badge-out">غیرفعال</span><?php endif; ?>
                    <span class="status-badge <?= $u['state'] === 'active' ? 'status-badge-in'
                        : ($u['state'] === 'never' ? 'status-badge-out' : 'status-badge-muted') ?>">
                        <?= $u['state'] === 'active' ? 'فعال' : ($u['state'] === 'never' ? 'شروع نکرده' : 'کم‌فعال') ?>
                    </span>
                </div>
                <?php /* ⛔ دو عددِ این خط دو سؤالِ متفاوت‌اند و هیچ‌کدام
                         نباید با دیگری اشتباه شود — مالکِ نصب **دو بار**
                         همین را گزارش کرد:

                         ۱. نسخه‌ی اول «۱ تراکنش · ۷ رکورد» می‌نوشت و او
                            آن را «۷ تراکنش» خواند، چون `records` خودش
                            تراکنش‌ها را هم داشت. دو عددی که کنارِ هم
                            می‌نشینند نباید هم‌پوشانی داشته باشند.
                         ۲. نسخه‌ی دوم «۱ تراکنش · ۶ رکوردِ دیگر» شد و
                            او عددِ اول را **امروز** خواند، در حالی که
                            جمعِ کلِ تاریخچه بود.

                         پس حالا هر دو عدد صریح‌اند: «کل» و «امروز». و
                         «رکوردِ دیگر» برداشته شد — چیزی که مالکِ نصب
                         رویش تصمیم می‌گیرد «چند تراکنش» است، نه «چند
                         ردیف در چند جدول». خودِ `records` سرِ جایش است
                         و `state` و قیفِ شروع از همان می‌آیند. */ ?>
                <span class="hint grid-card-sub">
                    <?= toPersianDigits((int)$u['tx']) ?> کل تراکنش
                    · <?= toPersianDigits((int)$u['today_tx']) ?> امروز
                    ·
                    <?php /* ⚠ «امروز»ِ خالی حذف شد: حالا کنارِ «۰ امروز»
                             می‌نشست و دو چیزِ متفاوت با یک کلمه گفته
                             می‌شدند. «آخرین ثبت امروز» می‌تواند کنارِ
                             «۰ امروز» درست باشد — کسی که امروز چک ثبت
                             کرده ولی تراکنش نه. */ ?>
                    <?php if ($u['days_since'] === null): ?>
                        هنوز شروع نکرده
                    <?php elseif ($u['days_since'] === 0): ?>
                        آخرین ثبت امروز
                    <?php else: ?>
                        آخرین ثبت <?= toPersianDigits($u['days_since']) ?> روز پیش
                    <?php endif; ?>
                </span>
        </div>
    <?php endforeach; ?>
    </div>
    <?php pagedNav($pAll); ?>
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
    <?php /* ⛔ فهرست و دکمه‌هایش به `admin/errors.php` رفتند و اینجا فقط
             یک خلاصه ماند. دلیلش خواسته‌ی صریحِ مالکِ نصب بود («شلوغ
             نباشه»): این صفحه نُه کارت دارد و یک فهرستِ هشت‌ردیفیِ خطا
             در تهِ آن، هم خودش گم می‌شد هم کارت‌های تحلیلیِ اطرافش را
             گم می‌کرد. اطلاع‌رسانیِ واقعی حالا نشانِ نوارِ مدیر است. */ ?>
    <?php if (!tableExists('app_errors')): ?>
        <p class="hint">migration_app_errors اجرا نشده.</p>
    <?php else: ?>
        <p class="hint" style="margin-top:0;">
            <?= h(toPersianDigits((string)$errWeek)) ?> خطای متمایز در هفته‌ی گذشته.
            <?php /* ⚠ عددِ «رسیدگی‌نشده» اینجا **تکرار نمی‌شود**: همان عدد
                     چند سانتی‌متر بالاتر روی نشانِ نوارِ مدیر هست، و دو بار
                     نوشتنش دقیقاً همان شلوغی‌ای است که این جابه‌جایی برای
                     رفعش انجام شد. (ضمناً یک کوئری هم کمتر.) */ ?>
        </p>
        <a href="<?= APP_BASE_PATH ?>/admin/errors.php" class="btn btn-sm">
            رفتن به خطاها
        </a>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="card-title">رویدادهای امنیتی</h2>
    <?php /* ⛔ فقط رویدادهای امنیتی/مدیریتی (`Audit::ACTIONS`)، نه کارِ روزمره‌ی
             کاربر با دفترش. و کارت‌های «آمار استفاده»ی بالا از این جدول
             نمی‌خوانند — «آخرین فعالیت» همچنان از رکوردهای خودِ کاربر
             می‌آید، نه از ورودها. توضیحش بالای `includes/audit.php`. */ ?>
    <?php if (!Audit::available()): ?>
        <p class="hint">migration_audit_log اجرا نشده.</p>
    <?php elseif (!$audit): ?>
        <p class="hint">هنوز رویدادی ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($audit as $a): ?>
            <div class="pay-row">
                <div>
                    <strong><?= h($a['action']) ?></strong>
                    <?php if ($a['actor_name'] !== null): ?>
                        <span class="hint">— <?= h($a['actor_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($a['target_name'] !== null && $a['target_user_id'] !== $a['actor_id']): ?>
                        <span class="hint">← <?= h($a['target_name']) ?></span>
                    <?php endif; ?>
                    <br>
                    <span class="hint ltr-num"><?= h(toJalali(substr((string)$a['created_at'], 0, 10))) ?>
                        <?= h(toPersianDigits(substr((string)$a['created_at'], 11, 5))) ?>
                        <?= $a['ip'] !== null ? '· ' . h($a['ip']) : '' ?></span>
                </div>
                <?php if ($a['detail'] !== null): ?>
                    <span class="hint ltr-num" style="max-width:45%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($a['detail']) ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <p class="hint">
            <?= h(toPersianDigits((string)Audit::KEEP_DAYS)) ?> روز نگه داشته می‌شود؛ ورودِ ناموفقِ
            ۲۴ ساعت گذشته: <?= h(toPersianDigits((string)Audit::countSince('auth.login_failed', 24))) ?>
        </p>
    <?php endif; ?>
</div>

<div class="card">
    <p class="hint" style="margin:0;">
        ⛔ در این صفحه هیچ محتوایی از تراکنش، مبلغ، یا طرفِ حسابِ کاربران
        دیده نمی‌شود — فقط شمارش و تاریخ. صفحه‌ی قبلی («تراکنش همه
        کاربران») که همه چیز را نشان می‌داد حذف شد.
    </p>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
