<?php
/**
 * «یادآورها» در «سررسیدها» ادغام شد (زبانه‌ی یادآورهای من).
 *
 * ⚠ `Notify::push()` برای هر یادآور همین آدرس را به‌عنوان مقصدِ اعلان
 *   ذخیره می‌کند، و اعلان‌های ساخته‌شده در دیتابیس مانده‌اند — پس این
 *   هدایت برای آن‌ها هم لازم است، نه فقط برای بوک‌مارک.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();   // کاربرِ واردنشده مستقیم به صفحه‌ی ورود، نه یک پرشِ اضافه
header('Location: ' . APP_BASE_PATH . '/due.php?t=reminders', true, 301);
exit;
