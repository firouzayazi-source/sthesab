<?php
/**
 * منطق نوشتن تراکنش — تنها جایی که این قواعد تعریف می‌شوند.
 *
 * چرا جدا شد: پیش از این، اعتبارسنجی و بررسی مالکیت و خودِ کوئری داخل
 * `api/add_transaction.php` و `api/update_transaction.php` بودند. با
 * آمدن `api/v1` همان‌ها باید بار دوم نوشته می‌شدند — و دو نسخه از منطقِ
 * پول دیر یا زود از هم دور می‌افتند: یکی سقف مبلغ را عوض می‌کند، آن یکی
 * نه؛ یکی مالکیت دسته را می‌سنجد، آن یکی فراموش می‌کند. حالا هر دو
 * مشتری (وب و API) از همین‌جا رد می‌شوند.
 *
 * این توابع **هیچ چیز چاپ نمی‌کنند و exit نمی‌کنند**. فقط نتیجه
 * برمی‌گردانند تا هر لایه پاسخ خودش را بسازد: وب با jsonResponse و
 * پاکت success/message، و API با پاکت ok/data.
 *
 * شکل خروجی:
 *   ['ok' => true,  'status' => 200, 'id' => 12]
 *   ['ok' => false, 'status' => 422, 'code' => 'validation', 'message' => '...']
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/** سقف مبلغ — ستون BIGINT است ولی عددِ بی‌معنی هم نباید وارد شود. */
const TX_MAX_AMOUNT = 999999999999;

/**
 * اعتبارسنجی مشترکِ فیلدهای یک تراکنش.
 *
 * @return array ['errors' => string[], 'values' => array]
 */
function txValidate(array $in): array
{
    $errors = [];

    $type = (string)($in['type'] ?? '');
    if (!in_array($type, ['income', 'expense'], true)) {
        $errors[] = 'نوع تراکنش نامعتبر است.';
    }

    $amount = sanitizeAmount($in['amount'] ?? '');
    if ($amount <= 0) {
        $errors[] = 'مبلغ باید بزرگ‌تر از صفر باشد.';
    }
    if ($amount > TX_MAX_AMOUNT) {
        $errors[] = 'مبلغ وارد شده بیش از حد بزرگ است.';
    }

    $title = trim((string)($in['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 255) {
        $errors[] = 'عنوان الزامی است و باید کمتر از ۲۵۵ کاراکتر باشد.';
    }

    $date = (string)($in['transaction_date'] ?? '');
    if (!isValidDate($date)) {
        $errors[] = 'تاریخ وارد شده نامعتبر است.';
    }

    $note = trim((string)($in['note'] ?? ''));
    if (mb_strlen($note) > 1000) {
        $errors[] = 'توضیحات نباید بیشتر از ۱۰۰۰ کاراکتر باشد.';
    }

    $categoryId = null;
    $rawCategory = (string)($in['category_id'] ?? '');
    if ($rawCategory !== '') {
        $categoryId = (int)$rawCategory;
        if ($categoryId <= 0) {
            $errors[] = 'دسته‌بندی انتخاب‌شده نامعتبر است.';
            $categoryId = null;
        }
    }

    return [
        'errors' => $errors,
        'values' => [
            'type'             => $type,
            'amount'           => $amount,
            'title'            => $title,
            'note'             => $note !== '' ? $note : null,
            'transaction_date' => $date,
            'category_id'      => $categoryId,
        ],
    ];
}

/**
 * دسته باید مالِ همین کاربر (یا پیش‌فرضِ برنامه) باشد و جهتش بخواند.
 *
 * اگر نبود، به‌جای خطا `null` می‌شود — یعنی تراکنش بی‌دسته ثبت می‌شود.
 * این رفتارِ از پیش موجود است و عمداً حفظ شده: پول نباید به خاطر یک
 * دسته‌ی نامعتبر گم شود. `categoryScopeSql()` تنها تعریفِ «دسته‌ی این
 * کاربر» در کل پروژه است و هر کوئری باید از آن رد شود.
 */
function txResolveCategory(int $userId, ?int $categoryId, string $type): ?int
{
    if ($categoryId === null) { return null; }

    $stmt = Database::getConnection()->prepare(
        'SELECT id FROM categories
         WHERE id = :id AND type = :type AND is_active = 1 AND ' . categoryScopeSql()
    );
    $stmt->execute(['id' => $categoryId, 'type' => $type] + categoryScopeParams($userId));

    return $stmt->fetchColumn() ? $categoryId : null;
}

/** حساب باید مالِ همین کاربر باشد، وگرنه بی‌حساب ثبت می‌شود. */
function txResolveWallet(int $userId, $walletId): ?int
{
    $walletId = (int)$walletId;
    if ($walletId <= 0) { return null; }

    $stmt = Database::getConnection()->prepare('SELECT id FROM wallets WHERE id = :id AND user_id = :u');
    $stmt->execute(['id' => $walletId, 'u' => $userId]);

    return $stmt->fetchColumn() ? $walletId : null;
}

/** ثبت تراکنش تازه. */
function txCreate(int $userId, array $in): array
{
    $v = txValidate($in);
    if ($v['errors']) {
        return ['ok' => false, 'status' => 422, 'code' => 'validation',
                'message' => implode(' ', $v['errors'])];
    }

    $val = $v['values'];
    $val['category_id'] = txResolveCategory($userId, $val['category_id'], $val['type']);
    $walletId = txResolveWallet($userId, $in['wallet_id'] ?? 0);

    $pdo = Database::getConnection();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('
            INSERT INTO transactions (user_id, category_id, wallet_id, type, amount, title, note, transaction_date)
            VALUES (:user_id, :category_id, :wallet_id, :type, :amount, :title, :note, :transaction_date)
        ');
        $stmt->execute(['user_id' => $userId, 'wallet_id' => $walletId] + $val);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();

        return ['ok' => true, 'status' => 201, 'id' => $id, 'message' => 'تراکنش با موفقیت ثبت شد.'];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('txCreate: ' . $e->getMessage());
        return ['ok' => false, 'status' => 500, 'code' => 'server_error',
                'message' => 'خطایی در ثبت تراکنش رخ داد. دوباره تلاش کنید.'];
    }
}

/**
 * ویرایش تراکنش.
 *
 * ⚠ `wallet_id` عمداً دست‌نخورده می‌ماند — رفتارِ از پیش موجودِ فرم
 * ویرایش همین است. تغییر حساب از این مسیر انجام نمی‌شود.
 */
function txUpdate(int $userId, int $id, array $in): array
{
    if ($id <= 0) {
        return ['ok' => false, 'status' => 422, 'code' => 'invalid_id',
                'message' => 'شناسه تراکنش نامعتبر است.'];
    }

    $owned = txAssertOwned($userId, $id, 'ویرایش');
    if ($owned !== null) { return $owned; }

    $v = txValidate($in);
    if ($v['errors']) {
        return ['ok' => false, 'status' => 422, 'code' => 'validation',
                'message' => implode(' ', $v['errors'])];
    }

    $val = $v['values'];
    $val['category_id'] = txResolveCategory($userId, $val['category_id'], $val['type']);

    try {
        Database::getConnection()->prepare('
            UPDATE transactions
            SET category_id = :category_id, type = :type, amount = :amount,
                title = :title, note = :note, transaction_date = :transaction_date
            WHERE id = :id AND user_id = :user_id
        ')->execute($val + ['id' => $id, 'user_id' => $userId]);

        return ['ok' => true, 'status' => 200, 'id' => $id, 'message' => 'تراکنش با موفقیت ویرایش شد.'];
    } catch (PDOException $e) {
        error_log('txUpdate: ' . $e->getMessage());
        return ['ok' => false, 'status' => 500, 'code' => 'server_error',
                'message' => 'خطایی در ویرایش تراکنش رخ داد.'];
    }
}

/** حذف تراکنش. */
function txDelete(int $userId, int $id): array
{
    if ($id <= 0) {
        return ['ok' => false, 'status' => 422, 'code' => 'invalid_id',
                'message' => 'شناسه تراکنش نامعتبر است.'];
    }

    $owned = txAssertOwned($userId, $id, 'حذف');
    if ($owned !== null) { return $owned; }

    try {
        Database::getConnection()
            ->prepare('DELETE FROM transactions WHERE id = :id AND user_id = :user_id')
            ->execute(['id' => $id, 'user_id' => $userId]);

        return ['ok' => true, 'status' => 200, 'id' => $id, 'message' => 'تراکنش با موفقیت حذف شد.'];
    } catch (PDOException $e) {
        error_log('txDelete: ' . $e->getMessage());
        return ['ok' => false, 'status' => 500, 'code' => 'server_error',
                'message' => 'خطایی در حذف تراکنش رخ داد.'];
    }
}

/**
 * تراکنش باید باشد و مالِ همین کاربر.
 *
 * ۴۰۴ و ۴۰۳ عمداً از هم جدا نگه داشته شده‌اند چون رفتارِ از پیش موجود
 * همین بوده. (اگر روزی خواستید وجود یا نبودِ رکوردِ دیگران هم لو نرود،
 * هر دو را ۴۰۴ کنید — ولی آن تغییرِ رفتار است و باید آگاهانه باشد.)
 *
 * @return array|null  خطا، یا null اگر همه‌چیز درست بود
 */
function txAssertOwned(int $userId, int $id, string $verb): ?array
{
    $stmt = Database::getConnection()->prepare('SELECT user_id FROM transactions WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $ownerId = $stmt->fetchColumn();

    if ($ownerId === false) {
        return ['ok' => false, 'status' => 404, 'code' => 'not_found',
                'message' => 'تراکنش مورد نظر یافت نشد.'];
    }
    if ((int)$ownerId !== $userId) {
        return ['ok' => false, 'status' => 403, 'code' => 'forbidden',
                'message' => "شما اجازه {$verb} این تراکنش را ندارید."];
    }

    return null;
}
