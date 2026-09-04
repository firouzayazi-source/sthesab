<?php
/**
 * مرکزِ اعلان — تنها جایی که «چه چیزی به کاربر خبر داده می‌شود» تعریف
 * می‌شود.
 *
 * ⛔ چرا داخلِ اپ و نه فقط ایمیل: هسته‌ی این برنامه سررسید است، ولی تا
 *    امروز تنها راهِ خبر دادن ایمیلِ روزانه بود — و کسی که ایمیل ثبت
 *    نکرده باشد هیچ خبری نمی‌گرفت. اعلانِ داخلِ اپ هیچ وابستگیِ بیرونی
 *    ندارد: نه پنل می‌خواهد، نه اعتبار، نه سرویسی که از ایران در دسترس
 *    باشد. پس **همیشه** کار می‌کند و بقیه‌ی کانال‌ها رویش سوارند.
 *
 * ⚠ اگر migration اجرا نشده باشد همه چیز بی‌صدا خاموش می‌ماند و هیچ
 *   صفحه‌ای نمی‌شکند — همان الگوی `LoginThrottle::available()`.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/schedule.php';

class Notify
{
    /** بازه‌ی پیش‌فرضِ «سررسیدِ نزدیک» وقتی کاربر چیزی انتخاب نکرده باشد. */
    public const DEFAULT_DAYS = 3;

    /**
     * بازه‌های «چند روز قبل خبر بده» برای یادآورِ دلخواه — تنها مرجع.
     *
     * ⛔ فرمِ یادآور و `api/save_reminder.php` هم از همین می‌خوانند؛ فهرستِ
     *    دوم نسازید، وگرنه گزینه‌ای که کاربر می‌بیند هنگام ذخیره بی‌صدا
     *    کنار گذاشته می‌شود.
     */
    public const REMINDER_STEPS = [1, 3, 7, 30];

    /** بیشترین اعلانی که در صفحه‌ی مرکزِ اعلان نشان داده می‌شود. */
    public const PAGE_LIMIT = 60;

    public static function available(): bool
    {
        static $ok = null;
        if ($ok === null) { $ok = tableExists('notifications'); }
        return $ok;
    }

    public static function remindersAvailable(): bool
    {
        static $ok = null;
        if ($ok === null) { $ok = tableExists('reminders'); }
        return $ok;
    }

    /**
     * ثبتِ یک اعلان.
     *
     * ⛔ `$dedupKey` اختیاری نیست در عمل: بدونِ آن، هر بار که تولیدکننده
     *    اجرا شود همان سررسید دوباره اعلان می‌شود و فهرست پر از تکراری
     *    می‌شود — و فهرستی که پر از تکراری باشد دیگر خوانده نمی‌شود.
     *    درج با `INSERT IGNORE` روی کلیدِ یکتا انجام می‌شود، پس تکرار
     *    بی‌صدا رد می‌شود نه اینکه خطا بدهد.
     *
     * @return bool آیا واقعاً چیزِ تازه‌ای ثبت شد؟
     */
    public static function push(
        int $userId,
        string $kind,
        string $title,
        string $body = '',
        string $link = '',
        ?string $dedupKey = null
    ): bool {
        if (!self::available() || $userId <= 0 || $title === '') { return false; }
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare('
                INSERT IGNORE INTO notifications (user_id, kind, title, body, link, dedup_key)
                VALUES (:u, :k, :t, :b, :l, :d)
            ');
            $stmt->execute([
                'u' => $userId,
                'k' => mb_substr($kind, 0, 32),
                't' => mb_substr($title, 0, 200),
                'b' => $body === '' ? null : mb_substr($body, 0, 500),
                'l' => $link === '' ? null : mb_substr($link, 0, 190),
                'd' => $dedupKey === null || $dedupKey === '' ? null : mb_substr($dedupKey, 0, 190),
            ]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            // ⛔ اعلان کارِ جانبی است و هرگز نباید بارگذاری صفحه را
            //    بشکند — همان قاعده‌ی `recordNetWorthSnapshot()`.
            return false;
        }
    }

    public static function unreadCount(int $userId): int
    {
        if (!self::available() || $userId <= 0) { return 0; }
        try {
            $stmt = Database::getConnection()->prepare(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :u AND read_at IS NULL'
            );
            $stmt->execute(['u' => $userId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /** @return array<int, array<string, mixed>> */
    public static function recent(int $userId, int $limit = self::PAGE_LIMIT): array
    {
        if (!self::available() || $userId <= 0) { return []; }
        try {
            $stmt = Database::getConnection()->prepare(
                'SELECT * FROM notifications WHERE user_id = :u ORDER BY id DESC LIMIT ' . max(1, min(200, $limit))
            );
            $stmt->execute(['u' => $userId]);
            return $stmt->fetchAll();
        } catch (Throwable $e) { return []; }
    }

    /** `$id = 0` یعنی همه. */
    public static function markRead(int $userId, int $id = 0): bool
    {
        if (!self::available() || $userId <= 0) { return false; }
        try {
            $pdo = Database::getConnection();
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE notifications SET read_at = NOW() WHERE user_id = :u AND id = :i AND read_at IS NULL'
                );
                $stmt->execute(['u' => $userId, 'i' => $id]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE notifications SET read_at = NOW() WHERE user_id = :u AND read_at IS NULL'
                );
                $stmt->execute(['u' => $userId]);
            }
            return true;
        } catch (Throwable $e) { return false; }
    }

    public static function deleteAll(int $userId): bool
    {
        if (!self::available() || $userId <= 0) { return false; }
        try {
            $stmt = Database::getConnection()->prepare('DELETE FROM notifications WHERE user_id = :u');
            $stmt->execute(['u' => $userId]);
            return true;
        } catch (Throwable $e) { return false; }
    }

    /**
     * ⛔ تولیدکننده — روزی یک بار برای هر کاربر.
     *
     * الگویش همان `processRecurringTransactions()` است: هیچ cron ای لازم
     * نیست، اولین بازدیدِ روز کار را انجام می‌دهد. نگهبانِ نشست فقط برای
     * **سرعت** است؛ درستی از `dedup_key` می‌آید، پس اجرای دوباره هم چیزی
     * را خراب نمی‌کند.
     *
     * @return int تعداد اعلانِ تازه
     */
    public static function generateFor(int $userId, bool $force = false): int
    {
        if (!self::available() || $userId <= 0) { return 0; }

        $today = today();
        if (!$force && (($_SESSION['notify_scan'] ?? '') === $today)) { return 0; }
        $_SESSION['notify_scan'] = $today;

        $made = 0;
        $made += self::generateReminders($userId, $today);
        $made += self::generateDueEvents($userId, $today);
        return $made;
    }

    /** یادآورهای شخصیِ کاربر که امروز (یا قبل‌تر) سررسید شده‌اند. */
    private static function generateReminders(int $userId, string $today): int
    {
        if (!self::remindersAvailable()) { return 0; }
        $made = 0;
        try {
            $pdo = Database::getConnection();

            // ⛔ پنجره تا بلندترین بازه‌ی مجاز باز است، نه فقط «امروز».
            //
            //    «چند روز قبل خبر بده» ذخیره می‌شد ولی هیچ‌جا خوانده
            //    نمی‌شد: کوئری فقط `remind_date <= today` را می‌گرفت، پس
            //    انتخابِ «یک هفته قبل» هیچ اثری نداشت و اعلان همیشه
            //    **روزِ خودِ سررسید** می‌آمد. تنظیمی که کار نمی‌کند از
            //    نبودنش بدتر است.
            $maxAhead = max(self::REMINDER_STEPS);
            $horizon  = date('Y-m-d', strtotime($today . ' +' . $maxAhead . ' day'));

            $stmt = $pdo->prepare('
                SELECT * FROM reminders
                WHERE user_id = :u AND is_done = 0 AND remind_date <= :h
                  AND (last_notified_on IS NULL OR last_notified_on < :t2)
                ORDER BY remind_date ASC LIMIT 50
            ');
            $stmt->execute(['u' => $userId, 'h' => $horizon, 't2' => $today]);
            foreach ($stmt->fetchAll() as $r) {
                $left = (int)floor((strtotime($r['remind_date']) - strtotime($today)) / 86400);

                // ⚠ روزهای انتخابیِ خودِ کاربر. مقدارِ خرابِ ذخیره‌شده نباید
                //   اعلان را خاموش کند، پس به پیش‌فرض برمی‌گردد.
                $days = json_decode((string)($r['notify_days_before'] ?? '[1]'), true);
                $days = is_array($days)
                    ? array_values(array_filter(array_map('intval', $days), fn ($d) => $d > 0))
                    : [];
                if (!$days) { $days = [1]; }

                // سررسیدِ امروز و عقب‌افتاده همیشه؛ آینده فقط سرِ یکی از
                // بازه‌های خودِ کاربر.
                if ($left > 0 && !in_array($left, $days, true)) { continue; }

                $late  = $r['remind_date'] < $today;
                $body  = $late
                    ? 'سررسیدش گذشته — ' . toJalali($r['remind_date'])
                    : ($left === 0
                        ? 'برای امروز'
                        : toPersianDigits((string)$left) . ' روز مانده — ' . toJalali($r['remind_date']));

                // مبلغِ **همین قسط**، نه مبلغِ خام: در تعهدِ چندقسطی این دو
                // یکی نیستند و قسطِ آخر باقیمانده را هم دارد.
                $due = Schedule::installmentAmount($r);
                if ($due > 0) { $body .= ' · ' . formatMoney($due); }
                $tc = (int)($r['total_count'] ?? 0);
                if ($tc > 1) {
                    $body .= ' · قسط ' . toPersianDigits((string)min((int)($r['done_count'] ?? 0) + 1, $tc))
                           . ' از ' . toPersianDigits((string)$tc);
                }
                if (!empty($r['note'])) { $body .= ' · ' . $r['note']; }

                // ⚠ کلیدِ یکتایی **تاریخِ خودِ یادآور** را دارد نه امروز را،
                //   وگرنه یادآورِ عقب‌افتاده هر روز یک اعلانِ تازه می‌ساخت.
                // ⚠ و بازه هم در کلید هست: کسی که «۷ روز قبل» و «۱ روز قبل»
                //   را با هم زده باید هر دو را بگیرد، نه یکی را.
                $key = 'reminder:' . $r['id'] . ':' . $r['remind_date'] . ':' . max($left, 0);
                if (self::push($userId, 'reminder', $r['title'], $body, 'reminders.php', $key)) {
                    $made++;
                }
                // ⚠ فقط وقتی چیزی فرستاده شد: وگرنه یک یادآورِ خارج از بازه،
                //   نگهبانِ «روزی یک بار» را برای بقیه‌ی همان روز می‌سوزاند.
                $up = $pdo->prepare('UPDATE reminders SET last_notified_on = :d WHERE id = :i AND user_id = :u');
                $up->execute(['d' => $today, 'i' => $r['id'], 'u' => $userId]);
            }
        } catch (Throwable $e) { /* اعلان نباید صفحه را بشکند */ }
        return $made;
    }

    /** چک، طلب و بدهی، قسط و تراکنش دوره‌ای که سررسیدشان نزدیک است. */
    private static function generateDueEvents(int $userId, string $today): int
    {
        $days = self::windowDays($userId);
        $to   = date('Y-m-d', strtotime($today . ' +' . $days . ' day'));
        $made = 0;
        try {
            // ⚠ از `financialEvents()` می‌آید، نه از کوئریِ تازه — همان
            //   تابعی که «آینده مالی» و ایمیلِ یادآوری از آن می‌خوانند.
            //   با کوئریِ دوم، اعلان و صفحه دیر یا زود دو چیزِ مختلف
            //   می‌گفتند.
            foreach (financialEvents($userId, $today, $to) as $e) {
                $body = !empty($e['is_overdue']) ? 'سررسید گذشته — ' . toJalali($e['date'])
                      : ($e['date'] === $today ? 'سررسید امروز' : 'سررسید ' . toJalali($e['date']));
                if (!empty($e['amount'])) { $body .= ' · ' . formatMoney((int)$e['amount']); }

                // ⛔ `financialEvents()` شناسه‌ی ردیف برنمی‌گرداند (رویدادِ
                //    قسط و تراکنشِ دوره‌ای اصلاً ردیفِ ذخیره‌شده ندارند و
                //    مجازی ساخته می‌شوند). پس کلیدِ یکتایی از خودِ
                //    محتوای رویداد ساخته می‌شود: همان رویداد در اجرای
                //    بعدی همان کلید را می‌دهد، و دو رویدادِ متفاوت هرگز
                //    یک کلید نمی‌گیرند.
                $key = 'due:' . substr(sha1(
                    ($e['kind'] ?? '?') . '|' . $e['date'] . '|' .
                    ($e['title'] ?? '') . '|' . ($e['amount'] ?? 0)
                ), 0, 32);

                if (self::push($userId, 'due', (string)($e['title'] ?? 'سررسید'), $body,
                        (string)($e['url'] ?? 'upcoming.php'), $key)) {
                    $made++;
                }
            }
        } catch (Throwable $e) { /* اعلان نباید صفحه را بشکند */ }
        return $made;
    }

    /** بازه‌ی «سررسیدِ نزدیک» از همان تنظیمی می‌آید که ایمیلِ یادآوری دارد. */
    private static function windowDays(int $userId): int
    {
        try {
            if (!tableExists('notification_prefs')) { return self::DEFAULT_DAYS; }
            $stmt = Database::getConnection()->prepare(
                'SELECT days_ahead FROM notification_prefs WHERE user_id = :u'
            );
            $stmt->execute(['u' => $userId]);
            $d = $stmt->fetchColumn();
            return $d === false ? self::DEFAULT_DAYS : max(0, min(30, (int)$d));
        } catch (Throwable $e) { return self::DEFAULT_DAYS; }
    }
}
