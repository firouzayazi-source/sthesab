<?php
/**
 * ⛔ خروجیِ کالاها برای ترب و سایت‌های مقایسه‌ی قیمت (migration_biz_feed، `store/feed.php`، `store/item.php`).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** مغازه بی‌هزینه در جست‌وجوی قیمت دیده شود — فقط کالاهایی که خودش انتخاب
 * کند (دسته/نوع/فقط موجود)، و هرگز قیمتِ خرید.
 *
 * ⛔ دو آدرسِ بی‌ورود، هر دو امضاشده با نمکِ خودِ خروجی (جدا از نمکِ لیستِ قیمت و فاکتور):
 *    - `feed.php?s&t` — فهرستِ کالاها (JSON یا CSV)؛ امضا `feed:{shop}:{salt}`.
 *    - `item.php?s&p&t` — صفحه‌ی یک کالا (همان که سایتِ مقایسه به آن پیوند می‌دهد)؛ امضا `item:{shop}:{pid}:{salt}`،
 *      فقط برای کالایی که **همین حالا** در صافیِ خروجی است.
 * ⛔ هر بار از دفتر؛ هیچ عددی ذخیره نمی‌شود. فقط نام، دسته، قیمتِ فروش و «موجود/ناموجود» — نه قیمتِ خرید،
 *    نه میانگینِ بها، نه شمارِ موجودی، نه IMEI. خدمت، غیرفعال و بی‌قیمت هرگز.
 * ⛔ قالب‌بندیِ هر قلم فقط `item()` است: اگر سایتِ مقایسه نامِ فیلدِ دیگری خواست، همین یک جا عوض می‌شود.
 */

require_once __DIR__ . '/biz_catalog.php';

final class BizFeed
{
    public const MAX_ROWS = 5000;
    public const DEFAULTS = ['category' => '', 'type' => '', 'in_stock' => true, 'salt' => ''];

    public static function ready(): bool
    {
        return function_exists('tableHasColumn') && tableHasColumn('biz_settings', 'feed');
    }

    public static function config(int $userId): array
    {
        Biz::settings($userId);
        $j = json_decode(Biz::$feedRaw[$userId] ?? '', true);
        $c = self::DEFAULTS;
        if (is_array($j)) {
            $c['category'] = (string)($j['category'] ?? '');
            $c['type']     = in_array($j['type'] ?? '', ['', 'phone', 'goods'], true) ? (string)$j['type'] : '';
            $c['in_stock'] = !empty($j['in_stock']);
            $c['salt']     = (string)($j['salt'] ?? '');
        }
        return $c;
    }

    public static function on(array $cfg): bool { return $cfg['salt'] !== ''; }

    /** @return array{ok:bool, message:string} */
    public static function save(int $userId, array $in, bool $on, bool $rotate = false): array
    {
        $cur = self::config($userId);
        $type = (string)($in['type'] ?? '');
        $cfg = ['category' => BizCommon::line((string)($in['category'] ?? '')), 'type' => in_array($type, ['', 'phone', 'goods'], true) ? $type : '',
                'in_stock' => !empty($in['in_stock']),
                // ⛔ خاموش = نمکِ خالی (هر آدرسِ قبلی بسته)؛ «باطل کردن» نمکِ تازه
                'salt' => !$on ? '' : ($rotate || $cur['salt'] === '' ? bin2hex(random_bytes(8)) : $cur['salt'])];
        Database::getConnection()->prepare(
            'INSERT INTO biz_settings (user_id, shop_name, feed) VALUES (:u, \'\', :j) ON DUPLICATE KEY UPDATE feed = VALUES(feed)'
        )->execute(['u' => $userId, 'j' => json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
        Biz::forgetSettings($userId);
        Biz::$feedRaw[$userId] = json_encode($cfg, JSON_UNESCAPED_UNICODE);
        return ['ok' => true, 'message' => !$on ? 'خروجی خاموش شد؛ آدرس‌های قبلی دیگر باز نمی‌شوند.' : ($rotate ? 'آدرس‌های قبلی باطل شدند؛ آدرسِ تازه را به سایتِ مقایسه بدهید.' : 'خروجیِ کالاها ذخیره شد.')];
    }

    private static function sign(string $msg): string
    {
        $key = defined('APP_SECRET_KEY') ? (string)APP_SECRET_KEY : '';
        return substr(rtrim(strtr(base64_encode(hash_hmac('sha256', $msg, $key, true)), '+/', '-_'), '='), 0, 24);
    }

    public static function feedToken(int $userId, string $salt): string { return self::sign("feed:{$userId}:{$salt}"); }
    public static function itemToken(int $userId, int $productId, string $salt): string { return self::sign("item:{$userId}:{$productId}:{$salt}"); }

    private static function base(): string { return defined('APP_URL') ? rtrim((string)APP_URL, '/') : ''; }

    public static function feedUrl(int $userId, string $format = 'json'): string
    {
        $cfg = self::config($userId);
        return self::base() . Biz::url('feed.php') . '?' . http_build_query(['s' => $userId, 't' => self::feedToken($userId, $cfg['salt'])] + ($format === 'csv' ? ['f' => 'csv'] : []));
    }

    public static function itemUrl(int $userId, int $productId, string $salt): string
    {
        return self::base() . Biz::url('item.php') . '?' . http_build_query(['s' => $userId, 'p' => $productId, 't' => self::itemToken($userId, $productId, $salt)]);
    }

    /** ⛔ تنها سنجشِ آدرسِ خروجی. */
    public static function verifyFeed(int $userId, string $token): ?array
    {
        if ($userId <= 0 || $token === '' || !self::ready()) { return null; }
        $cfg = self::config($userId);
        return self::on($cfg) && hash_equals(self::feedToken($userId, $cfg['salt']), $token) ? $cfg : null;
    }

    /** ⛔ تنها سنجشِ صفحه‌ی کالا: امضا **و** بودنِ همین حالا در صافی. @return array{cfg:array, row:array}|null */
    public static function verifyItem(int $userId, int $productId, string $token): ?array
    {
        if ($userId <= 0 || $productId <= 0 || $token === '' || !self::ready()) { return null; }
        $cfg = self::config($userId);
        if (!self::on($cfg) || !hash_equals(self::itemToken($userId, $productId, $cfg['salt']), $token)) { return null; }
        $rows = self::rows($userId, $cfg, $productId);
        return $rows ? ['cfg' => $cfg, 'row' => $rows[0]] : null;
    }

    /**
     * ردیف‌ها — فقط شناسه، نام، دسته، قیمتِ فروش و «موجود».
     * @return list<array{id:int, name:string, category:string, price:int, available:bool}>
     */
    public static function rows(int $userId, array $cfg, int $onlyId = 0): array
    {
        $where = ['p.user_id = :u', 'p.is_active = 1', 'p.track_stock = 1', 'p.sell_price > 0'];
        $bind = ['u' => $userId];
        if ($cfg['category'] !== '') { $where[] = 'c.name = :c'; $bind['c'] = $cfg['category']; }
        if ($cfg['type'] === 'phone') { $where[] = 'p.has_serial = 1'; } elseif ($cfg['type'] === 'goods') { $where[] = 'p.has_serial = 0'; }
        if ($cfg['in_stock']) { $where[] = 'p.stock_qty > 0'; }
        if ($onlyId > 0) { $where[] = 'p.id = :id'; $bind['id'] = $onlyId; }
        $st = Database::getConnection()->prepare(
            "SELECT p.id, p.name, COALESCE(c.name, '') AS category, p.sell_price AS price, (p.stock_qty > 0) AS available
             FROM biz_products p LEFT JOIN biz_categories c ON c.id = p.category_id
             WHERE " . implode(' AND ', $where) . ' ORDER BY p.id LIMIT ' . self::MAX_ROWS
        );
        $st->execute($bind);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'category' => (string)$r['category'],
                                    'price' => (int)$r['price'], 'available' => (int)$r['available'] === 1], $st->fetchAll());
    }

    /** ⛔ تنها قالبِ یک قلم در خروجی. */
    public static function item(int $userId, array $r, string $salt): array
    {
        return ['id' => 'p' . $r['id'], 'title' => $r['name'], 'category' => $r['category'],
                'price' => $r['price'], 'price_rial' => $r['price'] * 10, 'currency' => 'IRT',
                'availability' => $r['available'] ? 'instock' : 'outofstock', 'url' => self::itemUrl($userId, $r['id'], $salt)];
    }
}
