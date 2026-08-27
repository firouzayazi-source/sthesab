<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$userId = Auth::userId();

$fromDate = getParam('from_date', startOfJalaliMonth());
$toDate   = getParam('to_date', today());

if (!isValidDate($fromDate)) $fromDate = startOfJalaliMonth();
if (!isValidDate($toDate)) $toDate = today();
if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare('
    SELECT t.transaction_date, t.type, t.title, t.amount, t.note, c.name AS category_name
    FROM transactions t
    LEFT JOIN categories c ON c.id = t.category_id
    WHERE t.user_id = :user_id AND t.transaction_date BETWEEN :from_date AND :to_date
    ORDER BY t.transaction_date ASC, t.created_at ASC
');
$stmt->execute(['user_id' => $userId, 'from_date' => $fromDate, 'to_date' => $toDate]);
$rows = $stmt->fetchAll();

$filename = 'transactions-' . $fromDate . '-to-' . $toDate . '.csv';

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Description: File Transfer');
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');

// BOM برای نمایش صحیح فارسی در اکسل
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['تاریخ', 'نوع', 'عنوان', 'دسته‌بندی', 'مبلغ (تومان)', 'توضیح']);

foreach ($rows as $row) {
    fputcsv($out, [
        toJalali($row['transaction_date']),
        typeLabel($row['type']),
        $row['title'],
        $row['category_name'] ?? '',
        $row['amount'],
        $row['note'] ?? '',
    ]);
}

fclose($out);
exit;
