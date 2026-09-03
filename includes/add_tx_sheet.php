<?php
/**
 * شیت ثبت سریع تراکنش — با دکمه + در ناوبری پایین باز می‌شود.
 * چون در فوتر گنجانده شده، از هر صفحه‌ای در دسترس است.
 */
if (!isset($incomeCategories) || !isset($expenseCategories)) {
    $__cats = cachedCategories();
    $incomeCategories  = array_filter($__cats, fn($c) => $c['type'] === 'income');
    $expenseCategories = array_filter($__cats, fn($c) => $c['type'] === 'expense');
}
$__today = today();

// حساب‌های فعال کاربر برای انتخاب در فرم (کش‌شده در همین درخواست)
$__wallets = activeWallets((int)Auth::userId());
// چهار رقمِ آخرِ کارت — تنها چیزی که «از پیامک بانک» برای پیدا کردنِ
// حساب لازم دارد. شماره‌ی کامل عمداً بیرون نمی‌رود.
$__cardTails = walletCardTails((int)Auth::userId());
?>
<div class="sheet-overlay" id="addTxSheet">
    <div class="sheet">
        <div class="more-sheet-handle"></div>
        <div class="sheet-head">
            <h3 class="more-sheet-title" style="margin:0;">ثبت تراکنش</h3>
            <button type="button" class="modal-close" data-sheet-close>&times;</button>
        </div>

        <?php /* ⛔ «از پیامک بانک» عمداً یک دکمه‌ی کوچکِ کنارِ عنوان است،
                 نه یک حالت یا صفحه‌ی جدا. داده‌ی واقعیِ کاربر ایرانی در
                 پیامک بانک است، ولی TWA و PWA به SMS دسترسی ندارند — و
                 همین محدودیت مزیتِ حریم خصوصی است: هیچ چیزی به سرور
                 نمی‌رود، فقط فیلدهای همین فرم پر می‌شوند. */ ?>
        <button type="button" class="sms-paste-btn" id="smsPasteBtn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            از پیامک بانک
        </button>

        <div class="sms-paste" id="smsPasteBox">
            <div class="sms-paste-inner">
                <textarea id="smsPasteText" rows="3"
                          placeholder="پیامک بانک را اینجا بچسبانید…"></textarea>
                <div class="sms-paste-actions">
                    <button type="button" class="btn btn-secondary btn-sm" id="smsPasteApply">خواندن</button>
                    <span class="sms-paste-msg" id="smsPasteMsg"></span>
                </div>
            </div>
        </div>

        <div class="type-toggle" id="typeToggle">
            <button type="button" class="type-btn type-btn-income active" data-type="income">درآمد</button>
            <button type="button" class="type-btn type-btn-expense" data-type="expense">هزینه</button>
        </div>

        <form id="quickAddForm" class="quick-add-form" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="type" id="transactionType" value="income">

            <div class="form-group">
                <label for="amount">مبلغ (تومان)</label>
                <input type="text" inputmode="numeric" id="amount" name="amount" required placeholder="۰" class="amount-input">
            </div>

            <div class="form-group">
                <label for="title">عنوان</label>
                <?php /* datalist نه select: ورودیِ آزاد باید کار کند — همان
                         قاعده‌ای که برای اشخاص هم هست. انتخابِ یک عنوانِ
                         قبلی، دسته و حساب و مبلغِ همان ثبت را پر می‌کند. */ ?>
                <input type="text" id="title" name="title" required placeholder="این پول بابت چه بود؟"
                       maxlength="255" list="recentTitles">
            </div>

            <?php if (count($__wallets) > 1): ?>
            <div class="form-group">
                <label for="wallet_select">از/به حساب</label>
                <select id="wallet_select" name="wallet_id">
                    <?php foreach ($__wallets as $__w): ?>
                        <option value="<?= (int)$__w['id'] ?>"
                                <?php if (isset($__cardTails[(int)$__w['id']])): ?>data-card4="<?= h($__cardTails[(int)$__w['id']]) ?>"<?php endif; ?>><?= h($__w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php elseif (count($__wallets) === 1): ?>
                <input type="hidden" name="wallet_id" value="<?= (int)$__wallets[0]['id'] ?>">
            <?php endif; ?>

            <div class="form-row">
                <div class="form-group">
                    <label for="category_id">دسته‌بندی</label>
                    <select id="category_id" name="category_id">
                        <option value="">بدون دسته‌بندی</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>تاریخ</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" readonly value="<?= toJalali($__today) ?>">
                        <input type="hidden" class="jdp-hidden" id="transaction_date" name="transaction_date" value="<?= h($__today) ?>">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="note">توضیح (اختیاری)</label>
                <textarea id="note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-large" id="submitBtn">
                <span id="submitBtnText">ثبت تراکنش</span>
            </button>

            <div id="formMessage" class="form-message" hidden></div>
        </form>
    </div>
</div>

<script>
    window.CATEGORY_DATA = {
        income: <?= json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], array_values($incomeCategories)), JSON_UNESCAPED_UNICODE) ?>,
        expense: <?= json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], array_values($expenseCategories)), JSON_UNESCAPED_UNICODE) ?>
    };
</script>
