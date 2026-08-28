<?php
/**
 * کمترین چیزی که برای تست لازم است — بدون Composer و بدون وابستگی.
 *
 * این پروژه عمداً هیچ پکیج خارجی ندارد؛ تست‌ها هم نباید داشته باشند،
 * وگرنه روی هاست یا سروری که Composer ندارد اجرا نمی‌شوند.
 */

final class T
{
    public static int $passed = 0;
    public static array $failures = [];
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

    public static function skip(string $what, string $why): void
    {
        printf("    \033[0;33m-\033[0m %s \033[0;90m(%s)\033[0m\n", $what, $why);
    }

    public static function report(): int
    {
        printf("\n  %s\n", str_repeat('─', 58));
        if (!self::$failures) {
            printf("  \033[0;32m✅ %d بررسی، همه موفق\033[0m\n\n", self::$passed);
            return 0;
        }
        printf("  \033[0;31m❌ %d ناموفق (از %d بررسی)\033[0m\n\n",
            count(self::$failures), self::$passed + count(self::$failures));
        foreach (self::$failures as $f) { printf("    • %s\n", $f); }
        echo "\n";
        return 1;
    }
}
