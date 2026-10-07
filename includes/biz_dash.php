<?php
/**
 * ⛔ داده‌ی داشبوردِ فروشگاه — فقط خواندن، فقط `biz_*` (قاعده ۷۰).
 *
 * **خواسته‌ی مالکِ نصب:** «کاربر در ۵ ثانیه وضعیتِ مالیِ کسب‌وکار را
 * بفهمد.» هر تابعِ این فایل یک کوئری است و هر عدد از همان تعریف‌هایی
 * می‌آید که بقیه‌ی فروشگاه دارد: فروش خالصِ برگشت و فقط صادرشده
 * (`BizReports`)، مانده‌ی طرف‌حساب از `BizParties::BALANCE_SQL`، موجودیِ
 * صندوق از `BizCash::balanceSql()`. **هیچ عددی اینجا دوباره تعریف نمی‌شود**
 * جز دو مفهومِ تازه‌ی داشبورد، که هر دو همین‌جا مستندند:
 *
 * ۱. **جریانِ نقد** (`cashDaily()`): پولی که واقعاً وارد یا خارجِ صندوق‌های
 *    نقدی شد. ⛔ چک تا وصول نقد نیست — دریافت/پرداختِ چکی در روزِ ثبت
 *    شمرده نمی‌شود و انتقالِ وصولش (صندوقِ چک ↔ بانک) در روزِ وصول شمرده
 *    می‌شود. انتقال بینِ دو صندوقِ نقدی جریان نیست (پول از جیبی به جیبی
 *    رفته).
 * ۲. **وضعیتِ طلب/بدهی** (`ageState()`): فاکتور سررسید ندارد، پس سن از
 *    قدیمی‌ترین فاکتورِ تسویه‌نشده‌ی طرف‌حساب شمرده می‌شود با مهلتِ
 *    فرضیِ `CREDIT_DAYS`. برچسب‌ها همان خواسته‌اند: سررسید نشده، نزدیک
 *    سررسید، سررسید شده، معوق.
 */

require_once __DIR__ . '/biz_reports.php';

final class BizDash
{
    /** ⛔ مهلتِ فرضیِ نسیه (روز) — تنها مرجعِ «سررسید» روی داشبورد. */
    public const CREDIT_DAYS = 30;
    /** چند روز پیش از مهلت «نزدیک سررسید» است. */
    public const NEAR_DAYS = 7;
    /** بعد از چند روز «معوق» است. */
    public const LATE_DAYS = 60;

    /** ⛔ بازه‌های نمودارِ جریانِ نقد — تنها مرجع (`?cf=`). */
    public const CF_RANGES = ['7' => '۷ روز', '30' => '۳۰ روز', '90' => '۳ ماه', '180' => '۶ ماه', '365' => '۱ سال'];
    /** ⛔ دانه‌بندیِ نمودارِ درآمد و هزینه (`?ie=`). */
    public const IE_GRAINS = ['w' => 'هفتگی', 'm' => 'ماهانه', 'y' => 'سالانه'];

    public const MONTHS = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    public const AGE_LABELS = [
        'ok'   => 'سررسید نشده',
        'near' => 'نزدیک سررسید',
        'due'  => 'سررسید شده',
        'late' => 'معوق',
    ];

    /** @return array{0:string,1:string} [ok|near|due|late, برچسب] */
    public static function ageState(?string $oldest, ?string $today = null): array
    {
        if ($oldest === null || $oldest === '') { return ['ok', self::AGE_LABELS['ok']]; }
        $today = $today ?? today();
        $age = (int)floor((strtotime($today) - strtotime($oldest)) / 86400);
        $k = $age > self::LATE_DAYS ? 'late' : ($age > self::CREDIT_DAYS ? 'due' : ($age > self::CREDIT_DAYS - self::NEAR_DAYS ? 'near' : 'ok'));
        return [$k, self::AGE_LABELS[$k]];
    }

    /** ⛔ «در دفترِ نقد هست؟» — صندوقِ چک نقد نیست. */
    private const NOT_CHEQUE = "NOT IN ('cheque_in','cheque_out')";

    /**
     * جریانِ نقد و هزینه/درآمدِ متفرقه به تفکیکِ روز.
     * @return array<string,array{in:int,out:int,exp:int,inc:int}> کلید = تاریخِ میلادی (فقط روزهای دارای ردیف)
     */
    public static function cashDaily(int $userId, string $from, string $to): array
    {
        $nc = self::NOT_CHEQUE;
        $in = BizPay::sqlList(BizPay::IN_KINDS); $out = BizPay::sqlList(BizPay::OUT_KINDS);
        $st = Database::getConnection()->prepare(
            "SELECT y.pay_date AS d,
                SUM(CASE WHEN y.kind IN ({$in}) AND a.kind {$nc} THEN y.amount
                         WHEN y.kind = 'transfer' AND a.kind = 'cheque_in' AND t.kind {$nc} THEN y.amount ELSE 0 END) AS cin,
                SUM(CASE WHEN y.kind IN ({$out}) AND a.kind {$nc} THEN y.amount
                         WHEN y.kind = 'transfer' AND t.kind = 'cheque_out' AND a.kind {$nc} THEN y.amount ELSE 0 END) AS cout,
                SUM(CASE WHEN y.kind = 'expense' THEN y.amount ELSE 0 END) AS exp,
                SUM(CASE WHEN y.kind = 'income' THEN y.amount ELSE 0 END) AS inc
             FROM biz_payments y
             JOIN biz_accounts a ON a.id = y.account_id AND a.user_id = y.user_id
             LEFT JOIN biz_accounts t ON t.id = y.to_account_id AND t.user_id = y.user_id
             WHERE y.user_id = :u AND y.status = 'ok' AND y.pay_date BETWEEN :f AND :t
             GROUP BY y.pay_date"
        );
        $st->execute(['u' => $userId, 'f' => $from, 't' => $to]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(string)$r['d']] = ['in' => (int)$r['cin'], 'out' => (int)$r['cout'], 'exp' => (int)$r['exp'], 'inc' => (int)$r['inc']];
        }
        return $out;
    }

    /**
     * فروشِ خالص، بهای تمام‌شده و `other` (کسریِ انبار + خریدِ بی‌انبار) به
     * تفکیکِ روز — همان تعریف‌های `BizReports::sales()`، در **یک** کوئری.
     * @return array<string,array{rev:int,cost:int,other:int}>
     */
    public static function salesDaily(int $userId, string $from, string $to): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT d, SUM(rev) AS rev, SUM(cost) AS cost, SUM(other) AS other FROM (
                SELECT i.inv_date AS d,
                       SUM(CASE WHEN i.kind = 'sale' THEN l.net_total ELSE -l.net_total END) AS rev,
                       SUM(CASE WHEN i.kind = 'sale' THEN 1 ELSE -1 END * ROUND(COALESCE(l.unit_cost, 0) * l.qty)) AS cost,
                       0 AS other
                FROM biz_invoices i JOIN biz_invoice_lines l ON l.invoice_id = i.id AND l.user_id = i.user_id
                WHERE i.user_id = :u AND i.status = 'issued' AND i.kind IN ('sale','sale_return')
                  AND i.inv_date BETWEEN :f AND :t
                GROUP BY i.inv_date
                UNION ALL
                SELECT m.move_date, 0, 0, -SUM(ROUND(m.qty * COALESCE(m.unit_cost, p.avg_cost)))
                FROM biz_stock_moves m JOIN biz_products p FORCE INDEX (PRIMARY) ON p.id = m.product_id AND p.user_id = m.user_id
                WHERE m.user_id = :u2 AND m.kind = 'adjust' AND m.move_date BETWEEN :f2 AND :t2
                GROUP BY m.move_date
                UNION ALL
                SELECT i.inv_date, 0, 0, SUM(CASE WHEN i.kind = 'purchase' THEN l.net_total ELSE -l.net_total END)
                FROM biz_invoices i JOIN biz_invoice_lines l ON l.invoice_id = i.id AND l.user_id = i.user_id
                LEFT JOIN biz_products p FORCE INDEX (PRIMARY) ON p.id = l.product_id AND p.user_id = l.user_id
                WHERE i.user_id = :u3 AND i.status = 'issued' AND i.kind IN ('purchase','purchase_return')
                  AND i.inv_date BETWEEN :f3 AND :t3 AND (l.product_id IS NULL OR p.track_stock = 0)
                GROUP BY i.inv_date
             ) x GROUP BY d"
        );
        $st->execute(['u' => $userId, 'f' => $from, 't' => $to, 'u2' => $userId, 'f2' => $from, 't2' => $to,
                      'u3' => $userId, 'f3' => $from, 't3' => $to]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(string)$r['d']] = ['rev' => (int)$r['rev'], 'cost' => (int)$r['cost'], 'other' => (int)$r['other']];
        }
        return $out;
    }

    /**
     * تغییرِ امروزِ هر صندوق (`BizCash::balanceSql()` برای یک روز).
     * @return array<int,int> شناسه‌ی صندوق ← تغییر
     */
    public static function todayByAccount(int $userId, string $today): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT account_id, to_account_id, kind, SUM(amount) AS s FROM biz_payments
             WHERE user_id = :u AND status = 'ok' AND pay_date = :d GROUP BY account_id, to_account_id, kind"
        );
        $st->execute(['u' => $userId, 'd' => $today]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $a = (int)$r['account_id']; $s = (int)$r['s'];
            if (in_array($r['kind'], BizPay::IN_KINDS, true)) { $out[$a] = ($out[$a] ?? 0) + $s; }
            elseif (in_array($r['kind'], BizPay::OUT_KINDS, true)) { $out[$a] = ($out[$a] ?? 0) - $s; }
            elseif ($r['kind'] === 'transfer') {
                $out[$a] = ($out[$a] ?? 0) - $s;
                $t = (int)$r['to_account_id']; if ($t > 0) { $out[$t] = ($out[$t] ?? 0) + $s; }
            }
        }
        return $out;
    }

    /**
     * ⛔ دفترِ طرف‌حساب‌ها برای داشبورد — **یک** بار مانده‌ی همه، نه سه بار.
     *
     * پیش از این داشبورد `BizParties::summary()` و دو بار `balances()` (طلب و
     * بدهی) را جدا صدا می‌زد و هر سه `BALANCE_SQL` را روی **همه‌ی**
     * طرف‌حساب‌ها اجرا می‌کردند (هر کدام دو زیرکوئریِ همبسته به‌ازای هر ردیف).
     * اینجا مانده یک بار خوانده می‌شود، خلاصه و پنج‌تای بزرگِ هر سو در PHP از
     * رویش ساخته می‌شوند، و «قدیمی‌ترین فاکتورِ تسویه‌نشده» فقط برای همان
     * چند ردیفِ منتخب — با یک کوئریِ گروهی. خلاصه همان تعریفِ
     * `BizParties::summary()` است (تست برابری را می‌سنجد).
     *
     * @return array{summary: array{count:int, receivable:int, payable:int, debtors:int, creditors:int},
     *               debtors: list<array>, creditors: list<array>}
     */
    public static function partyBook(int $userId, int $limit = 5): array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare(
            'SELECT p.id, p.name, p.phone, ' . BizParties::BALANCE_SQL . ' AS balance
             FROM biz_parties p WHERE p.user_id = :u AND p.is_active = 1'
        );
        $st->execute(['u' => $userId]);
        $sum = ['count' => 0, 'receivable' => 0, 'payable' => 0, 'debtors' => 0, 'creditors' => 0];
        $deb = []; $cre = [];
        foreach ($st->fetchAll() as $r) {
            $r['balance'] = (int)$r['balance'];
            $sum['count']++;
            if ($r['balance'] > 0) { $sum['receivable'] += $r['balance']; $sum['debtors']++; $deb[] = $r; }
            elseif ($r['balance'] < 0) { $sum['payable'] -= $r['balance']; $sum['creditors']++; $cre[] = $r; }
        }
        // همان ترتیبِ قبلی: بزرگ‌ترین قدرِ مطلق، و در تساوی شناسه‌ی کوچک‌تر
        usort($deb, fn($a, $b) => [$b['balance'], $a['id']] <=> [$a['balance'], $b['id']]);
        usort($cre, fn($a, $b) => [$a['balance'], $a['id']] <=> [$b['balance'], $b['id']]);
        $deb = array_slice($deb, 0, $limit);
        $cre = array_slice($cre, 0, $limit);

        $ids = array_merge(array_column($deb, 'id'), array_column($cre, 'id'));
        $old = [];
        if ($ids) {
            $in = []; $params = ['u' => $userId];
            foreach (array_values($ids) as $i => $id) { $in[] = ':p' . $i; $params['p' . $i] = (int)$id; }
            $q = $pdo->prepare(
                "SELECT party_id, kind, MIN(inv_date) AS oldest FROM biz_invoices
                 WHERE user_id = :u AND status = 'issued' AND kind IN ('sale','purchase') AND paid < total
                   AND party_id IN (" . implode(',', $in) . ')
                 GROUP BY party_id, kind'
            );
            $q->execute($params);
            foreach ($q->fetchAll() as $o) { $old[(int)$o['party_id'] . ':' . $o['kind']] = (string)$o['oldest']; }
        }
        foreach ($deb as &$r) { $r['oldest'] = $old[(int)$r['id'] . ':sale'] ?? null; }
        unset($r);
        foreach ($cre as &$r) { $r['oldest'] = $old[(int)$r['id'] . ':purchase'] ?? null; }
        unset($r);
        return ['summary' => $sum, 'debtors' => $deb, 'creditors' => $cre];
    }

    /**
     * شمارِ فاکتورهای فروشِ تسویه‌نشده، نیمه‌پرداخت، و معوق — برای «نیاز به توجه».
     * @return array{open:int, open_sum:int, partial:int, late:int, late_sum:int, drafts:int}
     */
    public static function invoiceFlags(int $userId): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT COALESCE(SUM(status = 'issued' AND kind = 'sale' AND paid < total), 0) AS open_n,
                    COALESCE(SUM(CASE WHEN status = 'issued' AND kind = 'sale' AND paid < total THEN total - paid ELSE 0 END), 0) AS open_s,
                    COALESCE(SUM(status = 'issued' AND kind = 'sale' AND paid > 0 AND paid < total), 0) AS part_n,
                    COALESCE(SUM(status = 'issued' AND kind = 'sale' AND paid < total AND inv_date < DATE_SUB(CURDATE(), INTERVAL :late DAY)), 0) AS late_n,
                    COALESCE(SUM(CASE WHEN status = 'issued' AND kind = 'sale' AND paid < total AND inv_date < DATE_SUB(CURDATE(), INTERVAL :late2 DAY) THEN total - paid ELSE 0 END), 0) AS late_s,
                    COALESCE(SUM(status = 'draft'), 0) AS drafts
             FROM biz_invoices WHERE user_id = :u"
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('late', self::LATE_DAYS, PDO::PARAM_INT);
        $st->bindValue('late2', self::LATE_DAYS, PDO::PARAM_INT);
        $st->execute();
        $r = $st->fetch() ?: [];
        return ['open' => (int)($r['open_n'] ?? 0), 'open_sum' => (int)($r['open_s'] ?? 0), 'partial' => (int)($r['part_n'] ?? 0),
                'late' => (int)($r['late_n'] ?? 0), 'late_sum' => (int)($r['late_s'] ?? 0), 'drafts' => (int)($r['drafts'] ?? 0)];
    }

    /**
     * خلاصه‌ی انبار + گوشی‌های موجود (کالای سریال‌دار) در یک کوئری.
     * ⚠ روی نصبی که `has_serial` نیامده، همان `BizProducts::summary()` با گوشیِ صفر.
     * @return array{count:int, value:int, low:int, out:int, phones:int, phone_value:int}
     */
    public static function stock(int $userId): array
    {
        try {
            $st = Database::getConnection()->prepare(
                'SELECT COUNT(*) AS cnt,
                        COALESCE(SUM(CASE WHEN track_stock = 1 AND stock_qty > 0 THEN stock_qty * avg_cost ELSE 0 END), 0) AS val,
                        COALESCE(SUM(track_stock = 1 AND min_stock > 0 AND stock_qty <= min_stock), 0) AS low,
                        COALESCE(SUM(track_stock = 1 AND stock_qty <= 0), 0) AS outq,
                        COALESCE(SUM(CASE WHEN has_serial = 1 AND stock_qty > 0 THEN stock_qty ELSE 0 END), 0) AS ph,
                        COALESCE(SUM(CASE WHEN has_serial = 1 AND stock_qty > 0 THEN stock_qty * avg_cost ELSE 0 END), 0) AS phv
                 FROM biz_products WHERE user_id = :u AND is_active = 1'
            );
            $st->execute(['u' => $userId]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '42S22') { throw $e; }
            return BizProducts::summary($userId) + ['phones' => 0, 'phone_value' => 0];
        }
        $r = $st->fetch() ?: [];
        return ['count' => (int)($r['cnt'] ?? 0), 'value' => (int)round((float)($r['val'] ?? 0)), 'low' => (int)($r['low'] ?? 0),
                'out' => (int)($r['outq'] ?? 0), 'phones' => (int)round((float)($r['ph'] ?? 0)), 'phone_value' => (int)round((float)($r['phv'] ?? 0))];
    }

    /** کالای راکد: موجودی دارد ولی در `$days` روزِ گذشته فروش نرفته — به ترتیبِ ارزشِ خوابیده. */
    public static function stale(int $userId, int $days = 30, int $limit = 5): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT p.id, p.name, p.unit, p.stock_qty, ROUND(p.stock_qty * p.avg_cost) AS val
             FROM biz_products p
             WHERE p.user_id = :u AND p.is_active = 1 AND p.track_stock = 1 AND p.stock_qty > 0
               AND NOT EXISTS (SELECT 1 FROM biz_invoice_lines l JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
                               WHERE l.user_id = p.user_id AND l.product_id = p.id AND i.kind = 'sale' AND i.status = 'issued'
                                 AND i.inv_date >= DATE_SUB(CURDATE(), INTERVAL :d DAY))
             ORDER BY val DESC, p.id LIMIT :lim"
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('d', $days, PDO::PARAM_INT);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** بهترین مشتری‌ها در بازه — فروشِ خالص، فقط صادرشده. */
    public static function topCustomers(int $userId, string $from, string $to, int $limit = 5): array
    {
        $st = Database::getConnection()->prepare(
            // ⛔ بی‌مالیات — همان «فروشِ خالص»ِ `BizReports::sales()`
            "SELECT p.id, p.name, SUM(CASE WHEN i.kind = 'sale' THEN 1 ELSE -1 END * (i.total" . (Biz::accReady() ? ' - i.tax_total' : '') . ")) AS rev, SUM(i.kind = 'sale') AS docs
             FROM biz_invoices i JOIN biz_parties p ON p.id = i.party_id AND p.user_id = i.user_id
             WHERE i.user_id = :u AND i.status = 'issued' AND i.kind IN ('sale','sale_return') AND i.inv_date BETWEEN :f AND :t
             GROUP BY p.id, p.name HAVING rev > 0 ORDER BY rev DESC LIMIT :lim"
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('f', $from);
        $st->bindValue('t', $to);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** آخرین دریافت/پرداخت‌ها با صندوق و طرف‌حساب. */
    public static function recentPayments(int $userId, int $limit = 8): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT y.*, p.name AS party_name, a.name AS account_name, a.kind AS account_kind, t.name AS to_account_name
             FROM biz_payments y
             LEFT JOIN biz_parties p ON p.id = y.party_id AND p.user_id = y.user_id
             LEFT JOIN biz_accounts a ON a.id = y.account_id AND a.user_id = y.user_id
             LEFT JOIN biz_accounts t ON t.id = y.to_account_id AND t.user_id = y.user_id
             WHERE y.user_id = :u ORDER BY y.pay_date DESC, y.id DESC LIMIT :lim'
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /* ------------------------------------------------------------
       ابزارِ عدد — خالص، بی‌دیتابیس (tests/test_store_dash.php)
       ------------------------------------------------------------ */

    /**
     * تغییر نسبت به دوره‌ی قبل. ⛔ دوره‌ی قبلِ صفر درصد ندارد (نه «۱۰۰٪
     * بیشتر»ِ دروغ) و تغییرِ زیرِ نیم درصد «بی‌تغییر» است.
     * @return array{pct:?float, dir:string} dir = up|down|flat|none
     */
    public static function change(int $cur, int $prev): array
    {
        if ($prev === 0) { return ['pct' => null, 'dir' => $cur === 0 ? 'flat' : 'none']; }
        $pct = round(($cur - $prev) / abs($prev) * 100, 1);
        return ['pct' => $pct, 'dir' => abs($pct) < 0.5 ? 'flat' : ($pct > 0 ? 'up' : 'down')];
    }

    /**
     * بازه‌ی «همین مدت در ماهِ قبل» برای مقایسه‌ی منصفانه (روزِ ۱۰ با روزِ ۱۰).
     * @return array{0:string,1:string}
     */
    public static function prevSamePeriod(string $monthStart, string $today): array
    {
        [$pf, $pt] = BizReports::range('last_month');
        $days = (int)floor((strtotime($today) - strtotime($monthStart)) / 86400);
        $end  = date('Y-m-d', strtotime($pf . ' +' . $days . ' days'));
        return [$pf, min($end, $pt)];
    }

    /** جمعِ یک ستون از سریِ روزانه در بازه. */
    public static function sum(array $series, string $key, string $from, string $to): int
    {
        $s = 0;
        foreach ($series as $d => $v) { if ($d >= $from && $d <= $to) { $s += (int)($v[$key] ?? 0); } }
        return $s;
    }

    /**
     * سریِ روزانه‌ی کامل (روزهای بی‌ردیف صفر) برای یک کلید یا تابع.
     * @return array<string,int>
     */
    public static function fill(array $series, string $from, string $to, callable $pick): array
    {
        $out = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $out[$d] = (int)$pick($series[$d] ?? null);
        }
        return $out;
    }

    /**
     * گروه‌بندیِ سریِ روزانه به هفته/ماه/سالِ **شمسی**.
     * @param array<string,array<string,int>> $series
     * @return array<string,array{label:string, v:array<string,int>}> به ترتیبِ زمان
     */
    public static function bucket(array $series, string $from, string $to, string $grain): array
    {
        $out = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            [$y, $m, $dd] = array_map('intval', explode('-', $d));
            [$jy, $jm, $jd] = gregorianToJalali($y, $m, $dd);
            if ($grain === 'y') { $key = (string)$jy; $label = toPersianDigits((string)$jy); }
            elseif ($grain === 'w') {
                // هفته از شنبه — کلید = تاریخِ شنبه‌ی همان هفته
                $w = ((int)date('w', strtotime($d)) + 1) % 7;
                $sat = date('Y-m-d', strtotime($d . ' -' . $w . ' days'));
                [$sy, $sm, $sd] = array_map('intval', explode('-', $sat));
                [, $sjm, $sjd] = gregorianToJalali($sy, $sm, $sd);
                $key = $sat; $label = toPersianDigits($sjd . '/' . $sjm);
            } else { $key = sprintf('%04d-%02d', $jy, $jm); $label = self::MONTHS[$jm]; }
            if (!isset($out[$key])) { $out[$key] = ['label' => $label, 'v' => []]; }
            foreach ((array)($series[$d] ?? []) as $k => $v) { $out[$key]['v'][$k] = ($out[$key]['v'][$k] ?? 0) + (int)$v; }
        }
        return $out;
    }
}

/**
 * ⛔ نمودارهای داشبورد — SVGِ سمتِ سرور، بی‌کتابخانه و بی‌جاوااسکریپت.
 *    `store.js` فقط راهنمای روی نقطه را اضافه می‌کند (`data-tip`). محورِ
 *    زمان همیشه چپ‌به‌راست است (`.st-chart { direction: ltr }`).
 *    ⛔ هر متنی که از کاربر می‌آید با `h()` در `data-tip` می‌نشیند.
 */
final class BizChart
{
    /** مینی‌نمودار (KPI). */
    public static function spark(array $values, string $dir = ''): string
    {
        $values = array_values($values);
        $n = count($values);
        if ($n < 2) { return ''; }
        $w = 72; $h = 24;
        $min = min($values); $max = max($values);
        $span = $max - $min ?: 1;
        $pts = [];
        foreach ($values as $i => $v) {
            $pts[] = round($i / ($n - 1) * $w, 1) . ',' . round($h - 2 - ($v - $min) / $span * ($h - 4), 1);
        }
        $line = 'M' . implode(' L', $pts);
        $area = $line . ' L' . $w . ',' . $h . ' L0,' . $h . ' Z';
        $cls = $dir === 'up' ? ' is-up' : ($dir === 'down' ? ' is-down' : '');
        return '<svg class="st-spark' . $cls . '" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
            . '<path class="is-area" d="' . $area . '"/><path d="' . $line . '"/></svg>';
    }

    /** برچسبِ کوتاهِ مبلغ برای محور: ۱٫۲م، ۳۵۰ه. */
    public static function short(int $v): string
    {
        $a = abs($v); $s = $v < 0 ? '−' : '';
        if ($a >= 1e9) { $t = rtrim(rtrim(number_format($a / 1e9, 1, '.', ''), '0'), '.') . 'میلیارد'; }
        elseif ($a >= 1e6) { $t = rtrim(rtrim(number_format($a / 1e6, 1, '.', ''), '0'), '.') . 'م'; }
        elseif ($a >= 1e3) { $t = round($a / 1e3) . 'ه'; }
        else { $t = (string)$a; }
        return $s . toPersianDigits(str_replace('.', '٫', $t));
    }

    /**
     * نمودارِ خطیِ ورود/خروج/خالص.
     * @param array<int,array{label:string, tip:string, in:int, out:int}> $pts
     */
    public static function flow(array $pts): string
    {
        $n = count($pts);
        if ($n === 0) { return ''; }
        $W = 640; $H = 210; $L = 44; $R = 8; $T = 10; $B = 26;
        $max = 1; $min = 0;
        foreach ($pts as $p) { $max = max($max, $p['in'], $p['out'], $p['in'] - $p['out']); $min = min($min, $p['in'] - $p['out']); }
        $y = fn(int $v): float => round($T + ($max - $v) / (($max - $min) ?: 1) * ($H - $T - $B), 1);
        $x = fn(int $i): float => round($n === 1 ? ($L + ($W - $L - $R) / 2) : ($L + $i / ($n - 1) * ($W - $L - $R)), 1);
        $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="نمودارِ جریانِ نقد">';
        foreach ([0, 0.5, 1] as $f) {
            $v = (int)round($max - $f * ($max - $min));
            $gy = $y($v);
            $svg .= '<line class="st-grid-line" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $gy . '" y2="' . $gy . '"/>'
                  . '<text class="st-axis" x="' . ($L - 6) . '" y="' . ($gy + 3) . '" text-anchor="end">' . h(self::short($v)) . '</text>';
        }
        $pin = []; $pout = []; $pnet = [];
        foreach ($pts as $i => $p) { $pin[] = $x($i) . ',' . $y($p['in']); $pout[] = $x($i) . ',' . $y($p['out']); $pnet[] = $x($i) . ',' . $y($p['in'] - $p['out']); }
        $base = $y(0);
        $svg .= '<path class="st-a-in" d="M' . implode(' L', $pin) . ' L' . $x($n - 1) . ',' . $base . ' L' . $x(0) . ',' . $base . ' Z"/>';
        $svg .= '<path class="st-l-in" d="M' . implode(' L', $pin) . '"/>';
        $svg .= '<path class="st-l-out" d="M' . implode(' L', $pout) . '"/>';
        $svg .= '<path class="st-l-net" d="M' . implode(' L', $pnet) . '"/>';
        // برچسبِ محورِ زمان — حداکثر ۶ تا
        $step = max(1, (int)ceil($n / 6));
        foreach ($pts as $i => $p) {
            if ($i % $step !== 0 && $i !== $n - 1) { continue; }
            $svg .= '<text class="st-axis" x="' . $x($i) . '" y="' . ($H - 8) . '" text-anchor="middle">' . h($p['label']) . '</text>';
        }
        $cw = ($W - $L - $R) / max(1, $n - 1);
        foreach ($pts as $i => $p) {
            $cx = $x($i);
            $svg .= '<rect class="st-hit" x="' . round($cx - $cw / 2, 1) . '" y="' . $T . '" width="' . round(max(4, $cw), 1) . '" height="' . ($H - $T - $B) . '" data-tip="' . h($p['tip']) . '"/>'
                  . '<line class="st-guide" x1="' . $cx . '" x2="' . $cx . '" y1="' . $T . '" y2="' . ($H - $B) . '"/>';
        }
        return $svg . '</svg>';
    }

    /**
     * نمودارِ ستونیِ دوتایی (درآمد در برابرِ هزینه).
     * @param array<int,array{label:string, tip:string, a:int, b:int}> $pts
     */
    public static function bars(array $pts): string
    {
        $n = count($pts);
        if ($n === 0) { return ''; }
        $W = 640; $H = 210; $L = 44; $R = 8; $T = 10; $B = 26;
        $max = 1;
        foreach ($pts as $p) { $max = max($max, $p['a'], $p['b']); }
        $h = fn(int $v): float => round(max(0, $v) / $max * ($H - $T - $B), 1);
        $slot = ($W - $L - $R) / $n;
        $bw = max(2, min(18, $slot * 0.32));
        $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="نمودارِ درآمد و هزینه">';
        foreach ([0, 0.5, 1] as $f) {
            $gy = round($T + $f * ($H - $T - $B), 1);
            $svg .= '<line class="st-grid-line" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $gy . '" y2="' . $gy . '"/>'
                  . '<text class="st-axis" x="' . ($L - 6) . '" y="' . ($gy + 3) . '" text-anchor="end">' . h(self::short((int)round($max * (1 - $f)))) . '</text>';
        }
        $step = max(1, (int)ceil($n / 8));
        foreach ($pts as $i => $p) {
            $cx = $L + $slot * ($i + 0.5);
            $ha = $h($p['a']); $hb = $h($p['b']);
            $svg .= '<rect class="st-b-in" x="' . round($cx - $bw - 1, 1) . '" y="' . round($H - $B - $ha, 1) . '" width="' . round($bw, 1) . '" height="' . $ha . '" rx="2"/>'
                  . '<rect class="st-b-out" x="' . round($cx + 1, 1) . '" y="' . round($H - $B - $hb, 1) . '" width="' . round($bw, 1) . '" height="' . $hb . '" rx="2"/>';
            if ($i % $step === 0 || $i === $n - 1) {
                $svg .= '<text class="st-axis" x="' . round($cx, 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . h($p['label']) . '</text>';
            }
            $svg .= '<rect class="st-hit" x="' . round($cx - $slot / 2, 1) . '" y="' . $T . '" width="' . round($slot, 1) . '" height="' . ($H - $T - $B) . '" data-tip="' . h($p['tip']) . '"/>'
                  . '<line class="st-guide" x1="' . round($cx, 1) . '" x2="' . round($cx, 1) . '" y1="' . $T . '" y2="' . ($H - $B) . '"/>';
        }
        return $svg . '</svg>';
    }

    /**
     * دونات — قطعه‌ها به ترتیب، هر کدام کلاسِ رنگِ `st-cN`.
     * @param int[] $values
     */
    public static function donut(array $values): string
    {
        $total = array_sum($values);
        $r = 50; $c = 2 * M_PI * $r;
        $svg = '<svg class="st-donut" viewBox="0 0 132 132" aria-hidden="true"><circle class="st-donut-bg" cx="66" cy="66" r="' . $r . '"/>';
        if ($total > 0) {
            $off = 0.0;
            foreach (array_values($values) as $i => $v) {
                if ($v <= 0) { continue; }
                $len = $v / $total * $c;
                $svg .= '<circle class="st-c' . ($i + 1) . '" cx="66" cy="66" r="' . $r . '" stroke-dasharray="' . round(max(0, $len - 1.5), 2) . ' ' . round($c, 2)
                      . '" stroke-dashoffset="' . round(-$off, 2) . '" transform="rotate(-90 66 66)"/>';
                $off += $len;
            }
        }
        return $svg . '</svg>';
    }
}
