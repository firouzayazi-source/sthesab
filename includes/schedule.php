<?php
/**
 * هسته‌ی مشترکِ سررسید — تنها جایی که «تاریخِ بعدی» حساب می‌شود.
 *
 * ⛔ چهار بخشِ اپ سررسید دارند (چک، طلب/بدهی، تراکنشِ دوره‌ای، یادآورِ
 *    دلخواه) و تا امروز هرکدام حسابِ خودش را می‌کرد. با چند پیاده‌سازی،
 *    «۳۱ فروردین + ۳ ماه» در دو صفحه دو جواب می‌داد و هیچ‌کدام هم خطا
 *    نمی‌دادند. از این به بعد همه از همین فایل رد می‌شوند.
 *
 * ⛔ و این فایل **صاحبِ پول نیست**: جدولِ چک و بدهی و تراکنشِ دوره‌ای
 *    مالکِ مبلغ و تاریخِ خودشان می‌مانند و `reminders` فقط با
 *    `source_type`/`source_id` به آن‌ها اشاره می‌کند. اگر تاریخ‌ها منتقل
 *    می‌شدند، برای هر سررسید دو ردیفِ حقیقت می‌ماند.
 *
 * ⚠ تاریخ‌ها همه‌جا **میلادی** رد و بدل می‌شوند (`Y-m-d`) و تبدیل به شمسی
 *   فقط هنگام نمایش انجام می‌شود — همان قاعده‌ی همیشگیِ پروژه.
 */

require_once __DIR__ . '/functions.php';

/**
 * ⛔ روزِ لنگر: «۳۱» یعنی ۳۱، حتی بعد از گذشتن از ماهِ ۳۰ روزه.
 *
 * تابعِ قدیمیِ `advanceRecurringDate()` روز را از خودِ تاریخِ فعلی
 * می‌گرفت، پس یک بار افتادن در مهر (۳۰ روزه) روزِ ۳۱ را **برای همیشه**
 * به ۳۰ تبدیل می‌کرد: ۳۱ شهریور → ۳۰ مهر → ۳۰ آبان → … . کاربر می‌دید
 * قسطش یک روز جلو افتاده و دیگر برنمی‌گردد.
 *
 * با لنگرِ جدا، هر پرش از **روزِ اصلی** حساب می‌شود و فقط وقتی ماه
 * کوتاه‌تر است کوتاه می‌آید — دفعه‌ی بعد دوباره ۳۱.
 *
 * @param string $fromGregorian تاریخِ مبدأ (میلادی، Y-m-d)
 * @param int    $months        چند ماهِ شمسی جلو (۱، ۳، ۴، ۱۲ …)
 * @param int    $anchorDay     روزِ لنگر ۱..۳۱؛ ۰ یعنی از خودِ تاریخ برداشته شود
 * @param string $dayRule       fixed_day | last_day_of_month
 */
function jalaliAddMonths(string $fromGregorian, int $months, int $anchorDay = 0,
                         string $dayRule = 'fixed_day'): string
{
    [$gy, $gm, $gd] = array_map('intval', explode('-', $fromGregorian));
    [$jy, $jm, $jd] = gregorianToJalali($gy, $gm, $gd);

    if ($anchorDay < 1 || $anchorDay > 31) { $anchorDay = $jd; }

    // شماره‌ی ماهِ مطلق، تا حسابِ سال خودش دربیاید و برای هر N کار کند.
    $total = ($jy * 12) + ($jm - 1) + $months;
    $newJy = intdiv($total, 12);
    $newJm = ($total % 12) + 1;

    $len = jalaliMonthLength($newJy, $newJm);
    $newJd = $dayRule === 'last_day_of_month' ? $len : min($anchorDay, $len);

    $g = jalaliToGregorian($newJy, $newJm, $newJd);
    return sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
}

/** روزِ شمسیِ یک تاریخِ میلادی — برای برداشتنِ لنگر از تاریخِ شروع. */
function jalaliDayOf(string $gregorian): int
{
    [$gy, $gm, $gd] = array_map('intval', explode('-', $gregorian));
    return gregorianToJalali($gy, $gm, $gd)[2];
}

class Schedule
{
    /** بازه‌های اعلانِ مجاز — تنها مرجع. فهرستِ دوم نسازید. */
    public const NOTIFY_STEPS = [7, 3, 1];

    public const SOURCES = ['custom', 'cheque', 'debt', 'recurring_tx'];

    public static function available(): bool
    {
        static $ok = null;
        if ($ok === null) { $ok = tableExists('reminder_occurrences'); }
        return $ok;
    }

    /**
     * تاریخِ سررسیدِ بعدی از روی قانونِ یک یادآور.
     *
     * `null` یعنی تکراری در کار نیست (once) و کارِ این یادآور تمام است.
     */
    public static function nextDue(array $rule, string $fromGregorian): ?string
    {
        $type = (string)($rule['recurrence_type'] ?? 'once');
        if ($type === 'once') { return null; }

        $n = max(1, (int)($rule['recurrence_n'] ?? 1));
        $months = $type === 'yearly' ? 12 * $n : $n;

        return jalaliAddMonths(
            $fromGregorian,
            $months,
            (int)($rule['anchor_day'] ?? 0),
            (string)($rule['day_rule'] ?? 'fixed_day')
        );
    }

    /**
     * ⛔ occurrence ها **از قبل انبوه نمی‌شوند**: هر بار فقط تا امروز
     *    ساخته می‌شوند و بعدی وقتی می‌آید که این یکی done/skipped شود یا
     *    تاریخش بگذرد.
     *
     *    با انبوه‌سازی، یک وامِ ۳۶ ماهه ۳۶ ردیف می‌ساخت و عوض کردنِ
     *    قانون یعنی بازسازیِ همه‌ی ردیف‌های پرداخت‌نشده — همان دلیلی که
     *    اقساط در این پروژه از اول **مجازی** بودند.
     *
     * ⛔ و سررسیدهای عقب‌افتاده گم نمی‌شوند: هرکدام ردیفِ جدا با وضعیتِ
     *    `overdue` می‌گیرند، نه اینکه یکی جای همه بنشیند.
     *
     * @return int تعداد ردیفِ تازه
     */
    public static function materialize(int $userId, array $rule, ?string $today = null): int
    {
        if (!self::available()) { return 0; }
        $today = $today ?? today();
        $rid   = (int)$rule['id'];
        if (($rule['status'] ?? 'active') !== 'active') { return 0; }

        $pdo  = Database::getConnection();
        $made = 0;

        // ⛔ اگر همین حالا یک سررسیدِ **باز** برای این قانون هست، کاری
        //    نیست. بدونِ این شرط، هر اجرا یک سررسیدِ آینده‌ی تازه اضافه
        //    می‌کرد و کرونِ ساعتی روزی ۲۴ ردیف انبوه می‌ساخت — دقیقاً
        //    همان «از قبل انبوه‌سازی نکن» که قرار بود رعایت شود. با
        //    اجرای واقعی پیدا شد، نه با نگاه.
        $open = $pdo->prepare("
            SELECT COUNT(*) FROM reminder_occurrences
             WHERE reminder_id = :r AND status IN ('pending','overdue')
        ");
        $open->execute(['r' => $rid]);
        if ((int)$open->fetchColumn() > 0) {
            $pdo->prepare("
                UPDATE reminder_occurrences SET status = 'overdue'
                 WHERE user_id = :u AND reminder_id = :r
                   AND status = 'pending' AND due_date < :t
            ")->execute(['u' => $userId, 'r' => $rid, 't' => $today]);
            return 0;
        }

        // آخرین سررسیدی که برای این قانون ساخته شده.
        $st = $pdo->prepare('SELECT MAX(due_date) FROM reminder_occurrences WHERE reminder_id = :r');
        $st->execute(['r' => $rid]);
        $last = $st->fetchColumn();

        $due = $last === null || $last === false ? (string)$rule['remind_date'] : (string)$last;

        // ⚠ سقفِ حلقه: قانونِ خرابی که هرگز جلو نرود نباید صفحه را قفل کند.
        for ($i = 0; $i < 240; $i++) {
            if ($last !== null && $last !== false) {
                $next = self::nextDue($rule, $due);
                if ($next === null) { break; }          // once — دیگر تکراری نیست
                $due = $next;
            }
            if ($due > $today) {
                // ⚠ سررسیدِ آینده هم **یک** ردیف می‌گیرد تا در فهرست
                //   «این هفته/این ماه» دیده شود؛ ولی جلوتر نمی‌رویم.
                self::insertOccurrence($pdo, $rid, $userId, $due, $made);
                break;
            }
            self::insertOccurrence($pdo, $rid, $userId, $due, $made);
            $last = $due;
        }

        // هر چیزی که تاریخش گذشته و هنوز pending است، overdue می‌شود.
        $pdo->prepare("
            UPDATE reminder_occurrences
               SET status = 'overdue'
             WHERE user_id = :u AND reminder_id = :r
               AND status = 'pending' AND due_date < :t
        ")->execute(['u' => $userId, 'r' => $rid, 't' => $today]);

        return $made;
    }

    private static function insertOccurrence(PDO $pdo, int $rid, int $userId, string $due, int &$made): void
    {
        $ins = $pdo->prepare('
            INSERT IGNORE INTO reminder_occurrences (reminder_id, user_id, due_date)
            VALUES (:r, :u, :d)
        ');
        $ins->execute(['r' => $rid, 'u' => $userId, 'd' => $due]);
        if ($ins->rowCount() > 0) { $made++; }
    }

    /** همه‌ی قانون‌های فعالِ کاربر را تا امروز جلو می‌برد. */
    public static function materializeAll(int $userId, ?string $today = null): int
    {
        if (!self::available()) { return 0; }
        $st = Database::getConnection()->prepare(
            "SELECT * FROM reminders WHERE user_id = :u AND status = 'active'"
        );
        $st->execute(['u' => $userId]);
        $n = 0;
        foreach ($st->fetchAll() as $rule) { $n += self::materialize($userId, $rule, $today); }
        return $n;
    }

    /**
     * بستنِ یک سررسید.
     *
     * ⚠ فقط وضعیتِ **همین** سررسید عوض می‌شود؛ پول را صاحبِ خودش ثبت
     *   می‌کند (تراکنش، `debt_payments`، …). این تابع هرگز تراکنش نمی‌سازد.
     */
    public static function close(int $userId, int $occurrenceId, string $status, string $note = ''): bool
    {
        if (!in_array($status, ['done', 'skipped'], true)) { return false; }
        if (!self::available()) { return false; }

        $pdo = Database::getConnection();
        $st  = $pdo->prepare('
            UPDATE reminder_occurrences
               SET status = :s, done_at = NOW(), note = :n
             WHERE id = :i AND user_id = :u
        ');
        $st->execute(['s' => $status, 'n' => $note !== '' ? mb_substr($note, 0, 500) : null,
                      'i' => $occurrenceId, 'u' => $userId]);
        if ($st->rowCount() === 0) { return false; }

        // سررسیدِ بعدی همین حالا ساخته شود تا کاربر بلافاصله ببیندش.
        $r = $pdo->prepare('
            SELECT r.* FROM reminders r
            JOIN reminder_occurrences o ON o.reminder_id = r.id
            WHERE o.id = :i AND r.user_id = :u
        ');
        $r->execute(['i' => $occurrenceId, 'u' => $userId]);
        $rule = $r->fetch();
        if (!$rule) { return true; }

        if (($rule['recurrence_type'] ?? 'once') === 'once') {
            // ⛔ یادآورِ یک‌باره بعد از انجام شدن `finished` می‌شود، وگرنه
            //    برای همیشه در فهرست می‌ماند.
            $pdo->prepare("UPDATE reminders SET status = 'finished', is_done = 1
                            WHERE id = :r AND user_id = :u")
                ->execute(['r' => (int)$rule['id'], 'u' => $userId]);
            return true;
        }

        self::materialize($userId, $rule);
        return true;
    }

    /**
     * تعویق: تاریخِ همین سررسید جلو می‌رود، بدونِ ساختنِ ردیفِ تازه.
     *
     * ⚠ نگهبانِ اعلان (`reminder_notifications`) هم پاک می‌شود، وگرنه
     *   سررسیدِ تعویق‌شده دیگر هیچ اعلانی نمی‌گرفت — یعنی تعویق عملاً
     *   می‌شد «خاموش کردن».
     */
    public static function snooze(int $userId, int $occurrenceId, string $newDate): bool
    {
        if (!self::available() || !isValidDate($newDate)) { return false; }
        $pdo = Database::getConnection();
        $st  = $pdo->prepare("
            UPDATE reminder_occurrences
               SET due_date = :d, status = 'pending'
             WHERE id = :i AND user_id = :u AND status IN ('pending','overdue')
        ");
        $st->execute(['d' => $newDate, 'i' => $occurrenceId, 'u' => $userId]);
        if ($st->rowCount() === 0) { return false; }

        $pdo->prepare('DELETE FROM reminder_notifications WHERE occurrence_id = :i AND user_id = :u')
            ->execute(['i' => $occurrenceId, 'u' => $userId]);
        return true;
    }

    /** ساعتِ یادآوریِ کاربر (۰..۲۳). پیش‌فرض ۹ صبح. */
    public static function userHour(int $userId): int
    {
        try {
            if (!tableHasColumn('notification_prefs', 'notify_hour')) { return 9; }
            $st = Database::getConnection()->prepare(
                'SELECT notify_hour FROM notification_prefs WHERE user_id = :u'
            );
            $st->execute(['u' => $userId]);
            $h = $st->fetchColumn();
            return $h === false ? 9 : max(0, min(23, (int)$h));
        } catch (Throwable $e) { return 9; }
    }

    /** پله‌های اعلانِ یک قانون، از JSON. */
    public static function stepsOf(array $rule): array
    {
        $raw = json_decode((string)($rule['notify_days_before'] ?? '[1]'), true);
        if (!is_array($raw)) { $raw = [1]; }
        $out = [];
        foreach ($raw as $d) {
            $d = (int)$d;
            if (in_array($d, self::NOTIFY_STEPS, true)) { $out[$d] = true; }
        }
        // ⛔ روزِ سررسید همیشه هست، چه کاربر انتخابش کرده باشد چه نه:
        //    یادآوری که روزِ خودِ سررسید ساکت باشد کارش را نکرده.
        $out[0] = true;
        $keys = array_keys($out);
        rsort($keys);
        return $keys;
    }
}

/**
 * ⛔ همگام‌سازیِ قانون‌ها با جدول‌های دامنه — «اتصال» چهار بخش.
 *
 *    برای هر چکِ در جریان، هر طلب/بدهیِ تسویه‌نشده و هر تراکنشِ دوره‌ایِ
 *    فعال، یک ردیفِ قانون در `reminders` ساخته یا به‌روز می‌شود. کلیدِ
 *    یکتای `(user_id, source_type, source_id)` تضمین می‌کند که هر ردیفِ
 *    منبع فقط یک قانون داشته باشد.
 *
 * ⛔ و هیچ مبلغی اینجا **نوشته** نمی‌شود: مبلغ و تاریخ از خودِ ردیفِ
 *    منبع خوانده می‌شوند و همان‌جا هم می‌مانند. اگر کاربر تاریخِ چک را
 *    عوض کند، اجرای بعدیِ همین تابع قانون را هم‌تراز می‌کند.
 */
function syncScheduleRules(int $userId): int
{
    if (!Schedule::available() || $userId <= 0) { return 0; }
    $pdo = Database::getConnection();
    $n   = 0;

    $upsert = function (string $type, int $sid, string $title, ?string $date,
                        ?int $amount, ?int $walletId, string $rec = 'once', int $recN = 1)
                       use ($pdo, $userId, &$n): void {
        if ($date === null || $date === '') { return; }
        $anchor = jalaliDayOf($date);
        // ⚠ `ON DUPLICATE KEY` تاریخ و مبلغ را هم‌تراز می‌کند ولی به
        //   وضعیتِ occurrence ها دست نمی‌زند — آن‌ها کارِ خودشان را دارند.
        $st = $pdo->prepare("
            INSERT INTO reminders
                (user_id, source_type, source_id, title, remind_date, amount, wallet_id,
                 recurrence_type, recurrence_n, anchor_day, status)
            VALUES (:u, :st, :sid, :t, :d, :a, :w, :rt, :rn, :an, 'active')
            ON DUPLICATE KEY UPDATE
                title = VALUES(title), remind_date = VALUES(remind_date),
                amount = VALUES(amount), wallet_id = VALUES(wallet_id),
                anchor_day = VALUES(anchor_day),
                status = IF(status = 'paused', 'paused', 'active')
        ");
        $st->execute(['u' => $userId, 'st' => $type, 'sid' => $sid,
                      't' => mb_substr($title, 0, 200), 'd' => $date,
                      'a' => $amount, 'w' => $walletId, 'rt' => $rec, 'rn' => $recN,
                      'an' => $anchor]);
        if ($st->rowCount() > 0) { $n++; }
    };

    // ---------- چک‌های در جریان ----------
    try {
        $st = $pdo->prepare('SELECT id, direction, counterparty_name, amount, due_date
                             FROM cheques WHERE user_id = :u AND ' . chequeActiveSql());
        $st->execute(['u' => $userId]);
        foreach ($st->fetchAll() as $c) {
            $upsert('cheque', (int)$c['id'],
                'چک ' . ($c['direction'] === 'received' ? 'دریافتی از ' : 'صادره به ')
                      . $c['counterparty_name'],
                $c['due_date'], (int)$c['amount'], null);
        }
    } catch (Throwable $e) { /* جدول نیست */ }

    // ---------- طلب و بدهیِ تسویه‌نشده ----------
    try {
        $st = $pdo->prepare('SELECT id, direction, counterparty_name, amount, paid_amount, due_date
                             FROM debts WHERE user_id = :u AND is_settled = 0');
        $st->execute(['u' => $userId]);
        foreach ($st->fetchAll() as $d) {
            $remaining = max(0, (int)$d['amount'] - (int)$d['paid_amount']);
            $upsert('debt', (int)$d['id'],
                ($d['direction'] === 'receivable' ? 'طلب از ' : 'بدهی به ') . $d['counterparty_name'],
                $d['due_date'], $remaining, null);
        }
    } catch (Throwable $e) { /* جدول نیست */ }

    // ---------- تراکنش‌های دوره‌ایِ فعال ----------
    //
    // ⚠ `interval_count` همان N است و از قبل وجود داشت؛ فقط نگاشت
    //   می‌شود. `daily`/`weekly` قانونِ ماهانه ندارند، پس یک‌باره ثبت
    //   می‌شوند و خودِ موتورِ قدیمیِ تراکنش دوره‌ای جلوشان می‌برد.
    try {
        $st = $pdo->prepare('SELECT id, title, amount, wallet_id, frequency, interval_count, next_due_date
                             FROM recurring_transactions WHERE user_id = :u AND is_active = 1');
        $st->execute(['u' => $userId]);
        foreach ($st->fetchAll() as $r) {
            $freq = (string)$r['frequency'];
            $rec  = $freq === 'monthly' ? 'every_n_months' : ($freq === 'yearly' ? 'yearly' : 'once');
            $upsert('recurring_tx', (int)$r['id'], (string)$r['title'],
                $r['next_due_date'], (int)$r['amount'], $r['wallet_id'] ? (int)$r['wallet_id'] : null,
                $rec, max(1, (int)$r['interval_count']));
        }
    } catch (Throwable $e) { /* جدول نیست */ }

    // ⛔ قانونی که منبعش دیگر در جریان نیست باید بسته شود، وگرنه چکِ
    //    پاس‌شده تا ابد در فهرستِ سررسیدها می‌ماند.
    foreach ([
        'cheque'       => 'SELECT id FROM cheques WHERE user_id = :u AND ' . chequeActiveSql(),
        'debt'         => 'SELECT id FROM debts WHERE user_id = :u AND is_settled = 0',
        'recurring_tx' => 'SELECT id FROM recurring_transactions WHERE user_id = :u AND is_active = 1',
    ] as $type => $sql) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute(['u' => $userId]);
            $live = array_map('intval', array_column($st->fetchAll(), 'id'));
            $q = $pdo->prepare("UPDATE reminders SET status = 'finished'
                                 WHERE user_id = :u AND source_type = :t AND status <> 'finished'"
                               . ($live ? ' AND source_id NOT IN (' . implode(',', $live) . ')' : ''));
            $q->execute(['u' => $userId, 't' => $type]);
        } catch (Throwable $e) { /* جدول نیست */ }
    }

    return $n;
}
