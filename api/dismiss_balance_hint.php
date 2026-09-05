<?php
/**
 * «نیازی نیست» — بستنِ کارتِ «دقیقه‌ی اول».
 *
 * ⛔ چرا یک اندپوینتِ جدا لازم است: شرطِ نمایش کارت «همه‌ی حساب‌ها صفرند»
 *    است، و کاربری که واقعاً پولش صفر است هرگز از آن بیرون نمی‌آمد. یعنی
 *    کارتی که قرار بود یک بار دیده شود، برای او **هر روز** برمی‌گشت.
 *
 * ⚠ چیزی جز همان نشانه نمی‌نویسد و هیچ عددی را عوض نمی‌کند؛ کاربر بعداً
 *   هر وقت بخواهد از «تعدیل موجودی» در صفحه‌ی حساب‌ها واردش می‌کند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

markBalanceSetup(Auth::userId());

jsonResponse(['success' => true, 'message' => 'باشد — هر وقت خواستید از «تعدیل موجودی» واردش کنید.']);
