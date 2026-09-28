<?php
/**
 * ⛔ گزارش‌های فروشگاه — فقط خواندن، فقط `biz_*`.
 *
 * هر عدد از **اسنادِ صادرشده** ساخته می‌شود (پیش‌نویس و باطل هیچ‌جا
 * نمی‌آیند) و فروش همیشه **خالصِ برگشت** است: برگشت از فروش هم از درآمد
 * کم می‌شود هم از بهای تمام‌شده. سودِ ناخالص = فروشِ خالص − بهای تمام‌شده،
 * و بها همان `unit_cost`ی است که هنگامِ صدور روی ردیف نوشته شد — پس سودِ
 * ماهِ گذشته با خریدِ امروز عوض نمی‌شود.
 *
 * مبلغ‌ها تومان‌اند. هیچ عددی از دفترِ شخصی خوانده نمی‌شود (قاعده ۷۰).
 */

require_once __DIR__ . '/biz_catalog.php';

final class BizReports
{
    /** ⛔ تنها مرجعِ بازه‌ها؛ ناشناخته → «این ماه». */
    public const PERIODS = [
        'today'      => 'امروز',
        'week'       => 'این هفته',
        'month'      => 'این ماه',
        'last_month' => 'ماهِ قبل',
        'year'       => 'امسال',
        'custom'     => 'بازه‌ی دلخواه',
    ];

    /** @return array{0:string,1:string} [از, تا] میلادی */
    public static function range(string $p, string $from = '', string $to = ''): array
    {
        $today = today();
        switch ($p) {
            case 'today': return [$today, $today];
            case 'week':  return [startOfWeek(), $today];
            case 'year':  return [startOfJalaliYear(), $today];
            case 'last_month':
                $start = startOfJalaliMonth();
                $end   = date('Y-m-d', strtotime($start . ' -1 day'));
                [$y, $m, $d] = array_map('intval', explode('-', $end));
                [$jy, $jm, ] = gregorianToJalali($y, $m, $d);
                [$gy, $gm, $gd] = jalaliToGregorian($jy, $jm, 1);
                return [sprintf('%04d-%02d-%02d', $gy, $gm, $gd), $end];
            case 'custom':
                if (isValidDate($from) && isValidDate($to)) { return $from <= $to ? [$from, $to] : [$to, $from]; }
                return [startOfJalaliMonth(), $today];
            default: return [startOfJalaliMonth(), $today];
        }
    }

    /**
     * جمعِ فروش در بازه.
     * @return array{sales:int, returns:int, net:int, cogs:int, gross:int, margin:?float, docs:int, avg:int}
     */
    public static function sales(int $userId, string $from, string $to): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT i.kind, COALESCE(SUM(l.net_total), 0) AS rev,
                    COALESCE(SUM(ROUND(COALESCE(l.unit_cost, 0) * l.qty)), 0) AS cost,
                    COUNT(DISTINCT i.id) AS docs
             FROM biz_invoices i JOIN biz_invoice_lines l ON l.invoice_id = i.id AND l.user_id = i.user_id
             WHERE i.user_id = :u AND i.status = 'issued' AND i.kind IN ('sale','sale_return')
               AND i.inv_date BETWEEN :f AND :t
             GROUP BY i.kind"
        );
        $st->execute(['u' => $userId, 'f' => $from, 't' => $to]);
        $r = ['sale' => ['rev' => 0, 'cost' => 0, 'docs' => 0], 'sale_return' => ['rev' => 0, 'cost' => 0, 'docs' => 0]];
        foreach ($st->fetchAll() as $row) { $r[$row['kind']] = ['rev' => (int)$row['rev'], 'cost' => (int)$row['cost'], 'docs' => (int)$row['docs']]; }
        $net   = $r['sale']['rev'] - $r['sale_return']['rev'];
        $cogs  = $r['sale']['cost'] - $r['sale_return']['cost'];
        $gross = $net - $cogs;
        return [
            'sales' => $r['sale']['rev'], 'returns' => $r['sale_return']['rev'], 'net' => $net, 'cogs' => $cogs,
            'gross' => $gross, 'margin' => $net > 0 ? round($gross / $net * 100, 1) : null,
            'docs' => $r['sale']['docs'], 'avg' => $r['sale']['docs'] > 0 ? (int)round($r['sale']['rev'] / $r['sale']['docs']) : 0,
        ];
    }

    /** فروشِ خالصِ هر روز (برای نمودارِ میله‌ای) — روزهای بی‌فروش هم صفر می‌آیند. @return array<string,int> */
    public static function daily(int $userId, string $from, string $to): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT inv_date, SUM(CASE WHEN kind = 'sale' THEN total ELSE -total END) AS v
             FROM biz_invoices WHERE user_id = :u AND status = 'issued' AND kind IN ('sale','sale_return')
               AND inv_date BETWEEN :f AND :t GROUP BY inv_date"
        );
        $st->execute(['u' => $userId, 'f' => $from, 't' => $to]);
        $map = [];
        foreach ($st->fetchAll() as $r) { $map[(string)$r['inv_date']] = (int)$r['v']; }
        $out = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $out[$d] = $map[$d] ?? 0; }
        return $out;
    }

    /** پرفروش‌ترین‌ها — خالصِ برگشت، با سودِ هر کدام. */
    public static function topProducts(int $userId, string $from, string $to, int $limit = 10): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT COALESCE(p.name, l.description) AS name, l.product_id, l.unit,
                    SUM(CASE WHEN i.kind = 'sale' THEN l.qty ELSE -l.qty END) AS qty,
                    SUM(CASE WHEN i.kind = 'sale' THEN l.net_total ELSE -l.net_total END) AS rev,
                    SUM(CASE WHEN i.kind = 'sale' THEN 1 ELSE -1 END * ROUND(COALESCE(l.unit_cost, 0) * l.qty)) AS cost
             FROM biz_invoice_lines l
             JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
             LEFT JOIN biz_products p ON p.id = l.product_id AND p.user_id = l.user_id
             WHERE l.user_id = :u AND i.status = 'issued' AND i.kind IN ('sale','sale_return') AND i.inv_date BETWEEN :f AND :t
             GROUP BY l.product_id, COALESCE(p.name, l.description), l.unit
             ORDER BY rev DESC LIMIT :lim"
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('f', $from);
        $st->bindValue('t', $to);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return array_map(fn($r) => $r + ['profit' => (int)$r['rev'] - (int)$r['cost']], $st->fetchAll());
    }

    /** فروش و سود به تفکیکِ دسته — «بی‌دسته» جدا. */
    public static function byCategory(int $userId, string $from, string $to): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT c.name AS cat,
                    SUM(CASE WHEN i.kind = 'sale' THEN l.net_total ELSE -l.net_total END) AS rev,
                    SUM(CASE WHEN i.kind = 'sale' THEN 1 ELSE -1 END * ROUND(COALESCE(l.unit_cost, 0) * l.qty)) AS cost
             FROM biz_invoice_lines l
             JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
             LEFT JOIN biz_products p ON p.id = l.product_id AND p.user_id = l.user_id
             LEFT JOIN biz_categories c ON c.id = p.category_id AND c.user_id = l.user_id
             WHERE l.user_id = :u AND i.status = 'issued' AND i.kind IN ('sale','sale_return') AND i.inv_date BETWEEN :f AND :t
             GROUP BY c.name ORDER BY rev DESC"
        );
        $st->execute(['u' => $userId, 'f' => $from, 't' => $to]);
        return array_map(fn($r) => $r + ['profit' => (int)$r['rev'] - (int)$r['cost']], $st->fetchAll());
    }

    /** جمعِ هر نوعِ دریافت/پرداخت در بازه. @return array<string,int> */
    public static function cash(int $userId, string $from, string $to): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT kind, SUM(amount) AS s FROM biz_payments WHERE user_id = :u AND status = 'ok'
               AND pay_date BETWEEN :f AND :t GROUP BY kind"
        );
        $st->execute(['u' => $userId, 'f' => $from, 't' => $to]);
        $out = ['receipt' => 0, 'payment' => 0, 'expense' => 0, 'income' => 0, 'transfer' => 0];
        foreach ($st->fetchAll() as $r) { $out[(string)$r['kind']] = (int)$r['s']; }
        return $out;
    }

    /** بزرگ‌ترین هزینه‌ها بر اساسِ شرح. */
    public static function expenses(int $userId, string $from, string $to, int $limit = 8): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT COALESCE(title, 'بی‌شرح') AS title, SUM(amount) AS s, COUNT(*) AS n FROM biz_payments
             WHERE user_id = :u AND status = 'ok' AND kind = 'expense' AND pay_date BETWEEN :f AND :t
             GROUP BY COALESCE(title, 'بی‌شرح') ORDER BY s DESC LIMIT :lim"
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('f', $from);
        $st->bindValue('t', $to);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /**
     * سنِ طلب — فاکتورهای فروشِ تسویه‌نشده به تفکیکِ روزهای گذشته از
     * سررسید (یا اگر سررسید ندارد، از تاریخِ فاکتور).
     * @return array{buckets:array<string,array{n:int,s:int}>, total:int}
     */
    public static function aging(int $userId): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT DATEDIFF(CURDATE(), COALESCE(due_date, inv_date)) AS age, total - paid AS due
             FROM biz_invoices WHERE user_id = :u AND status = 'issued' AND kind = 'sale' AND paid < total"
        );
        $st->execute(['u' => $userId]);
        $b = ['0-30' => ['n' => 0, 's' => 0], '31-60' => ['n' => 0, 's' => 0], '61-90' => ['n' => 0, 's' => 0], '90+' => ['n' => 0, 's' => 0]];
        $total = 0;
        foreach ($st->fetchAll() as $r) {
            $a = max(0, (int)$r['age']);
            $k = $a <= 30 ? '0-30' : ($a <= 60 ? '31-60' : ($a <= 90 ? '61-90' : '90+'));
            $b[$k]['n']++; $b[$k]['s'] += (int)$r['due']; $total += (int)$r['due'];
        }
        return ['buckets' => $b, 'total' => $total];
    }

    /** برچسبِ ستونِ سنِ طلب. */
    public const AGING_LABELS = ['0-30' => 'تا ۳۰ روز', '31-60' => '۳۱ تا ۶۰', '61-90' => '۶۱ تا ۹۰', '90+' => 'بیش از ۹۰ روز'];
}
