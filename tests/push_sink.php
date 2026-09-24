<?php
/**
 * «سرویسِ پوشِ» ساختگی برای `test_push.php` — روترِ `php -S`.
 * هر درخواست را (سرآیندها + بدنه‌ی خام) در فایلِ `PUSH_SINK_FILE` می‌نویسد.
 * مسیرِ `/gone` ۴۱۰ می‌دهد (اشتراکِ منقضی)، `/fail` ۵۰۰، بقیه ۲۰۱.
 */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit('Not found.'); }
$f = getenv('PUSH_SINK_FILE');
$rec = [
    'path'    => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
    'body'    => base64_encode((string)file_get_contents('php://input')),
];
if ($f) { file_put_contents($f, json_encode($rec) . "\n", FILE_APPEND | LOCK_EX); }
$p = (string)$rec['path'];
http_response_code(str_starts_with($p, '/gone') ? 410 : (str_starts_with($p, '/fail') ? 500 : 201));
echo 'ok';
