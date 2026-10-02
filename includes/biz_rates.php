<?php
/**
 * قیمتِ فروشِ کالای ارزی — لایه‌ی فروشگاهیِ نرخِ روز (`includes/rates.php`).
 *
 * **خواسته‌ی مالکِ نصب:** «در فروشگاه هم برای تعیینِ قیمتِ کالاها به‌روز
 * مورد استفاده باشه» — و پاسخش به پرسشِ «چطور»: «پایه‌ی ارزی + خودکار».
 *
 * ⛔ کالا یک قیمتِ پایه به واحدِ یک نرخ دارد (۲۵۰ دلار، ۳٫۵ گرمِ طلای ۱۸،
 *    یک سکه‌ی امامی) و درصدِ سود. قیمتِ فروش = پایه × نرخ × (۱ + سود٪)،
 *    گرد به `rate_round`ِ همان فروشگاه. `apply()` **تنها نویسنده**ی
 *    `sell_price`ِ کالای وصل‌شده است؛ با هر نرخِ تازه (`Rates::listen()`) و
 *    با هر تغییرِ وصل یا گردکردن صدا زده می‌شود.
 * ⛔ فاکتورِ صادرشده بهای خودش را در ردیف دارد و هرگز عوض نمی‌شود؛ فقط
 *    قیمتِ پیش‌فرضِ فاکتورِ **بعدی** و فروشِ سریع تازه می‌شود.
 * ⚠ این فایل جدا از `rates.php` است چون طرفِ شخصی `rates.php` را بار
 *   می‌کند و نباید جدولِ فروشگاه را بشناسد (قاعده ۷۰).
 */

require_once __DIR__ . '/rates.php';

final class BizRates
{
    /** گردکردنِ مجازِ قیمتِ ارزی (تومان). */
    public const ROUNDS = [1 => 'بدونِ گرد کردن', 1000 => 'هزار تومان', 10000 => 'ده هزار تومان', 100000 => 'صد هزار تومان'];

    /** قیمتِ فروش = پایه × نرخ × (۱ + سود٪)، گرد به `$round` (نزدیک‌ترین). */
    public static function priceFor(float $base, int $rate, float $margin, int $round): int
    {
        $round = isset(self::ROUNDS[$round]) ? $round : 1000;
        $raw = $base * $rate * (1 + $margin / 100);
        if ($raw <= 0) { return 0; }
        return (int)(round($raw / $round) * $round);
    }

    /**
     * قیمتِ فروشِ همه‌ی کالاهای وصل‌شده (یا فقط یک فروشگاه) از نرخِ امروز.
     * هر فروشگاه جدا (`WHERE user_id`)، قاعده‌ی ۱؛ فقط ردیفی که عوض شده نوشته
     * می‌شود.
     * @return int کالاهای عوض‌شده
     */
    public static function apply(?int $userId = null): int
    {
        if (!Rates::available() || !tableHasColumn('biz_products', 'rate_code')) { return 0; }
        $pdo = Database::getConnection();
        $users = $userId !== null ? [$userId]
            : $pdo->query('SELECT DISTINCT user_id FROM biz_products WHERE rate_code IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        $rates = Rates::all();
        $sel = $pdo->prepare(
            'SELECT p.id, p.rate_code, p.rate_base, p.rate_margin, p.sell_price, COALESCE(s.rate_round, 1000) AS rnd
               FROM biz_products p LEFT JOIN biz_settings s ON s.user_id = p.user_id
              WHERE p.user_id = :u AND p.rate_code IS NOT NULL AND p.rate_base > 0'
        );
        $upd = $pdo->prepare('UPDATE biz_products SET sell_price = :sp WHERE id = :id AND user_id = :u');
        $n = 0;
        foreach ($users as $u) {
            $sel->execute(['u' => (int)$u]);
            foreach ($sel->fetchAll() as $r) {
                $rate = $rates[$r['rate_code']] ?? null;
                if (!$rate) { continue; }
                $sp = self::priceFor((float)$r['rate_base'], (int)$rate['price'], (float)($r['rate_margin'] ?? 0), (int)$r['rnd']);
                if ($sp > 0 && $sp !== (int)$r['sell_price']) {
                    $upd->execute(['sp' => $sp, 'id' => (int)$r['id'], 'u' => (int)$u]);
                    $n++;
                }
            }
        }
        return $n;
    }
}

// ⛔ هر جا این لایه بار شود (cron، صفحه‌ی مدیر، فروشگاه)، نرخِ تازه به قیمتِ
//    فروش می‌رسد — `Rates` لازم نیست فروشگاه را بشناسد.
Rates::listen(static function (): void { BizRates::apply(); });
