<?php
/**
 * «آینده مالی» در «سررسیدها» ادغام شد (زبانه‌ی فهرست).
 *
 * ⛔ این فایل عمداً می‌ماند و فقط هدایت می‌کند. حذفش سه چیز را بی‌صدا
 *    می‌شکست: بوک‌مارکِ کاربر، میان‌برِ PWA روی صفحه‌ی اصلیِ گوشی، و —
 *    مهم‌تر از همه — **ایمیل‌هایی که از قبل فرستاده شده‌اند**:
 *    `reminderEmailBody()` لینکش را با `appBaseUrl() . '/upcoming.php'`
 *    می‌ساخت و آن ایمیل‌ها دیگر قابل عوض کردن نیستند.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();   // کاربرِ واردنشده مستقیم به صفحه‌ی ورود، نه یک پرشِ اضافه
header('Location: ' . APP_BASE_PATH . '/due.php?t=list', true, 301);
exit;
