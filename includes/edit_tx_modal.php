<?php
/**
 * مودال مشترک ویرایش تراکنش — در index.php و transactions.php استفاده می‌شود
 * نیازمند: $incomeCategories و $expenseCategories در scope والد
 */
if (!isset($incomeCategories)) {
    $__c = cachedCategories();
    $incomeCategories  = array_filter($__c, fn($c) => $c['type'] === 'income');
    $expenseCategories = array_filter($__c, fn($c) => $c['type'] === 'expense');
}
?>
<div class="modal-overlay" id="editTxModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>ویرایش تراکنش</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>

        <div class="type-toggle" id="editTypeToggle">
            <button type="button" class="type-btn type-btn-income active" data-type="income">درآمد</button>
            <button type="button" class="type-btn type-btn-expense" data-type="expense">هزینه</button>
        </div>

        <form id="editTxForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="transaction_id" id="edit_transaction_id">
            <input type="hidden" name="type" id="edit_transaction_type" value="income">

            <div class="form-row">
                <div class="form-group">
                    <label for="edit_amount">مبلغ (تومان)</label>
                    <input type="text" inputmode="numeric" id="edit_amount" name="amount" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label>تاریخ</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" id="edit_date_display" readonly>
                        <input type="hidden" class="jdp-hidden" id="edit_transaction_date" name="transaction_date">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="edit_title">عنوان</label>
                <input type="text" id="edit_title" name="title" required maxlength="255">
            </div>

            <div class="form-group">
                <label for="edit_category_id">دسته‌بندی (اختیاری)</label>
                <select id="edit_category_id" name="category_id">
                    <option value="">بدون دسته‌بندی</option>
                </select>
            </div>

            <div class="form-group">
                <label for="edit_note">توضیح (اختیاری)</label>
                <textarea id="edit_note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <div id="editTxMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="editTxSubmitBtn">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>
