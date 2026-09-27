<?php
/**
 * چاپ و گزارش — درِ ورودِ همه‌ی برگه‌های چاپی (`BizPrint::DOCS`).
 *
 * هر کارت یک فرمِ GET به `print.php` است، نه جاوااسکریپت: با دکمه‌ی
 * بازگشتِ مرورگر و کپیِ آدرس هم کار می‌کند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_print.php';

$userId   = (int)Auth::userId();
$cats     = BizProducts::categories($userId);
$parties  = BizParties::all($userId, '', 500);
$products = BizProducts::all($userId, '', '', 500);
$prefs    = Biz::printPrefs($userId);
$printUrl = Biz::url('print.php');

$pageTitle = 'چاپ و گزارش';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">چاپ و گزارش</h1>
        <p class="st-muted">کاغذ: <?= h(Biz::PRINT_OPTIONS['paper'][$prefs['paper']]) ?> —
            <a href="<?= h(Biz::url('print-settings.php')) ?>">تنظیماتِ چاپ</a></p>
    </div>
</div>

<div class="st-cols">
    <?php foreach (['stock', 'prices'] as $d): ?>
    <form class="st-card st-form" method="get" action="<?= h($printUrl) ?>">
        <input type="hidden" name="doc" value="<?= h($d) ?>">
        <h2 class="st-h2"><?= h(BizPrint::DOCS[$d]) ?></h2>
        <p class="st-muted"><?= $d === 'stock' ? 'موجودی، میانگینِ بهای خرید و ارزشِ هر کالا، به تفکیکِ دسته، با جمعِ کل.' : 'نام، کد، واحد و قیمتِ فروشِ همه‌ی کالاها و خدمت‌ها.' ?></p>
        <div class="st-row2">
            <label class="st-field"><span>صافی</span>
                <select name="f">
                    <?php foreach (BizProducts::FILTERS as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="st-field"><span>دسته</span>
                <select name="c">
                    <option value="">همه‌ی دسته‌ها</option>
                    <?php foreach ($cats as $c): ?><option value="<?= h($c) ?>"><?= h($c) ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>
        <button type="submit" class="st-btn">نمایش برای چاپ</button>
    </form>
    <?php endforeach; ?>

    <form class="st-card st-form" method="get" action="<?= h($printUrl) ?>">
        <input type="hidden" name="doc" value="parties">
        <h2 class="st-h2"><?= h(BizPrint::DOCS['parties']) ?></h2>
        <p class="st-muted">فهرستِ مشتری‌ها و تأمین‌کننده‌ها با مانده‌ی بدهکار و بستانکارِ هر کدام.</p>
        <label class="st-field"><span>صافی</span>
            <select name="f">
                <?php foreach (BizParties::FILTERS as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="st-btn">نمایش برای چاپ</button>
    </form>

    <form class="st-card st-form" method="get" action="<?= h($printUrl) ?>">
        <input type="hidden" name="doc" value="party">
        <h2 class="st-h2"><?= h(BizPrint::DOCS['party']) ?></h2>
        <p class="st-muted">گردش و مانده‌ی یک طرف‌حساب، با جای امضا — برای دادن به خودِ مشتری یا تأمین‌کننده.</p>
        <?php if ($parties['rows']): ?>
        <label class="st-field"><span>طرف‌حساب</span>
            <select name="id" required>
                <?php foreach ($parties['rows'] as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h((string)$r['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <?php if ($parties['capped']): ?><p class="st-muted-i">فقط ۵۰۰ طرف‌حسابِ اول آمده‌اند؛ بقیه از صفحه‌ی خودِ طرف‌حساب چاپ می‌شوند.</p><?php endif; ?>
        <button type="submit" class="st-btn">نمایش برای چاپ</button>
        <?php else: ?>
        <p class="st-empty">هنوز طرف‌حسابی ثبت نشده است.</p>
        <?php endif; ?>
    </form>

    <form class="st-card st-form" method="get" action="<?= h($printUrl) ?>">
        <input type="hidden" name="doc" value="kardex">
        <h2 class="st-h2"><?= h(BizPrint::DOCS['kardex']) ?></h2>
        <p class="st-muted">همه‌ی ورود و خروج‌های یک کالا با مانده‌ی بعد از هر حرکت.</p>
        <?php if ($products['rows']): ?>
        <label class="st-field"><span>کالا</span>
            <select name="id" required>
                <?php foreach ($products['rows'] as $r): if ((int)$r['track_stock'] !== 1) { continue; } ?>
                <option value="<?= (int)$r['id'] ?>"><?= h((string)$r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($products['capped']): ?><p class="st-muted-i">فقط ۵۰۰ کالای اول آمده‌اند؛ بقیه از صفحه‌ی خودِ کالا چاپ می‌شوند.</p><?php endif; ?>
        <button type="submit" class="st-btn">نمایش برای چاپ</button>
        <?php else: ?>
        <p class="st-empty">هنوز کالایی ثبت نشده است.</p>
        <?php endif; ?>
    </form>
</div>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
