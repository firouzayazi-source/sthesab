<?php
/**
 * بکاپِ داده — صفحه‌ی خودش، با راهِ برگشت.
 *
 * ⛔ دلیلِ وجودِ این صفحه یک بن‌بستِ واقعی است: پیش از این، دکمه‌ی
 *    خروجی یک فرمِ POST بود که مستقیم به `api/export_data.php` می‌رفت.
 *    وقتی همه چیز درست پیش می‌رفت مرورگر فایل را می‌گرفت و صفحه
 *    عوض نمی‌شد؛ ولی هر مسیرِ شکست (توکنِ کهنه، خطای سرور) کاربر را در
 *    یک صفحه‌ی سفید با یک خطِ JSON رها می‌کرد — بی‌منو، بی‌دکمه، و در
 *    اپِ نصب‌شده حتی بی‌دکمه‌ی بازگشتِ مرورگر.
 *
 * ⛔ حالا دو لایه جلویش را می‌گیرد و هر دو لازم‌اند:
 *    ۱. این صفحه دکمه‌ی «بازگشت» دارد که **همیشه** سرِ جایش است —
 *       چه کاربر بکاپ بگیرد، چه پشیمان شود.
 *    ۲. خودِ اندپوینت هم در هر شکستی به همین‌جا برمی‌گردد با پیامِ
 *       روشن، نه به یک صفحه‌ی JSON.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_data.php';
require_once __DIR__ . '/includes/user_import.php';

Auth::initSession();
Auth::requireLogin();

$fileName = backupFileName();

// ⛔ «بازگرداندن» روی همین صفحه است، نه یک صفحه‌ی تازه. کسی که فایل را
//    اینجا گرفته، روزِ حادثه هم به همین‌جا برمی‌گردد — همان قاعده‌ی
//    `references.php`: فهرستِ تازه ته یک صفحه‌ی دیگر قایم نمی‌شود.
$currentRows = importCounts(Auth::userId());
$currentTotal = array_sum($currentRows);

// ⛔ یادآوریِ ماهانه بدونِ این خط یک ادعای نادیدنی است: کاربر اعلان
//    می‌گیرد، می‌آید اینجا، و هیچ‌جا نمی‌بیند آخرین بار کِی بوده — پس
//    نمی‌تواند بفهمد اعلان درست گفته یا نه. همان قاعده‌ی «خطِ وضعیتِ
//    ذخیره‌شده» در کارتِ اتصالِ پیامک.
$lastBackup = lastBackupAt(Auth::userId());

$pageTitle = 'بکاپ داده';
include __DIR__ . '/includes/header.php';
?>

<?php /* همان الگوی `references.php`: اگر از داخلِ اپ آمده `history.back()`
         و وگرنه خانه. صفحه‌ای که فقط از پروفایل باز می‌شود بدون این،
         راهِ بسته شدن ندارد. */ ?>
<a href="<?= APP_BASE_PATH ?>/profile.php" class="page-back js-page-back">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
    بازگشت
</a>

<div class="card">
    <h2 class="card-title">یک فایل، همه‌ی داده‌ی شما</h2>

    <p class="privacy-p" style="margin-bottom:6px;">
        همه‌ی تراکنش‌ها، حساب‌ها، چک‌ها، طلب و بدهی، بودجه، پس‌انداز،
        دارایی، معاملات و دسته‌بندی‌های شخصی‌تان در یک فایل ذخیره می‌شود.
    </p>
    <p class="hint" style="margin-bottom:16px;">
        رمز عبور و اطلاعاتِ ورودِ شما در این فایل <strong>نیست</strong>.
    </p>

    <p class="hint" style="margin-bottom:14px;">
        <?php if ($lastBackup !== null): ?>
            آخرین پشتیبان: <?= toJalali(substr($lastBackup, 0, 10)) ?>
        <?php else: ?>
            هنوز پشتیبانی نگرفته‌اید.
        <?php endif; ?>
    </p>

    <div class="backup-file">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
        <span class="backup-file-name"><?= h($fileName) ?></span>
    </div>

    <form method="post" action="<?= APP_BASE_PATH ?>/api/export_data.php" style="margin-top:16px;">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn-primary btn-block btn-large">دریافت فایل بکاپ</button>
    </form>

    <a href="<?= APP_BASE_PATH ?>/profile.php" class="btn btn-secondary btn-block js-page-back" style="margin-top:10px;">
        بازگشت بدون گرفتن بکاپ
    </a>

    <p class="hint" style="margin-top:16px;">
        فایل با تاریخِ همان روز ساخته می‌شود، پس بکاپ‌های مختلف روی هم
        نمی‌افتند. جایی نگهش دارید که خودتان به آن دسترسی داشته باشید —
        این فایل جای دیگری ذخیره نمی‌شود.
    </p>
</div>

<?php /* ⛔ نیمه‌ی دومِ همین صفحه: بکاپی که یک بار برگردانده نشده باشد
         بکاپ نیست، یک فرضیه است. تا امروز این فایل هیچ خواننده‌ای
         نداشت. */ ?>
<div class="card">
    <h2 class="card-title">بازگرداندن از فایل</h2>

    <p class="privacy-p" style="margin-bottom:6px;">
        اگر فایلِ بکاپ دارید، می‌توانید دفترتان را از روی آن برگردانید —
        روی همین دستگاه یا روی هر نصبِ دیگری از این برنامه.
    </p>

    <div class="restore-warn">
        <strong>داده‌ی فعلیِ شما جایگزین می‌شود.</strong>
        هر چه الان در دفترتان هست پاک می‌شود و محتوای فایل می‌نشیند.
        این کار <strong>برگشت ندارد</strong> — اگر مطمئن نیستید، اول از
        همین بالا یک فایل بکاپ از وضعِ فعلی بگیرید.
    </div>

    <p class="hint" style="margin-bottom:6px;">
        <?php if ($currentTotal > 0): ?>
            الان در دفتر شما <span class="ltr-num"><?= toPersianDigits((string)$currentTotal) ?></span>
            ردیف هست:
        <?php else: ?>
            دفتر شما الان خالی است، پس چیزی از دست نمی‌رود.
        <?php endif; ?>
    </p>

    <?php if ($currentRows): ?>
        <ul class="restore-list">
            <?php foreach ($currentRows as $t => $n): ?>
                <li>
                    <span><?= h(importTableLabel($t)) ?></span>
                    <span class="ltr-num"><?= toPersianDigits((string)$n) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="<?= APP_BASE_PATH ?>/api/import_data.php"
          enctype="multipart/form-data" style="margin-top:16px;">
        <?= Csrf::field() ?>

        <div class="form-group">
            <label for="restoreFile">فایل بکاپ</label>
            <input type="file" id="restoreFile" name="backup"
                   accept=".<?= h(BACKUP_EXT) ?>,application/json" required>
        </div>

        <?php /* ⚠ با جاوااسکریپت پر می‌شود: خودِ فایل شمارشش را دارد،
                 پس پیش‌نمایش هیچ رفت‌وبرگشتی به سرور لازم ندارد و هیچ
                 چیزی هم جایی ذخیره نمی‌شود. بدونِ جاوااسکریپت فرم
                 دست‌نخورده کار می‌کند — فقط بی‌پیش‌نمایش. */ ?>
        <div class="restore-preview" id="restorePreview" hidden></div>

        <div class="form-group">
            <label for="restoreConfirm">
                برای تأیید، عبارتِ «<?= h(IMPORT_CONFIRM_PHRASE) ?>» را تایپ کنید
            </label>
            <input type="text" id="restoreConfirm" name="confirm"
                   autocomplete="off" required>
        </div>

        <button type="submit" class="btn btn-danger btn-block btn-large">
            بازگرداندن داده از فایل
        </button>
    </form>

    <p class="hint" style="margin-top:16px;">
        رمز عبور، شماره موبایل و اشتراکِ شما از فایل خوانده نمی‌شوند و
        دست‌نخورده می‌مانند. فایلِ پیوست‌ها هم در بکاپ نیست، پس پیوستی که
        روی این سرور نباشد برنمی‌گردد.
    </p>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
