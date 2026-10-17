<?php
/**
 * ⛔ اعلان از راهِ ربات بله یا تلگرام (migration_bot، `admin/bot.php`، بخشِ «ربات» در تنظیماتِ فروشگاه).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** اعلانِ گوشی از ایران پایدار نیست؛ خلاصه‌ی شبانه و هشدارها در بله یا
 * تلگرام برسد — **کاملاً جدا از دو رباتی که روی همین سرور کار می‌کنند**.
 *
 * ⛔ جداسازی: این کلاس فقط با API عمومیِ پیام‌رسان و فقط با توکنی حرف می‌زند که مدیرِ نصب برای **همین** کار
 *    ساخته (رمزشده در `app_settings`). هیچ فایل، سرویس، پورت یا وب‌هوکی از رباتِ دیگری را نمی‌شناسد. و چون
 *    `getUpdates` پیام‌های یک ربات را «مصرف» می‌کند، توکنی که وب‌هوک دارد (یعنی رباتِ دیگری پشتش است)
 *    **رد می‌شود** — وگرنه پیام‌های آن ربات را می‌دزدیدیم.
 * ⛔ وب‌هوک نه: اتصال با `getUpdates` فقط وقتی کاربر «بررسیِ اتصال» را می‌زند (`poll()`)؛ هیچ پورت یا مسیرِ
 *    تازه‌ای در nginx باز نمی‌شود.
 * ⛔ ربات هرگز شروع‌کننده نیست: کاربر کدِ یک‌بارمصرفِ ۶ رقمیِ خودش (`code()`، ۳۰ دقیقه) را به ربات می‌فرستد؛
 *    فقط همان گفت‌وگو وصل می‌شود.
 * ⛔ تست هرگز پیامِ واقعی نمی‌فرستد: سدِ `Outbound::blockedFor()` در تنها درِ خروج (`call()`) و `$httpForTests`.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/outbound.php';
if (is_file(__DIR__ . '/crypto.php')) { require_once __DIR__ . '/crypto.php'; }

final class Bot
{
    public const PROVIDERS = [
        'bale'     => ['label' => 'بله',    'api' => 'https://tapi.bale.ai',      'chat' => 'https://ble.ir/'],
        'telegram' => ['label' => 'تلگرام', 'api' => 'https://api.telegram.org', 'chat' => 'https://t.me/'],
    ];
    public const SET_PROVIDER = 'bot_provider';
    public const SET_TOKEN    = 'bot_token';
    public const SET_NAME     = 'bot_username';
    public const SET_OFFSET   = 'bot_offset';
    public const CODE_MINUTES = 30;

    /** فقط برای تست: `fn(string $url, array $payload): array{code:int, body:string}`. */
    public static ?Closure $httpForTests = null;

    public static function ready(): bool
    {
        return tableExists('bot_links');
    }

    public static function provider(): string
    {
        $p = (string)getSetting(self::SET_PROVIDER, '');
        return isset(self::PROVIDERS[$p]) ? $p : '';
    }

    private static function token(): string
    {
        $raw = (string)getSetting(self::SET_TOKEN, '');
        if ($raw === '') { return ''; }
        return class_exists('Crypto') ? (string)(Crypto::decrypt($raw) ?? '') : $raw;
    }

    public static function configured(): bool
    {
        return self::ready() && self::provider() !== '' && self::token() !== '';
    }

    public static function username(): string
    {
        return (string)getSetting(self::SET_NAME, '');
    }

    /** پیوندِ گفت‌وگو با ربات (برای دکمه‌ی «باز کردنِ ربات»). */
    public static function chatUrl(): string
    {
        $p = self::provider();
        return $p !== '' && self::username() !== '' ? self::PROVIDERS[$p]['chat'] . rawurlencode(self::username()) : '';
    }

    /** ⛔ تنها درِ خروج. @return array{ok:bool, result:mixed, error:string, code:int} */
    private static function call(string $method, array $payload = [], ?string $provider = null, ?string $token = null): array
    {
        $provider = $provider ?? self::provider();
        $token = $token ?? self::token();
        if ($provider === '' || $token === '') { return ['ok' => false, 'result' => null, 'error' => 'ربات تنظیم نشده است.', 'code' => 0]; }
        $url = self::PROVIDERS[$provider]['api'] . '/bot' . $token . '/' . $method;
        if (self::$httpForTests !== null && PHP_SAPI === 'cli') {
            $r = (self::$httpForTests)($url, $payload);
            $code = (int)$r['code']; $body = (string)$r['body'];
        } else {
            if (($e = Outbound::blockedFor($url, 'پیامِ ربات')) !== null) { return ['ok' => false, 'result' => null, 'error' => $e, 'code' => 0]; }
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
            $body = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            unset($ch);
            if ($code === 0) { return ['ok' => false, 'result' => null, 'error' => 'به ' . self::PROVIDERS[$provider]['label'] . ' وصل نشد' . ($err !== '' ? ' (' . $err . ')' : '') . '.', 'code' => 0]; }
        }
        $j = json_decode($body, true);
        $ok = is_array($j) && ($j['ok'] ?? false) === true;
        // ⛔ هرگز توکن در پیامِ خطا نمی‌آید (آدرس را برنمی‌گردانیم)
        return ['ok' => $ok, 'result' => $ok ? ($j['result'] ?? null) : null, 'code' => $code,
                'error' => $ok ? '' : 'پاسخِ ' . self::PROVIDERS[$provider]['label'] . ': ' . mb_substr((string)(is_array($j) ? ($j['description'] ?? 'خطا') : 'نامعتبر'), 0, 120)];
    }

    /**
     * ⛔ ذخیره‌ی ربات (مدیرِ نصب): توکن با `getMe` سنجیده می‌شود و **رباتِ وب‌هوک‌دار رد** — یعنی رباتِ دیگری پشتش است.
     * توکنِ خالی = دست نزن (همان قاعده‌ی کلیدِ درگاه).
     * @return array{ok:bool, message:string}
     */
    public static function saveConfig(string $provider, string $token): array
    {
        if ($provider === '') {
            setSetting(self::SET_PROVIDER, '');
            return ['ok' => true, 'message' => 'ربات خاموش شد.'];
        }
        if (!isset(self::PROVIDERS[$provider])) { return ['ok' => false, 'message' => 'پیام‌رسان معتبر نیست.']; }
        $token = trim($token);
        if ($token === '') {
            if (self::token() === '') { return ['ok' => false, 'message' => 'توکنِ ربات را بنویسید.']; }
            setSetting(self::SET_PROVIDER, $provider);
            return ['ok' => true, 'message' => 'پیام‌رسان ذخیره شد.'];
        }
        if (!preg_match('/^\d{4,15}:[A-Za-z0-9_-]{20,80}$/', $token)) { return ['ok' => false, 'message' => 'شکلِ توکن درست نیست (مثلِ 123456:ABC…).']; }
        $me = self::call('getMe', [], $provider, $token);
        if (!$me['ok'] || !is_array($me['result'])) { return ['ok' => false, 'message' => 'توکن پذیرفته نشد — ' . $me['error']]; }
        $wh = self::call('getWebhookInfo', [], $provider, $token);
        if ($wh['ok'] && is_array($wh['result']) && (string)($wh['result']['url'] ?? '') !== '') {
            return ['ok' => false, 'message' => 'این ربات وب‌هوک دارد، یعنی برنامه‌ی دیگری پشتش است. برای ' . (defined('APP_NAME') ? APP_NAME : 'این برنامه') . ' یک رباتِ تازه بسازید — توکنِ ربات‌های دیگر را این‌جا نگذارید.'];
        }
        setSetting(self::SET_PROVIDER, $provider);
        setSetting(self::SET_TOKEN, class_exists('Crypto') && Crypto::available() ? (string)Crypto::encrypt($token) : $token);
        setSetting(self::SET_NAME, (string)($me['result']['username'] ?? ''));
        setSetting(self::SET_OFFSET, '0');
        return ['ok' => true, 'message' => 'ربات «' . (string)($me['result']['username'] ?? '') . '» ذخیره شد.'];
    }

    /** @return array{linked:bool, code:?string, code_at:?string, linked_at:?string} */
    public static function link(int $userId): array
    {
        $st = Database::getConnection()->prepare('SELECT chat_id, code, code_at, linked_at FROM bot_links WHERE user_id = :u');
        $st->execute(['u' => $userId]);
        $r = $st->fetch();
        $fresh = $r && $r['code'] !== null && strtotime((string)$r['code_at']) > time() - self::CODE_MINUTES * 60;
        return ['linked' => $r && $r['chat_id'] !== null, 'code' => $fresh ? (string)$r['code'] : null,
                'code_at' => $r['code_at'] ?? null, 'linked_at' => $r['linked_at'] ?? null];
    }

    /** کدِ یک‌بارمصرفِ تازه (۶ رقم، یکتا). */
    public static function code(int $userId): string
    {
        $pdo = Database::getConnection();
        for ($i = 0; $i < 8; $i++) {
            $c = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            try {
                $pdo->prepare('INSERT INTO bot_links (user_id, code, code_at) VALUES (:u, :c, NOW())
                               ON DUPLICATE KEY UPDATE code = VALUES(code), code_at = VALUES(code_at)')->execute(['u' => $userId, 'c' => $c]);
                return $c;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') { throw $e; }
            }
        }
        throw new RuntimeException('کدِ یکتا ساخته نشد.');
    }

    /**
     * ⛔ خواندنِ پیام‌های تازه‌ی ربات و وصل کردنِ کدهای معتبر. فقط وقتی کسی «بررسی» را می‌زند.
     * @return array{ok:bool, message:string, linked:int}
     */
    public static function poll(): array
    {
        if (!self::configured()) { return ['ok' => false, 'message' => 'ربات تنظیم نشده است.', 'linked' => 0]; }
        $offset = (int)getSetting(self::SET_OFFSET, '0');
        $r = self::call('getUpdates', ['offset' => $offset, 'timeout' => 0, 'limit' => 100, 'allowed_updates' => ['message']]);
        if (!$r['ok']) { return ['ok' => false, 'message' => $r['error'], 'linked' => 0]; }
        $pdo = Database::getConnection();
        $n = 0; $max = $offset - 1;
        $find = $pdo->prepare('SELECT user_id FROM bot_links WHERE code = :c AND code_at > DATE_SUB(NOW(), INTERVAL ' . self::CODE_MINUTES . ' MINUTE)');
        $set = $pdo->prepare('UPDATE bot_links SET chat_id = :chat, code = NULL, code_at = NULL, linked_at = NOW() WHERE user_id = :u AND code = :c');
        foreach ((array)$r['result'] as $up) {
            $max = max($max, (int)($up['update_id'] ?? 0));
            $msg = $up['message'] ?? null;
            if (!is_array($msg) || ($msg['chat']['type'] ?? '') !== 'private') { continue; }
            if (!preg_match('/(?:^|\s)(\d{6})(?:\s|$)/', toLatinDigits((string)($msg['text'] ?? '')), $m)) { continue; }
            $find->execute(['c' => $m[1]]);
            $uid = (int)$find->fetchColumn();
            if ($uid <= 0) { continue; }
            $set->execute(['chat' => (int)$msg['chat']['id'], 'u' => $uid, 'c' => $m[1]]);
            if ($set->rowCount() > 0) {
                $n++;
                self::call('sendMessage', ['chat_id' => (int)$msg['chat']['id'], 'text' => 'وصل شد ✓ از این به بعد خلاصه‌ها و هشدارهای ' . (defined('APP_NAME') ? APP_NAME : 'این برنامه') . ' این‌جا می‌آید.']);
            }
        }
        // ⛔ پیشروی فقط بعد از پردازش — پیامِ خوانده‌نشده هرگز دور ریخته نمی‌شود
        if ($max >= $offset) { setSetting(self::SET_OFFSET, (string)($max + 1)); }
        return ['ok' => true, 'message' => $n > 0 ? 'وصل شد.' : 'هنوز پیامِ کد به ربات نرسیده است.', 'linked' => $n];
    }

    /** فرستادن به گفت‌وگوی وصل‌شده‌ی یک کاربر. بی‌اتصال = false، بی‌صدا. */
    public static function send(int $userId, string $text): bool
    {
        if (!self::configured()) { return false; }
        $st = Database::getConnection()->prepare('SELECT chat_id FROM bot_links WHERE user_id = :u AND chat_id IS NOT NULL');
        $st->execute(['u' => $userId]);
        $chat = $st->fetchColumn();
        if ($chat === false) { return false; }
        return self::call('sendMessage', ['chat_id' => (int)$chat, 'text' => mb_substr($text, 0, 3500)])['ok'];
    }

    public static function unlink(int $userId): void
    {
        Database::getConnection()->prepare('DELETE FROM bot_links WHERE user_id = :u')->execute(['u' => $userId]);
    }
}
