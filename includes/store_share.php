<?php
/**
 * سهمِ سهامدارِ فروشگاه — خواندنی، و سودش در دفترِ خودش.
 *
 * **خواسته‌ی مالکِ نصب:** «فقط می‌خوام در حسابلند طرف دارایشو ببینه و
 * سودش رو بعد از معامله که محاسبه شد آنلاین در حسابلند جزو سود روزانه
 * خودکار ثبت بشه، و در بخش دارایی بخش مدیر یک توگل داراییِ کل فروشگاه
 * هم اضافه بشه فقط برای سوپرادمین.»
 *
 * ─── ⛔ هیچ محاسبه‌ای اینجا انجام نمی‌شود ───
 *
 * درصدِ سهم، سود هر قلم، و مانده‌ی هر سهامدار همه در حسابداریِ فروشگاه
 * حساب می‌شوند و آنجا سند می‌خورند. این فایل فقط **می‌خواند و نشان
 * می‌دهد**. اگر روزی وسوسه شدید درصدی را اینجا ضرب کنید، بدانید که
 * نسخه‌ی دومِ منطقِ پول ساخته‌اید و دیر یا زود دو عدد متفاوت به یک
 * آدم نشان می‌دهید — همان چیزی که `walletBalances()` و
 * `categoryScopeSql()` برای نبودنش نوشته شدند، این بار بین دو برنامه.
 *
 * ─── ⛔ چرا HTTP و نه خواندنِ مستقیمِ دیتابیسِ فروشگاه ───
 *
 * فایلِ SQLite آن سیستم روی همین سرور است (`/data/…` داخلِ کانتینرِ
 * خودش) و باز کردنش از اینجا **قانونِ استقلالِ پروژه** را می‌شکند: هیچ
 * کدی در این مخزن حق ندارد به دیتابیس یا پوشه‌ی سرویسِ دیگری دست بزند.
 * پس یک اندپوینتِ **فقط‌خواندنیِ** توکن‌دار روی `127.0.0.1` خوانده
 * می‌شود. هزینه‌اش یک درخواستِ HTTP است؛ سودش این است که مرزِ دو
 * سیستم دست‌نخورده می‌ماند و طرفِ مقابل می‌تواند جابه‌جا شود بی‌آنکه
 * این فایل عوض شود.
 *
 * ─── ⛔ آینه، نه خواندنِ زنده در هر بارگذاری ───
 *
 * پاسخ در `store_sync` کش می‌شود. آن سیستم با داکر اجرا می‌شود و با هر
 * انتشار ری‌استارت می‌شود؛ با خواندنِ صرفاً زنده، صفحه‌ی داراییِ سهامدار
 * در هر دیپلویِ فروشگاه **خالی** می‌شد — بی‌هیچ خطایی که کاربر بفهمدش.
 * حالا آخرین نسخه با «آخرین به‌روزرسانی» می‌ماند؛ همان تورِ نجاتِ
 * سرویس‌ورکر.
 *
 * ─── ⛔ سودِ ثبت‌شده `wallet_id` ندارد ───
 *
 * دقیقاً همان قاعده‌ی `syncTradeProfitTransactions()`: دارایی سهامدار
 * خودش یک قلمِ «خالص دارایی» است، پس اگر سود به حسابی هم می‌نشست همان
 * پول **دو بار** شمرده می‌شد. تراکنش برای این است که در «سودِ روزانه»
 * و گزارشِ درآمد دیده شود، نه برای جابه‌جا کردنِ موجودی.
 *
 * ─── ⛔ ایدمپوتنسی با `transactions.store_share_ref` ───
 *
 * هر سطرِ سهم در سمتِ فروشگاه یک شناسه‌ی یکتا دارد و همان روی ستونِ
 * یکتای `store_share_ref` می‌نشیند. بدونش هر همگام‌سازی همان سود را
 * دوباره به‌عنوان درآمد ثبت می‌کرد و درآمدِ آن کاربر هر چند دقیقه باد
 * می‌شد، بی‌هیچ خطایی.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

final class StoreShare
{
    /**
     * ⛔ سقفِ «نهایتاً ۵ نفر» — خواسته‌ی صریحِ مالکِ نصب.
     *
     * در **دو** جا اعمال می‌شود (اینجا و اندپوینتِ وصل کردن با کدِ ۴۲۲)،
     * همان درسِ `PINNED_WALLET_MAX`: اگر فقط هنگام خواندن بریده می‌شد،
     * مدیر ده نفر وصل می‌کرد، پنج نفر کار می‌کردند، و هیچ‌جا نمی‌فهمید
     * بقیه کجا رفتند.
     */
    public const MAX_LINKS = 5;

    /** پاسخِ کش‌شده تا این چند ثانیه تازه شمرده می‌شود */
    public const SYNC_TTL = 300;

    /**
     * ⚠ مهلتِ کوتاه عمدی است: این درخواست وسطِ بارگذاریِ یک صفحه اجرا
     *   می‌شود و اگر آن سیستم خوابیده باشد نباید صفحه‌ی کاربر را معلق
     *   کند. با شکست، آینه‌ی قبلی نشان داده می‌شود.
     */
    public const HTTP_TIMEOUT = 3;

    /** دسته‌بندی‌های پیش‌فرضِ برنامه که `migration_store_share` می‌سازد */
    public const CATEGORY_INCOME  = 'سود سهام فروشگاه';
    public const CATEGORY_EXPENSE = 'زیان سهام فروشگاه';

    /** @var array<string,mixed>|null|false کشِ درخواستی؛ false = نخوانده‌ایم */
    private static $payloadCache = false;

    /* ═══════════════════ در دسترس بودن ═══════════════════ */

    /**
     * آدرس و توکن هر دو تنظیم شده‌اند؟
     *
     * ⛔ پیش‌فرض خاموش است و این قابل مذاکره نیست: `config/config.php`
     *    وارد گیت نمی‌شود، پس هیچ نصبی با یک `git pull` ناگهان شروع
     *    نمی‌کند به صدا زدنِ یک سرویسِ بیرونی — و `privacy.php` هم
     *    درباره‌اش دروغ نمی‌گوید.
     */
    public static function configured(): bool
    {
        return self::endpoint() !== '' && self::token() !== '';
    }

    /** پیکربندی هست و جدولِ پیوند هم با migration آمده است؟ */
    public static function available(): bool
    {
        if (!self::configured()) { return false; }
        try {
            return tableExists('store_shareholders') && tableExists('store_sync');
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function endpoint(): string
    {
        return defined('STORE_API_URL') ? trim((string)STORE_API_URL) : '';
    }

    private static function token(): string
    {
        return defined('STORE_API_TOKEN') ? trim((string)STORE_API_TOKEN) : '';
    }

    /* ═══════════════════ فهرستِ اجازه ═══════════════════ */

    /** همه‌ی پیوندها، برای صفحه‌ی مدیر. @return list<array<string,mixed>> */
    public static function links(): array
    {
        if (!self::available()) { return []; }
        try {
            $st = Database::getConnection()->query(
                'SELECT s.id, s.user_id, s.store_contact_id, s.display_name, s.is_active,
                        s.approved_at, u.username, u.full_name
                 FROM store_shareholders s
                 JOIN users u ON u.id = s.user_id
                 ORDER BY s.is_active DESC, s.id ASC'
            );
            return $st->fetchAll();
        } catch (PDOException $e) {
            Log::error('store_share.links_failed', $e);
            return [];
        }
    }

    /** پیوندِ فعالِ همین کاربر، یا `null`. */
    public static function linkFor(int $userId): ?array
    {
        if (!self::available() || $userId <= 0) { return null; }
        try {
            $st = Database::getConnection()->prepare(
                'SELECT id, user_id, store_contact_id, display_name
                 FROM store_shareholders
                 WHERE user_id = :u AND is_active = 1
                 LIMIT 1'
            );
            $st->execute(['u' => $userId]);
            $row = $st->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            Log::error('store_share.link_for_failed', $e);
            return null;
        }
    }

    /** شمارشِ پیوندهای فعال — مبنای سقف. */
    public static function activeCount(): int
    {
        if (!self::available()) { return 0; }
        try {
            return (int)Database::getConnection()
                ->query('SELECT COUNT(*) FROM store_shareholders WHERE is_active = 1')
                ->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * وصل کردنِ یک کاربرِ اینجا به یک سهامدارِ آن سیستم.
     *
     * @return array{ok:bool,message:string}
     */
    public static function link(int $userId, int $contactId, string $displayName = ''): array
    {
        if (!self::available()) {
            return ['ok' => false, 'message' => 'اتصال به حسابداری فروشگاه تنظیم نشده است.'];
        }
        if ($userId <= 0 || $contactId <= 0) {
            return ['ok' => false, 'message' => 'کاربر یا سهامدار انتخاب نشده است.'];
        }

        $pdo = Database::getConnection();
        try {
            // ⛔ سقف پیش از هر نوشتنی سنجیده می‌شود، و ردیفِ غیرفعالِ
            //    همان کاربر دوباره فعال می‌شود نه اینکه ردیفِ دومی بسازد
            //    (کلیدِ یکتا اجازه نمی‌دهد و پیامِ خطا هم هیچ نمی‌گفت).
            $st = $pdo->prepare('SELECT id, is_active FROM store_shareholders WHERE user_id = :u LIMIT 1');
            $st->execute(['u' => $userId]);
            $existing = $st->fetch();

            if (!$existing || (int)$existing['is_active'] === 0) {
                if (self::activeCount() >= self::MAX_LINKS) {
                    return ['ok' => false, 'message' => 'سقف ' . toPersianDigits((string)self::MAX_LINKS)
                        . ' سهامدار پر شده است. یکی را غیرفعال کنید.'];
                }
            }

            // ⚠ نگهبانِ `class_exists` عمدی است: این فایل از `db.php` و
            //   `functions.php` بالا می‌آید و `auth.php` را لازم ندارد،
            //   پس در مسیرِ خط فرمان کلاسِ `Auth` وجود ندارد — همان
            //   باگی که یک بار `Audit::available()` را ساکت کرد.
            $owner = class_exists('Auth') ? Auth::userId() : 0;
            if ($existing) {
                $pdo->prepare(
                    'UPDATE store_shareholders
                     SET store_contact_id = :c, display_name = :n, is_active = 1,
                         approved_by = :b, approved_at = NOW()
                     WHERE id = :id'
                )->execute(['c' => $contactId, 'n' => mb_substr($displayName, 0, 120),
                            'b' => $owner ?: null, 'id' => $existing['id']]);
            } else {
                $pdo->prepare(
                    'INSERT INTO store_shareholders
                        (user_id, store_contact_id, display_name, is_active, approved_by, approved_at)
                     VALUES (:u, :c, :n, 1, :b, NOW())'
                )->execute(['u' => $userId, 'c' => $contactId,
                            'n' => mb_substr($displayName, 0, 120), 'b' => $owner ?: null]);
            }
        } catch (PDOException $e) {
            // کلیدِ یکتای `store_contact_id`: یک سهامدار به دو کاربر نمی‌رسد
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'message' => 'این سهامدار قبلاً به کاربر دیگری وصل شده است.'];
            }
            Log::error('store_share.link_failed', $e);
            return ['ok' => false, 'message' => 'ثبت پیوند انجام نشد.'];
        }

        return ['ok' => true, 'message' => 'سهامدار وصل شد.'];
    }

    /**
     * قطعِ پیوند.
     *
     * ⛔ ردیف حذف نمی‌شود بلکه غیرفعال می‌شود، و **تراکنش‌های سودِ
     *    ثبت‌شده هم دست نمی‌خورند**: آن پول واقعاً به او رسیده و دفترِ
     *    خودش است. قطع کردنِ دسترسی با پاک کردنِ تاریخچه‌ی مالی یکی
     *    نیست — همان قاعده‌ی «داده‌ی کاربر گروگان گرفته نمی‌شود».
     */
    public static function unlink(int $id): bool
    {
        if (!self::available() || $id <= 0) { return false; }
        try {
            Database::getConnection()
                ->prepare('UPDATE store_shareholders SET is_active = 0 WHERE id = :id')
                ->execute(['id' => $id]);
            return true;
        } catch (PDOException $e) {
            Log::error('store_share.unlink_failed', $e);
            return false;
        }
    }

    /* ═══════════════════ آینه ═══════════════════ */

    /** پاسخِ کش‌شده، یا `null` اگر هنوز چیزی نیامده باشد. */
    public static function payload(): ?array
    {
        if (self::$payloadCache !== false) { return self::$payloadCache; }
        self::$payloadCache = null;

        if (!self::available()) { return null; }
        try {
            $row = Database::getConnection()
                ->query('SELECT payload FROM store_sync WHERE id = 1')
                ->fetch();
            if ($row && $row['payload'] !== null && $row['payload'] !== '') {
                $data = json_decode((string)$row['payload'], true);
                if (is_array($data)) { self::$payloadCache = $data; }
            }
        } catch (PDOException $e) {
            Log::error('store_share.payload_failed', $e);
        }
        return self::$payloadCache;
    }

    /** وضعیتِ آخرین همگام‌سازی: `fetched_at` و `last_error`. */
    public static function status(): array
    {
        $out = ['fetched_at' => null, 'last_error' => null];
        if (!self::available()) { return $out; }
        try {
            $row = Database::getConnection()
                ->query('SELECT fetched_at, last_error FROM store_sync WHERE id = 1')
                ->fetch();
            if ($row) {
                $out['fetched_at'] = $row['fetched_at'] ?: null;
                $out['last_error'] = $row['last_error'] ?: null;
            }
        } catch (PDOException $e) {
            // وضعیتِ نمایشی است؛ نباید صفحه را بشکند
        }
        return $out;
    }

    /**
     * اگر آینه کهنه است تازه‌اش کن.
     *
     * ⛔ جای فراخوانی‌اش عمداً محدود است (`my-assets.php`): این یک
     *    درخواستِ HTTP وسطِ بارگذاریِ صفحه است و گذاشتنش در `header.php`
     *    یعنی هزینه‌اش روی **هر** صفحه‌ی اپ می‌نشیند — همان «خزشِ
     *    بی‌صدا» که کلِ `test_query_budget` برای گرفتنش نوشته شد.
     *
     * ⚠ حدش، صادقانه: اگر سهامدار هفته‌ای اپ را باز نکند، سودش هم آن
     *   هفته ثبت نمی‌شود. بی‌ضرر است چون **تاریخِ تراکنش تاریخِ سندِ
     *   فروشگاه است**، نه روزِ همگام‌سازی؛ پس وقتی باز کند، ردیف روی
     *   همان روزِ درست می‌نشیند و گزارشِ ماه درست می‌شود.
     */
    public static function refreshIfStale(): void
    {
        if (!self::available()) { return; }
        if (self::activeCount() === 0) { return; }

        try {
            $row = Database::getConnection()
                ->query('SELECT UNIX_TIMESTAMP(fetched_at) AS ts FROM store_sync WHERE id = 1')
                ->fetch();
            $age = ($row && $row['ts']) ? (time() - (int)$row['ts']) : PHP_INT_MAX;
        } catch (PDOException $e) {
            return;
        }

        if ($age < self::SYNC_TTL) { return; }
        self::sync();
    }

    /**
     * یک دور همگام‌سازی: بخوان، آینه را بنویس، و سهم‌ها را در دفترِ
     * کاربرانِ وصل‌شده ثبت کن.
     *
     * @return array{ok:bool,message:string,written:int,removed:int}
     */
    public static function sync(): array
    {
        if (!self::available()) {
            return ['ok' => false, 'message' => 'اتصال به حسابداری فروشگاه تنظیم نشده است.',
                    'written' => 0, 'removed' => 0];
        }

        $fetched = self::fetch();
        if (!$fetched['ok']) {
            self::noteError($fetched['message']);
            return ['ok' => false, 'message' => $fetched['message'], 'written' => 0, 'removed' => 0];
        }

        $data = $fetched['data'];
        try {
            Database::getConnection()->prepare(
                'UPDATE store_sync
                 SET payload = :p, fetched_at = NOW(), last_error = NULL
                 WHERE id = 1'
            )->execute(['p' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        } catch (PDOException $e) {
            Log::error('store_share.mirror_write_failed', $e);
            return ['ok' => false, 'message' => 'ذخیره‌ی پاسخ انجام نشد.', 'written' => 0, 'removed' => 0];
        }
        self::$payloadCache = $data;

        $written = 0;
        $removed = 0;
        foreach (self::links() as $link) {
            if ((int)$link['is_active'] !== 1) { continue; }
            $res = self::applyShares((int)$link['user_id'], (int)$link['store_contact_id']);
            $written += $res['written'];
            $removed += $res['removed'];
        }

        return ['ok' => true, 'message' => 'به‌روز شد.', 'written' => $written, 'removed' => $removed];
    }

    private static function noteError(string $message): void
    {
        try {
            Database::getConnection()
                ->prepare('UPDATE store_sync SET last_error = :e WHERE id = 1')
                ->execute(['e' => mb_substr($message, 0, 255)]);
        } catch (PDOException $e) {
            // خودِ ثبتِ خطا نباید خطا بدهد
        }
    }

    /* ═══════════════════ تنها جای تصمیمِ «این ردیف مالِ کیست» ═══════════════════ */

    /**
     * سهمِ همین کاربر از آینه، یا `null`.
     *
     * ⛔ تنها جای این تصمیم است (مثل `categoryScopeSql()`) و **هر دو**
     *    شرط لازم است:
     *    ۱. یک پیوندِ **فعال** برای همین `user_id` وجود داشته باشد؛
     *    ۲. و آینه واقعاً ردیفی با همان `store_contact_id` داشته باشد.
     *
     *    هیچ‌کدام به‌تنهایی کافی نیست: با شرطِ اول تنها، مدیری که پیوند
     *    را غیرفعال کرده باز هم داده می‌دید (چون آینه سرِ جایش است)؛ و
     *    با شرطِ دوم تنها، هر کاربری که شناسه‌اش اتفاقاً با یک
     *    `contact_id` می‌خواند دفترِ یک سهامدارِ دیگر را می‌دید.
     */
    public static function forUser(int $userId): ?array
    {
        $link = self::linkFor($userId);
        if (!$link) { return null; }

        $data = self::payload();
        if (!is_array($data) || !isset($data['shareholders']) || !is_array($data['shareholders'])) {
            return null;
        }

        $contactId = (int)$link['store_contact_id'];
        foreach ($data['shareholders'] as $sh) {
            if (!is_array($sh) || (int)($sh['id'] ?? 0) !== $contactId) { continue; }
            return $sh;
        }
        return null;
    }

    /**
     * ارزشِ داراییِ همین کاربر در فروشگاه — همان «مانده»ی دفترِ آن سیستم.
     *
     * ⛔ جمعِ بهای گوشی‌های نفروخته **نیست**، و دلیلش مهم است: لحظه‌ای که
     *    گوشی فروخته می‌شود از انبار بیرون می‌رود ولی پولش همچنان به او
     *    بدهکار است. با جمعِ گوشی‌ها، خالص داراییِ او سرِ هر فروش
     *    **سقوط** می‌کرد و بعد با پرداخت برمی‌گشت — عددی که هیچ معنایی
     *    ندارد. «مانده» هر سه حالت را با هم دارد: اصلِ سرمایه، سهمِ
     *    سودِ پرداخت‌نشده، و کسرِ آنچه گرفته.
     */
    public static function valueFor(int $userId): ?int
    {
        $sh = self::forUser($userId);
        if ($sh === null) { return null; }
        return (int)round((float)($sh['balance'] ?? 0));
    }

    /**
     * خالصِ داراییِ کلِ فروشگاه — فقط برای مدیر.
     *
     * ⛔ **خالص** است نه ناخالص: داراییِ فروشگاه منهای آنچه به سهامداران
     *    بدهکار است. با عددِ ناخالص، گوشیِ سهامدارها هم جزو ثروتِ مالکِ
     *    فروشگاه شمرده می‌شد — یعنی همان پول دو بار، یک بار در دفترِ
     *    سهامدار و یک بار اینجا.
     */
    public static function storeNetWorth(): ?int
    {
        $data = self::payload();
        if (!is_array($data) || !isset($data['store']) || !is_array($data['store'])) { return null; }
        if (!array_key_exists('net_worth', $data['store'])) { return null; }
        return (int)round((float)$data['store']['net_worth']);
    }

    /* ═══════════════════ ثبتِ سود در دفترِ کاربر ═══════════════════ */

    /**
     * سطرهای سهمِ این سهامدار را با دفترِ خودش هم‌گام می‌کند.
     *
     * ⛔ «هم‌گام» است نه «افزودن» — همان قاعده‌ی
     *    `syncTradeProfitTransactions()`. سطری که در سمتِ فروشگاه دیگر
     *    وجود ندارد (فاکتور ویرایش شده، پس سندش پاک و از نو ساخته شده)
     *    باید از اینجا هم برود، وگرنه یک درآمدِ **یتیم** برای همیشه در
     *    دفترِ کاربر می‌ماند و هیچ‌کس نمی‌فهمد از کجا آمده.
     *
     * @return array{written:int,removed:int}
     */
    private static function applyShares(int $userId, int $contactId): array
    {
        $out = ['written' => 0, 'removed' => 0];
        $sh  = self::forUser($userId);
        if ($sh === null || (int)($sh['id'] ?? 0) !== $contactId) { return $out; }

        $shares = isset($sh['shares']) && is_array($sh['shares']) ? $sh['shares'] : [];
        $pdo    = Database::getConnection();
        $seen   = [];

        foreach ($shares as $share) {
            if (!is_array($share)) { continue; }
            $ref = trim((string)($share['ref'] ?? ''));
            if ($ref === '' || mb_strlen($ref) > 64) { continue; }

            $amount = (int)round((float)($share['amount'] ?? 0));
            $date   = (string)($share['date'] ?? '');
            if ($amount === 0 || !isValidDate($date)) { continue; }

            // ⛔ پیشوند، تا با هر منبعِ دیگری که فردا ممکن است همین ستون
            //    را بنویسد قاطی نشود. شناسه‌ی آن‌طرف سراسری است ولی
            //    «سراسری در آن سیستم» با «یکتا در این ستون» یکی نیست.
            $key = 'store:' . $contactId . ':' . $ref;
            if (mb_strlen($key) > 64) { continue; }
            $seen[] = $key;

            $type  = $amount > 0 ? 'income' : 'expense';
            $title = trim((string)($share['description'] ?? ''));
            if ($title === '') {
                $title = $amount > 0 ? 'سهم سود فروشگاه' : 'سهم زیان فروشگاه';
            }
            $catId = self::categoryId($type);

            try {
                /*
                 * ⛔ بدونِ `wallet_id` — دلیلش بالای همین فایل نوشته شده.
                 *   و `ON DUPLICATE KEY` چون همان سطر ممکن است در سمتِ
                 *   فروشگاه مبلغش عوض شده باشد (تخفیفِ فاکتور اصلاح شده)
                 *   و ردیفِ کهنه در دفترِ کاربر یعنی گزارشِ غلط.
                 */
                $st = $pdo->prepare(
                    'INSERT INTO transactions
                        (user_id, category_id, type, amount, title, note, transaction_date, store_share_ref)
                     VALUES (:u, :c, :t, :a, :ti, :no, :d, :ref)
                     ON DUPLICATE KEY UPDATE
                        category_id = VALUES(category_id), type = VALUES(type),
                        amount = VALUES(amount), title = VALUES(title),
                        transaction_date = VALUES(transaction_date)'
                );
                $st->execute([
                    'u' => $userId, 'c' => $catId, 't' => $type, 'a' => abs($amount),
                    'ti' => mb_substr($title, 0, 255),
                    'no' => 'ثبت خودکار از حسابداری فروشگاه',
                    'd' => $date, 'ref' => $key,
                ]);
                if ($st->rowCount() > 0) { $out['written']++; }
            } catch (PDOException $e) {
                Log::error('store_share.tx_write_failed', $e, ['uid' => $userId]);
            }
        }

        /*
         * ⛔ حذفِ سطرهای بی‌مرجع فقط وقتی انجام می‌شود که آینه واقعاً
         *   ردیفِ همین سهامدار را داشته باشد — و `forUser()` بالاتر همان
         *   را تضمین کرده. با پاسخِ ناقص یا خالی، این حلقه کلِ درآمدِ
         *   سهامِ کاربر را پاک می‌کرد: بدترین شکلِ خرابی، چون بی‌صداست و
         *   بازگشت هم ندارد.
         */
        try {
            $sql = 'DELETE FROM transactions
                    WHERE user_id = :u AND store_share_ref LIKE :pre';
            $params = ['u' => $userId, 'pre' => 'store:' . $contactId . ':%'];
            if ($seen !== []) {
                $keep = [];
                foreach ($seen as $i => $k) {
                    $keep[] = ':k' . $i;
                    $params['k' . $i] = $k;
                }
                $sql .= ' AND store_share_ref NOT IN (' . implode(',', $keep) . ')';
            }
            $del = $pdo->prepare($sql);
            $del->execute($params);
            $out['removed'] = $del->rowCount();
        } catch (PDOException $e) {
            Log::error('store_share.tx_prune_failed', $e, ['uid' => $userId]);
        }

        return $out;
    }

    /**
     * دسته‌بندیِ سیستمیِ سود/زیانِ سهام — اگر نبود ساخته می‌شود.
     *
     * ⛔ پیش‌فرضِ برنامه (`user_id IS NULL`)، همان استدلالِ «سود
     *    معاملات»: اگر شخصی می‌شد هر سهامدار یک نسخه‌ی تکراری می‌ساخت.
     */
    private static function categoryId(string $type): ?int
    {
        static $cache = [];
        if (array_key_exists($type, $cache)) { return $cache[$type]; }

        $name = $type === 'income' ? self::CATEGORY_INCOME : self::CATEGORY_EXPENSE;
        try {
            $pdo = Database::getConnection();
            $st  = $pdo->prepare(
                'SELECT id FROM categories
                 WHERE name = :n AND type = :t' .
                (tableHasColumn('categories', 'user_id') ? ' AND user_id IS NULL' : '') .
                ' LIMIT 1'
            );
            $st->execute(['n' => $name, 't' => $type]);
            $id = $st->fetchColumn();
            if (!$id) {
                $pdo->prepare('INSERT INTO categories (name, type, is_active) VALUES (:n, :t, 1)')
                    ->execute(['n' => $name, 't' => $type]);
                $id = $pdo->lastInsertId();
            }
            return $cache[$type] = (int)$id;
        } catch (PDOException $e) {
            Log::error('store_share.category_failed', $e);
            return $cache[$type] = null;
        }
    }

    /* ═══════════════════ خواندنِ اندپوینت ═══════════════════ */

    /**
     * یک GET ساده با توکن.
     *
     * ⛔ `Sms::post()` قابل استفاده‌ی دوباره نبود (خصوصی است و فقط POST
     *    می‌زند)، پس همان الگو تکرار شده: curl اگر بود، وگرنه
     *    `stream_context_create`. pool این اپ `exec`/`shell_exec` را
     *    بسته، پس curlِ خط‌فرمانی ممکن نیست؛ و افزونه‌ی curl هم روی هر
     *    نصبی نیست.
     *
     * @return array{ok:bool,message:string,data:array}
     */
    private static function fetch(): array
    {
        $url = self::endpoint();
        $tok = self::token();
        $bad = ['ok' => false, 'data' => []];

        $headers = ['Authorization: Bearer ' . $tok, 'Accept: application/json'];
        $body    = null;
        $code    = 0;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
                // ⛔ پراکسیِ محیط نباید وسط بیفتد: مقصد `127.0.0.1` است.
                CURLOPT_PROXY          => '',
            ]);
            $out = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($out === false) {
                return $bad + ['message' => 'به حسابداری فروشگاه وصل نشد: ' . $err];
            }
            $body = (string)$out;
        } elseif (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(['http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => self::HTTP_TIMEOUT,
                'ignore_errors' => true,
                'proxy'         => null,
            ]]);
            $out = @file_get_contents($url, false, $ctx);
            if ($out === false) {
                return $bad + ['message' => 'به حسابداری فروشگاه وصل نشد.'];
            }
            $body = (string)$out;
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) { $code = (int)$m[1]; }
            }
        } else {
            return $bad + ['message' => 'نه افزونه‌ی curl هست و نه allow_url_fopen.'];
        }

        /*
         * ⛔ «۲۰۰ گرفتیم» با «داده آمد» یکی نیست — همان درسِ پنل‌های
         *   پیامک و قاعده‌ی nginx در `api/v1`. اگر nginx یا یک صفحه‌ی
         *   خطای HTML جواب بدهد، `ok` در بدنه نیست و همان‌جا رد می‌شود.
         */
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return $bad + ['message' => 'پاسخ حسابداری فروشگاه JSON نبود (کد ' . $code . ').'];
        }
        if (empty($data['ok'])) {
            $msg = isset($data['error']) ? (string)$data['error'] : ('کد ' . $code);
            return $bad + ['message' => 'حسابداری فروشگاه داده نداد: ' . mb_substr($msg, 0, 120)];
        }
        if (!isset($data['shareholders']) || !is_array($data['shareholders'])) {
            return $bad + ['message' => 'پاسخ حسابداری فروشگاه فهرست سهامداران را نداشت.'];
        }

        return ['ok' => true, 'message' => '', 'data' => $data];
    }
}
