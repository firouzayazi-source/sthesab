<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();
$todayStr = today();

seedUserDefaults($userId);

$typesStmt = $pdo->prepare('SELECT id, name, unit FROM asset_types WHERE user_id = :user_id ORDER BY name');
$typesStmt->execute(['user_id' => $userId]);
$assetTypes = $typesStmt->fetchAll();

// موجودی تجمیع‌شده به تفکیک نوع
$summaryStmt = $pdo->prepare('
    SELECT at.id, at.name, at.unit,
           COALESCE(SUM(a.quantity), 0) AS total_qty,
           COALESCE(SUM(a.quantity * COALESCE(a.unit_price, 0)), 0) AS total_value,
           COUNT(a.id) AS cnt
    FROM asset_types at
    LEFT JOIN assets a ON a.asset_type_id = at.id AND a.user_id = :user_id
    WHERE at.user_id = :user_id2
    GROUP BY at.id, at.name, at.unit
    HAVING cnt > 0
    ORDER BY total_value DESC, at.name
');
$summaryStmt->execute(['user_id' => $userId, 'user_id2' => $userId]);
$assetSummary = $summaryStmt->fetchAll();

$totalPortfolioValue = array_sum(array_column($assetSummary, 'total_value'));

// رکوردهای جزئی
$listStmt = $pdo->prepare('
    SELECT a.*, at.name AS type_name, at.unit
    FROM assets a
    JOIN asset_types at ON at.id = a.asset_type_id
    WHERE a.user_id = :user_id
    ORDER BY a.entry_date DESC, a.created_at DESC
');
$listStmt->execute(['user_id' => $userId]);
$assetRecords = $listStmt->fetchAll();

// کالای معاملاتیِ موجود هم دارایی است — اما فقط-خواندنی: خرید و فروش
// فقط از بخش معاملات انجام می‌شود، اینجا فقط «آخرین موجودی» دیده می‌شود.
$openTrades = [];
$openTradesCost = 0;
if (tradesTablesExist($pdo) && tradesEnabled($pdo, $userId)) {
    foreach (tradesWithProgress($userId) as $t) {
        if (!$t['is_closed']) {
            $openTrades[] = $t;
            $openTradesCost += $t['open_cost'];
        }
    }
}

$pageTitle = 'دارایی‌ها';
include __DIR__ . '/includes/header.php';
?>

<?php if (!empty($openTrades)): ?>
<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">کالای معاملاتی موجود</h2>
        <a href="<?= APP_BASE_PATH ?>/trades.php" class="btn btn-secondary btn-sm" style="text-decoration:none;">خرید و فروش</a>
    </div>
    <p class="hint" style="margin-bottom:11px;">
        جمع سرمایه به بهای خرید: <b><?= formatMoney($openTradesCost) ?> <?= h(APP_CURRENCY) ?></b>
        — معامله فقط از بخش معاملات انجام می‌شود.
    </p>
    <?php foreach ($openTrades as $t): ?>
        <div class="trade-sale-row">
            <div class="trade-sale-info">
                <b><?= h($t['title']) ?></b>
                <span class="trade-sale-meta">
                    <?php if ((float)$t['qty'] != 1.0): ?>موجودی: <?= formatQty($t['remaining_qty']) ?> واحد · <?php endif; ?>
                    ارزش خرید: <?= formatMoney($t['open_cost']) ?> <?= h(APP_CURRENCY) ?>
                    · خرید <?= toJalali($t['buy_date']) ?>
                </span>
            </div>
            <a href="<?= APP_BASE_PATH ?>/trades.php" class="btn btn-secondary btn-sm" style="text-decoration:none;flex:none;">فروش</a>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($totalPortfolioValue > 0): ?>
<div class="summary-grid" style="grid-template-columns: 1fr;">
    <div class="summary-box">
        <div class="label">ارزش تقریبی کل دارایی‌ها (بر اساس قیمت‌های ثبت‌شده)</div>
        <div class="value stats-income"><?= formatMoney($totalPortfolioValue) ?> <small>تومان</small></div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($assetSummary)): ?>
<div class="card">
    <h2 class="card-title">موجودی به تفکیک نوع</h2>
    <?php foreach ($assetSummary as $s): ?>
        <div class="stats-row" style="padding:10px 0; border-bottom:1px solid var(--color-gray-100);">
            <span class="stats-label"><?= h($s['name']) ?></span>
            <span class="stats-value">
                <?= formatQuantity($s['total_qty']) ?> <small><?= h($s['unit']) ?></small>
                <?php if ((int)$s['total_value'] > 0): ?>
                    <span style="color:var(--color-gray-500); font-weight:400;"> &nbsp;≈ <?= formatMoney($s['total_value']) ?> تومان</span>
                <?php endif; ?>
            </span>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">ثبت‌های دارایی</h2>
        <button type="button" class="btn btn-primary btn-sm" data-modal-open="addAssetModal" <?= empty($assetTypes) ? 'disabled' : '' ?>>+ ثبت دارایی</button>
    </div>

    <?php if (empty($assetTypes)): ?>
        <p class="empty-row">ابتدا از بخش «مدیریت انواع دارایی» پایین صفحه، یک نوع دارایی اضافه کنید.</p>
    <?php elseif (empty($assetRecords)): ?>
        <p class="empty-row">هنوز دارایی‌ای ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($assetRecords as $a): ?>
            <div class="tx-row">
                <div class="tx-row-summary">
                    <span class="tx-row-title"><?= h($a['type_name']) ?></span>
                    <span class="tx-row-amount"><?= formatQuantity($a['quantity']) ?> <small style="font-weight:400; color:var(--color-gray-500);"><?= h($a['unit']) ?></small></span>
                    <span class="tx-row-chevron">▾</span>
                </div>
                <div class="tx-row-details">
                    <div class="tx-row-details-line"><span>تاریخ</span><span><?= toJalali($a['entry_date']) ?></span></div>
                    <?php if (!empty($a['unit_price'])): ?>
                        <div class="tx-row-details-line"><span>قیمت واحد</span><span><?= formatMoney($a['unit_price']) ?> تومان</span></div>
                        <div class="tx-row-details-line"><span>ارزش کل</span><span><?= formatMoney((float)$a['quantity'] * (int)$a['unit_price']) ?> تومان</span></div>
                    <?php endif; ?>
                    <?php if (!empty($a['note'])): ?>
                        <div class="tx-row-details-line"><span>توضیح</span><span><?= h($a['note']) ?></span></div>
                    <?php endif; ?>
                    <div class="tx-row-actions">
                        <button type="button" class="btn btn-secondary btn-sm js-edit-asset"
                            data-id="<?= (int)$a['id'] ?>"
                            data-quantity="<?= h($a['quantity']) ?>"
                            data-unit-price="<?= (int)($a['unit_price'] ?? 0) ?>"
                            data-note="<?= h($a['note'] ?? '') ?>"
                            data-entry-date="<?= h($a['entry_date']) ?>">ویرایش</button>
                        <button class="delete-btn js-delete-asset" data-id="<?= (int)$a['id'] ?>">حذف</button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- مدیریت انواع دارایی -->
<div class="card collapsible-card collapsed">
    <div class="collapsible-header">
        <h2 class="card-title" style="margin-bottom:0;">مدیریت انواع دارایی</h2>
        <span class="collapse-chevron">▾</span>
    </div>
    <div class="collapsible-body">
        <div class="ref-manager">
            <div class="ref-add-row">
                <input type="text" id="newAssetType" placeholder="نام (مثلاً: سکه نیم)" maxlength="100">
                <input type="text" id="newAssetUnit" placeholder="واحد (عدد/گرم)" maxlength="30" style="max-width:120px;">
                <button type="button" class="btn btn-secondary btn-sm js-ref-add" data-kind="asset_type" data-input="newAssetType" data-unit-input="newAssetUnit">افزودن</button>
            </div>
            <div class="ref-chip-list" id="assetTypeList">
                <?php if (empty($assetTypes)): ?>
                    <span class="ref-empty">هنوز نوعی اضافه نکرده‌اید.</span>
                <?php else: ?>
                    <?php foreach ($assetTypes as $at): ?>
                        <span class="ref-chip"><?= h($at['name']) ?> <small style="opacity:.6;">(<?= h($at['unit']) ?>)</small><button type="button" class="ref-chip-x js-ref-delete" data-kind="asset_type" data-id="<?= (int)$at['id'] ?>">&times;</button></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <p style="font-size:12px; color:var(--color-gray-500); margin-top:10px;">
                نوعی که روی آن دارایی ثبت شده باشد قابل حذف نیست.
            </p>
        </div>
    </div>
</div>

<!-- مودال افزودن دارایی -->
<div class="modal-overlay" id="addAssetModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>ثبت دارایی</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>

        <form id="addAssetForm" autocomplete="off">
            <?= Csrf::field() ?>

            <div class="form-group">
                <label for="add_asset_type">نوع دارایی</label>
                <select id="add_asset_type" name="asset_type_id" required>
                    <?php foreach ($assetTypes as $at): ?>
                        <option value="<?= (int)$at['id'] ?>" data-unit="<?= h($at['unit']) ?>"><?= h($at['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="add_asset_qty">مقدار <span class="req">*</span></label>
                    <input type="text" inputmode="decimal" id="add_asset_qty" name="quantity" required placeholder="مثلاً ۲٫۵">
                </div>
                <div class="form-group">
                    <label>تاریخ</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" readonly value="<?= toJalali($todayStr) ?>">
                        <input type="hidden" class="jdp-hidden" id="add_asset_date" name="entry_date" value="<?= h($todayStr) ?>">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="add_asset_price">قیمت واحد به تومان (اختیاری)</label>
                <input type="text" inputmode="numeric" id="add_asset_price" name="unit_price" autocomplete="off">
            </div>

            <div class="form-group">
                <label for="add_asset_note">توضیح (اختیاری)</label>
                <textarea id="add_asset_note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <div id="addAssetMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="addAssetSubmitBtn">ثبت</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال ویرایش دارایی -->
<div class="modal-overlay" id="editAssetModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>ویرایش دارایی</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>

        <form id="editAssetForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="asset_id" id="edit_asset_id">

            <div class="form-row">
                <div class="form-group">
                    <label for="edit_asset_qty">مقدار <span class="req">*</span></label>
                    <input type="text" inputmode="decimal" id="edit_asset_qty" name="quantity" required>
                </div>
                <div class="form-group">
                    <label>تاریخ</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" id="edit_asset_date_display" readonly>
                        <input type="hidden" class="jdp-hidden" id="edit_asset_date" name="entry_date">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="edit_asset_price">قیمت واحد به تومان (اختیاری)</label>
                <input type="text" inputmode="numeric" id="edit_asset_price" name="unit_price" autocomplete="off">
            </div>

            <div class="form-group">
                <label for="edit_asset_note">توضیح (اختیاری)</label>
                <textarea id="edit_asset_note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <div id="editAssetMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="editAssetSubmitBtn">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php include __DIR__ . '/includes/footer.php'; ?>
