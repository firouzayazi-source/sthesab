<?php
/**
 * ⛔ پیامکِ بانک از پس‌زمینه‌ی اپ اندروید (`SmsSync.java`).
 *
 * فقط فیلدهای خوانده‌شده می‌رسند (`smsWorker()` در `assets/js/sms-core.js`)،
 * هرگز متنِ پیامک. منطق فقط در `includes/sms_sync.php`؛ این‌جا فقط پوسته.
 *
 * ⛔ هر دو مسیرِ نوشتن/خواندن `requireUser('sms')` دارند: توکنِ محدودِ اپ
 *    (`scope = sms`) **فقط** همین‌ها را باز می‌کند و بقیه‌ی v1 را نه.
 */

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/sms_sync.php';

/**
 * POST sms/claim {nonce} — بی‌توکن؛ کدِ یک‌بارمصرفی که خودِ اپ ساخته و صفحه‌ی
 * واردشده به کاربرِ نشست بسته است (`api/sms_link.php`).
 */
function v1SmsClaim(array $params): void
{
    $r = SmsSync::claim(Api::input('nonce'));
    if (!$r['ok']) { Api::fail('sms_claim', $r['message'], $r['status']); }
    Api::ok(['token' => $r['token'], 'username' => $r['username']]);
}

/** GET sms/wallets — نشانه‌های حساب‌ها (فقط چهار رقمِ آخر)، همان `walletSmsKeys()`ِ سایت. */
function v1SmsWallets(array $params): void
{
    $userId = Api::requireUser('sms');
    Api::ok(['items' => walletSmsKeys($userId)]);
}

/** POST sms/tx — یک پیامکِ خوانده‌شده → یک تراکنش (+ مانده). */
function v1SmsTx(array $params): void
{
    $userId = Api::requireUser('sms');
    $b = Api::body();
    $r = SmsSync::post($userId, [
        'type'      => $b['type'] ?? '',
        'amount'    => $b['amount'] ?? '',
        'date'      => $b['date'] ?? '',
        'wallet_id' => $b['wallet_id'] ?? 0,
        'how'       => $b['how'] ?? '',
        'balance'   => $b['balance'] ?? null,
        'note'      => $b['note'] ?? '',
        'fp'        => $b['fp'] ?? '',
        'sms_at'    => $b['sms_at'] ?? null,
    ]);
    if (!$r['ok']) { Api::fail('sms_tx', $r['message'], $r['status']); }
    Api::ok(['id' => $r['id'], 'duplicate' => $r['duplicate'], 'balance_set' => $r['balance_set'],
             'message' => $r['message']], $r['status']);
}
