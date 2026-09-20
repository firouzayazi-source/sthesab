<?php
/**
 * کالاهای فروشگاه — همان چیزی که از قلمِ «دارایی من در فروشگاه» باز
 * می‌شود.
 *
 * **خواسته‌ی مالکِ نصب:** «در بخش دارایی‌ها پایینِ اون توگلا، دقیقاً
 * جایِ «دارایی من در فروشگاه»، این دکمه باشه. واردش بشیم، کل محصولات
 * فروشگاه با سهامدارانش رو من ببینم، کلاً. و برای سایر بچه‌ها هم این
 * دکمه باشه ولی فقط دارایی خودشون رو ببینن. یعنی «دارایی من در
 * فروشگاه» خودش یک کارت کوچیک بشه که از طریق اون بتونم توش تمام
 * محصولاتشون رو مدیریت بکنم و دسته‌بندی — مثلاً لوازم جانبی، گوشی — و
 * سود و زیانشون رو ببینم.»
 *
 * ⛔ **چرا صفحه‌ی جدا و نه همان کارتِ `my-assets.php`:** آن کارت فهرستِ
 *    کاملِ دستگاه‌ها را **درجا** رندر می‌کرد. با یک سهامدارِ ده‌تایی
 *    قابل تحمل بود؛ با «کل محصولات فروشگاه با سهامدارانش» صفحه‌ی دارایی
 *    — که وظیفه‌اش نشان دادنِ **ترکیبِ** دارایی است — به یک فهرستِ
 *    صدها ردیفی تبدیل می‌شد و نمودار و توگل‌ها زیرش گم می‌شدند. همان
 *    الگوی «چهار خزشِ بی‌صدا»: هیچ ردیفی به‌تنهایی بد نیست، حاصلِ
 *    جمعشان است.
 *
 * ⛔ **دامنه اینجا تصمیم گرفته نمی‌شود.** `StoreShare::assetOwners()`
 *    تنها جای آن است — همان تابعی که دکمه‌ی ورود هم به آن بند است. با
 *    دو نسخه، دیر یا زود دکمه برای کسی رندر می‌شد که صفحه‌اش خالی است،
 *    یا بدتر: یک کاربر دفترِ سهامدارِ دیگری را می‌دید.
 *
 * ⛔ **و هیچ محاسبه‌ای اینجا نیست.** درصدِ سهم، سودِ هر قلم و مانده‌ی هر
 *    سهامدار در حسابداریِ فروشگاه حساب و **سند** می‌شوند. جمع‌های بالای
 *    هر کارت همان‌هایی‌اند که آن سیستم فرستاده، روی **همه‌ی** کالاها؛
 *    و جمعِ زیرِ هر دسته صریح «جمعِ همین ردیف‌ها» نامیده می‌شود، چون
 *    فهرست می‌تواند بریده باشد.
 *
 * ⚠ هیچ دکمه‌ی خرید و فروشی ندارد و نباید داشته باشد — همان مرزی که
 *   کارتِ قبلی هم داشت: ثبت و ویرایش کارِ پنلِ فروشگاه است.
 */
require_once __DIR__ . '/includes/auth.php';
// ⚠ `csrf.php` لازم است هرچند این صفحه هیچ فرمِ نویسنده‌ای ندارد: فوتر
//   شیتِ ثبت تراکنش را در **هر** صفحه رندر می‌کند و آن `Csrf::field()`
//   می‌خواهد. بدونش رندر وسطِ کار با «Class "Csrf" not found» می‌بُرید —
//   نه خطایی روی صفحه، فقط HTML نصفه. `test_page_render` گرفتش.
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/store_share.php';
require_once __DIR__ . '/includes/paged_list.php';

Auth::initSession();
Auth::requireLogin();

$userId  = (int)Auth::userId();
$isAdmin = Auth::isAdmin();

$owners = StoreShare::available() ? StoreShare::assetOwners($userId, $isAdmin) : [];
$status = StoreShare::available() ? StoreShare::status() : ['fetched_at' => null, 'last_error' => null];

/**
 * ⛔ فهرستِ صافی‌ها **تنها مرجع** است — هم چیپ‌ها از آن رندر می‌شوند هم
 *    `?f=` با آن سنجیده می‌شود (درسِ `DUE_TABS`). با فهرستِ دوم، عوض
 *    کردنِ نامِ یک صافی هیچ خطایی نمی‌داد و فقط لینک‌ها بی‌صدا به
 *    پیش‌فرض برمی‌گشتند.
 */
const STORE_ASSET_FILTERS = [
    'open' => 'در انبار',
    'sold' => 'فروخته‌شده',
    'all'  => 'همه',
];
$filter = (string)getParam('f', 'open');
if (!isset(STORE_ASSET_FILTERS[$filter])) { $filter = 'open'; }

$q = trim((string)getParam('q', ''));

/** صافیِ یک ردیف: وضعیت و جست‌وجو. */
$matches = static function (array $r) use ($filter, $q): bool {
    if ($filter === 'open' && $r['sold']) { return false; }
    if ($filter === 'sold' && !$r['sold']) { return false; }
    if ($q === '') { return true; }
    // ⚠ ارقامِ فارسی هم باید پیدا شوند: کیبوردِ فارسی «۱۲۳» می‌دهد و آن
    //   با `123` یکی نیست — همان قاعده‌ی `txSearchAmount()`.
    $needle = mb_strtolower(toLatinDigits($q));
    $hay    = mb_strtolower(toLatinDigits($r['product'] . ' ' . $r['label'] . ' ' . $r['cat_label']));
    return mb_strpos($hay, $needle) !== false;
};

$pageTitle = 'کالاهای فروشگاه';
include __DIR__ . '/includes/header.php';
?>

<a href="<?= APP_BASE_PATH ?>/my-assets.php" class="page-back js-page-back">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
    بازگشت
</a>

<?php if (!StoreShare::available()): ?>
    <div class="card">
        <h2 class="card-title">کالاهای فروشگاه</h2>
        <p class="empty-row">اتصال به حسابداری فروشگاه تنظیم نشده است.</p>
    </div>
<?php elseif ($owners === []): ?>
    <div class="card">
        <h2 class="card-title">کالاهای فروشگاه</h2>
        <p class="empty-row">
            <?php if ($isAdmin): ?>
                هنوز هیچ سهامداری وصل نشده و کالایی هم از فروشگاه نرسیده است.<br>
                <a href="<?= APP_BASE_PATH ?>/admin/store-share.php" class="link-more">مدیریت سهامداران فروشگاه ←</a>
            <?php else: ?>
                حساب شما به هیچ سهامداری در فروشگاه وصل نیست.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">کالاهای فروشگاه</h2>
        <span class="asset-tag">فقط نمایش</span>
    </div>

    <p class="hint">
        <?php if ($isAdmin): ?>
            کالای همه‌ی سهامدارها و خودِ فروشگاه، به تفکیکِ دسته.
        <?php else: ?>
            کالاهایی که به نامِ شما ثبت شده‌اند.
        <?php endif; ?>
        ثبت و ویرایش در پنلِ فروشگاه انجام می‌شود؛ اینجا فقط دیده می‌شود.
        <?php if ($status['fetched_at']): ?>
            آخرین به‌روزرسانی: <?= h(jalaliWithWeekday(substr((string)$status['fetched_at'], 0, 10))) ?>.
        <?php endif; ?>
        <?php if ($status['last_error']): ?>
            <br><span style="color:var(--warn-ink);">آخرین تلاش ناموفق بود؛ عددهای زیر از آخرین نسخه‌ی موفق است.</span>
        <?php endif; ?>
    </p>

    <?php /* صافیِ وضعیت — چیپ، نه منو: دو تپ کمتر و هر سه در یک نگاه. */ ?>
    <div class="due-filters">
        <?php foreach (STORE_ASSET_FILTERS as $key => $label): ?>
            <a class="due-filter <?= $filter === $key ? 'is-active' : '' ?>"
               href="<?= pagedUrl(['f' => $key, 'all' => null]) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="store-asset-search">
        <input type="hidden" name="f" value="<?= h($filter) ?>">
        <input type="search" name="q" value="<?= h($q) ?>" class="input"
               placeholder="نام کالا، IMEI یا دسته…" autocapitalize="none" autocorrect="off">
        <button type="submit" class="btn btn-secondary btn-sm">جستجو</button>
        <?php if ($q !== ''): ?>
            <a class="btn btn-secondary btn-sm" href="<?= pagedUrl(['q' => null, 'all' => null]) ?>">پاک کردن</a>
        <?php endif; ?>
    </form>
</div>

<?php foreach ($owners as $oi => $own): ?>
<?php
    // ⚠ فهرستِ هر مالک صافی می‌خورد **پیش از** دسته‌بندی، وگرنه یک
    //   دسته با صفر ردیف هم سرآیند می‌گرفت.
    $rows = array_values(array_filter($own['rows'], $matches));

    // گروه‌بندی بر اساسِ برچسبی که خودِ فروشگاه فرستاده.
    //
    // ⚠ برچسبِ خالی یعنی نصبِ فروشگاه هنوز این کلید را نمی‌فرستد. آن
    //   وقت «دسته‌بندی‌نشده» نوشته می‌شود، نه یک حدسِ محلی: دو سیستم
    //   نباید دو اسم روی یک چیز بگذارند.
    $groups = [];
    foreach ($rows as $r) {
        $g = $r['cat_label'] !== '' ? $r['cat_label'] : 'دسته‌بندی‌نشده';
        $groups[$g][] = $r;
    }
    ksort($groups);
?>
<div class="card">
    <div class="card-header-row">
        <h2 class="card-title"><?= h($own['name']) ?></h2>
        <?php if ($own['is_house']): ?>
            <span class="asset-tag">بدون سهامدار</span>
        <?php endif; ?>
    </div>

    <?php
    /*
     * ⛔ این کارت فقط سه چیز می‌گوید، و بقیه **عمداً** برداشته شد:
     * کالای موجودِ این شخص، اصلِ پولِ کالای فروخته‌شده، و سهمِ سود.
     *
     * ⛔ «مانده‌ی حساب» و «اصل سرمایه» پس گرفته شدند. هر دو از دفترِ
     * کلِ فروشگاه می‌آمدند (`shareholderLedger()`) و مالکِ نصب گزارش
     * کرد که روی نصبِ واقعی با کالای شمرده‌شده نمی‌خوانند — سرمایه‌ی
     * ثبت‌شده ۹۹ میلیون بیشتر از جمعِ بهای خریدِ کالاها بود. عددی که
     * از روی کالاهای همین کارت قابل تأیید نیست، جای نشستن روی همین
     * کارت را ندارد؛ جایش پنلِ خودِ فروشگاه است.
     *   ⚠ و همان جا هم برداشته نشد: `StoreShare::valueFor()` هنوز
     *   «مانده» را به قلمِ «دارایی من در فروشگاه» می‌دهد، چون آن عدد
     *   خالص دارایی را می‌سازد و با جمعِ کالاها سرِ **هر فروش** سقوط
     *   می‌کرد (بالاتر در CLAUDE.md نوشته شده). این تصمیم فقط برای
     *   این کارت است، نه برای مدلِ پول.
     *
     * ⛔ و «فروخته‌شده» دیگر `sold_total` نیست. آن `SUM(salePrice)` است
     * — یعنی قیمتی که کالا به مشتری فروخته شد، که سهمِ **فروشگاه** را
     * هم در خود دارد. روی کارتِ یک سهامدار همان عدد دروغ می‌گوید:
     * گوشیِ ۱۴۵ میلیونی، برای سهامدار ۱۱۵ اصلِ پول بود و ۱۲ سهمِ سود،
     * نه ۱۴۵. پس اصلِ پول از جمعِ `cost` همان ردیف‌ها می‌آید و سود
     * قلمِ جدای خودش را دارد.
     */
    $soldCost = 0;
    $soldRows = 0;
    foreach ($own['rows'] as $r) {
        if ($r['kind'] !== 'device' || !$r['sold']) { continue; }
        $soldRows++;
        $soldCost += (int)$r['cost'];
    }
    /*
     * ⛔ «نمی‌دانیم» با «صفر» یکی نیست: اگر فهرست بریده باشد یا پاسخ
     *   همه‌ی ردیف‌های فروخته را نفرستد، جمع ناقص است و یک عددِ کمتر
     *   از واقعیت نشان می‌داد — بی‌هیچ نشانه‌ای. در آن حالت فقط تعداد
     *   گفته می‌شود.
     */
    $soldKnown = !$own['capped'] && $soldRows === $own['sold_count'];
    ?>
    <div class="store-own-stats">
        <div class="store-own-stat">
            <span class="store-own-label">سرمایه در انبار</span>
            <b class="ltr-num"><?= formatMoney($own['active_cost']) ?></b>
            <small><?= toPersianDigits((string)$own['active_count']) ?> دستگاه · بهای خرید</small>
        </div>
        <?php if ($own['sold_count'] > 0): ?>
        <div class="store-own-stat">
            <span class="store-own-label">اصل پول فروخته‌شده</span>
            <?php if ($soldKnown): ?>
                <b class="ltr-num"><?= formatMoney($soldCost) ?></b>
                <small><?= toPersianDigits((string)$own['sold_count']) ?> دستگاه · بهای خرید</small>
            <?php else: ?>
                <b>—</b>
                <small><?= toPersianDigits((string)$own['sold_count']) ?> دستگاه · مبلغ در دسترس نیست</small>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!$own['is_house']): ?>
        <div class="store-own-stat">
            <span class="store-own-label">سهم سود</span>
            <b class="ltr-num<?= $own['own_profit'] < 0 ? ' asset-amount-neg' : '' ?>"><?= formatMoney($own['own_profit']) ?></b>
        </div>
        <?php endif; ?>
        <?php if ($own['paid'] !== null && $own['paid'] !== 0): ?>
        <div class="store-own-stat">
            <span class="store-own-label">پرداخت‌شده</span>
            <b class="ltr-num"><?= formatMoney($own['paid']) ?></b>
            <small>تسویه‌ی نقدی</small>
        </div>
        <?php endif; ?>
        <?php if ($own['items_cost'] !== 0): ?>
        <div class="store-own-stat">
            <span class="store-own-label">لوازم جانبی</span>
            <b class="ltr-num"><?= formatMoney($own['items_cost']) ?></b>
            <small>سرمایه</small>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($own['capped']): ?>
        <p class="hint" style="color:var(--warn-ink);">
            فهرستِ زیر بریده شده است؛ جمع‌های بالا روی همه‌ی کالاها حساب شده‌اند.
        </p>
    <?php endif; ?>

    <?php if ($groups === []): ?>
        <p class="empty-row">
            <?= $q !== '' ? 'چیزی با این جستجو پیدا نشد.' : 'کالایی با این صافی نیست.' ?>
        </p>
    <?php else: ?>
        <?php foreach ($groups as $label => $gRows): ?>
        <?php
            // ⚠ جمعِ زیرِ هر دسته صریح «جمعِ همین ردیف‌ها» است، نه یک
            //   جمعِ کل: صافی و سقفِ فهرست هر دو رویش اثر دارند و اگر
            //   «کلِ دسته» نامیده می‌شد، عددی می‌داد که با جمعِ بالا
            //   نمی‌خواند و کسی نمی‌فهمید کدام درست است.
            $gCost   = array_sum(array_column($gRows, 'cost'));
            $gProfit = 0;
            $gHasProfit = false;
            foreach ($gRows as $r) {
                if ($r['own'] !== null) { $gProfit += $r['own']; $gHasProfit = true; }
            }
            $slice = pagedSlice($gRows, 'g' . $oi . '_' . crc32((string)$label));
        ?>
        <div class="store-cat-head">
            <span class="store-cat-name"><?= h((string)$label) ?></span>
            <span class="store-cat-meta">
                <?= toPersianDigits((string)count($gRows)) ?> قلم ·
                بها <span class="ltr-num"><?= formatMoney((int)$gCost) ?></span>
                <?php if ($gHasProfit): ?>
                    · سهم سود <span class="ltr-num<?= $gProfit < 0 ? ' asset-amount-neg' : '' ?>"><?= formatMoney((int)$gProfit) ?></span>
                <?php endif; ?>
            </span>
        </div>

        <?php foreach ($slice['rows'] as $r): ?>
            <div class="tx-row">
                <div class="tx-row-summary">
                    <div class="tx-row-texts">
                        <span class="tx-row-title"><?= h($r['product']) ?></span>
                        <span class="tx-row-cat">
                            <?= $r['sold'] ? 'فروخته شد' : 'در انبار' ?>
                            <?php if ($r['kind'] === 'item'): ?>
                                · <?= formatQuantity($r['qty']) ?> عدد
                            <?php endif; ?>
                            <?php if ($r['label'] !== ''): ?>
                                · <span class="ltr-num"><?= h($r['label']) ?></span>
                            <?php endif; ?>
                            <?php if ($r['date'] !== ''): ?>
                                · <?= h(toJalali($r['date'])) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <span class="tx-row-amount">
                        <span class="ltr-num"><?= formatMoney($r['sold'] ? $r['price'] : $r['cost']) ?></span>
                        <?php if ($r['own'] !== null && $r['own'] !== 0): ?>
                            <small class="store-row-own<?= $r['own'] < 0 ? ' asset-amount-neg' : '' ?>">
                                سهم <span class="ltr-num"><?= formatMoney($r['own']) ?></span>
                            </small>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>

        <?php pagedNav($slice); ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
