<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Auth::requireAdmin();

$pdo = Database::getConnection();

$period = getParam('period', 'all');
$type   = getParam('type', 'all');
$search = getParam('search', '');
$filterUserId = getParam('user_id', '');

$conditions = [];
$params = [];

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
}

if ($type === 'income' || $type === 'expense') {
    $conditions[] = 't.type = :type';
    $params['type'] = $type;
}

if ($search !== '') {
    $conditions[] = 't.title LIKE :search';
    $params['search'] = '%' . $search . '%';
}

if ($filterUserId !== '' && (int)$filterUserId > 0) {
    $conditions[] = 't.user_id = :filter_user_id';
    $params['filter_user_id'] = (int)$filterUserId;
}

$whereClause = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);

$sql = "
    SELECT t.id, t.type, t.amount, t.title, t.note, t.transaction_date, t.created_at, t.user_id,
           u.full_name, u.role AS user_role, c.name AS category_name
    FROM transactions t
    JOIN users u ON u.id = t.user_id
    LEFT JOIN categories c ON c.id = t.category_id
    $whereClause
    ORDER BY t.transaction_date DESC, t.created_at DESC
    LIMIT 500
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$allUsers = $pdo->query('SELECT id, full_name, role FROM users ORDER BY full_name')->fetchAll();

$pageTitle = 'تراکنش همه کاربران';
include __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="alert alert-info">این بخش فقط جهت مشاهده است — ادمین اجازه ویرایش یا حذف تراکنش سایر کاربران را ندارد.</div>

    <form method="GET" id="filterForm">
        <input type="hidden" name="period" value="<?= h($period) ?>">
        <input type="hidden" name="type" value="<?= h($type) ?>">

        <div class="filter-bar">
            <div class="filter-chip <?= $period === 'all' ? 'active' : '' ?>" data-group="period" data-filter="all">همه</div>
            <div class="filter-chip <?= $period === 'today' ? 'active' : '' ?>" data-group="period" data-filter="today">امروز</div>
            <div class="filter-chip <?= $period === 'week' ? 'active' : '' ?>" data-group="period" data-filter="week">این هفته</div>
            <div class="filter-chip <?= $period === 'month' ? 'active' : '' ?>" data-group="period" data-filter="month">این ماه</div>
            <div class="filter-chip <?= $period === 'year' ? 'active' : '' ?>" data-group="period" data-filter="year">امسال</div>
        </div>

        <div class="filter-bar">
            <div class="filter-chip <?= $type === 'all' ? 'active' : '' ?>" data-group="type" data-filter="all">همه انواع</div>
            <div class="filter-chip <?= $type === 'income' ? 'active' : '' ?>" data-group="type" data-filter="income">درآمد</div>
            <div class="filter-chip <?= $type === 'expense' ? 'active' : '' ?>" data-group="type" data-filter="expense">هزینه</div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>کاربر</label>
                <select name="user_id" onchange="this.form.submit()">
                    <option value="">همه کاربران</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (string)$u['id'] === $filterUserId ? 'selected' : '' ?>>
                            <?= h($u['full_name']) ?> <?= $u['role'] === 'admin' ? '(مدیر)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group filter-search" style="align-self:end;">
                <label>جستجو</label>
                <input type="text" name="search" placeholder="جستجو بر اساس عنوان..." value="<?= h($search) ?>">
            </div>
            <div style="align-self:end;">
                <button type="submit" class="btn btn-secondary btn-sm">اعمال</button>
            </div>
        </div>
    </form>

    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>تاریخ</th>
                    <th>نوع</th>
                    <th>عنوان</th>
                    <th>دسته‌بندی</th>
                    <th>مبلغ</th>
                    <th>کاربر</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr><td colspan="6" class="empty-row">هیچ تراکنشی با این فیلتر یافت نشد.</td></tr>
                <?php else: ?>
                    <?php foreach ($transactions as $tx): ?>
                        <tr>
                            <td data-label="تاریخ"><?= toJalali($tx['transaction_date']) ?></td>
                            <td data-label="نوع">
                                <span class="type-tag type-tag-<?= h($tx['type']) ?>"><?= typeLabel($tx['type']) ?></span>
                            </td>
                            <td data-label="عنوان"><?= h($tx['title']) ?><?php if (!empty($tx['note'])): ?><br><small style="color:var(--color-gray-500);"><?= h($tx['note']) ?></small><?php endif; ?></td>
                            <td data-label="دسته‌بندی"><?= $tx['category_name'] ? h($tx['category_name']) : '—' ?></td>
                            <td data-label="مبلغ" class="amount-cell amount-<?= h($tx['type']) ?>"><?= formatMoney($tx['amount']) ?> تومان</td>
                            <td data-label="کاربر"><?= h($tx['full_name']) ?><?= $tx['user_role'] === 'admin' ? ' <span class="user-role-badge badge-admin">مدیر</span>' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
