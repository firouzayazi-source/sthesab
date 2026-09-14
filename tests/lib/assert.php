<?php
/**
 * کمترین چیزی که برای تست لازم است — بدون Composer و بدون وابستگی.
 *
 * این پروژه عمداً هیچ پکیج خارجی ندارد؛ تست‌ها هم نباید داشته باشند،
 * وگرنه روی هاست یا سروری که Composer ندارد اجرا نمی‌شوند.
 */

final class T
{
    /** کد خروجی که «اجرا نشد» را از «شکست خورد» جدا می‌کند (run.sh می‌شناسدش) */
    public const EXIT_BLOCKED = 2;

    public static int $passed = 0;
    public static array $failures = [];
    public static array $blocked = [];
    private static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        printf("\n  \033[1m%s\033[0m\n", $name);
    }

    public static function ok(bool $cond, string $what, string $detail = ''): void
    {
        if ($cond) {
            self::$passed++;
            return;
        }
        self::$failures[] = self::$group . ' › ' . $what . ($detail !== '' ? "\n      $detail" : '');
        printf("    \033[0;31m✗\033[0m %s\n", $what);
        if ($detail !== '') { printf("      %s\n", $detail); }
    }

    public static function same($expected, $actual, string $what): void
    {
        self::ok(
            $expected === $actual,
            $what,
            $expected === $actual ? '' : 'انتظار: ' . json_encode($expected, JSON_UNESCAPED_UNICODE)
                . '  ولی: ' . json_encode($actual, JSON_UNESCAPED_UNICODE)
        );
    }

    /** برای تست‌هایی که هزاران مورد را می‌آزمایند و نباید هزاران خط چاپ کنند */
    public static function bulk(int $checked, array $bad, string $what, int $show = 5): void
    {
        if (!$bad) {
            self::$passed++;
            printf("    \033[0;32m✓\033[0m %s (%s مورد)\n", $what, number_format($checked));
            return;
        }
        $detail = count($bad) . ' مورد ناموفق از ' . number_format($checked) . '؛ نمونه:'
            . "\n      " . implode("\n      ", array_slice($bad, 0, $show));
        self::$failures[] = self::$group . ' › ' . $what . "\n      " . $detail;
        printf("    \033[0;31m✗\033[0m %s\n      %s\n", $what, $detail);
    }

    public static function pass(string $what): void
    {
        self::$passed++;
        printf("    \033[0;32m✓\033[0m %s\n", $what);
    }

    /**
     * ردِ **نرم** — چیزی که نبودنش روی این ماشین پذیرفتنی است: node نصب
     * نیست، پورت آزاد نبود، این migration اختیاری هنوز اجرا نشده.
     * کد خروج را عوض نمی‌کند.
     */
    public static function skip(string $what, string $why): void
    {
        printf("    \033[0;33m-\033[0m %s \033[0;90m(%s)\033[0m\n", $what, $why);
    }

    /**
     * ⛔ ردِ **سخت** — زیرساختی که تست بدونش هیچ چیزی را نمی‌سنجد:
     * `config/config.php` نیست، یا اتصال به دیتابیس برقرار نشد.
     *
     * **چرا جدا از `skip()`:** یک بار MariaDB خوابیده بود، حدود ۲۵ مجموعه
     * پیامِ «اتصال به دیتابیس برقرار نشد» چاپ کردند، و ته اجرا نوشت
     * «✅ هر ۴۲ مجموعه تست موفق بود». یعنی می‌شد با خیالِ راحت push کرد
     * در حالی که **هیچ‌کدام از تست‌های دیتابیسی اصلاً اجرا نشده بودند**.
     * `skip()` هیچ شمارنده‌ای را بالا نمی‌برد و `report()` هم صفر
     * برمی‌گرداند — همان «سنجشی که روی خرابی سبز می‌شود» که این پروژه
     * همه‌جا برای نفیِ آن تست نوشته، این بار داخلِ خودِ تست‌رانر.
     */
    public static function blocked(string $what, string $why): void
    {
        self::$blocked[] = self::$group . ' › ' . $what . ' — ' . $why;
        printf("    \033[0;31m⛔\033[0m %s \033[0;31m(%s)\033[0m\n", $what, $why);
    }

    public static function report(): int
    {
        printf("\n  %s\n", str_repeat('─', 58));
        if (self::$failures) {
            printf("  \033[0;31m❌ %d ناموفق (از %d بررسی)\033[0m\n\n",
                count(self::$failures), self::$passed + count(self::$failures));
            foreach (self::$failures as $f) { printf("    • %s\n", $f); }
            echo "\n";
            return 1;
        }
        if (self::$blocked) {
            printf("  \033[0;31m⛔ اجرا نشد — زیرساختِ لازم نبود:\033[0m\n\n");
            foreach (self::$blocked as $b) { printf("    • %s\n", $b); }
            echo "\n";
            return self::EXIT_BLOCKED;
        }
        printf("  \033[0;32m✅ %d بررسی، همه موفق\033[0m\n\n", self::$passed);
        return 0;
    }
}
