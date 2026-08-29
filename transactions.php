<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();

$period = getParam('period', 'all');
$type   = getParam('type', 'all');
$search = getParam('search', '');
$fromDate = getParam('from_date', '');
$toDate   = getParam('to_date', '');
$walletId = (int)getParam('wallet', '0');

$conditions = ['t.user_id = :user_id'];
$params = ['user_id' => $userId];

if ($period === 'today') {
    $conditions[] = 't.transaction_date = :p_from';
    $params['p_from'] = today();
} elseif ($period === 'week') {
    $conditions[] = 't.transaction_date BETWEEN :p_from AND :p_to';
    $params['p_from'] = startOfWeek();
    $params['p_to'] = today();
} elseif ($period === 'month') {
    $conditions[] = 't.transaction_date BETWEEN :p_from AND :p_to';
    $params['p_from'] = startOfJalaliMonth();
    $params['p_to'] = today();
} elseif ($period === 'year') {
    $conditions[] = 't.transaction_date BETWEEN :p_from AND :p_to';
    $params['p_from'] = startOfJalaliYear();
    $params['p_to'] = today();
} elseif ($period === 'custom' && isValidDate($fromDate) && isValidDate($toDate)) {
    $conditions[] = 't.transaction_date BETWEEN :p_from AND :p_to';
    $params['p_from'] = $fromDate;
    $params['p_to'] = $toDate;
}

if ($type === 'income' || $type === 'expense') {
    $conditions[] = 't.type = :type';
    $params['type'] = $type;
}

if ($search !== '') {
    $conditions[] = 't.title LIKE :search';
    $params['search'] = '%' . $search . '%';
}

// فیلتر حساب: بدون این، موجودی منفی یک حساب دیده می‌شد ولی هیچ راهی
// نبود بفهمی کدام تراکنش‌ها رویش نشسته‌اند.
$walletList = [];
$walletName = '';
try {
    $wStmt = $pdo->prepare('SELECT id, name FROM wallets WHERE user_id = :u ORDER BY is_active DESC, sort_order, name');
    $wStmt->execute(['u' => $userId]);
    $walletList = $wStmt->fetchAll();
} catch (PDOException $e) {
    $walletList = [];   // جدول حساب‌ها هنوز ساخته نشده
}

if ($walletId > 0) {
    foreach ($walletList as $w) {
        if ((int)$w['id'] === $walletId) { $walletName = $w['name']; break; }
    }
    if ($walletName === '') {
        $walletId = 0;      // مال این کاربر نیست — نادیده گرفته می‌شود
    } else {
        $conditions[] = 't.wallet_id = :wallet_id';
        $params['wallet_id'] = $walletId;
    }
}

$whereClause = 'WHERE ' . implode(' AND ', $conditions);

// ---------- صفحه‌بندی ----------
// بدون این، با انباشته‌شدن تراکنش‌ها صفحه به مگابایت می‌رسید و روی موبایل کند می‌شد.
$perPage = 40;
$page = max(1, (int)getParam('p', '1'));
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM transactions t $whereClause");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetch()['cnt'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$sql = "
    SELECT t.id, t.type, t.amount, t.title, t.note, t.transaction_date, t.created_at, t.category_id,
           c.name AS category_name, c.icon AS cat_icon, c.color AS cat_color
    FROM transactions t
    LEFT JOIN categories c ON c.id = t.category_id
    $whereClause
    ORDER BY t.transaction_date DESC, t.created_at DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$categories = cachedCategories();
$incomeCategories  = array_filter($categories, fn($c) => $c['type'] === 'income');
$expenseCategories = array_filter($categories, fn($c) => $c['type'] === 'expense');

$pageTitle = 'تراکنش‌های من';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <form method="GET" id="filterForm">
        <input type="hidden" name="period" value="<?= h($period) ?>">
        <input type="hidden" name="type" value="<?= h($type) ?>">
        <input type="hidden" name="wallet" value="<?= (int)$walletId ?>">

        <div class="filter-bar">
            <div class="filter-chip <?= $period === 'all' ? 'active' : '' ?>" data-group="period" data-filter="all">همه</div>
            <div class="filter-chip <?= $period === 'today' ? 'active' : '' ?>" data-group="period" data-filter="today">امروز</div>
            <div class="filter-chip <?= $period === 'week' ? 'active' : '' ?>" data-group="period" data-filter="week">این هفته</div>
            <div class="filter-chip <?= $period === 'month' ? 'active' : '' ?>" data-group="period" data-filter="month">این ماه</div>
            <div class="filter-chip <?= $period === 'year' ? 'active' : '' ?>" data-group="period" data-filter="year">امسال</div>
            <div class="filter-chip <?= $period === 'custom' ? 'active' : '' ?>" data-group="period" data-filter="custom">بازه دلخواه</div>
        </div>

        <div class="filter-row">
            <div class="seg">
                <div class="seg-item <?= $type === 'all' ? 'active' : '' ?>" data-group="type" data-filter="all">همه</div>
                <div class="seg-item <?= $type === 'income' ? 'active' : '' ?>" data-group="type" data-filter="income">درآمد</div>
                <div class="seg-item <?= $type === 'expense' ? 'active' : '' ?>" data-group="type" data-filter="expense">هزینه</div>
            </div>
            <div class="search-inline">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                <input type="text" name="search" placeholder="جستجو…" value="<?= h($search) ?>">
            </div>
        </div>

        <?php if (count($walletList) > 1): ?>
        <div class="filter-bar" style="margin-top:8px;">
            <div class="filter-chip <?= $walletId === 0 ? 'active' : '' ?>" data-group="wallet" data-filter="0">همه حساب‌ها</div>
            <?php foreach ($walletList as $w): ?>
                <div class="filter-chip <?= $walletId === (int)$w['id'] ? 'active' : '' ?>"
                     data-group="wallet" data-filter="<?= (int)$w['id'] ?>"><?= h($w['name']) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($period === 'custom'): ?>
        <div class="form-row" style="margin-top:10px;">
            <div class="form-group">
                <label>از تاریخ</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" readonly value="<?= isValidDate($fromDate) ? toJalali($fromDate) : '' ?>">
                    <input type="hidden" class="jdp-hidden" name="from_date" value="<?= h($fromDate) ?>">
                </div>
            </div>
            <div class="form-group">
                <label>تا تاریخ</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" readonly value="<?= isValidDate($toDate) ? toJalali($toDate) : '' ?>">
                    <input type="hidden" class="jdp-hidden" name="to_date" value="<?= h($toDate) ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm" style="align-self:end;">اعمال بازه</button>
        </div>
        <?php endif; ?>
    </form>

    <div class="table-wrapper">
        <?php if (empty($transactions)): ?>
            <p class="empty-row">با این فیلتر چیزی پیدا نشد.<br>بازه یا نوع دیگری را امتحان کنید.</p>
        <?php else: ?>
            <?php renderTransactionsGrouped($transactions); ?>

            <?php if ($totalPages > 1): ?>
                <?php
                // پارامترهای فیلتر فعلی حفظ می‌شوند تا صفحه‌بندی فیلتر را از بین نبرد
                $qs = $_GET;
                $link = function (int $target) use ($qs) {
                    $qs['p'] = $target;
                    return '?' . http_build_query($qs);
                };
                ?>
                <div class="pager">
                    <?php if ($page > 1): ?>
                        <a href="<?= h($link($page - 1)) ?>" class="pager-btn">قبلی</a>
                    <?php else: ?>
                        <span class="pager-btn pager-off">قبلی</span>
                    <?php endif; ?>

                    <span class="pager-info">
                        صفحه <?= toPersianDigits($page) ?> از <?= toPersianDigits($totalPages) ?>
                        <small>(<?= toPersianDigits($totalRows) ?> تراکنش)</small>
                    </span>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= h($link($page + 1)) ?>" class="pager-btn">بعدی</a>
                    <?php else: ?>
                        <span class="pager-btn pager-off">بعدی</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php include __DIR__ . '/includes/edit_tx_modal.php'; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
