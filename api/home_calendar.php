<?php
/**
 * تقویمِ خانه — درونِ کارت برای یک ماه (HTML، نه JSON).
 *
 * ⛔ فقط‌خواندنی است و عمداً CSRF ندارد (فهرستِ `$csrfExempt` در
 *    `test_api_contract.php`). اگر روزی چیزی نوشت، باید اضافه شود.
 * ⛔ خروجی از همان `homeCalendarHtml()`ِ پوسته‌ی خانه است — یک رندرکننده.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/home_calendar.php';

Auth::initSession();
if (!Auth::isLoggedIn()) { http_response_code(401); exit; }

$userId = Auth::userId();
[$jy, $jm] = homeCalendarMonth((int)getParam('jy', '0'), (int)getParam('jm', '0'));

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo homeCalendarHtml($jy, $jm, homeCalendarData($userId, $jy, $jm));
