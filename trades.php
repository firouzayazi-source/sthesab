<?php
/**
 * معاملات — دفتر خرید و فروش، جدا از درآمد و هزینه.
 *
 * چرا جدا: خرید یک گوشی برای فروش، هزینه نیست و فروشش هم درآمد نیست؛
 * فقط تفاوتش سود یا زیان است. اگر داخل تراکنش‌ها می‌رفت، گزارش‌های
 * هزینه/درآمد بی‌معنی می‌شدند. تنها نقطه‌ی تماس، موجودی حساب‌هاست
 * (walletBalances) اگر معامله به حسابی وصل شده باشد.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();
$todayStr = today();

$tablesReady = tradesTablesExist($pdo);
$enabled = $tablesReady && tradesEnabled($pdo, $userId);

$trades  = $enabled ? tradesWithProgress($userId) : [];
$summary = tradesSummary($trades);

$wallets = [];
if ($enabled) {
    $wStmt = $pdo->prepare('SELECT id, name FROM wallets WHERE user_id = :u AND is_active = 1 ORDER BY sort_order, name');
    $wStmt->execute(['u' => $userId]);
    $wallets = $wStmt->fetchAll();
}

$pageTitle = 'معاملات';
include __DIR__ . '/includes/header.php';
?>

<?php if (!$tablesReady): ?>
<div class="card">
    <p class="empty-row">جدول معاملات هنوز ساخته نشده. روی سرور اجرا کنید:<br><code>bash deploy/migrate.sh --apply</code></p>
</div>

<?php elseif (!$enabled): ?>
<div class="card" style="text-align:center;">
    <h2 class="card-title" style="margin-bottom:8px;">بخش معاملات خاموش است</h2>
    <p class="hint" style="margin-bottom:16px;">
        دفتری جدا از درآمد و هزینه برای خرید و فروش: جنسی را می‌خرید،
        بعداً می‌فروشید، و سود هر معامله همین‌جا حساب می‌شود —
        بدون اینکه چیزی وارد گزارش‌های درآمد/هزینه شود.
    </p>
    <button type="button" class="btn btn-primary" id="enableTradesBtn">روشن کردن بخش معاملات</button>
    <div id="enableTradesMsg" class="form-message" hidden></div>
</div>

<?php else: ?>

<!-- ---------- جمع‌بندی ---------- -->
<div class="card">
    <div class="trade-summary">
        <div class="trade-sum-item">
            <span class="trade-sum-label">سود قطعی‌شده</span>
            <b class="trade-sum-value <?= $summary['realized_profit'] > 0 ? 'is-in' : ($summary['realized_profit'] < 0 ? 'is-out' : '') ?>">
                <?= $summary['realized_profit'] < 0 ? '−' : '' ?><?= formatMoney(abs($summary['realized_profit'])) ?>
                <small><?= h(APP_CURRENCY) ?></small>
            </b>
        </div>
        <div class="trade-sum-item">
            <span class="trade-sum-label">سرمایه در معاملات باز</span>
            <b class="trade-sum-value"><?= formatMoney($summary['open_cost']) ?> <small><?= h(APP_CURRENCY) ?></small></b>
        </div>
        <div class="trade-sum-item">
            <span class="trade-sum-label">باز / بسته</span>
            <b class="trade-sum-value"><?= toPersianDigits($summary['open_count']) ?> / <?= toPersianDigits($summary['closed_count']) ?></b>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">معامله‌ها</h2>
        <button type="button" class="btn btn-primary btn-sm" id="addTradeBtn">+ خرید جدید</button>
    </div>
</div>

<?php if (empty($trades)): ?>
    <div class="card">
        <p class="empty-row">هنوز معامله‌ای ثبت نکرده‌اید.<br>
        مثلاً: «آیفون ۱۳» را با مبلغ خرید ثبت کنید و هر وقت فروختید، فروشش را بزنید.</p>
    </div>
<?php else: ?>
    <?php foreach ($trades as $t): ?>
        <?php
            $isClosed = $t['is_closed'];
            $sales = ((int)$t['sales_count'] > 0) ? tradeSales($userId, (int)$t['id']) : [];
        ?>
        <div class="card trade-card <?= $isClosed ? 'trade-closed' : '' ?>">
            <div class="trade-head">
                <div class="trade-title-wrap">
                    <div class="trade-title"><?= h($t['title']) ?></div>
                    <div class="trade-meta">
                        خرید: <?= toJalali($t['buy_date']) ?>
                        <?php if ((float)$t['qty'] != 1.0): ?> · تعداد: <?= formatQty($t['qty']) ?><?php endif; ?>
                        <?php if ($t['wallet_name']): ?> · از <?= h($t['wallet_name']) ?><?php endif; ?>
                    </div>
                </div>
                <span class="trade-status <?= $isClosed ? 'is-closed' : 'is-open' ?>">
                    <?= $isClosed ? 'بسته شد' : 'باز' ?>
                </span>
            </div>

            <div class="trade-figures">
                <div class="trade-fig">
                    <span>خرید</span>
                    <b><?= formatMoney($t['buy_total']) ?></b>
                </div>
                <?php if ((int)$t['side_costs'] > 0): ?>
                <div class="trade-fig">
                    <span>هزینه جانبی</span>
                    <b><?= formatMoney($t['side_costs']) ?></b>
                </div>
                <?php endif; ?>
                <div class="trade-fig">
                    <span>فروش تاکنون</span>
                    <b><?= formatMoney($t['sold_total']) ?></b>
                </div>
                <div class="trade-fig">
                    <span>سود قطعی</span>
                    <b class="<?= $t['realized_profit'] > 0 ? 'is-in' : ($t['realized_profit'] < 0 ? 'is-out' : '') ?>">
                        <?= $t['realized_profit'] < 0 ? '−' : '' ?><?= formatMoney(abs($t['realized_profit'])) ?>
                    </b>
                </div>
            </div>

            <?php if (!$isClosed && (float)$t['qty'] != 1.0): ?>
                <p class="trade-remaining">مانده از این معامله: <?= formatQty($t['remaining_qty']) ?> واحد</p>
            <?php endif; ?>

            <?php if ($t['notes']): ?>
                <p class="trade-notes"><?= nl2br(h($t['notes'])) ?></p>
            <?php endif; ?>

            <?php if (!empty($sales)): ?>
            <div class="trade-sales">
                <?php foreach ($sales as $s): ?>
                    <div class="trade-sale-row">
                        <div class="trade-sale-info">
                            <b><?= formatMoney($s['sale_total']) ?> <?= h(APP_CURRENCY) ?></b>
                            <span class="trade-sale-meta">
                                <?= toJalali($s['sale_date']) ?>
                                <?php if ((float)$s['qty'] != 1.0 || (float)$t['qty'] != 1.0): ?> · <?= formatQty($s['qty']) ?> واحد<?php endif; ?>
                                <?php if ($s['wallet_name']): ?> · به <?= h($s['wallet_name']) ?><?php endif; ?>
                            </span>
                            <?php if ($s['notes']): ?><span class="trade-sale-meta"><?= h($s['notes']) ?></span><?php endif; ?>
                        </div>
                        <button type="button" class="delete-btn js-del-sale" data-id="<?= (int)$s['id'] ?>">حذف</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="trade-actions">
                <?php if (!$isClosed): ?>
                    <button type="button" class="btn btn-primary btn-sm js-sell-trade"
                        data-id="<?= (int)$t['id'] ?>"
                        data-title="<?= h($t['title']) ?>"
                        data-remaining="<?= h(rtrim(rtrim(number_format((float)$t['remaining_qty'], 3, '.', ''), '0'), '.')) ?>">ثبت فروش</button>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary btn-sm js-edit-trade"
                    data-id="<?= (int)$t['id'] ?>"
                    data-title="<?= h($t['title']) ?>"
                    data-qty="<?= h(rtrim(rtrim(number_format((float)$t['qty'], 3, '.', ''), '0'), '.')) ?>"
                    data-buy-total="<?= (int)$t['buy_total'] ?>"
                    data-side-costs="<?= (int)$t['side_costs'] ?>"
                    data-buy-date="<?= h($t['buy_date']) ?>"
                    data-wallet="<?= (int)($t['buy_wallet_id'] ?? 0) ?>"
                    data-notes="<?= h($t['notes'] ?? '') ?>">ویرایش</button>
                <button type="button" class="delete-btn js-del-trade" data-id="<?= (int)$t['id'] ?>">حذف</button>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- ---------- ثبت / ویرایش خرید ---------- -->
<div class="modal-overlay" id="tradeModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="tradeModalTitle">خرید جدید</h3>
            <button type="button" class="modal-close" data-modal-close="tradeModal" aria-label="بستن">&times;</button>
        </div>
        <form id="tradeForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="trade_id" id="trade_id" value="">

            <div class="form-group">
                <label for="trade_title">چه چیزی خریدید؟</label>
                <input type="text" id="trade_title" name="title" required maxlength="150"
                       placeholder="مثلاً: آیفون ۱۳ / سکه امامی / پراید ۹۸">
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label for="trade_buy_total">مبلغ کل خرید</label>
                    <input type="text" inputmode="numeric" id="trade_buy_total" name="buy_total" required placeholder="۰" class="amount-input">
                </div>
                <div class="form-group">
                    <label for="trade_qty">تعداد / مقدار</label>
                    <input type="text" inputmode="decimal" id="trade_qty" name="qty" value="1"
                           placeholder="۱">
                </div>
            </div>

            <div class="form-group">
                <label>تاریخ خرید</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" readonly value="<?= toJalali($todayStr) ?>">
                    <input type="hidden" class="jdp-hidden" id="trade_buy_date" name="buy_date" value="<?= h($todayStr) ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="trade_wallet">پرداخت از حساب</label>
                <select id="trade_wallet" name="buy_wallet_id">
                    <option value="0">— بدون برداشت از حساب —</option>
                    <?php foreach ($wallets as $w): ?>
                        <option value="<?= (int)$w['id'] ?>"><?= h($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">اگر انتخاب کنید، مبلغ خرید از موجودی آن حساب کم می‌شود.</p>
            </div>

            <div class="form-group">
                <label for="trade_side_costs">هزینه‌های جانبی <span style="color:var(--muted);font-weight:400">(اختیاری)</span></label>
                <input type="text" inputmode="numeric" id="trade_side_costs" name="side_costs" placeholder="۰" class="amount-input">
                <p class="hint">کارمزد، حمل، تعمیر… فقط در محاسبه‌ی سود لحاظ می‌شود، از حساب کم نمی‌شود.</p>
            </div>

            <div class="form-group">
                <label for="trade_notes">توضیحات <span style="color:var(--muted);font-weight:400">(اختیاری)</span></label>
                <textarea id="trade_notes" name="notes" rows="2" maxlength="2000"></textarea>
            </div>

            <div id="tradeMessage" class="form-message" hidden></div>
            <button type="submit" class="btn btn-primary btn-block" id="tradeSubmitBtn">ثبت</button>
        </form>
    </div>
</div>

<!-- ---------- ثبت فروش ---------- -->
<div class="modal-overlay" id="sellModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="sellModalTitle">ثبت فروش</h3>
            <button type="button" class="modal-close" data-modal-close="sellModal" aria-label="بستن">&times;</button>
        </div>
        <form id="sellForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="trade_id" id="sell_trade_id" value="">

            <div class="form-row-2">
                <div class="form-group">
                    <label for="sell_total">مبلغ کل فروش</label>
                    <input type="text" inputmode="numeric" id="sell_total" name="sale_total" required placeholder="۰" class="amount-input">
                </div>
                <div class="form-group">
                    <label for="sell_qty">تعداد</label>
                    <input type="text" inputmode="decimal" id="sell_qty" name="qty" value="1">
                    <p class="hint" id="sellRemainingHint"></p>
                </div>
            </div>

            <div class="form-group">
                <label>تاریخ فروش</label>
                <div class="jdp-field">
                    <input type="text" class="jdp-display" readonly value="<?= toJalali($todayStr) ?>">
                    <input type="hidden" class="jdp-hidden" id="sell_date" name="sale_date" value="<?= h($todayStr) ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="sell_wallet">واریز به حساب</label>
                <select id="sell_wallet" name="wallet_id">
                    <option value="0">— بدون واریز به حساب —</option>
                    <?php foreach ($wallets as $w): ?>
                        <option value="<?= (int)$w['id'] ?>"><?= h($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="sell_notes">توضیحات <span style="color:var(--muted);font-weight:400">(اختیاری)</span></label>
                <input type="text" id="sell_notes" name="notes" maxlength="500">
            </div>

            <div id="sellMessage" class="form-message" hidden></div>
            <button type="submit" class="btn btn-primary btn-block" id="sellSubmitBtn">ثبت فروش</button>
        </form>
    </div>
</div>

<?php endif; ?>

<meta name="csrf-token" content="<?= Csrf::token() ?>">
<?php include __DIR__ . '/includes/footer.php'; ?>
