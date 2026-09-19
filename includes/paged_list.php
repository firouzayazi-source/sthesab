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
 * ⛔ چند شماره‌ی صفحه کنارِ صفحه‌ی جاری رندر می‌شود.
 *
 * با ده کاربر «قبلی/بعدی» کافی بود. با هزار کاربر و صفحه‌های ۲۵تایی،
 * چهل صفحه می‌شود و رسیدن به صفحه‌ی ۳۷ یعنی **سی و شش بار** زدنِ
 * «بعدی» — یعنی همان «سقفِ بی‌صدا»، فقط این بار به شکلِ فاصله.
 */
const PAGED_WINDOW = 2;

/**
 * برشِ یک صفحه از آرایه، با خواندنِ وضعیت از خودِ آدرس.
 *
 * @param list<array<string,mixed>> $rows همه‌ی ردیف‌ها (از قبل خوانده‌شده)
 * @param string $key                     شناسه‌ی یکتای این فهرست در صفحه
 * @param int    $size                    چند ردیف در هر صفحه
 * @return array{rows:list<array<string,mixed>>, page:int, pages:int, total:int, all:bool, key:string, size:int, offset:int}
 */
function pagedSlice(array $rows, string $key, int $size = PAGED_LIST_SIZE): array
{
    $rows = array_values($rows);
    $s    = pagedWindow(count($rows), $key, $size);
    $s['rows'] = $s['all'] ? $rows : array_slice($rows, $s['offset'], $s['size']);
    return $s;
}

/**
 * ⛔ همان حسابِ صفحه‌بندی، ولی **بدونِ ردیف‌ها** — برای فهرستی که با
 *    `LIMIT` از دیتابیس می‌آید.
 *
 * چرا هر دو لازم‌اند و کدام کجاست:
 *
 *  - `pagedSlice()` وقتی درست است که ردیف‌ها **از قبل و برای کارِ
 *    دیگری** خوانده شده‌اند. `admin/insights.php` سه فهرستش را از یک
 *    آرایه‌ی `userActivity()` می‌سازد؛ آنجا `LIMIT` یعنی شش کوئریِ تازه
 *    و دو جوابِ ناهماهنگ.
 *  - `pagedWindow()` وقتی درست است که فهرست **تنها مصرف‌کننده‌ی** کوئری
 *    است. `admin/users.php` با هزار کاربر، هزار ردیف را از دیتابیس
 *    می‌کشید تا بیست‌وپنج‌تا نشان بدهد — همان «خزشِ بی‌صدا» که کلِ
 *    `test_query_budget` برای گرفتنش نوشته شد. هزینه‌اش **یک** کوئریِ
 *    `COUNT` است، و فقط وقتی که صافی‌ای در کار باشد.
 *
 * @return array{rows:list<array<string,mixed>>, page:int, pages:int, total:int, all:bool, key:string, size:int, offset:int}
 */
function pagedWindow(int $total, string $key, int $size = PAGED_LIST_SIZE): array
{
    $total = max(0, $total);
    $size  = max(1, $size);

    // ⚠ «همه» فقط برای همین فهرست. مقایسه با نامِ کلید است نه یک
    //   پرچمِ عمومی — وگرنه باز کردنِ یکی سه‌تا را باز می‌کرد.
    if (getParam('all') === $key) {
        return ['rows' => [], 'page' => 1, 'pages' => 1, 'total' => $total,
                'all' => true, 'key' => $key, 'size' => $total, 'offset' => 0];
    }

    $pages = max(1, (int)ceil($total / $size));
    // شماره‌ی بیرون از بازه به نزدیک‌ترین صفحه بریده می‌شود، نه اینکه
    // فهرستِ خالی بدهد: `?pg_users=99` دستِ آدم می‌خورد و صفحه‌ی خالیِ
    // بی‌توضیح شبیهِ خرابی دیده می‌شود.
    $page = max(1, min($pages, (int)getParam('pg_' . $key, '1')));

    return [
        'rows'   => [],
        'page'   => $page,
        'pages'  => $pages,
        'total'  => $total,
        'all'    => false,
        'key'    => $key,
        'size'   => $size,
        'offset' => ($page - 1) * $size,
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
    $size = (int)($s['size'] ?? PAGED_LIST_SIZE);
    if ($s['all']) {
        // با «همه» اندازه برابرِ کل است، پس شرطِ زیر همیشه برقرار می‌شد
        // و نوارِ برگشت به صفحه‌بندی رندر نمی‌شد.
        $size = PAGED_LIST_SIZE;
    }
    if ($s['total'] <= $size) { return; }
    $key = $s['key'];

    // ⚠ پنجره‌ی شماره‌ها همیشه هم‌اندازه می‌ماند، حتی سرِ اول و آخرِ
    //   فهرست: بدونِ این، روی صفحه‌ی ۱ فقط سه شماره دیده می‌شد و روی
    //   صفحه‌ی وسط پنج‌تا — یعنی نوار زیرِ دستِ کاربر جابه‌جا می‌شد.
    $span = PAGED_WINDOW * 2 + 1;
    $from = max(1, min($s['page'] - PAGED_WINDOW, $s['pages'] - $span + 1));
    $to   = min($s['pages'], max($s['page'] + PAGED_WINDOW, $span));
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

            <?php if ($s['pages'] > $span): ?>
                <?php /* ⚠ شماره‌ها فقط وقتی می‌آیند که واقعاً پرشی لازم باشد؛
                         با سه صفحه، «قبلی/بعدی» خودش کافی است و سه عددِ
                         اضافه فقط نوار را شلوغ می‌کند. */ ?>
                <span class="pager-nums">
                    <?php if ($from > 1): ?>
                        <a class="pager-num" href="<?= pagedUrl(['pg_' . $key => 1, 'all' => null]) ?>">۱</a>
                        <?php if ($from > 2): ?><span class="pager-gap">…</span><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($p = $from; $p <= $to; $p++): ?>
                        <?php if ($p === $s['page']): ?>
                            <span class="pager-num is-current" aria-current="page"><?= toPersianDigits($p) ?></span>
                        <?php else: ?>
                            <a class="pager-num" href="<?= pagedUrl(['pg_' . $key => $p, 'all' => null]) ?>"><?= toPersianDigits($p) ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($to < $s['pages']): ?>
                        <?php if ($to < $s['pages'] - 1): ?><span class="pager-gap">…</span><?php endif; ?>
                        <a class="pager-num" href="<?= pagedUrl(['pg_' . $key => $s['pages'], 'all' => null]) ?>"><?= toPersianDigits($s['pages']) ?></a>
                    <?php endif; ?>
                </span>
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
