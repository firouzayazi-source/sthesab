<?php
/**
 * فهرست‌های کمکی و داشبورد — همه فقط خواندنی.
 *
 * ⚠ `cachedCategories()` اینجا استفاده **نمی‌شود**: آن تابع کاربر را از
 * `Auth::userId()` یعنی از نشست می‌گیرد، و درخواست API نشستی ندارد. اگر
 * صدایش می‌زدیم، شناسه صفر می‌شد و کاربر فقط دسته‌های پیش‌فرض را می‌دید.
 * قاعده‌ی معنایی همان است (`categoryScopeSql`)، فقط شناسه از توکن می‌آید.
 */

require_once __DIR__ . '/../../../includes/api.php';

/**
 * GET wallets — حساب‌ها با موجودی محاسبه‌شده.
 *
 * `walletBalances()` تنها مرجع موجودی در کل پروژه است و اینجا هم همان
 * صدا زده می‌شود، نه یک جمعِ دست‌ساز — وگرنه عددِ اپ با عددِ سایت فرق
 * می‌کرد (چک پاس‌شده و پرداخت طلب هم در آن حساب می‌شوند).
 *
 * شماره‌ی کارت و شبا عمداً **نمی‌آیند**: در سایت هم فقط در نمای کارت باز
 * می‌شوند و حساس‌اند. اگر روزی اپ لازمشان داشت، اندپوینت جداگانه.
 */
function v1Wallets(array $params): void
{
    $userId = Api::requireUser();

    $items = array_map(static function (array $w): array {
        return [
            'id'         => (int)$w['id'],
            'name'       => $w['name'],
            'kind'       => $w['kind'],
            'kind_label' => $w['kind_label'] ?? null,
            'bank_name'  => $w['bank_name'] ?? null,
            'color'      => $w['color'] ?? null,
            'balance'    => Api::money($w['balance']),
            'is_active'  => (int)$w['is_active'] === 1,
        ];
    }, walletBalances($userId));

    Api::ok([
        'items' => $items,
        'total_balance' => Api::money(totalBalance($userId)),
    ]);
}

/**
 * GET categories  ?type=income|expense
 *
 * `is_default` می‌گوید دسته‌ی برنامه است یا شخصیِ کاربر — اپ برای همان
 * تصمیم می‌گیرد که دکمه‌ی حذف را نشان بدهد یا نه.
 */
function v1Categories(array $params): void
{
    $userId = Api::requireUser();

    $where = ['is_active = 1', categoryScopeSql()];
    $args  = categoryScopeParams($userId);

    $type = Api::query('type');
    if ($type !== '') {
        if (!in_array($type, ['income', 'expense'], true)) {
            Api::fail('invalid_type', 'نوع دسته‌بندی نامعتبر است.', 422);
        }
        $where[] = 'type = :type';
        $args['type'] = $type;
    }

    $stmt = Database::getConnection()->prepare(
        'SELECT id, name, type, icon, color, user_id FROM categories
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY type, name'
    );
    $stmt->execute($args);

    $items = array_map(static fn(array $c): array => [
        'id'         => (int)$c['id'],
        'name'       => $c['name'],
        'type'       => $c['type'],
        'icon'       => $c['icon'] ?? null,
        'color'      => $c['color'] ?? null,
        'is_default' => $c['user_id'] === null,
    ], $stmt->fetchAll());

    Api::ok(['items' => $items]);
}

/**
 * GET dashboard — همان اعدادی که صفحه‌ی اول سایت نشان می‌دهد.
 *
 * بازه‌ی پیش‌فرض «ماه جاری شمسی» است و از `startOfJalaliMonth()` می‌آید،
 * نه از ماه میلادی — وگرنه عددِ اپ با عددِ سایت نمی‌خواند.
 */
function v1Dashboard(array $params): void
{
    $userId = Api::requireUser();

    $from = Api::query('from') ?: startOfJalaliMonth();
    $to   = Api::query('to')   ?: today();

    if (!isValidDate($from) || !isValidDate($to)) {
        Api::fail('invalid_range', 'بازه‌ی تاریخ نامعتبر است.', 422);
    }

    $stmt = Database::getConnection()->prepare('
        SELECT type, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt
        FROM transactions
        WHERE user_id = :uid AND transaction_date BETWEEN :from AND :to
        GROUP BY type
    ');
    $stmt->execute(['uid' => $userId, 'from' => $from, 'to' => $to]);

    $income = 0; $expense = 0; $count = 0;
    foreach ($stmt->fetchAll() as $row) {
        $count += (int)$row['cnt'];
        if ($row['type'] === 'income') { $income = Api::money($row['total']); }
        else                           { $expense = Api::money($row['total']); }
    }

    Api::ok([
        'range' => ['from' => Api::date($from), 'to' => Api::date($to)],
        'income'  => $income,
        'expense' => $expense,
        'net'     => $income - $expense,
        'transaction_count' => $count,
        'total_balance'     => Api::money(totalBalance($userId)),
    ]);
}
