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

// ترتیبِ انتخابگرِ دسته‌بندی — از `categoriesByUse()` که تنها مرجعِ این
// تصمیم است. هم شبکه‌ی چیپ‌ها از این ترتیب ساخته می‌شود هم خودِ
// `<select>`، پس چیپِ سوم با گزینه‌ی سومِ منو یکی است.
$__useCounts = categoryUseCounts((int)Auth::userId());

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
                    <?php /* ⛔ «خودکار خواندنِ پیامک» روی وب ممکن نیست: نه
                             PWA و نه TWA به SMS دسترسی دارند و هیچ API ای
                             هم برایش نیست (Web OTP فقط کدِ ورودِ همان
                             دامنه را می‌دهد، نه پیامکِ بانک). نزدیک‌ترین
                             چیز به «خودکار» همین است: یک دکمه که خودش
                             کلیپ‌بورد را می‌خواند و بلافاصله پارس می‌کند —
                             کاربر پیامک را کپی می‌کند و یک تپ. اگر
                             مرورگر اجازه‌ی خواندنِ کلیپ‌بورد ندهد، دکمه
                             اصلاً نشان داده نمی‌شود تا دکمه‌ی بی‌کار
                             نباشد. */ ?>
                    <button type="button" class="btn btn-primary btn-sm" id="smsPasteRead" hidden>چسباندن و خواندن</button>
                    <button type="button" class="btn btn-secondary btn-sm" id="smsPasteApply">خواندن</button>
                    <span class="sms-paste-msg" id="smsPasteMsg"></span>
                </div>
            </div>
        </div>

        <?php /* ⛔ پیش‌فرض «هزینه» است، نه «درآمد» — و این یک خرابیِ
                 بی‌صدا را می‌بندد. روی همین دیتابیس شمرده شد:
                 ۲۹٬۲۲۴ هزینه در برابر ۱۰٬۷۷۹ درآمد (۷۳٪ به ۲۷٪). با
                 پیش‌فرضِ «درآمد»، کاربری که عجله دارد و تاگل را نمی‌بیند
                 خرجش را به‌عنوان **درآمد** ثبت می‌کند: نه خطایی، نه
                 هشداری — فقط موجودی بالا می‌رود و گزارشِ ماه دروغ
                 می‌گوید.

                 ⚠ ترتیبِ دکمه‌ها عمداً دست نخورد. عوض کردنش حافظه‌ی
                 عضلانیِ کاربرِ فعلی را می‌شکست، بی‌آنکه چیزی به دست
                 بیاید — پیش‌فرض همان کاری را می‌کند که لازم است.

                 ⛔ `active` و `value` باید همیشه یکی باشند. اگر از هم
                 دور بیفتند، فهرستِ دسته‌بندی برای یک جهت ساخته می‌شود و
                 چیزی که به سرور می‌رود جهتِ دیگر است — یعنی تراکنش
                 برعکس ثبت می‌شود و دسته‌اش هم `NULL`. قاعده ۳۷ در
                 `test_api_contract.php` همین را می‌سنجد. */ ?>
        <div class="type-toggle" id="typeToggle">
            <button type="button" class="type-btn type-btn-income" data-type="income">درآمد</button>
            <button type="button" class="type-btn type-btn-expense active" data-type="expense">هزینه</button>
        </div>

        <form id="quickAddForm" class="quick-add-form" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="type" id="transactionType" value="expense">

            <div class="form-group">
                <label for="amount">مبلغ (تومان)</label>
                <input type="text" inputmode="numeric" id="amount" name="amount" required placeholder="۰" class="amount-input">
            </div>

            <?php /* ⛔ دسته‌بندی **بالای** عنوان آمد، نه پایینش. ترتیبِ
                     قبلی (مبلغ → عنوان → دسته) کاربر را مجبور می‌کرد
                     اول یک جمله بنویسد و بعد به چیزی برسد که خودش همان
                     جمله را می‌گوید. حالا مسیرِ کوتاه این است: مبلغ،
                     یک تپ روی چیپ، ثبت. */ ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="category_id">دسته‌بندی</label>
                    <?php /* ⛔ شبکه‌ی چیپ‌ها **جایگزینِ** `<select>` نشد،
                             رویش سوار شد. مقدار، رویدادِ `change`،
                             اعتبارسنجیِ فرم، و `setType()` /
                             `resetSelect()` / `optionPicker()` /
                             `smsAutoOk()` همه به وجودِ خودِ `<select>`
                             بندند — همان استدلالی که `optionPicker()` را
                             هم به این شکل نگه داشت. چیپ فقط
                             `selectedIndex` را می‌نویسد.

                             خالی رندر می‌شود و `app.js` پرش می‌کند، چون
                             با عوض شدنِ نوع باید از نو ساخته شود؛ یک
                             نسخه‌ی سرورساخته بی‌صدا کهنه می‌ماند. با
                             `:empty` در CSS پنهان است، پس تا پر نشدن
                             هیچ فضایی نمی‌گیرد. */ ?>
                    <div class="cat-grid" id="catGrid"></div>
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
                <label for="title">عنوان (اختیاری)</label>
                <?php /* datalist نه select: ورودیِ آزاد باید کار کند — همان
                         قاعده‌ای که برای اشخاص هم هست. انتخابِ یک عنوانِ
                         قبلی، دسته و حساب و مبلغِ همان ثبت را پر می‌کند.

                         ⛔ `required` برداشته شد. آن یک فیلد بین کاربر و
                         ثبتِ خرجش می‌ایستاد و جوابش را هم اغلب نداشت
                         («۴۵ هزار نان» عنوانِ تازه‌ای لازم ندارد، دسته‌اش
                         خودش می‌گوید). خالی که بماند سرور نامِ دسته را
                         می‌گذارد — `fallbackTxTitle()`، تنها جای این
                         تصمیم. خودِ اپ یک بار دورِ همین `required` زده
                         بود: مسیرِ «ثبت خودکار از پیامک» مجبور شد عنوانِ
                         ساختگی بگذارد وگرنه `requestSubmit()` بی‌صدا
                         می‌ایستاد. */ ?>
                <input type="text" id="title" name="title" placeholder="اگر خالی بماند، نام دسته‌بندی می‌نشیند"
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

<?php
/**
 * ⛔ آیکون از `categoryIconSvg()` می‌آید، نه از یک نگاشتِ دومِ
 *    جاوااسکریپتی: کلیدها در `categoryIconMap()` تعریف شده‌اند و نسخه‌ی
 *    دوم با اولین آیکونِ تازه (مثل `shield` برای بیمه) عقب می‌ماند و
 *    **بی‌صدا** همه را به `default` می‌برد.
 */
$__catJson = static function (array $cats) use ($__useCounts): string {
    $out = [];
    foreach (categoriesByUse(array_values($cats), $__useCounts) as $c) {
        $out[] = [
            'id'    => (int)$c['id'],
            'name'  => $c['name'],
            'icon'  => categoryIconSvg($c['icon'] ?? null, 22),
            'color' => !empty($c['color']) ? $c['color'] : '#64748b',
        ];
    }
    // ⛔ `JSON_HEX_TAG` اجباری است: نامِ دسته را خودِ کاربر می‌نویسد و
    //    اینجا داخلِ `<script>` می‌نشیند. بدونِ آن، دسته‌ای به نامِ
    //    «‎</script>…» از تگ بیرون می‌زد. نسخه‌ی قبلی این پرچم را نداشت.
    return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
};
?>
<script>
    window.CATEGORY_DATA = {
        income: <?= $__catJson($incomeCategories) ?>,
        expense: <?= $__catJson($expenseCategories) ?>
    };
    // سقفِ چیپ‌ها از سرور می‌آید تا عددش یک جا بماند (CATEGORY_GRID_MAX).
    window.CATEGORY_GRID_MAX = <?= CATEGORY_GRID_MAX ?>;

    // ⛔ ردیفِ چیپ فهرستِ **جدا** دارد، نه `slice()` روی همان بالا: از
    //    وقتی کاربر می‌تواند خودش انتخاب کند («فهرست‌های من»)، آنچه چیپ
    //    می‌گیرد زیرمجموعه‌ی دلخواهِ اوست نه صرفاً هشت‌تای اول. تصمیمش
    //    فقط در `categoriesForGrid()` گرفته می‌شود و اینجا فقط شناسه‌ها
    //    می‌آیند — خودِ نام و آیکون از همان `CATEGORY_DATA` خوانده
    //    می‌شود تا دو نسخه از یک ردیف در صفحه نباشد.
    window.CATEGORY_GRID = {
        income: <?= json_encode(array_map(
            fn($c) => (int)$c['id'],
            categoriesForGrid(cachedCategories(), $__useCounts, 'income')
        )) ?>,
        expense: <?= json_encode(array_map(
            fn($c) => (int)$c['id'],
            categoriesForGrid(cachedCategories(), $__useCounts, 'expense')
        )) ?>
    };
</script>
