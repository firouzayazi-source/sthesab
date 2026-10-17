<?php
/**
 * کالای خواب و ریسکِ افتِ قیمت — منطق فقط `BizAging` (فقط خواندن؛ هیچ چیز ذخیره نمی‌شود).
 * ⛔ مبلغ‌ها بهای خریدند، پس صفحه فقط با «بها و سود» (`Biz::PAGE_CAPS`).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_aging.php';

$userId = Biz::ownerId();
$f      = (string)getParam('f', '');
$f      = in_array($f, ['', 'idle', 'risk'], true) ? $f : '';
$rows   = BizAging::rows($userId);
$sum    = BizAging::summary($rows);
if ($f === 'idle') { $rows = array_values(array_filter($rows, fn($r) => $r['oldest'] === null || $r['oldest'] > BizAging::IDLE_DAYS)); }
if ($f === 'risk') { $rows = array_values(array_filter($rows, fn($r) => $r['risk'] > 0 || $r['below_sell'])); usort($rows, fn($a, $b) => $b['risk'] <=> $a['risk']); }
$days = fn(?int $d): string => $d === null ? 'نامعلوم' : toPersianDigits((string)$d) . ' روز';

$pageTitle = 'کالای خواب';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">کالای خواب و ریسکِ افتِ قیمت</h1>
        <p class="st-muted">سنِ هر گوشی از روزِ ورودِ همان دستگاه؛ بقیه‌ی کالاها به فرضِ «اول‌وارده، اول‌فروخته». ریسک = بهای خریدِ مانده بالاتر از «قیمتِ خرید»ِ امروزِ کالا.</p>
    </div>
</div>

<div class="st-kpis st-kpis-6">
    <?php foreach (BizAging::LABELS as $i => $lbl): ?>
    <div class="st-kpi-card<?= $i >= 2 ? ' is-warn' : '' ?>">
        <span class="st-kpi-label"><?= h($lbl) ?></span>
        <span class="st-kpi-value st-num"><?= formatMoney($sum['value'][$i]) ?></span>
        <span class="st-kpi-foot"><?= h(formatQty($sum['qty'][$i])) ?> قلم</span>
    </div>
    <?php endforeach; ?>
    <a class="st-kpi-card" href="<?= h(Biz::url('aging.php?f=risk')) ?>">
        <span class="st-kpi-label">ریسکِ افتِ قیمت</span>
        <span class="st-kpi-value st-num<?= $sum['risk'] > 0 ? ' is-neg' : '' ?>"><?= formatMoney($sum['risk']) ?></span>
        <span class="st-kpi-foot"><?= toPersianDigits((string)$sum['risky']) ?> کالا بالاتر از قیمتِ امروز خریده شده<?= $sum['below_sell'] ? ' · ' . toPersianDigits((string)$sum['below_sell']) . ' زیرِ قیمتِ فروش' : '' ?></span>
    </a>
    <div class="st-kpi-card">
        <span class="st-kpi-label">کلِ موجودی (به بهای خرید)</span>
        <span class="st-kpi-value st-num"><?= formatMoney($sum['total']) ?></span>
        <span class="st-kpi-foot">خواب (بیش از <?= toPersianDigits((string)BizAging::IDLE_DAYS) ?> روز): <?= formatMoney($sum['idle']) ?></span>
    </div>
</div>

<nav class="st-tabs" aria-label="صافی">
    <?php foreach (['' => 'همه', 'idle' => 'بیش از ' . toPersianDigits((string)BizAging::IDLE_DAYS) . ' روز', 'risk' => 'ریسکِ قیمت'] as $fk => $fl): ?>
        <a class="st-tab-chip<?= $fk === $f ? ' is-active' : '' ?>" href="<?= h(Biz::url('aging.php' . ($fk !== '' ? '?f=' . $fk : ''))) ?>"><?= h($fl) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$rows): ?>
    <div class="st-card st-empty-card"><p class="st-empty">کالایی در این دسته نیست.</p></div>
<?php else: ?>
<div class="st-table-wrap">
    <table class="st-table st-table-compact">
        <thead><tr><th>کالا</th><th class="st-th-num">موجودی</th><th class="st-th-num">قدیمی‌ترین</th><th class="st-th-num">ارزش (بهای خرید)</th>
            <th class="st-th-num">قیمتِ خریدِ امروز</th><th class="st-th-num">ریسکِ افت</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a class="st-row-link" href="<?= h(Biz::url('product.php?id=' . $r['id'])) ?>"><?= h($r['name']) ?></a>
                    <?php if ($r['below_sell']): ?><br><span class="st-badge is-warn">قیمتِ فروش زیرِ بهای خرید</span><?php endif; ?>
                    <?php if ($r['serial']): foreach ($r['lots'] as $l): if ($l['imei'] !== null && $l['age'] !== null && $l['age'] > BizAging::IDLE_DAYS): ?>
                        <br><small class="st-muted-i" dir="ltr"><?= h($l['imei']) ?></small> <small class="st-muted-i">· <?= h($days($l['age'])) ?></small>
                    <?php endif; endforeach; endif; ?></td>
                <td class="st-td-num"><span class="st-num"><?= h(formatQty($r['qty'])) ?></span> <small class="st-muted-i"><?= h($r['unit']) ?></small></td>
                <td class="st-td-num"><span class="st-num<?= $r['oldest'] === null || $r['oldest'] > BizAging::IDLE_DAYS ? ' is-neg' : '' ?>"><?= h($days($r['oldest'])) ?></span></td>
                <td class="st-td-num"><span class="st-num"><?= formatMoney($r['value']) ?></span></td>
                <td class="st-td-num"><?= $r['buy_price'] > 0 ? '<span class="st-num">' . formatMoney($r['buy_price']) . '</span>' : '<small class="st-muted-i">ثبت نشده</small>' ?></td>
                <td class="st-td-num"><?= $r['risk'] > 0 ? '<span class="st-num is-neg">' . formatMoney($r['risk']) . '</span>' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
