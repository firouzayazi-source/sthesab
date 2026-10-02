<?php
/**
 * «تقویم مالی» — اول در «سررسیدها» ادغام شد (زبانه‌ی تقویم) و حالا روی
 * خانه است (`includes/home_calendar.php`). استاب می‌ماند تا بوک‌مارک و
 * لینک‌های قبلی نشکنند؛ ماهِ انتخاب‌شده حفظ می‌شود.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/home_calendar.php';

Auth::initSession();
Auth::requireLogin();   // کاربرِ واردنشده مستقیم به صفحه‌ی ورود، نه یک پرشِ اضافه

$jy = (int)getParam('jy', '0');
$jm = (int)getParam('jm', '0');
header('Location: ' . homeCalendarUrl($jy ?: null, $jm ?: null), true, 301);
exit;
