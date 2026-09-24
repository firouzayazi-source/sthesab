<?php
/**
 * نمای کارت بانکی + تعدیل موجودی — دو مودالِ مشترک.
 *
 * ⛔ این فایل عمداً از `wallets.php` بیرون کشیده شد، نه کپی.
 *   صفحه‌ی خانه هم حالا با تپ روی کارتِ پین‌شده همین نما را باز می‌کند و
 *   با دو نسخه، دیر یا زود یکی‌شان از دیگری عقب می‌افتاد: مثلاً ردیفِ
 *   «شبا» به یکی اضافه می‌شد و به آن یکی نه، و کاربر بسته به اینکه از
 *   کجا کارت را باز کرده چیزِ متفاوتی می‌دید — بی‌هیچ خطایی.
 *
 * ⛔ نگهبانِ `APP_BASE_PATH` اختیاری نیست: سایتِ nginx فقط `/deploy/`,
 *   `/tests/`, `/config/` و `/includes/` را می‌بندد. آن یکی هست، ولی
 *   همان درسِ `admin/_nav.php` می‌گوید لایه‌ی nginx می‌تواند از دست برود
 *   (certbot فایل سایت را بازنویسی می‌کند) و آن‌وقت باز کردنِ مستقیمِ
 *   این فایل برای هر کسی ۵۰۰ می‌داد.
 *
 * ⚠ دو دکمه‌ی «ویرایش حساب» و «تعدیل موجودی» به چیزهایی وصل‌اند که فقط
 *   در `wallets.php` هستند (شیتِ ویرایش، و همین مودالِ تعدیل). `app.js`
 *   وجودشان را می‌سنجد و اگر نبودند دکمه را به `wallets.php` می‌برد —
 *   دکمه‌ای که کار نمی‌کند بدتر از نبودنش است.
 */
if (!defined('APP_BASE_PATH')) {
    http_response_code(404);
    exit;
}
?>
<!-- ---------- نمای کارت بانکی ----------
     طرح و رنگ از روی بانکِ انتخاب‌شده ساخته می‌شود، و لوگوی رسمیِ همان
     بانک (`bankLogoUrl()`) کنارِ نامش. -->
<div class="modal-overlay" id="bankCardModal">
    <div class="modal-box bank-card-box">
        <div class="modal-header">
            <h3 id="bankCardTitle">کارت</h3>
            <button type="button" class="modal-close" data-modal-close="bankCardModal" aria-label="بستن">&times;</button>
        </div>

        <div class="bank-card" id="bankCardVisual">
            <div class="bank-card-shine"></div>
            <div class="bank-card-top">
                <span class="bank-card-bank"><span class="bank-logo" id="bcLogo" hidden><img src="" alt="" width="22" height="22"></span><span id="bcBank"></span></span>
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
