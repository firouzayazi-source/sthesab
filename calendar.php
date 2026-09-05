<?php
/**
 * «تقویم مالی» در «سررسیدها» ادغام شد (زبانه‌ی تقویم).
 * ماهِ انتخاب‌شده حفظ می‌شود تا لینکِ یک ماهِ مشخص نشکند.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();   // کاربرِ واردنشده مستقیم به صفحه‌ی ورود، نه یک پرشِ اضافه

$q = 'due.php?t=calendar';
$jy = (int)getParam('jy', '0');
$jm = (int)getParam('jm', '0');
if ($jy >= 1300 && $jy <= 1500 && $jm >= 1 && $jm <= 12) {
    $q .= '&jy=' . $jy . '&jm=' . $jm;
}
header('Location: ' . APP_BASE_PATH . '/' . $q, true, 301);
exit;
