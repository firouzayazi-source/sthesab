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
?>
<div class="sheet-overlay" id="addTxSheet">
    <div class="sheet">
        <div class="more-sheet-handle"></div>
        <div class="sheet-head">
            <h3 class="more-sheet-title" style="margin:0;">ثبت تراکنش</h3>
            <button type="button" class="modal-close" data-sheet-close>&times;</button>
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
                <input type="text" id="title" name="title" required placeholder="این پول بابت چه بود؟" maxlength="255">
            </div>

            <?php if (count($__wallets) > 1): ?>
            <div class="form-group">
                <label for="wallet_select">از/به حساب</label>
                <select id="wallet_select" name="wallet_id">
                    <?php foreach ($__wallets as $__w): ?>
                        <option value="<?= (int)$__w['id'] ?>"><?= h($__w['name']) ?></option>
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
