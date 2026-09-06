<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();
$todayStr = today();

$wallets = walletBalances($userId);
$total   = 0;
foreach ($wallets as $__w) {
    if ((int)$__w['is_active'] === 1) { $total += (int)$__w['balance']; }
}

// دریافتی و پرداختی این ماه — همان چیزی که تا حالا بالای صفحه‌ی گزارش
// بود. جایش اینجاست: کنار خودِ حساب‌ها معنا دارد، نه در گزارش که یک بار
// دیگر همین عدد را نشان می‌داد.
$monthStats = ['income' => 0, 'expense' => 0];
$msStmt = $pdo->prepare('
    SELECT
        COALESCE(SUM(CASE WHEN type = "income"  THEN amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END), 0) AS expense
    FROM transactions
    WHERE user_id = :u AND transaction_date BETWEEN :f AND :t
');
$msStmt->execute(['u' => $userId, 'f' => startOfJalaliMonth(), 't' => $todayStr]);
if ($row = $msStmt->fetch()) {
    $monthStats = ['income' => (int)$row['income'], 'expense' => (int)$row['expense']];
}

$activeWallets = array_values(array_filter($wallets, fn($w) => (int)$w['is_active'] === 1));

// «کارت بانکی» از فهرست انتخاب برداشته شد (عملاً همان حساب بانکی بود).
// ولی اگر کاربر از قبل حسابی با این نوع دارد، گزینه‌اش باید در فرم بماند
// وگرنه ویرایشِ ساده‌ی همان حساب نوعش را بی‌سروصدا عوض می‌کرد.
$legacyKind = '';
foreach ($wallets as $__w) {
    if ($__w['kind'] === 'card') { $legacyKind = 'card'; break; }
}

$transferStmt = $pdo->prepare('
    SELECT tr.*, wf.name AS from_name, wt.name AS to_name
    FROM transfers tr
    JOIN wallets wf ON wf.id = tr.from_wallet_id
    JOIN wallets wt ON wt.id = tr.to_wallet_id
    WHERE tr.user_id = :u
    ORDER BY tr.transfer_date DESC, tr.created_at DESC
    LIMIT 30
');
$transferStmt->execute(['u' => $userId]);
$transfers = $transferStmt->fetchAll();

$pageTitle = 'حساب‌ها';
include __DIR__ . '/includes/header.php';
?>

<div class="balance-ribbon">
    <div class="balance-label">موجودی کل حساب‌ها</div>
    <div class="balance-value">
        <span class="bv-num"><?= $total < 0 ? '−' : '' ?><?= formatMoney(abs($total)) ?></span>
        <span class="bv-unit"><?= h(APP_CURRENCY) ?></span>
    </div>
    <div class="balance-split">
        <div>
            <div class="bs-label">دریافتی این ماه</div>
            <div class="bs-value bs-in"><?= formatMoney($monthStats['income']) ?></div>
        </div>
        <div>
            <div class="bs-label">پرداختی این ماه</div>
            <div class="bs-value bs-out"><?= formatMoney($monthStats['expense']) ?></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">حساب‌ها و کیف پول‌ها</h2>
        <button type="button" class="btn btn-primary btn-sm js-add-wallet">+ حساب جدید</button>
    </div>

    <?php if (empty($wallets)): ?>
        <p class="empty-row">هنوز حسابی ندارید.</p>
    <?php else: ?>
        <?php foreach ($wallets as $w): ?>
            <?php $__bp = bankPreset($w['bank_code'] ?? null); ?>
            <div class="wallet-row js-show-card <?= (int)$w['is_active'] ? '' : 'wallet-off' ?>"
                data-id="<?= (int)$w['id'] ?>"
                data-name="<?= h($w['name']) ?>"
                data-bank="<?= h($w['bank_name'] ?: ($__bp['name'] ?? '')) ?>"
                data-card="<?= h(formatCardNumber($w['card_number'] ?? '')) ?>"
                data-account="<?= h(toPersianDigits($w['account_number'] ?? '')) ?>"
                data-iban="<?= h(formatIban($w['iban'] ?? '')) ?>"
                data-kind="<?= h(walletKindLabel($w['kind'], $w['kind_label'] ?? null)) ?>"
                data-balance="<?= ((int)$w['balance'] < 0 ? '−' : '') . formatMoney(abs((int)$w['balance'])) ?>"
                data-c1="<?= h($w['color']) ?>"
                data-c2="<?= h(shadeColor($w['color'])) ?>">
                <span class="wallet-chip" style="background: <?= h($w['color']) ?>1f; color: <?= h($w['color']) ?>;">
                    <?php if ($w['kind'] === 'card'): ?>
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2.5" y="5.5" width="19" height="13" rx="2.5"/><path d="M2.5 10h19"/></svg>
                    <?php elseif ($w['kind'] === 'bank'): ?>
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"><path d="M3 9.5L12 4l9 5.5"/><path d="M5 10v8M9.7 10v8M14.3 10v8M19 10v8"/><path d="M3 20h18"/></svg>
                    <?php elseif ($w['kind'] === 'cash'): ?>
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2.5" y="6.5" width="19" height="11" rx="2"/><circle cx="12" cy="12" r="2.4"/></svg>
                    <?php else: ?>
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 7.5h15a2.5 2.5 0 012.5 2.5v7a2.5 2.5 0 01-2.5 2.5H5.5A2.5 2.5 0 013 17V7.5z"/><path d="M3 7.5l12-3v3"/><circle cx="17" cy="13.5" r="1.2"/></svg>
                    <?php endif; ?>
                </span>

                <div class="wallet-meta">
                    <div class="wallet-name"><?= h($w['name']) ?></div>
                    <div class="wallet-sub">
                        <?= h(walletKindLabel($w['kind'], $w['kind_label'] ?? null)) ?>
                        <?php if (!empty($w['bank_name'])): ?> · <?= h($w['bank_name']) ?><?php endif; ?>
                        <?php if (!empty($w['card_last4'])): ?> · <?= toPersianDigits($w['card_last4']) ?><?php endif; ?>
                        <?php if (!(int)$w['is_active']): ?> · غیرفعال<?php endif; ?>
                    </div>
                </div>

                <div class="wallet-bal <?= (int)$w['balance'] < 0 ? 'amount-expense' : '' ?>">
                    <?= (int)$w['balance'] < 0 ? '−' : '' ?><?= formatMoney(abs((int)$w['balance'])) ?>
                </div>

                <?php /* ⚠ `data-stop="1"` لازم است: کلِ ردیف `js-show-card`
                         است و بدونِ آن، تپ روی پین نمای کارت را هم باز
                         می‌کرد. همان الگوی دکمه‌ی ویرایش.
                         ⚠ فقط برای حسابِ فعال رندر می‌شود، چون
                         `pinnedWallets()` حسابِ غیرفعال را در هر حال
                         کنار می‌گذارد — کلیدی که کار نمی‌کند بدتر از
                         نبودنش است. */ ?>
                <?php if (tableHasColumn('wallets', 'pinned') && (int)$w['is_active']): ?>
                <button type="button" class="wallet-pin js-pin-wallet<?= !empty($w['pinned']) ? ' is-on' : '' ?>"
                    data-stop="1" data-id="<?= (int)$w['id'] ?>"
                    data-pinned="<?= !empty($w['pinned']) ? '1' : '0' ?>"
                    aria-pressed="<?= !empty($w['pinned']) ? 'true' : 'false' ?>"
                    title="<?= !empty($w['pinned']) ? 'برداشتن از صفحه‌ی خانه' : 'نمایش روی صفحه‌ی خانه' ?>"
                    aria-label="نمایش روی صفحه‌ی خانه">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4h6l-1 5 3.5 3v2h-11v-2L10 9 9 4z"/><path d="M12 14v6"/></svg>
                </button>
                <?php endif; ?>

                <button type="button" class="wallet-menu js-edit-wallet" data-stop="1"
                    data-id="<?= (int)$w['id'] ?>"
                    data-name="<?= h($w['name']) ?>"
                    data-kind="<?= h($w['kind']) ?>"
                    data-kind-label="<?= h($w['kind_label'] ?? '') ?>"
                    data-bank="<?= h($w['bank_name'] ?? '') ?>"
                    data-last4="<?= h($w['card_last4'] ?? '') ?>"
                    data-bank-code="<?= h($w['bank_code'] ?? '') ?>"
                    data-card="<?= h($w['card_number'] ?? '') ?>"
                    data-account="<?= h($w['account_number'] ?? '') ?>"
                    data-iban="<?= h($w['iban'] ?? '') ?>"
                    data-color="<?= h($w['color']) ?>"
                    data-init="<?= (int)$w['initial_balance'] ?>"
                    data-active="<?= (int)$w['is_active'] ?>"
                    aria-label="ویرایش">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/></svg>
                </button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">انتقال بین حساب‌ها</h2>
        <button type="button" class="btn btn-primary btn-sm js-add-transfer" <?= count($activeWallets) < 2 ? 'disabled' : '' ?>>+ انتقال</button>
    </div>

    <?php if (count($activeWallets) < 2): ?>
        <p class="empty-row">برای انتقال، حداقل به دو حساب فعال نیاز دارید.</p>
    <?php elseif (empty($transfers)): ?>
        <p class="empty-row">هنوز انتقالی ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($transfers as $tr): ?>
            <div class="transfer-row">
                <div class="transfer-path">
                    <span class="tp-from"><?= h($tr['from_name']) ?></span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
                    <span class="tp-to"><?= h($tr['to_name']) ?></span>
                </div>
                <div class="transfer-side">
                    <div class="transfer-amount"><?= formatMoney($tr['amount']) ?></div>
                    <div class="transfer-date"><?= toJalali($tr['transfer_date']) ?></div>
                </div>
                <div class="transfer-actions">
                    <button type="button" class="btn btn-secondary btn-sm js-edit-transfer"
                        data-id="<?= (int)$tr['id'] ?>"
                        data-from="<?= (int)$tr['from_wallet_id'] ?>"
                        data-to="<?= (int)$tr['to_wallet_id'] ?>"
                        data-amount="<?= (int)$tr['amount'] ?>"
                        data-fee="<?= (int)$tr['fee'] ?>"
                        data-note="<?= h($tr['note'] ?? '') ?>"
                        data-date="<?= h($tr['transfer_date']) ?>">ویرایش</button>
                    <button class="delete-btn js-delete-transfer" data-id="<?= (int)$tr['id'] ?>">حذف</button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- مودال حساب -->
<div class="modal-overlay" id="walletModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="walletModalTitle">حساب جدید</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form id="walletForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="wallet_id" id="wallet_id" value="">

            <div class="form-group">
                <label for="wallet_name">نام حساب</label>
                <input type="text" id="wallet_name" name="name" required maxlength="100" placeholder="مثلاً: بانک ملت">
            </div>

            <div class="form-group">
                <label for="wallet_kind">نوع</label>
                <select id="wallet_kind" name="kind">
                    <option value="cash">نقدی</option>
                    <option value="bank">حساب بانکی</option>
                    <option value="other">سایر</option>
                    <?php if (in_array($legacyKind, ['card'], true)): ?>
                        <option value="card">کارت بانکی (قدیمی)</option>
                    <?php endif; ?>
                </select>
            </div>

            <!-- «سایر» به‌تنهایی چیزی نمی‌گوید. اسمی که اینجا بنویسید
                 برای دفعه‌ی بعد می‌ماند — مثل نام بانک‌ها. -->
            <div class="form-group" id="walletKindLabelWrap" hidden>
                <label for="wallet_kind_label">این حساب چه نوعی است؟</label>
                <select id="wallet_kind_label" name="kind_label">
                    <?php foreach (walletKinds($userId) as $k): ?>
                        <option value="<?= h($k['name']) ?>"><?= h($k['name']) ?></option>
                    <?php endforeach; ?>
                    <option value="__new__">+ نوع تازه…</option>
                </select>
                <input type="text" id="wallet_kind_new" name="kind_label_new" maxlength="60"
                       placeholder="مثلاً: حساب ارزی" hidden style="margin-top:8px;">
                <p class="hint">هر نوعی که بسازید در فهرست می‌ماند و دفعه‌ی بعد فقط انتخابش می‌کنید.</p>
            </div>

            <div id="walletBankFields">
                <div class="form-group">
                    <label for="wallet_bank_code">بانک</label>
                    <select id="wallet_bank_code" name="bank_code">
                        <option value="">— انتخاب کنید —</option>
                        <?php foreach (bankPresets() as $code => $b): ?>
                            <option value="<?= h($code) ?>" data-c1="<?= h($b[1]) ?>"><?= h($b[0]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">با انتخاب بانک، رنگ و طرح کارت خودکار تنظیم می‌شود — رنگ را می‌توانید دستی هم عوض کنید.</p>
                </div>

                <div class="form-group">
                    <label for="wallet_bank">نام دلخواه بانک (اختیاری)</label>
                    <input type="text" id="wallet_bank" name="bank_name" maxlength="100"
                           placeholder="اگر خالی بماند، نام بانک انتخاب‌شده می‌نشیند">
                </div>

                <div class="form-group">
                    <label for="wallet_card_number">شماره کارت</label>
                    <input type="text" inputmode="numeric" id="wallet_card_number" name="card_number"
                           maxlength="23" placeholder="۱۶ رقم" class="ltr-num">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="wallet_account_number">شماره حساب</label>
                        <input type="text" inputmode="numeric" id="wallet_account_number" name="account_number"
                               maxlength="30" class="ltr-num">
                    </div>
                    <div class="form-group">
                        <label for="wallet_last4">۴ رقم آخر کارت</label>
                        <input type="text" inputmode="numeric" id="wallet_last4" name="card_last4" maxlength="4" class="ltr-num">
                        <p class="hint">اگر شماره کارت را کامل بزنید، خودش پر می‌شود.</p>
                    </div>
                </div>

                <div class="form-group">
                    <label for="wallet_iban">شبا</label>
                    <div class="iban-field">
                        <span class="iban-prefix">IR</span>
                        <input type="text" inputmode="numeric" id="wallet_iban" name="iban"
                               maxlength="29" placeholder="۲۴ رقم" class="ltr-num">
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="wallet_init">موجودی اولیه</label>
                    <input type="text" inputmode="numeric" id="wallet_init" name="initial_balance" placeholder="۰">
                    <p class="hint">اگر بعداً با پول واقعی نخواند، از «تعدیل موجودی» درستش کنید.</p>
                </div>
                <div class="form-group">
                    <label for="wallet_color">رنگ</label>
                    <input type="color" id="wallet_color" name="color" value="#16794f">
                </div>
            </div>

            <div id="walletMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="walletSubmitBtn">ذخیره</button>
            </div>

            <div id="walletExtraActions" class="wallet-extra" hidden>
                <button type="button" class="btn btn-secondary btn-sm" id="walletToggleBtn"></button>
                <button type="button" class="delete-btn" id="walletDeleteBtn">حذف حساب</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال انتقال -->
<div class="modal-overlay" id="transferModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="transferModalTitle">انتقال بین حساب‌ها</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form id="transferForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="transfer_id" id="transfer_id" value="">

            <div class="form-group">
                <label for="transfer_from">از حساب</label>
                <select id="transfer_from" name="from_wallet_id" required>
                    <?php foreach ($activeWallets as $w): ?>
                        <option value="<?= (int)$w['id'] ?>"><?= h($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="transfer_to">به حساب</label>
                <select id="transfer_to" name="to_wallet_id" required>
                    <?php foreach ($activeWallets as $w): ?>
                        <option value="<?= (int)$w['id'] ?>"><?= h($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="transfer_amount">مبلغ (تومان)</label>
                <input type="text" inputmode="numeric" id="transfer_amount" name="amount" required class="amount-input" placeholder="۰">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="transfer_fee">کارمزد (اختیاری)</label>
                    <input type="text" inputmode="numeric" id="transfer_fee" name="fee" placeholder="۰">
                </div>
                <div class="form-group">
                    <label>تاریخ</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" id="transfer_date_display" readonly value="<?= toJalali($todayStr) ?>">
                        <input type="hidden" class="jdp-hidden" id="transfer_date" name="transfer_date" value="<?= h($todayStr) ?>">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="transfer_note">توضیح (اختیاری)</label>
                <textarea id="transfer_note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <p class="hint">انتقال در گزارش درآمد و هزینه شمرده نمی‌شود؛ فقط موجودی دو حساب را جابه‌جا می‌کند.</p>

            <div id="transferMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="transferSubmitBtn">ثبت</button>
            </div>
        </form>
    </div>
</div>

<!-- ---------- نمای کارت بانکی ----------
     طرح و رنگ از روی بانکِ انتخاب‌شده ساخته می‌شود. عمداً از لوگو یا
     تصویر کارت واقعی بانک‌ها استفاده نشده — علامت تجاری‌شان است. -->
<div class="modal-overlay" id="bankCardModal">
    <div class="modal-box bank-card-box">
        <div class="modal-header">
            <h3 id="bankCardTitle">کارت</h3>
            <button type="button" class="modal-close" data-modal-close="bankCardModal" aria-label="بستن">&times;</button>
        </div>

        <div class="bank-card" id="bankCardVisual">
            <div class="bank-card-shine"></div>
            <div class="bank-card-top">
                <span class="bank-card-bank" id="bcBank"></span>
                <span class="bank-card-kind" id="bcKind"></span>
            </div>
            <div class="bank-card-chip" aria-hidden="true"></div>
            <div class="bank-card-number" id="bcNumber"></div>
            <div class="bank-card-bottom">
                <div>
                    <span class="bank-card-label">صاحب حساب</span>
                    <span class="bank-card-owner" id="bcOwner"></span>
                </div>
                <div class="bank-card-balance-wrap">
                    <span class="bank-card-label">موجودی</span>
                    <span class="bank-card-balance" id="bcBalance"></span>
                </div>
            </div>
        </div>

        <div class="bank-card-rows" id="bcRows"></div>

        <div class="bank-card-actions">
            <button type="button" class="btn btn-primary btn-sm" id="bcEditBtn">ویرایش حساب</button>
            <button type="button" class="btn btn-secondary btn-sm" id="bcAdjustBtn">تعدیل موجودی</button>
            <a href="<?= APP_BASE_PATH ?>/transactions.php" class="btn btn-secondary btn-sm bank-card-actions-wide" id="bcTxLink">تراکنش‌های این حساب</a>
        </div>
    </div>
</div>

<!-- ---------- تعدیل موجودی ----------
     موجودی هر حساب محاسبه‌شده است؛ اگر با پول واقعی نخواند (مثلاً چون
     خرج نقدی روی حسابی نشسته که واقعاً از آن پرداخت نشده)، اینجا برابرش
     می‌کنیم. تعدیل عمداً تراکنش نمی‌سازد تا گزارش درآمد/هزینه دست‌نخورده
     بماند — فقط موجودی اولیه‌ی حساب جابه‌جا می‌شود. -->
<div class="modal-overlay" id="adjustWalletModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="adjustWalletTitle">تعدیل موجودی</h3>
            <button type="button" class="modal-close" data-modal-close="adjustWalletModal">&times;</button>
        </div>
        <form id="adjustWalletForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="wallet_id" id="adjust_wallet_id">

            <p class="hint" id="adjustCurrentHint"></p>

            <div class="type-toggle" id="adjustModeToggle">
                <button type="button" class="type-btn type-btn-income active" data-mode="add">افزودن</button>
                <button type="button" class="type-btn type-btn-expense" data-mode="sub">کم کردن</button>
                <button type="button" class="type-btn type-btn-neutral" data-mode="set">موجودی واقعی</button>
            </div>
            <input type="hidden" name="mode" id="adjust_mode" value="add">

            <div class="form-group">
                <label for="adjust_amount">مبلغ (تومان)</label>
                <input type="text" inputmode="numeric" id="adjust_amount" name="amount" required class="amount-input" placeholder="۰">
                <p class="hint" id="adjustModeHint">این مبلغ به موجودی حساب اضافه می‌شود.</p>
            </div>

            <label class="switch" id="adjustNegWrap" hidden style="margin:2px 0 4px;">
                <input type="checkbox" name="negative" value="1" id="adjust_negative">
                <span class="switch-track"><span class="switch-knob"></span></span>
                <span class="switch-text">موجودی واقعی منفی است (بدهکار)</span>
            </label>

            <p class="hint">تعدیل در گزارش درآمد و هزینه شمرده نمی‌شود؛ فقط عدد حساب را با واقعیت برابر می‌کند.</p>

            <div id="adjustWalletMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close="adjustWalletModal">انصراف</button>
                <button type="submit" class="btn btn-primary" id="adjustWalletSubmitBtn">اعمال</button>
            </div>
        </form>
    </div>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php include __DIR__ . '/includes/footer.php'; ?>
