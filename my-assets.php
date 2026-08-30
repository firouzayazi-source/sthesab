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
        'name'  => $s['name'],
        'qty'   => (float)$s['total_qty'],
        'unit'  => $s['unit'],
        'value' => (int)$s['total_value'],
        'kind'  => 'asset',
    ];
}

// کالای بازِ بخش معاملات **یک قلمِ جمع‌شده** است، نه یکی به‌ازای هر کالا.
//
// چرا: این صفحه نمای کلیِ ترکیب دارایی است — «چند درصدم طلاست، چند
// درصدم پولِ نقد». اسمِ تک‌تکِ معامله‌ها (گوشی، سکه، …) اینجا فهرست را
// شلوغ می‌کرد و درصدها را خرد می‌کرد، در حالی که جای دیدنِ آن‌ها صفحه‌ی
// معاملات است. با یک ردیف و یک توگل، می‌شود پرسید «دارایی‌ام بدون
// بخش معاملات چقدر است؟» — که همان سؤالِ واقعی است.
//
// دارایی‌های ثبت‌شده عمداً جدا جدا می‌مانند (به تفکیک نوع)، چون آن‌ها
// خودشان ترکیبِ دارایی‌اند نه یک بخش.
$tradeInventoryValue = 0;
$tradeOpenCount      = 0;
if (tradesTablesExist($pdo) && tradesEnabled($pdo, $userId)) {
    foreach (tradesWithProgress($userId) as $t) {
        if ($t['is_closed']) { continue; }
        $tradeInventoryValue += $t['open_cost'];
        $tradeOpenCount++;
    }
}
if ($tradeOpenCount > 0) {
    $portfolio[] = [
        'name'  => 'مجموع دارایی‌های بخش معاملات',
        'qty'   => (float)$tradeOpenCount,
        'unit'  => 'قلم',
        'value' => (int)$tradeInventoryValue,
        'kind'  => 'trade',
    ];
}

// پولِ توی حساب‌ها هم دارایی است. به‌صورت یک قلم می‌آید تا در همین
// نمودار دیده شود، ولی مثل بقیه‌ی قلم‌ها قابل خاموش کردن است — گاهی
// می‌خواهید بدانید دارایی غیرنقدی‌تان چقدر است.
$walletsTotal = 0;
$walletsCount = 0;
try {
    foreach (walletBalances($userId) as $w) {
        if ((int)$w['is_active'] !== 1) { continue; }
        $walletsTotal += (int)$w['balance'];
        $walletsCount++;
    }
} catch (PDOException $e) {
    $walletsCount = 0;
}
if ($walletsCount > 0) {
    $portfolio[] = [
        'name'  => 'مجموع حساب‌ها',
        'qty'   => (float)$walletsCount,
        'unit'  => 'حساب',
        'value' => $walletsTotal,
        'kind'  => 'wallets',
    ];
}

// بزرگ‌ترین‌ها اول — وقتی اقلام زیاد شوند، مهم‌ها بالا می‌مانند
usort($portfolio, fn($a, $b) => $b['value'] <=> $a['value']);

// همان پالت گزارش دسته‌بندی، تا دو صفحه یک زبان بصری داشته باشند.
// قاچ اول سبز است (دارایی) و بقیه از چرخه‌ی هیوهای متمایز می‌آیند.
$paletteLight = chartPalette('green', 'light');
$paletteDark  = chartPalette('green', 'dark');
$pn = count($paletteLight);
foreach ($portfolio as $i => $row) {
    $portfolio[$i]['color_l'] = $paletteLight[$i % $pn];
    $portfolio[$i]['color_d'] = $paletteDark[$i % $pn];
    // کلید پایدار برای به‌خاطر سپردن انتخابِ روشن/خاموش در همین مرورگر
    $portfolio[$i]['key'] = $row['kind'] . ':' . $row['name'];
}

// جمع اولیه = همه‌ی قلم‌ها روشن. جاوااسکریپت با خاموش کردن هر قلم،
// همین عدد و درصدها و نمودار را دوباره می‌سازد.
$portfolioTotal = 0;
foreach ($portfolio as $row) { $portfolioTotal += $row['value']; }
$chartable = array_values(array_filter($portfolio, fn($r) => $r['value'] > 0));

$pageTitle = 'دارایی‌ها';
include __DIR__ . '/includes/header.php';
?>

<!-- ---------- نمای کلی دارایی‌ها ----------
     هر قلم یک کلید روشن/خاموش دارد. خاموش کردنش چیزی را پاک نمی‌کند؛
     فقط از جمع و نمودار بیرونش می‌گذارد — تا بشود پرسید «دارایی‌ام
     بدون طلا چقدر است؟» یا «بدون پولِ توی حساب‌ها چقدر؟». انتخاب در
     همین مرورگر به خاطر می‌ماند. -->
<div class="card">
    <div class="asset-total-row">
        <span class="asset-total-label">ارزش کل دارایی‌ها</span>
        <b class="asset-total-value" id="assetGrandTotal"><?= formatMoney($portfolioTotal) ?> <small><?= h(APP_CURRENCY) ?></small></b>
    </div>
    <p class="hint asset-total-note" id="assetExcludedNote" hidden></p>

    <?php if (empty($portfolio)): ?>
        <p class="empty-row">هنوز دارایی‌ای ثبت نشده است.</p>
    <?php else: ?>
        <?php if (!empty($chartable)): ?>
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
                <div class="cat-breakdown-item asset-item"
                     data-name="<?= h($row['name']) ?>"
                     data-key="<?= h($row['key']) ?>"
                     data-value="<?= (int)$row['value'] ?>">
                    <div class="cat-breakdown-summary">
                        <span class="cat-dot" style="--dot-l:<?= h($row['color_l']) ?>; --dot-d:<?= h($row['color_d']) ?>;"></span>
                        <?php
                        // برچسب فقط روی دارایی‌های ثبت‌شده معنا دارد. دو ردیفِ
                        // جمع‌شده («مجموع دارایی‌های بخش معاملات» و «مجموع
                        // حساب‌ها») خودشان اسمشان را می‌گویند، و برچسبِ تکراری
                        // فقط عرض می‌گرفت و نام را با «…» می‌برید.
                        ?>
                        <span class="cat-breakdown-name"><?= h($row['name']) ?></span>
                        <span class="cat-breakdown-pct"><?= $row['value'] > 0 ? toPersianDigits($pct) . '٪' : '—' ?></span>
                        <span class="cat-breakdown-amount"><?= $row['value'] !== 0 ? formatMoney(abs($row['value'])) : '' ?></span>
                        <label class="switch switch-sm asset-toggle" title="اعمال در جمع و نمودار">
                            <input type="checkbox" class="js-asset-include" checked>
                            <span class="switch-track"><span class="switch-knob"></span></span>
                        </label>
                    </div>
                    <div class="asset-item-qty">
                        <?= formatQuantity($row['qty']) ?> <?= h($row['unit']) ?>
                        <?php if ($row['value'] === 0): ?>
                            <span class="asset-noprice">قیمت واحد ثبت نشده</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($row['value'] > 0): ?>
                    <div class="cat-breakdown-bar-track">
                        <div class="cat-breakdown-bar" style="width:<?= $pct ?>%; --dot-l:<?= h($row['color_l']) ?>; --dot-d:<?= h($row['color_d']) ?>;"></div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="asset-empty-filter" id="assetNoMatch" hidden>چیزی با این نام پیدا نشد.</p>

        <?php if ($tradeOpenCount > 0): ?>
        <p class="hint" style="margin-top:12px;">
            «مجموع دارایی‌های بخش معاملات» جمعِ <?= toPersianDigits((string)$tradeOpenCount) ?> کالای فروخته‌نشده است.
            برای دیدن و فروششان به <a href="<?= APP_BASE_PATH ?>/trades.php">بخش معاملات</a> بروید.
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
        <p class="empty-row">ابتدا از صفحه‌ی «فهرست‌های من» یک نوع دارایی اضافه کنید.<br><a href="<?= APP_BASE_PATH ?>/references.php" class="link-more">رفتن به فهرست‌های من ←</a></p>
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

<!-- مدیریت انواع دارایی به صفحه‌ی «فهرست‌های من» منتقل شد. -->
<p class="ref-link-row">
    <a href="<?= APP_BASE_PATH ?>/references.php" class="link-more">افزودن یا حذف انواع دارایی ←</a>
</p>

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

<?php if (!empty($chartable)): ?>
<!-- همان منبعی که گزارش دسته‌بندی از آن استفاده می‌کند -->
<?php foreach (assetUrls(['js/chart.umd.js']) as $__u): ?>
<script defer src="<?= h($__u) ?>"></script>
<?php endforeach; ?>
<script id="assetPortfolioData" type="application/json"><?= json_encode(array_map(fn($r) => [
    'key'   => $r['key'],
    'name'  => $r['name'],
    'value' => (int)$r['value'],
    'cl'    => $r['color_l'],
    'cd'    => $r['color_d'],
], $chartable), JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
