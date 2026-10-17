<?php
/**
 * انبارگردانی با اسکن — منطق فقط `BizCount` (اسکن بی‌اثر؛ اعمال فقط با تأیید و از `BizStock::adjustTo()`).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_count.php';

$userId = Biz::ownerId();
$id     = (int)getParam('id', '0');
$self   = Biz::url('count.php' . ($id > 0 ? '?id=' . $id : ''));
$ready  = BizCount::ready();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $a = postParam('action');
    if ($a === 'open') {
        $wh = isset($_POST['warehouse_id']) ? BizWarehouses::pick($userId, postParam('warehouse_id')) : null;
        if ($wh === false) { redirectWithMessage(Biz::url('count.php'), 'error', 'انبار پیدا نشد یا غیرفعال است.'); }
        $r = BizCount::open($userId, $wh, (string)postParam('note'), Auth::userId());
        redirectWithMessage(Biz::url('count.php?id=' . $r['id']), 'success', $r['message']);
    }
    if ($a === 'scan')   { $r = BizCount::scan($userId, $id, (string)($_POST['codes'] ?? '')); redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']); }
    if ($a === 'unscan') { BizCount::unscan($userId, $id, (int)postParam('line')); redirectWithMessage($self . '&scope=' . rawurlencode((string)postParam('scope')), 'success', 'اسکن حذف شد.'); }
    if ($a === 'apply')  { $r = BizCount::apply($userId, $id, (string)postParam('scope'), postParam('confirm') === '1'); redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']); }
    if ($a === 'cancel') { BizCount::cancel($userId, $id); redirectWithMessage(Biz::url('count.php'), 'success', 'انبارگردانی لغو شد؛ موجودی دست نخورد.'); }
    redirectWithMessage($self, 'error', 'درخواست نامعتبر است.');
}

$count = $ready && $id > 0 ? BizCount::get($userId, $id) : null;
$scope = getParam('scope') === 'all' ? 'all' : 'scanned';
$diff  = $count ? BizCount::diff($userId, $id, $scope) : null;
$whs   = $ready ? BizWarehouses::list($userId, true) : [];

$pageTitle = 'انبارگردانی';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <?php if ($count): ?><a class="st-back" href="<?= h(Biz::url('count.php')) ?>">‹ انبارگردانی‌ها</a><?php endif; ?>
        <h1 class="st-h1"><?= $count ? 'انبارگردانیِ شماره‌ی ' . toPersianDigits((string)$count['number']) : 'انبارگردانی با اسکن' ?></h1>
        <p class="st-muted">اسکن هیچ اثری بر موجودی ندارد. آخرِ کار اختلاف‌ها را ببینید و فقط با تأیید اعمال کنید؛ گوشیِ IMEIدار خودکار اصلاح نمی‌شود.</p>
    </div>
</div>
<?php if (!$ready): ?>
    <div class="st-flash st-flash-err">این بخش هنوز راه نیفتاده است؛ مدیرِ نصب باید به‌روزرسانی را با migration اجرا کند.</div>
<?php elseif (!$count): ?>
<form method="post" action="<?= h(Biz::url('count.php')) ?>" class="st-card st-form">
    <?= Csrf::field() ?><input type="hidden" name="action" value="open">
    <h2 class="st-h2">انبارگردانیِ تازه</h2>
    <div class="st-row2">
        <?php if ($whs): ?>
        <label class="st-field"><span>انبار</span><select name="warehouse_id"><option value="0">انبارِ اصلی</option>
            <?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>"><?= h((string)$w['name']) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <label class="st-field"><span>یادداشت</span><input type="text" name="note" maxlength="200" placeholder="مثلاً «قفسه‌ی لوازمِ جانبی»"></label>
    </div>
    <button type="submit" class="st-btn">شروعِ شمارش</button>
</form>
<?php $all = BizCount::all($userId); if ($all): ?>
<div class="st-table-wrap">
    <table class="st-table st-table-compact">
        <thead><tr><th class="st-th-num">شماره</th><th>یادداشت</th><th class="st-th-num">اسکن</th><th>وضعیت</th><th class="st-th-num">تاریخ</th></tr></thead>
        <tbody><?php foreach ($all as $c): ?>
            <tr><td class="st-td-num"><a class="st-row-link" href="<?= h(Biz::url('count.php?id=' . (int)$c['id'])) ?>"><?= toPersianDigits((string)$c['number']) ?></a></td>
                <td><?= h((string)($c['note'] ?? '')) ?></td><td class="st-td-num"><span class="st-num"><?= toPersianDigits((string)$c['scans']) ?></span></td>
                <td><?= h(BizCount::STATUSES[$c['status']] ?? '') ?></td><td class="st-td-num"><?= h(toJalali(substr((string)$c['created_at'], 0, 10))) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table>
</div>
<?php endif; ?>
<?php else: $open = $count['status'] === 'open'; ?>
<?php if ($open): ?>
<form method="post" action="<?= h($self) ?>" class="st-card st-form" data-count-form>
    <?= Csrf::field() ?><input type="hidden" name="action" value="scan">
    <label class="st-field"><span>اسکن کنید <small class="st-muted">— بارکدِ کالا یا IMEIِ گوشی؛ هر اسکن به فهرستِ پایین می‌رود</small></span>
        <input type="text" autocomplete="off" autofocus data-scan data-count-scan placeholder="اسکن یا تایپ و Enter"></label>
    <label class="st-field"><span>فهرستِ اسکن‌ها <small class="st-muted">(<b data-count-n>۰</b> اسکن؛ هر خط یک کد، «کد×۳» برای سه عدد)</small></span>
        <textarea name="codes" rows="5" dir="ltr" data-count-list></textarea></label>
    <button type="submit" class="st-btn">ثبتِ اسکن‌ها</button>
</form>
<?php endif; ?>

<nav class="st-tabs" aria-label="دامنه">
    <a class="st-tab-chip<?= $scope === 'scanned' ? ' is-active' : '' ?>" href="<?= h(Biz::url('count.php?id=' . $id)) ?>">فقط کالاهای اسکن‌شده</a>
    <a class="st-tab-chip<?= $scope === 'all' ? ' is-active' : '' ?>" href="<?= h(Biz::url('count.php?id=' . $id . '&scope=all')) ?>">همه‌ی کالاها (شمرده‌نشده = صفر)</a>
</nav>

<?php $bad = array_values(array_filter($diff['rows'], fn($r) => abs($r['diff']) >= 0.0005)); ?>
<section class="st-card">
    <h2 class="st-h2">اختلاف‌ها (<?= toPersianDigits((string)count($bad)) ?>)</h2>
    <?php if (!$bad): ?><p class="st-muted">اختلافی نیست.</p><?php else: ?>
    <div class="st-table-wrap"><table class="st-table st-table-compact">
        <thead><tr><th>کالا</th><th class="st-th-num">دفتر</th><th class="st-th-num">شمارش</th><th class="st-th-num">اختلاف</th></tr></thead>
        <tbody><?php foreach ($bad as $r): ?>
            <tr><td><a class="st-row-link" href="<?= h(Biz::url('product.php?id=' . $r['id'])) ?>"><?= h($r['name']) ?></a><?= $r['serial'] ? ' <span class="st-badge is-warn">گوشی — بررسیِ دستی</span>' : '' ?></td>
                <td class="st-td-num"><span class="st-num"><?= h(formatQty($r['system'])) ?></span></td>
                <td class="st-td-num"><span class="st-num"><?= h(formatQty($r['counted'])) ?></span></td>
                <td class="st-td-num"><span class="st-num <?= $r['diff'] < 0 ? 'is-neg' : 'is-pos' ?>"><?= $r['diff'] > 0 ? '+' : '−' ?><?= h(formatQty(abs($r['diff']))) ?></span> <small class="st-muted-i"><?= $r['diff'] < 0 ? 'کم است' : 'اضافه است' ?></small></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<?php if ($diff['missing'] || $diff['foreign'] || $diff['unknown']): ?>
<section class="st-card">
    <h2 class="st-h2">گوشی‌ها و کدهای ناشناس</h2>
    <?php if ($diff['missing']): ?><p><b>در دفتر هست، اسکن نشد:</b></p><ul><?php foreach ($diff['missing'] as $m): ?><li><span dir="ltr"><?= h($m['imei']) ?></span> — <?= h($m['product']) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($diff['foreign']): ?><p><b>اسکن شد، در دفتر «در انبار» نیست:</b></p><ul><?php foreach ($diff['foreign'] as $m): ?><li><span dir="ltr"><?= h($m['imei']) ?></span> — <?= h($m['product']) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($diff['unknown']): ?><p><b>کدِ ناشناس (نه SKU، نه IMEIِ ثبت‌شده):</b></p><ul>
        <?php foreach ($diff['unknown'] as $m): ?><li><span dir="ltr"><?= h($m['code']) ?></span><?= $m['qty'] != 1 ? ' ×' . h(formatQty($m['qty'])) : '' ?>
            <?php if ($open): ?><form method="post" action="<?= h($self) ?>" class="st-inline"><?= Csrf::field() ?><input type="hidden" name="action" value="unscan"><input type="hidden" name="line" value="<?= (int)$m['id'] ?>"><input type="hidden" name="scope" value="<?= h($scope) ?>"><button type="submit" class="st-link-btn st-link-danger">حذف</button></form><?php endif; ?></li>
        <?php endforeach; ?></ul><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($open): ?>
<form method="post" action="<?= h($self) ?>" class="st-card st-form" onsubmit="return confirm('موجودیِ کالاهای بی‌IMEI به شمارش برسد؟');">
    <?= Csrf::field() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="scope" value="<?= h($scope) ?>">
    <label class="st-check"><input type="checkbox" name="confirm" value="1"><span>اختلافِ کالاهای بی‌IMEI را با «انبارگردانی» اصلاح کن (<?= $scope === 'all' ? 'همه‌ی کالاها — شمرده‌نشده صفر می‌شود' : 'فقط کالاهای اسکن‌شده' ?>).</span></label>
    <div class="st-actionbar"><button type="submit" class="st-btn">اعمال و بستن</button></div>
</form>
<form method="post" action="<?= h($self) ?>" onsubmit="return confirm('این انبارگردانی لغو شود؟ موجودی دست نمی‌خورد.');">
    <?= Csrf::field() ?><input type="hidden" name="action" value="cancel"><button type="submit" class="st-link-btn st-link-danger">لغوِ انبارگردانی</button>
</form>
<?php endif; ?>
<?php endif; ?>
<?php if ($count && $count['status'] === 'open'): foreach (assetUrls(['js/store-count.js']) as $__u): ?><script defer src="<?= h($__u) ?>"></script><?php endforeach; endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
