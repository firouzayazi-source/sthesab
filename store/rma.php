<?php
/**
 * گارانتیِ پخش‌کننده — منطق فقط `BizRma` (پیگیری؛ نه پول، نه انبار).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_rma.php';
require_once __DIR__ . '/../includes/biz_quickbuy.php';
require_once __DIR__ . '/../includes/biz_docview.php';

$userId = Biz::ownerId();
$self   = Biz::url('rma.php');
$error  = '';
$form   = ['party_id' => 0, 'device' => '', 'imei' => '', 'problem' => '', 'tracking' => '', 'note' => '', 'source' => 'customer',
           'repair_id' => (int)getParam('repair', '0')];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && BizRma::ready()) {
    Csrf::verifyOrFail(postParam('csrf_token'));
    if (postParam('action') === 'close') {
        $r = BizRma::close($userId, (int)postParam('id'), (string)postParam('status'), ['new_imei' => (string)postParam('new_imei'), 'note' => (string)postParam('note')]);
        redirectWithMessage($self . '?f=' . rawurlencode((string)postParam('f', 'open')), $r['ok'] ? 'success' : 'error', $r['message']);
    }
    $form = array_merge($form, array_intersect_key($_POST, $form));
    // ⛔ تاریخ فقط `docDate()` (نامعتبر = خطا، نه امروز)
    $sd = BizDocView::docDate((string)($_POST['sent_on'] ?? ''), 'تاریخِ ارسال');
    $form['sent_on'] = $sd['ok'] ? $sd['date'] : 'x';
    $r = $sd['ok'] ? BizRma::create($userId, $form, Auth::userId()) : ['ok' => false, 'message' => $sd['message']];
    if ($r['ok']) { redirectWithMessage($self, 'success', $r['message']); }
    $error = $r['message'];
}

$filter = (string)getParam('f', 'open');
if (!in_array($filter, ['open', 'late', ''], true) && !isset(BizRma::STATUSES[$filter])) { $filter = 'open'; }
$q      = mb_substr(trim(getParam('q')), 0, 60);
$list   = BizRma::ready() ? BizRma::list($userId, $filter, $q, max(1, (int)getParam('page', '1'))) : ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$counts = BizRma::ready() ? BizRma::counts($userId) : ['open' => 0, 'late' => 0];
$rep    = $form['repair_id'] > 0 ? BizRepairs::get($userId, $form['repair_id']) : null;
if ($rep && $form['device'] === '') { $form['device'] = (string)$rep['device']; $form['imei'] = (string)($rep['imei'] ?? ''); $form['problem'] = (string)$rep['problem']; }
$suppliers = BizQuickBuy::suppliers($userId);

$pageTitle = 'گارانتیِ پخش';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">گارانتیِ پخش‌کننده</h1>
        <p class="st-muted">گوشی‌هایی که برای تعمیر یا تعویض به شرکت رفته‌اند. بیش از <?= toPersianDigits((string)BizRma::OVERDUE_DAYS) ?> روز «دیرکرده» است. فقط پیگیری است: پول و انبار از همین‌جا عوض نمی‌شوند.</p>
    </div>
</div>
<?php if (!BizRma::ready()): ?>
    <div class="st-flash st-flash-err">این بخش هنوز راه نیفتاده است؛ مدیرِ نصب باید به‌روزرسانی را با migration اجرا کند.</div>
<?php else: ?>
<?php if ($error !== ''): ?><div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div><?php endif; ?>

<details class="st-card"<?= $error !== '' || $rep ? ' open' : '' ?> id="new">
    <summary class="st-h2">+ ارسالِ تازه به گارانتی</summary>
    <form method="post" action="<?= h($self) ?>" class="st-form">
        <?= Csrf::field() ?>
        <?php if ($rep): ?><input type="hidden" name="repair_id" value="<?= (int)$rep['id'] ?>"><p class="st-muted">از قبضِ تعمیرِ شماره‌ی <?= toPersianDigits((string)$rep['number']) ?> — <?= h(BizRepairs::customer($rep)) ?></p><?php endif; ?>
        <div class="st-row2">
            <label class="st-field"><span>شرکتِ گارانتی (پخش‌کننده)</span>
                <select name="party_id" required><option value="">— انتخاب —</option>
                    <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)$s['id'] === (int)$form['party_id'] ? ' selected' : '' ?>><?= h((string)$s['name']) ?></option><?php endforeach; ?>
                </select></label>
            <?php if (!$rep): ?>
            <label class="st-field"><span>گوشیِ کیست؟</span>
                <select name="source"><?php foreach (BizRma::SOURCES as $k => $l): ?><option value="<?= h($k) ?>"<?= $k === $form['source'] ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
            <?php endif; ?>
        </div>
        <div class="st-row2">
            <label class="st-field"><span>دستگاه</span><input type="text" name="device" maxlength="120" value="<?= h((string)$form['device']) ?>" required></label>
            <label class="st-field"><span>IMEI</span><input type="text" name="imei" inputmode="numeric" dir="ltr" maxlength="20" value="<?= h((string)$form['imei']) ?>" data-imei1></label>
        </div>
        <label class="st-field"><span>ایراد</span><input type="text" name="problem" maxlength="500" value="<?= h((string)$form['problem']) ?>" required></label>
        <div class="st-row2">
            <label class="st-field"><span>شماره‌ی پذیرش/رهگیریِ شرکت</span><input type="text" name="tracking" maxlength="60" dir="ltr" value="<?= h((string)$form['tracking']) ?>"></label>
            <label class="st-field"><span>تاریخِ ارسال</span><input type="text" name="sent_on" data-num inputmode="numeric" value="<?= h(BizDocView::jDate(today())) ?>" dir="ltr"></label>
        </div>
        <label class="st-field"><span>یادداشت</span><input type="text" name="note" maxlength="500" value="<?= h((string)$form['note']) ?>"></label>
        <button type="submit" class="st-btn">ثبتِ ارسال</button>
    </form>
</details>

<nav class="st-tabs" aria-label="وضعیت">
    <a class="st-tab-chip<?= $filter === 'open' ? ' is-active' : '' ?>" href="<?= h($self . '?f=open') ?>">نزدِ شرکت (<?= toPersianDigits((string)$counts['open']) ?>)</a>
    <a class="st-tab-chip<?= $filter === 'late' ? ' is-active' : '' ?>" href="<?= h($self . '?f=late') ?>">دیرکرده (<?= toPersianDigits((string)$counts['late']) ?>)</a>
    <a class="st-tab-chip<?= $filter === '' ? ' is-active' : '' ?>" href="<?= h($self . '?f=') ?>">همه</a>
</nav>
<form class="st-inline-form" method="get" action="<?= h($self) ?>" role="search">
    <input type="hidden" name="f" value="<?= h($filter) ?>">
    <label class="st-field st-grow"><span>جست‌وجو (IMEI، شماره، دستگاه، شرکت)</span><input type="search" name="q" value="<?= h($q) ?>"></label>
    <button type="submit" class="st-btn st-btn-ghost">جست‌وجو</button>
</form>

<?php if (!$list['rows']): ?>
    <div class="st-card st-empty-card"><p class="st-empty">موردی نیست.</p></div>
<?php else: ?>
<div class="st-table-wrap">
    <table class="st-table st-table-compact">
        <thead><tr><th class="st-th-num">شماره</th><th>دستگاه</th><th>شرکت</th><th class="st-th-num">ارسال</th><th class="st-th-num">روز</th><th>وضعیت</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r): $late = BizRma::overdue($r); ?>
            <tr>
                <td class="st-td-num"><span class="st-num"><?= toPersianDigits((string)$r['number']) ?></span></td>
                <td><?= h((string)$r['device']) ?><?php if ($r['imei']): ?><br><small class="st-muted-i" dir="ltr"><?= h((string)$r['imei']) ?></small><?php endif; ?>
                    <br><small class="st-muted-i"><?= h(BizRma::SOURCES[$r['source']] ?? '') ?><?= $r['repair_id'] ? ' · <a href="' . h(Biz::url('repair.php?id=' . (int)$r['repair_id'])) . '">قبضِ تعمیر</a>' : '' ?><?= $r['tracking'] ? ' · رهگیری <span dir="ltr">' . h((string)$r['tracking']) . '</span>' : '' ?></small></td>
                <td><?= h((string)$r['party_name']) ?></td>
                <td class="st-td-num"><?= h(toJalali((string)$r['sent_on'])) ?></td>
                <td class="st-td-num"><span class="st-num<?= $late ? ' is-neg' : '' ?>"><?= toPersianDigits((string)BizRma::days($r)) ?></span></td>
                <td>
                    <?php if ($r['status'] === 'sent'): ?>
                        <span class="st-badge<?= $late ? ' is-warn' : '' ?>"><?= $late ? 'دیرکرده' : h(BizRma::STATUSES['sent']) ?></span>
                        <form method="post" action="<?= h($self) ?>" class="st-inline-form st-rma-close">
                            <?= Csrf::field() ?><input type="hidden" name="action" value="close"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="f" value="<?= h($filter) ?>">
                            <select name="status" aria-label="نتیجه"><?php foreach (BizRma::STATUSES as $k => $l): if ($k === 'sent') { continue; } ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select>
                            <input type="text" name="new_imei" inputmode="numeric" dir="ltr" maxlength="20" placeholder="IMEIِ تازه (برای تعویض)" aria-label="IMEIِ تازه" data-imei1>
                            <button type="submit" class="st-btn st-btn-sm">ثبتِ برگشت</button>
                        </form>
                    <?php else: ?>
                        <?= h(BizRma::STATUSES[$r['status']] ?? $r['status']) ?><?= $r['returned_on'] ? ' · ' . h(toJalali((string)$r['returned_on'])) : '' ?>
                        <?php if ($r['new_imei']): ?><br><small class="st-muted-i">IMEIِ تازه: <span dir="ltr"><?= h((string)$r['new_imei']) ?></span></small><?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
