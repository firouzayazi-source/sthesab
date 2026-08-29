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

// نمای کلی دارایی‌ها: هم دارایی‌های ثبت‌شده‌ی خود کاربر، هم کالایی که
// در بخش معاملات خریده و هنوز نفروخته — چون همه‌ی این‌ها دارایی‌اند.
//
// ⚠️ اینجا فقط «چقدر داریم» دیده می‌شود. خرید و فروش فقط در بخش
// معاملات انجام می‌شود و کم و زیاد کردن مقدار دارایی فقط همین‌جا.
$portfolio = [];

foreach ($assetSummary as $s) {
    $portfolio[] = [
        'name'     => $s['name'],
        'qty'      => (float)$s['total_qty'],
        'unit'     => $s['unit'],
        'value'    => (int)$s['total_value'],
        'is_trade' => false,
    ];
}

$tradeInventoryValue = 0;
if (tradesTablesExist($pdo) && tradesEnabled($pdo, $userId)) {
    foreach (tradesWithProgress($userId) as $t) {
        if ($t['is_closed']) { continue; }
        $tradeInventoryValue += $t['open_cost'];
        $portfolio[] = [
            'name'     => $t['title'],
            'qty'      => (float)$t['remaining_qty'],
            'unit'     => 'واحد',
            'value'    => (int)$t['open_cost'],
            'is_trade' => true,
        ];
    }
}

// بزرگ‌ترین‌ها اول — وقتی اقلام زیاد شوند، مهم‌ها بالا می‌مانند
usort($portfolio, fn($a, $b) => $b['value'] <=> $a['value']);

$portfolioTotal = $totalPortfolioValue + $tradeInventoryValue;

// همان پالت گزارش دسته‌بندی، تا دو صفحه یک زبان بصری داشته باشند
$palette = ['#d97706', '#0891b2', '#7c3aed', '#db2777', '#059669', '#dc2626',
            '#4f46e5', '#ca8a04', '#0d9488', '#e11d48', '#6d28d9', '#16a34a'];
$chartLabels = []; $chartValues = []; $chartColors = [];
foreach ($portfolio as $i => $row) {
    $portfolio[$i]['color'] = $palette[$i % count($palette)];
    if ($row['value'] > 0) {
        $chartLabels[] = $row['name'];
        $chartValues[] = $row['value'];
        $chartColors[] = $portfolio[$i]['color'];
    }
}

$pageTitle = 'دارایی‌ها';
include __DIR__ . '/includes/header.php';
?>

<!-- ---------- نمای کلی دارایی‌ها ---------- -->
<div class="card">
    <div class="asset-total-row">
        <span class="asset-total-label">ارزش کل دارایی‌ها</span>
        <b class="asset-total-value"><?= formatMoney($portfolioTotal) ?> <small><?= h(APP_CURRENCY) ?></small></b>
    </div>

    <?php if (empty($portfolio)): ?>
        <p class="empty-row">هنوز دارایی‌ای ثبت نشده است.</p>
    <?php else: ?>
        <?php if (!empty($chartValues)): ?>
        <div class="chart-container" style="max-width:250px; height:250px; margin:16px auto 18px;">
            <canvas id="assetChart"></canvas>
        </div>
        <?php endif; ?>

        <?php if (count($portfolio) > 6): ?>
        <div class="asset-search-wrap">
            <input type="search" id="assetFilter" class="asset-search"
                   placeholder="جستجو میان <?= toPersianDigits(count($portfolio)) ?> دارایی…"
                   autocapitalize="none" autocorrect="off">
        </div>
        <?php endif; ?>

        <div class="category-breakdown-list" id="assetBreakdown">
            <?php foreach ($portfolio as $row): ?>
                <?php $pct = $portfolioTotal > 0 ? round($row['value'] / $portfolioTotal * 100, 1) : 0; ?>
                <div class="cat-breakdown-item asset-item" data-name="<?= h($row['name']) ?>">
                    <div class="cat-breakdown-summary">
                        <span class="cat-dot" style="background:<?= h($row['color']) ?>;"></span>
                        <span class="cat-breakdown-name">
                            <?= h($row['name']) ?>
                            <?php if ($row['is_trade']): ?><span class="asset-tag">معامله</span><?php endif; ?>
                        </span>
                        <span class="cat-breakdown-pct"><?= $row['value'] > 0 ? toPersianDigits($pct) . '٪' : '—' ?></span>
                        <span class="cat-breakdown-amount"><?= $row['value'] > 0 ? formatMoney($row['value']) : '' ?></span>
                    </div>
                    <div class="asset-item-qty">
                        <?= formatQuantity($row['qty']) ?> <?= h($row['unit']) ?>
                        <?php if ($row['value'] === 0): ?>
                            <span class="asset-noprice">قیمت واحد ثبت نشده</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($row['value'] > 0): ?>
                    <div class="cat-breakdown-bar-track">
                        <div class="cat-breakdown-bar" style="width:<?= $pct ?>%; background:<?= h($row['color']) ?>;"></div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="asset-empty-filter" id="assetNoMatch" hidden>چیزی با این نام پیدا نشد.</p>

        <?php if ($tradeInventoryValue > 0): ?>
        <p class="hint" style="margin-top:12px;">
            موارد نشان‌دار «معامله» از بخش خرید و فروش آمده‌اند و فقط همان‌جا قابل فروش‌اند.
        </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

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

<?php if (!empty($chartValues)): ?>
<!-- همان منبعی که گزارش دسته‌بندی از آن استفاده می‌کند -->
<script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var box = document.querySelector('.chart-container');
    // Chart.js از CDN می‌آید و ممکن است در دسترس نباشد؛ در آن صورت
    // به‌جای یک مستطیل خالی، فهرست رنگی پایین به‌تنهایی کار می‌کند.
    if (!window.Chart) { if (box) box.hidden = true; return; }
    new Chart(document.getElementById('assetChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
            datasets: [{
                data: <?= json_encode($chartValues) ?>,
                backgroundColor: <?= json_encode($chartColors) ?>,
                borderWidth: 2,
                borderColor: getComputedStyle(document.documentElement)
                    .getPropertyValue('--surface').trim() || '#ffffff'
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            cutout: '62%',
            plugins: { legend: { display: false } }
        }
    });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
