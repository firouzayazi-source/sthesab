<?php
// گزارش مالی به داشبورد منتقل و ادغام شد
require_once __DIR__ . '/includes/auth.php';
Auth::initSession();

$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: dashboard.php' . ($query !== '' ? '?' . $query : ''));
exit;
