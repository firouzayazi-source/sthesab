<?php
/**
 * این فایل به‌جای manifest.json ثابت استفاده می‌شود چون start_url و scope
 * باید مسیر مطلق درست اپ را بدانند (که فقط PHP با APP_BASE_PATH می‌داند).
 * یک manifest.json ایستا باعث می‌شد start_url نسبت به پوشه خود مانیفست
 * (یعنی assets/) حل شود، نه ریشه اپ — و همین باعث خطای Forbidden هنگام
 * باز کردن از آیکون صفحه اصلی می‌شد.
 */
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$manifest = [
    'name'             => APP_NAME,
    'short_name'       => APP_NAME,
    'start_url'        => APP_BASE_PATH . '/index.php',
    'scope'            => APP_BASE_PATH . '/',
    'display'          => 'standalone',
    'background_color' => '#0b0b0b',
    'theme_color'      => '#0b0b0b',
    'orientation'      => 'portrait',
    'icons' => [
        ['src' => APP_BASE_PATH . '/assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => APP_BASE_PATH . '/assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
