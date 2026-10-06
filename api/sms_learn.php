<?php
/**
 * ⛔ «یک بار بپرس، بعد خودکار» — حسابی که کاربر برای یک پیامکِ بانک انتخاب
 *    کرد، برای پیامک‌های بعدیِ همان بانک یاد گرفته می‌شود (`SmsSync::learn()`).
 *
 * بعد از ثبتِ دستیِ تراکنشی که از پیامک پر شده بود صدا زده می‌شود. ورودی
 * فقط نشانه‌هاست (`smsSourceKeys()`: چهار رقمِ کارت/حساب یا اثرِ انگشتِ
 * هشت‌رقمیِ خطِ فرستنده) — متنِ پیامک هرگز به سرور نمی‌آید (قاعده ۱۹).
 * فراموش کردن از ویرایشِ حساب است (`smsForgetWallet()`، `api/save_wallet.php`).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sms_sync.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId   = Auth::userId();
$walletId = (int)postParam('wallet_id');

$keys = explode(',', (string)postParam('keys'));
$ok = SmsSync::learn($userId, $walletId, $keys);
jsonResponse(['success' => $ok, 'message' => $ok ? 'یاد گرفته شد.' : 'یاد گرفته نشد.'], $ok ? 200 : 422);
