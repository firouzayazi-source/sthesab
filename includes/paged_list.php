<?php
/**
 * ⛔ فهرستِ توری و صفحه‌بندی‌شده — تنها جای این تصمیم.
 *
 * **مسئله‌ی واقعی، و مالکِ نصب گزارشش کرد:** «آمار استفاده» فهرستِ
 * **همه‌ی** کاربران را یک‌جا و زیرِ هم می‌ریخت. با هفت کاربر خواندنی
 * بود؛ با هفتاد تا صفحه‌ای می‌شود که هیچ‌کس تا تهش نمی‌رود — یعنی
 * کارت‌های تحلیلیِ **پایینِ** همان صفحه (رشد ماهانه، سلامتِ cron،
 * خطاها) عملاً نامرئی می‌شوند. همان الگوی «چهار خزشِ بی‌صدا»: هیچ
 * ردیفی به‌تنهایی بد نیست، حاصلِ جمعشان است.
 *
 * ⛔ **و دو فهرستِ دیگرِ همان صفحه بدتر بودند:** `array_slice($never, 0, 20)`
 *    بیست‌تای اول را نشان می‌داد و **هیچ‌جا نمی‌گفت بقیه کجا رفتند** —
 *    دقیقاً همان «سقفِ بی‌صدا» که راهنما جای دیگری ممنوعش کرده. حالا
 *    عددِ کل همیشه نوشته می‌شود.
 *
 * ⛔ **برش در PHP است، نه `LIMIT` در SQL.** ردیف‌ها از **یک** کوئریِ
 *    تجمیعی می‌آیند (`userActivity()`) و سه فهرستِ این صفحه هر سه از
 *    همان **یک آرایه** ساخته می‌شوند. با `LIMIT`، هر فهرست یک
 *    `SELECT` و یک `COUNT` جدا لازم داشت — یعنی شش کوئریِ تازه روی
 *    صفحه‌ای که بودجه‌اش در `test_query_budget` ثبت است، و بدتر:
 *    دو کوئریِ جدا می‌توانستند دو جواب بدهند و قیفِ شروع با فهرستِ
 *    زیرش نخواند. برشِ آرایه هیچ کوئری‌ای اضافه نمی‌کند.
 *
 * ⛔ **«همه» یک لینک است، نه جاوااسکریپت.** خواسته‌ی صریحِ مالکِ نصب
 *    («یا اگه خواستم همه در یک لیست مثل الان») باید با دکمه‌ی بازگشتِ
 *    مرورگر و با کپیِ آدرس هم کار کند؛ و کلیدی که با نرسیدنِ `app.js`
 *    بی‌صدا از کار بیفتد همان «دکمه‌ی بی‌کار» است.
 *
 * ⛔ **`?all=` نامِ فهرست را می‌گیرد، نه یک بولین.** سه فهرست روی یک
 *    صفحه‌اند؛ با یک کلیدِ مشترک، باز کردنِ یکی هر سه را باز می‌کرد —
 *    یعنی همان شلوغی‌ای که این قابلیت برای رفعش نوشته شد.
 */

/**
 * ⛔ تنها مرجعِ «چند تا در هر صفحه». عددِ سخت‌کد در دو جا (برش و متنِ
 *    راهنما) یعنی عوض کردنِ یکی، دیگری را بی‌صدا دروغ‌گو می‌کند —
 *    همان درسِ `ACTIVE_DAYS` که در سه جا نوشته شده بود.
 */
const PAGED_LIST_SIZE = 10;

/**
 * برشِ یک صفحه از آرایه، با خواندنِ وضعیت از خودِ آدرس.
 *
 * @param list<array<string,mixed>> $rows همه‌ی ردیف‌ها (از قبل خوانده‌شده)
 * @param string $key                     شناسه‌ی یکتای این فهرست در صفحه
 * @return array{rows:list<array<string,mixed>>, page:int, pages:int, total:int, all:bool, key:string}
 */
function pagedSlice(array $rows, string $key): array
{
    $rows  = array_values($rows);
    $total = count($rows);

    // ⚠ «همه» فقط برای همین فهرست. مقایسه با نامِ کلید است نه یک
    //   پرچمِ عمومی — وگرنه باز کردنِ یکی سه‌تا را باز می‌کرد.
    if (getParam('all') === $key) {
        return ['rows' => $rows, 'page' => 1, 'pages' => 1, 'total' => $total, 'all' => true, 'key' => $key];
    }

    $pages = max(1, (int)ceil($total / PAGED_LIST_SIZE));
    // شماره‌ی بیرون از بازه به نزدیک‌ترین صفحه بریده می‌شود، نه اینکه
    // فهرستِ خالی بدهد: `?pg_users=99` دستِ آدم می‌خورد و صفحه‌ی خالیِ
    // بی‌توضیح شبیهِ خرابی دیده می‌شود.
    $page  = max(1, min($pages, (int)getParam('pg_' . $key, '1')));

    return [
        'rows'  => array_slice($rows, ($page - 1) * PAGED_LIST_SIZE, PAGED_LIST_SIZE),
        'page'  => $page,
        'pages' => $pages,
        'total' => $total,
        'all'   => false,
        'key'   => $key,
    ];
}

/**
 * ⛔ آدرسِ صفحه‌بندی **بقیه‌ی پارامترها را نگه می‌دارد.**
 *
 * سه فهرست روی یک صفحه‌اند؛ اگر لینکِ صفحه‌ی دومِ یکی بقیه را دور
 * بریزد، کاربر روی فهرستِ دیگر جایش را **بی‌صدا** از دست می‌دهد و
 * برمی‌گردد سرِ صفحه‌ی اول. خرابی‌اش هیچ خطایی ندارد و فقط آزاردهنده
 * است — یعنی دقیقاً همان چیزی که کسی گزارشش نمی‌کند.
 */
function pagedUrl(array $set): string
{
    $q = $_GET;
    foreach ($set as $k => $v) {
        if ($v === null) { unset($q[$k]); } else { $q[$k] = (string)$v; }
    }
    // فقط رشته‌های ساده؛ `?pg_users[]=1` نباید به `http_build_query` برسد.
    $q = array_filter($q, 'is_scalar');
    $qs = http_build_query($q);
    return htmlspecialchars(basename($_SERVER['SCRIPT_NAME']) . ($qs !== '' ? '?' . $qs : ''), ENT_QUOTES, 'UTF-8');
}

/**
 * نوارِ صفحه‌بندی. با یک صفحه و بدونِ سرریز **اصلاً رندر نمی‌شود** —
 * نوارِ «۱ از ۱» چیزی نمی‌گوید و فقط جا می‌گیرد (همان قاعده‌ی نقطه‌های
 * اسلایدرِ خانه که با یک اسلاید رندر نمی‌شوند).
 */
function pagedNav(array $s): void
{
    if ($s['total'] <= PAGED_LIST_SIZE) { return; }
    $key = $s['key'];
    ?>
    <nav class="pager" aria-label="صفحه‌بندی">
        <?php if ($s['all']): ?>
            <span class="pager-info">همه‌ی <?= toPersianDigits($s['total']) ?> مورد</span>
            <a class="pager-link" href="<?= pagedUrl(['all' => null, 'pg_' . $key => null]) ?>">صفحه‌بندی</a>
        <?php else: ?>
            <?php if ($s['page'] > 1): ?>
                <a class="pager-link" href="<?= pagedUrl(['pg_' . $key => $s['page'] - 1, 'all' => null]) ?>" rel="prev">قبلی</a>
            <?php else: ?>
                <span class="pager-link is-off">قبلی</span>
            <?php endif; ?>

            <span class="pager-info">
                صفحه <?= toPersianDigits($s['page']) ?> از <?= toPersianDigits($s['pages']) ?>
                · <?= toPersianDigits($s['total']) ?> مورد
            </span>

            <?php if ($s['page'] < $s['pages']): ?>
                <a class="pager-link" href="<?= pagedUrl(['pg_' . $key => $s['page'] + 1, 'all' => null]) ?>" rel="next">بعدی</a>
            <?php else: ?>
                <span class="pager-link is-off">بعدی</span>
            <?php endif; ?>

            <a class="pager-link" href="<?= pagedUrl(['all' => $key, 'pg_' . $key => null]) ?>">همه در یک فهرست</a>
        <?php endif; ?>
    </nav>
    <?php
}
