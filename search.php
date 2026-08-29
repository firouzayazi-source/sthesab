<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();
$q = trim(getParam('q', ''));

$results = [
    'transactions' => [],
    'debts' => [],
    'cheques' => [],
    'assets' => [],
    'wallets' => [],
    'goals' => [],
];
$totalFound = 0;

if ($q !== '') {
    $like = '%' . $q . '%';
    $likeNum = '%' . toLatinDigits($q) . '%';

    // ---------- تراکنش‌ها ----------
    try {
        $stmt = $pdo->prepare('
            SELECT t.id, t.type, t.amount, t.title, t.note, t.transaction_date, t.category_id,
                   c.name AS category_name, c.icon AS cat_icon, c.color AS cat_color
            FROM transactions t
            LEFT JOIN categories c ON c.id = t.category_id
            WHERE t.user_id = :u
              AND (t.title LIKE :q1 OR t.note LIKE :q2 OR CAST(t.amount AS CHAR) LIKE :q3 OR c.name LIKE :q4)
            ORDER BY t.transaction_date DESC
            LIMIT 40
        ');
        $stmt->execute(['u' => $userId, 'q1' => $like, 'q2' => $like, 'q3' => $likeNum, 'q4' => $like]);
        $results['transactions'] = $stmt->fetchAll();
    } catch (PDOException $e) { /* ignore */ }

    // ---------- طلب و بدهی ----------
    try {
        $stmt = $pdo->prepare('
            SELECT id, direction, counterparty_name, amount, paid_amount, note, due_date, is_settled
            FROM debts
            WHERE user_id = :u AND (counterparty_name LIKE :q1 OR note LIKE :q2 OR CAST(amount AS CHAR) LIKE :q3)
            ORDER BY due_date DESC LIMIT 20
        ');
        $stmt->execute(['u' => $userId, 'q1' => $like, 'q2' => $like, 'q3' => $likeNum]);
        $results['debts'] = $stmt->fetchAll();
    } catch (PDOException $e) { /* ignore */ }

    // ---------- چک‌ها ----------
    try {
        $stmt = $pdo->prepare('
            SELECT ch.id, ch.direction, ch.counterparty_name, ch.amount, ch.due_date, ch.is_settled,
                   ch.sayadi_number, ch.cheque_number, b.name AS bank_name
            FROM cheques ch
            LEFT JOIN banks b ON b.id = ch.bank_id
            WHERE ch.user_id = :u
              AND (ch.counterparty_name LIKE :q1 OR ch.note LIKE :q2 OR CAST(ch.amount AS CHAR) LIKE :q3
                   OR ch.sayadi_number LIKE :q4 OR ch.cheque_number LIKE :q5 OR b.name LIKE :q6)
            ORDER BY ch.due_date DESC LIMIT 20
        ');
        $stmt->execute(['u' => $userId, 'q1' => $like, 'q2' => $like, 'q3' => $likeNum,
                        'q4' => $likeNum, 'q5' => $likeNum, 'q6' => $like]);
        $results['cheques'] = $stmt->fetchAll();
    } catch (PDOException $e) { /* ignore */ }

    // ---------- دارایی‌ها ----------
    try {
        $stmt = $pdo->prepare('
            SELECT a.id, a.quantity, a.unit_price, a.note, a.entry_date, at.name AS type_name, at.unit
            FROM assets a JOIN asset_types at ON at.id = a.asset_type_id
            WHERE a.user_id = :u AND (at.name LIKE :q1 OR a.note LIKE :q2)
            ORDER BY a.entry_date DESC LIMIT 20
        ');
        $stmt->execute(['u' => $userId, 'q1' => $like, 'q2' => $like]);
        $results['assets'] = $stmt->fetchAll();
    } catch (PDOException $e) { /* ignore */ }

    // ---------- حساب‌ها ----------
    try {
        $stmt = $pdo->prepare('
            SELECT id, name, kind, bank_name, card_last4
            FROM wallets
            WHERE user_id = :u AND (name LIKE :q1 OR bank_name LIKE :q2 OR card_last4 LIKE :q3)
            LIMIT 15
        ');
        $stmt->execute(['u' => $userId, 'q1' => $like, 'q2' => $like, 'q3' => $likeNum]);
        $results['wallets'] = $stmt->fetchAll();
    } catch (PDOException $e) { /* ignore */ }

    // ---------- اهداف پس‌انداز ----------
    try {
        $stmt = $pdo->prepare('
            SELECT id, title, target_amount, color FROM savings_goals
            WHERE user_id = :u AND title LIKE :q1 LIMIT 15
        ');
        $stmt->execute(['u' => $userId, 'q1' => $like]);
        $results['goals'] = $stmt->fetchAll();
    } catch (PDOException $e) { /* ignore */ }

    foreach ($results as $group) { $totalFound += count($group); }
}

$pageTitle = 'جستجو';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <form method="GET" class="search-page-form">
        <div class="search-inline" style="flex:1;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="نام، مبلغ، شماره چک، توضیح…" autofocus>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">جستجو</button>
    </form>
    <?php if ($q !== ''): ?>
        <p class="hint" style="margin-top:10px;">
            <?= $totalFound > 0 ? toPersianDigits($totalFound) . ' نتیجه برای «' . h($q) . '»' : 'چیزی برای «' . h($q) . '» پیدا نشد.' ?>
        </p>
    <?php endif; ?>
</div>

<?php if ($q === ''): ?>
    <div class="card">
        <p class="empty-row">در همه‌جای اپ جستجو کنید:<br>تراکنش، طلب و بدهی، چک، دارایی، حساب و هدف پس‌انداز.</p>
    </div>
<?php endif; ?>

<?php if (!empty($results['transactions'])): ?>
<div class="card">
    <h2 class="card-title">تراکنش‌ها (<?= toPersianDigits(count($results['transactions'])) ?>)</h2>
    <?php renderTransactionsGrouped($results['transactions']); ?>
</div>
<?php endif; ?>

<?php if (!empty($results['debts'])): ?>
<div class="card">
    <h2 class="card-title">طلب و بدهی (<?= toPersianDigits(count($results['debts'])) ?>)</h2>
    <?php foreach ($results['debts'] as $d): ?>
        <?php $rem = (int)$d['amount'] - (int)($d['paid_amount'] ?? 0); ?>
        <a href="debts.php" class="event-row">
            <span class="event-dot event-dot-<?= $d['direction'] === 'receivable' ? 'in' : 'out' ?>"></span>
            <span class="event-body">
                <span class="event-title"><?= $d['direction'] === 'receivable' ? 'طلب از ' : 'بدهی به ' ?><?= h($d['counterparty_name']) ?></span>
                <span class="event-meta">
                    سررسید <?= toJalali($d['due_date']) ?>
                    <?= (int)$d['is_settled'] ? ' · تسویه شد' : '' ?>
                </span>
            </span>
            <span class="event-amount <?= $d['direction'] === 'receivable' ? 'amount-income' : 'amount-expense' ?>"><?= formatMoney($rem > 0 ? $rem : (int)$d['amount']) ?></span>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($results['cheques'])): ?>
<div class="card">
    <h2 class="card-title">چک‌ها (<?= toPersianDigits(count($results['cheques'])) ?>)</h2>
    <?php foreach ($results['cheques'] as $c): ?>
        <a href="cheques.php" class="event-row">
            <span class="event-dot event-dot-<?= $c['direction'] === 'received' ? 'in' : 'out' ?>"></span>
            <span class="event-body">
                <span class="event-title">چک <?= $c['direction'] === 'received' ? 'از ' : 'به ' ?><?= h($c['counterparty_name']) ?></span>
                <span class="event-meta">
                    <?= $c['due_date'] ? 'سررسید ' . toJalali($c['due_date']) : 'بدون سررسید' ?>
                    <?= !empty($c['bank_name']) ? ' · ' . h($c['bank_name']) : '' ?>
                    <?= !empty($c['sayadi_number']) ? ' · صیادی ' . toPersianDigits($c['sayadi_number']) : '' ?>
                </span>
            </span>
            <span class="event-amount <?= $c['direction'] === 'received' ? 'amount-income' : 'amount-expense' ?>"><?= formatMoney($c['amount']) ?></span>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($results['wallets'])): ?>
<div class="card">
    <h2 class="card-title">حساب‌ها (<?= toPersianDigits(count($results['wallets'])) ?>)</h2>
    <?php foreach ($results['wallets'] as $w): ?>
        <a href="wallets.php" class="event-row">
            <span class="event-body">
                <span class="event-title"><?= h($w['name']) ?></span>
                <span class="event-meta"><?= walletKindLabel($w['kind'], $w['kind_label'] ?? null) ?><?= !empty($w['bank_name']) ? ' · ' . h($w['bank_name']) : '' ?></span>
            </span>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($results['assets'])): ?>
<div class="card">
    <h2 class="card-title">دارایی‌ها (<?= toPersianDigits(count($results['assets'])) ?>)</h2>
    <?php foreach ($results['assets'] as $a): ?>
        <a href="my-assets.php" class="event-row">
            <span class="event-body">
                <span class="event-title"><?= h($a['type_name']) ?></span>
                <span class="event-meta"><?= toJalali($a['entry_date']) ?><?= !empty($a['note']) ? ' · ' . h($a['note']) : '' ?></span>
            </span>
            <span class="event-amount"><?= formatQuantity($a['quantity']) ?> <?= h($a['unit']) ?></span>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($results['goals'])): ?>
<div class="card">
    <h2 class="card-title">اهداف پس‌انداز (<?= toPersianDigits(count($results['goals'])) ?>)</h2>
    <?php foreach ($results['goals'] as $g): ?>
        <a href="savings.php" class="event-row">
            <span class="event-dot" style="background: <?= h($g['color']) ?>;"></span>
            <span class="event-body">
                <span class="event-title"><?= h($g['title']) ?></span>
                <span class="event-meta">هدف: <?= formatMoney($g['target_amount']) ?> تومان</span>
            </span>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
