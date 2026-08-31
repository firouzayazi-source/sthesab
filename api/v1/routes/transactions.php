<?php
/**
 * تراکنش‌ها — مسیر اصلیِ پول.
 *
 * نوشتن از `includes/transactions.php` می‌گذرد؛ همان توابعی که
 * `api/add_transaction.php` وب هم صدا می‌زند. پس قواعد اعتبارسنجی و
 * مالکیت یک جا تعریف شده‌اند و نمی‌توانند از هم دور بیفتند.
 */

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/transactions.php';

/** بیشترین تعداد ردیف در یک صفحه — سقف دارد تا یک درخواست سرور را نخواباند. */
const V1_MAX_PER_PAGE = 100;

/** یک ردیف تراکنش، به شکلی که در همه‌ی اندپوینت‌ها یکسان است. */
function v1TransactionRow(array $r): array
{
    return [
        'id'     => (int)$r['id'],
        'type'   => $r['type'],
        'amount' => Api::money($r['amount']),
        'title'  => $r['title'],
        'note'   => $r['note'],
        'date'   => Api::date($r['transaction_date']),
        'category' => $r['category_id'] === null ? null : [
            'id'    => (int)$r['category_id'],
            'name'  => $r['category_name'],
            'icon'  => $r['category_icon'] ?? null,
            'color' => $r['category_color'] ?? null,
        ],
        'wallet' => $r['wallet_id'] === null ? null : [
            'id'   => (int)$r['wallet_id'],
            'name' => $r['wallet_name'],
        ],
        'created_at' => $r['created_at'] ?? null,
    ];
}

/** کوئری مشترکِ خواندن، تا شکل ردیف در فهرست و تکی یکی بماند. */
function v1TransactionSelect(): string
{
    return '
        SELECT t.id, t.type, t.amount, t.title, t.note, t.transaction_date, t.created_at,
               t.category_id, c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
               t.wallet_id, w.name AS wallet_name
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        LEFT JOIN wallets    w ON w.id = t.wallet_id
    ';
}

/**
 * GET transactions
 *   ?from=YYYY-MM-DD &to= &type=income|expense &category_id= &wallet_id=
 *   &page=1 &per_page=50
 *
 * تاریخ‌ها میلادی گرفته می‌شوند (همان چیزی که خودِ API برگردانده)، تا
 * اپ هرگز مجبور به تبدیل شمسی نشود.
 */
function v1TransactionsList(array $params): void
{
    $userId = Api::requireUser();

    // قطعه‌های ثابت در آرایه، مقادیر همیشه bind — الگوی transactions.php
    $where  = ['t.user_id = :uid'];
    $args   = ['uid' => $userId];

    $from = Api::query('from');
    if ($from !== '') {
        if (!isValidDate($from)) { Api::fail('invalid_from', 'تاریخ شروع نامعتبر است.', 422); }
        $where[] = 't.transaction_date >= :from';
        $args['from'] = $from;
    }

    $to = Api::query('to');
    if ($to !== '') {
        if (!isValidDate($to)) { Api::fail('invalid_to', 'تاریخ پایان نامعتبر است.', 422); }
        $where[] = 't.transaction_date <= :to';
        $args['to'] = $to;
    }

    $type = Api::query('type');
    if ($type !== '') {
        if (!in_array($type, ['income', 'expense'], true)) {
            Api::fail('invalid_type', 'نوع تراکنش نامعتبر است.', 422);
        }
        $where[] = 't.type = :type';
        $args['type'] = $type;
    }

    $categoryId = (int)Api::query('category_id');
    if ($categoryId > 0) { $where[] = 't.category_id = :cat'; $args['cat'] = $categoryId; }

    $walletId = (int)Api::query('wallet_id');
    if ($walletId > 0) { $where[] = 't.wallet_id = :wal'; $args['wal'] = $walletId; }

    $page    = max(1, (int)Api::query('page', '1'));
    $perPage = (int)Api::query('per_page', '50');
    $perPage = max(1, min(V1_MAX_PER_PAGE, $perPage ?: 50));
    $offset  = ($page - 1) * $perPage;

    $pdo    = Database::getConnection();
    $clause = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM transactions t ' . $clause);
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    // LIMIT/OFFSET با عدد صحیحِ سنجیده‌شده درج می‌شوند، نه با ورودی خام
    $stmt = $pdo->prepare(
        v1TransactionSelect() . ' ' . $clause .
        " ORDER BY t.transaction_date DESC, t.id DESC LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($args);

    Api::ok([
        'items' => array_map('v1TransactionRow', $stmt->fetchAll()),
        'page'  => [
            'number'   => $page,
            'per_page' => $perPage,
            'total'    => $total,
            'pages'    => (int)ceil($total / $perPage),
        ],
    ]);
}

/** GET transactions/{id} */
function v1TransactionsShow(array $params): void
{
    $userId = Api::requireUser();

    $stmt = Database::getConnection()->prepare(
        v1TransactionSelect() . ' WHERE t.id = :id AND t.user_id = :uid'
    );
    $stmt->execute(['id' => $params['id'], 'uid' => $userId]);
    $row = $stmt->fetch();

    if (!$row) { Api::fail('not_found', 'تراکنش مورد نظر یافت نشد.', 404); }

    Api::ok(v1TransactionRow($row));
}

/** POST transactions */
function v1TransactionsCreate(array $params): void
{
    $userId = Api::requireUser();
    $b = Api::body();

    $result = txCreate($userId, [
        'type'             => $b['type'] ?? '',
        'amount'           => $b['amount'] ?? '',
        'title'            => $b['title'] ?? '',
        'note'             => $b['note'] ?? '',
        'transaction_date' => $b['transaction_date'] ?? '',
        'category_id'      => $b['category_id'] ?? '',
        'wallet_id'        => $b['wallet_id'] ?? '',
    ]);

    if (!$result['ok']) { Api::fail($result['code'], $result['message'], $result['status']); }

    Api::ok(['id' => $result['id'], 'message' => $result['message']], 201);
}

/** PATCH transactions/{id} */
function v1TransactionsUpdate(array $params): void
{
    $userId = Api::requireUser();
    $b = Api::body();

    $result = txUpdate($userId, $params['id'], [
        'type'             => $b['type'] ?? '',
        'amount'           => $b['amount'] ?? '',
        'title'            => $b['title'] ?? '',
        'note'             => $b['note'] ?? '',
        'transaction_date' => $b['transaction_date'] ?? '',
        'category_id'      => $b['category_id'] ?? '',
    ]);

    if (!$result['ok']) { Api::fail($result['code'], $result['message'], $result['status']); }

    Api::ok(['id' => $result['id'], 'message' => $result['message']]);
}

/** DELETE transactions/{id} */
function v1TransactionsDelete(array $params): void
{
    $userId = Api::requireUser();

    $result = txDelete($userId, $params['id']);
    if (!$result['ok']) { Api::fail($result['code'], $result['message'], $result['status']); }

    Api::ok(['id' => $result['id'], 'message' => $result['message']]);
}
