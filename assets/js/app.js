/* ============================================================
   انتخابگر شخص (چک، طلب و بدهی، معامله)
   ------------------------------------------------------------
   هر `<select class="js-person-select">` یک ورودیِ متنی را کنترل
   می‌کند (`data-target`). انتخاب یک شخص، نامش را داخل همان ورودی
   می‌گذارد؛ زدنِ «سایر» ورودی را باز می‌کند تا کاربر دستی بنویسد.

   چیزی که به سرور می‌رود همچنان همان ورودیِ `counterparty_name` است،
   پس هیچ اندپوینتی عوض نشده.

   بیرون از DOMContentLoaded است چون فرم‌های ویرایش با
   `syncPersonPicker()` از اسکریپت‌های درون‌صفحه‌ای پر می‌شوند و آن‌ها
   زودتر از این فایل اجرا می‌شوند.
   ============================================================ */
(function () {
    var OTHER = '__other__';

    /** سمتِ شخصِ انتخاب‌شده را کنار عنوان نشان می‌دهد. */
    function showRole(sel) {
        var badge = sel.parentNode.querySelector('.js-person-role');
        if (!badge) { return; }
        var opt  = sel.options[sel.selectedIndex];
        var role = (opt && opt.getAttribute('data-role')) || '';
        badge.textContent = role;
        badge.hidden = role === '';
    }

    function applyChoice(sel) {
        var input = document.getElementById(sel.getAttribute('data-target'));
        if (!input) { return; }
        var hint = sel.parentNode.querySelector('.js-person-hint');
        showRole(sel);

        if (sel.value === OTHER) {
            input.hidden = false;
            if (input.value && sel.dataset.knownName === input.value) { input.value = ''; }
            input.focus();
        } else if (sel.value === '') {
            input.hidden = true;
            input.value = '';
        } else {
            input.hidden = true;
            input.value = sel.value;
            sel.dataset.knownName = sel.value;
        }
        if (hint) { hint.hidden = sel.value !== OTHER && sel.value !== ''; }
    }

    /**
     * فرم ویرایش را با نامِ ذخیره‌شده هماهنگ می‌کند: اگر آن نام در فهرست
     * بود همان انتخاب می‌شود، وگرنه «سایر» با ورودیِ باز و پرشده.
     * (روی window چون صفحه‌ها از اسکریپت درون‌خطی صدایش می‌زنند.)
     */
    window.syncPersonPicker = function (inputId, name) {
        var input = document.getElementById(inputId);
        if (!input) { return; }
        input.value = name || '';

        var sel = document.querySelector('.js-person-select[data-target="' + inputId + '"]');
        if (!sel) { input.hidden = false; return; }

        var found = false;
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === name && name !== '') { found = true; break; }
        }
        sel.value = found ? name : OTHER;
        sel.dataset.knownName = found ? name : '';
        input.hidden = found;
        showRole(sel);
        var hint = sel.parentNode.querySelector('.js-person-hint');
        if (hint) { hint.hidden = found; }
    };

    document.addEventListener('change', function (e) {
        var sel = e.target.closest ? e.target.closest('.js-person-select') : null;
        if (sel) { applyChoice(sel); }
    });
}());

/* ============================================================
   ثبت سرویس‌ورکر
   ------------------------------------------------------------
   با این، اپ از آیکون صفحه‌ی اصلی گوشی مثل یک برنامه باز می‌شود،
   فایل‌های ثابت از حافظه‌ی خودِ گوشی می‌آیند، و اگر اینترنت نبود
   به‌جای صفحه‌ی خطای مرورگر، صفحه‌ی «اینترنت نیست» خودمان می‌آید.

   سرویس‌ورکر عمداً هیچ HTML ای کش نمی‌کند — توضیحش در sw.js است.

   ثبت بعد از load انجام می‌شود، نه همان اول: دانلود و نصبِ سرویس‌ورکر
   با بارگذاریِ خودِ صفحه سرِ پهنای باند دعوا نکند.
   ============================================================ */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        var base = (window.APP_BASE || '');
        navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' })
            .catch(function () { /* بی‌سروصدا: اپ بدون این هم کامل کار می‌کند */ });
    });
}

/* ============================================================
   رنگِ وابسته به حالت شب
   ------------------------------------------------------------
   نمودارها رنگشان را در جاوااسکریپت می‌گیرند، پس با عوض شدن حالت شب
   باید دوباره رنگ بگیرند — نقطه‌های رنگی کنار فهرست‌ها این مشکل را
   ندارند چون رنگشان از متغیر CSS می‌آید.

   این تکه عمداً بیرون از DOMContentLoaded است: اسکریپت درون‌صفحه‌ایِ
   صفحه‌ی گزارش شنونده‌اش را هنگام تجزیه‌ی HTML ثبت می‌کند، یعنی زودتر
   از شنونده‌ی این فایل که با defer اجرا می‌شود. اگر این توابع داخل
   شنونده تعریف می‌شدند، آن صفحه با «registerThemedChart is not defined»
   می‌شکست.
   ============================================================ */
(function () {
    var themedCharts = [];
    window.__themeHooks = window.__themeHooks || [];

    function surfaceColor() {
        return getComputedStyle(document.documentElement)
            .getPropertyValue('--surface').trim() || '#ffffff';
    }

    function paintChart(entry) {
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        var colors = dark ? entry.dark : entry.light;
        var surface = surfaceColor();
        entry.chart.data.datasets.forEach(function (ds) {
            ds.backgroundColor = colors;
            ds.borderColor = surface;
        });
        entry.chart.update('none');
    }

    window.registerThemedChart = function (chart, lightColors, darkColors) {
        var entry = { chart: chart, light: lightColors, dark: darkColors };
        themedCharts.push(entry);
        paintChart(entry);
    };

    window.onThemeChange = function (fn) { window.__themeHooks.push(fn); };

    window.__repaintThemedCharts = function () {
        themedCharts.forEach(paintChart);
        window.__themeHooks.forEach(function (fn) {
            try { fn(); } catch (e) {}
        });
    };
})();

document.addEventListener('DOMContentLoaded', function () {

    // اول از همه: حالا که این فایل واقعاً اجرا شد، صفحه دیگر «در حال
    // آماده شدن» نیست. پیش از این، اگر app.js نمی‌رسید صفحه کامل و
    // خوش‌ظاهر بالا می‌آمد و کاربر دکمه می‌زد و هیچ اتفاقی نمی‌افتاد،
    // بی‌هیچ نشانه‌ای از خرابی.
    document.documentElement.classList.remove('js-loading');

    // پایه‌ی آدرس API — تا فراخوانی‌ها از داخل پوشه admin/ هم درست کار کند
    function apiUrl(name) {
        var base = (typeof window.APP_BASE === 'string') ? window.APP_BASE : '';
        return base + '/api/' + name;
    }

    // ---------- منوی موبایل ----------
    var menuToggle = document.getElementById('menuToggle');
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');

    if (menuToggle && sidebar && overlay) {
        menuToggle.addEventListener('click', function () {
            sidebar.classList.add('open');
            overlay.classList.add('show');
        });
        overlay.addEventListener('click', function () {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        });
    }

    // ---------- فرمت‌کننده مبلغ (جداکننده سه‌رقمی هنگام تایپ) ----------
    function toLatinDigitsJs(str) {
        var persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        for (var i = 0; i < 10; i++) {
            str = str.replace(new RegExp(persian[i], 'g'), i);
        }
        return str;
    }

    function toPersianDigitsJs(str) {
        var persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return String(str).replace(/[0-9]/g, function (d) { return persian[d]; });
    }

    // بازگرداندن یک <select> به گزینه‌ی پیش‌فرضِ خودِ HTML.
    // مقداردهی دستی به '0' اینجا کار نمی‌کند: فهرست حسابِ فروش گزینه‌ی
    // «۰» ندارد و مرورگر انتخاب را خالی می‌گذارد، آن‌وقت فرم بدون حساب
    // ارسال می‌شد.
    function resetSelect(el) {
        if (!el) return;
        for (var i = 0; i < el.options.length; i++) {
            if (el.options[i].defaultSelected) { el.selectedIndex = i; return; }
        }
        el.selectedIndex = 0;
    }

    function setupAmountFormatter(inputId) {
        var input = document.getElementById(inputId);
        if (!input) return;
        input.addEventListener('input', function () {
            var raw = this.value.replace(/[^\d۰-۹]/g, '');
            raw = toLatinDigitsJs(raw);
            if (raw === '') {
                this.value = '';
                return;
            }
            this.value = toPersianDigitsJs(Number(raw).toLocaleString('en-US').replace(/,/g, '\u066C'));
        });
    }

    setupAmountFormatter('amount');
    setupAmountFormatter('edit_amount');

    // ---------- تاگل نوع تراکنش (درآمد/هزینه) — قابل استفاده مجدد برای چند فرم مستقل ----------
    function populateCategorySelect(selectEl, type) {
        if (!selectEl || !window.CATEGORY_DATA) return;
        while (selectEl.options.length > 1) {
            selectEl.remove(1);
        }
        var list = window.CATEGORY_DATA[type] || [];
        list.forEach(function (cat) {
            var opt = document.createElement('option');
            opt.value = cat.id;
            opt.textContent = cat.name;
            selectEl.appendChild(opt);
        });
    }

    function setupTypeToggle(toggleId, hiddenInputId, categorySelectId) {
        var toggle = document.getElementById(toggleId);
        if (!toggle) return null;

        var buttons = toggle.querySelectorAll('.type-btn');
        var hiddenInput = document.getElementById(hiddenInputId);
        var categorySelect = categorySelectId ? document.getElementById(categorySelectId) : null;

        function activate(btn, resetCategory) {
            buttons.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            var type = btn.getAttribute('data-type');
            hiddenInput.value = type;

            if (categorySelect && (type === 'income' || type === 'expense')) {
                populateCategorySelect(categorySelect, type);
                if (resetCategory) categorySelect.value = '';
            }
        }

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () { activate(this, true); });
        });

        var initialBtn = toggle.querySelector('.type-btn.active') || buttons[0];
        if (initialBtn) activate(initialBtn, false);

        return {
            setType: function (type) {
                var btn = toggle.querySelector('.type-btn[data-type="' + type + '"]');
                if (btn) activate(btn, false);
            }
        };
    }

    var quickAddToggle = setupTypeToggle('typeToggle', 'transactionType', 'category_id');
    var editToggle = setupTypeToggle('editTypeToggle', 'edit_transaction_type', 'edit_category_id');

    // ---------- ارسال فرم ثبت سریع تراکنش (AJAX) ----------
    var quickAddForm = document.getElementById('quickAddForm');
    if (quickAddForm) {
        quickAddForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var submitBtn = document.getElementById('submitBtn');
            var submitBtnText = document.getElementById('submitBtnText');
            var formMessage = document.getElementById('formMessage');
            var amountInput = document.getElementById('amount');

            var formData = new FormData(quickAddForm);
            var rawAmount = amountInput.value.replace(/[,\u066C]/g, '');
            formData.set('amount', rawAmount);

            submitBtn.disabled = true;
            submitBtnText.textContent = 'در حال ثبت...';

            fetch(apiUrl('add_transaction.php'), {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                formMessage.hidden = false;
                formMessage.classList.remove('success', 'error');

                if (data.success) {
                    formMessage.classList.add('show', 'success');
                    formMessage.textContent = data.message || 'تراکنش با موفقیت ثبت شد.';
                    window.location.reload();
                } else {
                    formMessage.classList.add('show', 'error');
                    formMessage.textContent = data.message || 'خطایی رخ داد. دوباره تلاش کنید.';
                }
            })
            .catch(function () {
                formMessage.hidden = false;
                formMessage.classList.add('show', 'error');
                formMessage.textContent = 'خطا در ارتباط با سرور. اتصال اینترنت را بررسی کنید.';
            })
            .finally(function () {
                submitBtn.disabled = false;
                submitBtnText.textContent = 'ثبت تراکنش';
            });
        });
    }

    // ---------- حذف تراکنش (AJAX) — در صفحه اصلی و صفحه تراکنش‌ها ----------
    document.querySelectorAll('.js-delete-tx').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var txId = this.getAttribute('data-id');
            var csrfToken = document.querySelector('meta[name="csrf-token"]')
                ? document.querySelector('meta[name="csrf-token"]').content
                : '';

            if (!confirm('آیا از حذف این تراکنش مطمئن هستید؟ این عملیات قابل بازگشت نیست.')) {
                return;
            }

            var row = this.closest('tr');
            var formData = new FormData();
            formData.append('transaction_id', txId);
            formData.append('csrf_token', csrfToken);

            fetch(apiUrl('delete_transaction.php'), {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                // برداشتن ردیف کافی نیست: جمع‌ها و موجودی حساب‌ها بالای
                // صفحه از روی همین رکورد ساخته شده‌اند و بی‌تازه‌سازی،
                // عدد قدیمی می‌ماند و کاربر فکر می‌کند حذف نشده.
                if (data.success) {
                    if (row) row.remove();
                    window.location.reload();
                } else {
                    alert(data.message || 'خطا در حذف تراکنش.');
                }
            })
            .catch(function () {
                alert('خطا در ارتباط با سرور.');
            });
        });
    });

    // ---------- ویرایش تراکنش: پیش‌پر کردن مودال ----------
    var monthNamesFa = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    document.querySelectorAll('.js-edit-tx').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!editToggle || !window.JalaliDatePicker) return;

            var id = this.getAttribute('data-id');
            var type = this.getAttribute('data-type');
            var amount = this.getAttribute('data-amount');
            var title = this.getAttribute('data-title');
            var note = this.getAttribute('data-note');
            var date = this.getAttribute('data-date');
            var categoryId = this.getAttribute('data-category-id');

            document.getElementById('edit_transaction_id').value = id;
            document.getElementById('edit_amount').value = amount ? toPersianDigitsJs(Number(amount).toLocaleString('en-US').replace(/,/g, '\u066C')) : '';
            document.getElementById('edit_title').value = title || '';
            document.getElementById('edit_note').value = note || '';

            editToggle.setType(type);
            document.getElementById('edit_category_id').value = (categoryId && categoryId !== '0') ? categoryId : '';

            var parts = date.split('-').map(Number);
            var j = window.JalaliDatePicker.gregorianToJalali(parts[0], parts[1], parts[2]);
            document.getElementById('edit_date_display').value =
                window.JalaliDatePicker.toFa(j[2]) + ' ' + monthNamesFa[j[1] - 1] + ' ' + window.JalaliDatePicker.toFa(j[0]);
            document.getElementById('edit_transaction_date').value = date;

            var msgEl = document.getElementById('editTxMessage');
            if (msgEl) { msgEl.hidden = true; msgEl.classList.remove('show', 'success', 'error'); }

            openModal('editTxModal');
        });
    });

    var editTxForm = document.getElementById('editTxForm');
    if (editTxForm) {
        editTxForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var submitBtn = document.getElementById('editTxSubmitBtn');
            var msgEl = document.getElementById('editTxMessage');
            var amountInput = document.getElementById('edit_amount');

            var formData = new FormData(editTxForm);
            var rawAmount = amountInput.value.replace(/[,\u066C]/g, '');
            formData.set('amount', rawAmount);

            submitBtn.disabled = true;

            fetch(apiUrl('update_transaction.php'), {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                msgEl.hidden = false;
                msgEl.classList.remove('success', 'error');
                if (data.success) {
                    msgEl.classList.add('show', 'success');
                    msgEl.textContent = data.message || 'با موفقیت ذخیره شد.';
                    window.location.reload();
                } else {
                    msgEl.classList.add('show', 'error');
                    msgEl.textContent = data.message || 'خطایی رخ داد.';
                }
            })
            .catch(function () {
                msgEl.hidden = false;
                msgEl.classList.add('show', 'error');
                msgEl.textContent = 'خطا در ارتباط با سرور.';
            })
            .finally(function () {
                submitBtn.disabled = false;
            });
        });
    }

    // ---------- افزودن طلب/بدهی ----------
    setupAmountFormatter('add_debt_amount');
    setupAmountFormatter('edit_debt_amount');

    var addDebtToggle = setupTypeToggle('addDebtToggle', 'add_debt_direction', null);

    function setJdpValue(displayId, hiddenId, gDateStr) {
        if (!window.JalaliDatePicker || !gDateStr) return;
        var parts = gDateStr.split('-').map(Number);
        var j = window.JalaliDatePicker.gregorianToJalali(parts[0], parts[1], parts[2]);
        function pad2(n) { return (n < 10 ? '0' : '') + n; }
        document.getElementById(displayId).value =
            window.JalaliDatePicker.toFa(j[0]) + '/' + window.JalaliDatePicker.toFa(pad2(j[1])) + '/' + window.JalaliDatePicker.toFa(pad2(j[2]));
        document.getElementById(hiddenId).value = gDateStr;
    }

    document.querySelectorAll('.js-add-debt').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var direction = this.getAttribute('data-direction');
            if (addDebtToggle) addDebtToggle.setType(direction);

            document.getElementById('add_counterparty').value = '';
            document.getElementById('add_debt_amount').value = '';
            document.getElementById('add_debt_note').value = '';

            var dueHidden = document.getElementById('add_due_date');
            dueHidden.value = '';
            dueHidden.closest('.jdp-field').querySelector('.jdp-display').value = '';

            var msgEl = document.getElementById('addDebtMessage');
            if (msgEl) { msgEl.hidden = true; msgEl.classList.remove('show', 'success', 'error'); }

            openModal('addDebtModal');
        });
    });

    var addDebtForm = document.getElementById('addDebtForm');
    if (addDebtForm) {
        addDebtForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var submitBtn = document.getElementById('addDebtSubmitBtn');
            var msgEl = document.getElementById('addDebtMessage');
            var amountInput = document.getElementById('add_debt_amount');

            if (!document.getElementById('add_due_date').value) {
                msgEl.hidden = false;
                msgEl.classList.remove('success');
                msgEl.classList.add('show', 'error');
                msgEl.textContent = 'تاریخ سررسید را انتخاب کنید.';
                return;
            }

            var formData = new FormData(addDebtForm);
            formData.set('amount', amountInput.value.replace(/[,\u066C]/g, ''));

            submitBtn.disabled = true;

            fetch(apiUrl('add_debt.php'), {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                msgEl.hidden = false;
                msgEl.classList.remove('success', 'error');
                if (data.success) {
                    msgEl.classList.add('show', 'success');
                    msgEl.textContent = data.message || 'با موفقیت ثبت شد.';
                    window.location.reload();
                } else {
                    msgEl.classList.add('show', 'error');
                    msgEl.textContent = data.message || 'خطایی رخ داد.';
                }
            })
            .catch(function () {
                msgEl.hidden = false;
                msgEl.classList.add('show', 'error');
                msgEl.textContent = 'خطا در ارتباط با سرور.';
            })
            .finally(function () {
                submitBtn.disabled = false;
            });
        });
    }

    // ---------- ویرایش طلب/بدهی ----------
    document.querySelectorAll('.js-edit-debt').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('edit_debt_id').value = this.getAttribute('data-id');
            syncPersonPicker('edit_counterparty', this.getAttribute('data-counterparty'));
            document.getElementById('edit_debt_amount').value = toPersianDigitsJs(Number(this.getAttribute('data-amount')).toLocaleString('en-US').replace(/,/g, '\u066C'));
            document.getElementById('edit_debt_note').value = this.getAttribute('data-note') || '';

            setJdpValue('edit_entry_date_display', 'edit_entry_date', this.getAttribute('data-entry-date'));
            setJdpValue('edit_due_date_display', 'edit_due_date', this.getAttribute('data-due-date'));

            var msgEl = document.getElementById('editDebtMessage');
            if (msgEl) { msgEl.hidden = true; msgEl.classList.remove('show', 'success', 'error'); }

            openModal('editDebtModal');
        });
    });

    var editDebtForm = document.getElementById('editDebtForm');
    if (editDebtForm) {
        editDebtForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var submitBtn = document.getElementById('editDebtSubmitBtn');
            var msgEl = document.getElementById('editDebtMessage');
            var amountInput = document.getElementById('edit_debt_amount');

            var formData = new FormData(editDebtForm);
            formData.set('amount', amountInput.value.replace(/[,\u066C]/g, ''));

            submitBtn.disabled = true;

            fetch(apiUrl('update_debt.php'), {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                msgEl.hidden = false;
                msgEl.classList.remove('success', 'error');
                if (data.success) {
                    msgEl.classList.add('show', 'success');
                    msgEl.textContent = data.message || 'با موفقیت ذخیره شد.';
                    window.location.reload();
                } else {
                    msgEl.classList.add('show', 'error');
                    msgEl.textContent = data.message || 'خطایی رخ داد.';
                }
            })
            .catch(function () {
                msgEl.hidden = false;
                msgEl.classList.add('show', 'error');
                msgEl.textContent = 'خطا در ارتباط با سرور.';
            })
            .finally(function () {
                submitBtn.disabled = false;
            });
        });
    }

    /**
     * پرسیدن «به کدام حساب؟» پیش از تسویه‌ی یک چک یا طلب/بدهی.
     *
     * تیک بلافاصله برداشته می‌شود و فقط بعد از پاسخ موفق سرور (که صفحه
     * را تازه می‌کند) دوباره تیک می‌خورد. این‌طور اگر کاربر مودال را
     * ببندد، ظاهرِ صفحه چیزی را نشان نمی‌دهد که در دیتابیس ثبت نشده.
     */
    var settleAsk = {};

    function askSettleWallet(modalId, checkboxEl, opts) {
        var modal = document.getElementById(modalId);
        var form  = document.getElementById(modalId + 'Form');
        var msgEl = document.getElementById(modalId + 'Message');
        var btnEl = document.getElementById(modalId + 'SubmitBtn');
        var selEl = document.getElementById(modalId + 'Wallet');
        var hintEl = document.getElementById(modalId + 'Hint');
        if (!modal || !form) { return; }

        checkboxEl.checked = false;   // تا وقتی سرور تأیید نکرده، تیک نمی‌ماند

        settleAsk[modalId] = { id: checkboxEl.getAttribute('data-id'), opts: opts };

        if (hintEl) { hintEl.textContent = opts.hint || ''; }
        if (msgEl) { msgEl.hidden = true; msgEl.classList.remove('show', 'success', 'error'); }
        if (btnEl) { btnEl.disabled = false; }
        resetSelect(selEl);

        if (!form.getAttribute('data-wired')) {
            form.setAttribute('data-wired', '1');
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var pending = settleAsk[modalId];
                if (!pending) { return; }

                var fd = new FormData();
                fd.append(pending.opts.idField, pending.id);
                fd.append('wallet_id', selEl ? selEl.value : '');
                fd.append('csrf_token', csrf());
                if (btnEl) { btnEl.disabled = true; }

                var fail = function (text) {
                    if (msgEl) {
                        msgEl.hidden = false;
                        msgEl.classList.remove('success');
                        msgEl.classList.add('show', 'error');
                        msgEl.textContent = text;
                    }
                    if (btnEl) { btnEl.disabled = false; }
                };

                fetch(pending.opts.url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.success) { window.location.reload(); return; }
                        fail(d.message || 'خطایی رخ داد.');
                    })
                    .catch(function () { fail('خطا در ارتباط با سرور.'); });
            });
        }

        modal.classList.add('show');
    }

    // ---------- تیک تسویه طلب/بدهی ----------
    // زدن تیک یعنی پول واقعاً جابه‌جا شده، پس اول می‌پرسیم از/به کدام
    // حساب. برداشتن تیک سؤالی ندارد — همان اثر پس گرفته می‌شود.
    var debtSettleModal = document.getElementById('debtSettle');
    document.querySelectorAll('.js-toggle-debt').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            var checkboxEl = this;
            if (debtSettleModal && checkboxEl.checked) {
                askSettleWallet('debtSettle', checkboxEl, {
                    hint: 'باقیمانده‌ی این مورد به‌عنوان تسویه ثبت می‌شود.',
                    url: apiUrl('toggle_debt_settled.php'),
                    idField: 'debt_id'
                });
                return;
            }

            var debtId = this.getAttribute('data-id');
            var csrfToken = document.querySelector('meta[name="csrf-token"]')
                ? document.querySelector('meta[name="csrf-token"]').content
                : '';

            var formData = new FormData();
            formData.append('debt_id', debtId);
            formData.append('csrf_token', csrfToken);

            checkboxEl.disabled = true;

            fetch(apiUrl('toggle_debt_settled.php'), {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'خطایی رخ داد.');
                    checkboxEl.checked = !checkboxEl.checked;
                    checkboxEl.disabled = false;
                }
            })
            .catch(function () {
                alert('خطا در ارتباط با سرور.');
                checkboxEl.checked = !checkboxEl.checked;
                checkboxEl.disabled = false;
            });
        });
    });

    // ---------- حذف طلب/بدهی ----------
    document.querySelectorAll('.js-delete-debt').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var debtId = this.getAttribute('data-id');
            var csrfToken = document.querySelector('meta[name="csrf-token"]')
                ? document.querySelector('meta[name="csrf-token"]').content
                : '';

            if (!confirm('آیا از حذف این مورد مطمئن هستید؟')) return;

            var card = this.closest('.debt-card');
            var formData = new FormData();
            formData.append('debt_id', debtId);
            formData.append('csrf_token', csrfToken);

            fetch(apiUrl('delete_debt.php'), {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    if (card) card.remove();
                    window.location.reload();   // مجموع طلب/بدهی بالای صفحه هم باید عوض شود
                } else {
                    alert(data.message || 'خطا در حذف.');
                }
            })
            .catch(function () {
                alert('خطا در ارتباط با سرور.');
            });
        });
    });

    // ---------- گزارش دسته‌بندی: نمایش تراکنش‌های هر دسته با کلیک ----------
    document.querySelectorAll('.js-cat-toggle').forEach(function (el) {
        el.addEventListener('click', function () {
            var catId = this.getAttribute('data-cat-id');
            var detailEl = document.getElementById('catDetail' + catId);
            if (!detailEl) return;

            if (!detailEl.hidden) {
                detailEl.hidden = true;
                return;
            }

            if (detailEl.dataset.loaded === '1') {
                detailEl.hidden = false;
                return;
            }

            var typeEl = document.getElementById('reportType');
            var fromEl = document.getElementById('reportFromDate');
            var toEl = document.getElementById('reportToDate');
            if (!typeEl || !fromEl || !toEl) return;

            detailEl.innerHTML = '<p style="text-align:center; color:var(--color-gray-500); padding:8px 0;">در حال بارگذاری...</p>';
            detailEl.hidden = false;

            var url = apiUrl('category_transactions.php') + '?category_id=' + encodeURIComponent(catId)
                + '&type=' + encodeURIComponent(typeEl.value)
                + '&from_date=' + encodeURIComponent(fromEl.value)
                + '&to_date=' + encodeURIComponent(toEl.value);

            fetch(url)
                .then(function (res) { return res.text(); })
                .then(function (html) {
                    detailEl.innerHTML = html;
                    detailEl.dataset.loaded = '1';
                })
                .catch(function () {
                    detailEl.innerHTML = '<p style="text-align:center; color:var(--color-expense); padding:8px 0;">خطا در بارگذاری.</p>';
                });
        });
    });

    // ---------- شیت ثبت تراکنش (دکمه + در ناوبری پایین) ----------
    var addTxBtn = document.getElementById('addTxBtn');
    var addTxSheet = document.getElementById('addTxSheet');
    if (addTxBtn && addTxSheet) {
        addTxBtn.addEventListener('click', function () {
            addTxSheet.classList.add('show');
            var amt = document.getElementById('amount');
            if (amt) setTimeout(function () { amt.focus(); }, 120);
        });
    }

    // بستن شیت‌ها: کلیک روی پس‌زمینه، دکمه ضربدر، یا کلید Escape
    document.querySelectorAll('.sheet-overlay, .more-sheet-overlay').forEach(function (ov) {
        ov.addEventListener('click', function (e) {
            if (e.target === ov) ov.classList.remove('show');
        });
    });
    document.querySelectorAll('[data-sheet-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var ov = btn.closest('.sheet-overlay, .more-sheet-overlay');
            if (ov) ov.classList.remove('show');
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.sheet-overlay.show, .more-sheet-overlay.show').forEach(function (ov) {
            ov.classList.remove('show');
        });
    });

    // ---------- حساب‌ها و انتقال ----------
    setupAmountFormatter('wallet_init');
    setupAmountFormatter('transfer_amount');
    setupAmountFormatter('transfer_fee');

    var walletModal = document.getElementById('walletModal');

    function walletKindFields() {
        var kind = document.getElementById('wallet_kind');
        var box  = document.getElementById('walletBankFields');
        if (!kind || !box) return;
        box.hidden = (kind.value === 'cash');

        // «سایر» باید اسم داشته باشد، وگرنه بعداً معلوم نیست چه حسابی بوده
        var labelWrap = document.getElementById('walletKindLabelWrap');
        if (labelWrap) {
            labelWrap.hidden = (kind.value !== 'other');
            if (kind.value === 'other') { walletKindLabelField(); }
        }
    }

    // انتخاب «+ نوع تازه…» جعبه‌ی متن را باز می‌کند
    function walletKindLabelField() {
        var sel = document.getElementById('wallet_kind_label');
        var txt = document.getElementById('wallet_kind_new');
        if (!sel || !txt) return;
        var isNew = (sel.value === '__new__' || sel.options.length === 1);
        txt.hidden = !isNew;
        if (!isNew) { txt.value = ''; }
    }
    var kindLabelSel = document.getElementById('wallet_kind_label');
    if (kindLabelSel) kindLabelSel.addEventListener('change', walletKindLabelField);
    var kindSel = document.getElementById('wallet_kind');
    if (kindSel) kindSel.addEventListener('change', walletKindFields);

    // فرم «حساب جدید» باید کاملاً خام باز شود. نسخه‌ی قبلی فقط چند فیلد
    // را پاک می‌کرد و شماره‌ی کارت و حساب و شبای حسابِ قبلی سر جایشان
    // می‌ماندند — کاربر حساب تازه می‌ساخت و اطلاعات حساب دیگری تویش بود.
    function resetWalletForm() {
        ['wallet_id', 'wallet_name', 'wallet_bank', 'wallet_last4', 'wallet_init',
         'wallet_card_number', 'wallet_account_number', 'wallet_iban',
         'wallet_kind_new'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.value = '';
        });
        var kind = document.getElementById('wallet_kind');
        if (kind) kind.value = 'cash';
        var bcode = document.getElementById('wallet_bank_code');
        if (bcode) bcode.value = '';
        var kl = document.getElementById('wallet_kind_label');
        if (kl) resetSelect(kl);
        var color = document.getElementById('wallet_color');
        if (color) color.value = '#16794f';
        var m = document.getElementById('walletMessage');
        if (m) { m.hidden = true; m.classList.remove('show', 'success', 'error'); }
    }

    document.querySelectorAll('.js-add-wallet').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('walletModalTitle').textContent = 'حساب جدید';
            resetWalletForm();
            document.getElementById('walletExtraActions').hidden = true;
            walletKindFields();
            openModal('walletModal');
        });
    });

    // ---------- شماره کارت / شبا: گروه‌بندی چهارتایی هنگام تایپ ----------
    function groupDigits(v) {
        var d = String(v || '').replace(/[^\d۰-۹٠-٩]/g, '');
        d = toLatinDigitsJs(d);
        return d.replace(/(.{4})/g, '$1 ').trim();
    }
    ['wallet_card_number', 'wallet_iban'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', function () {
            var atEnd = this.selectionStart === this.value.length;
            this.value = groupDigits(this.value);
            if (atEnd) this.setSelectionRange(this.value.length, this.value.length);
            // ۴ رقم آخر کارت را خودش پر کند
            if (id === 'wallet_card_number') {
                var last4 = document.getElementById('wallet_last4');
                var digits = toLatinDigitsJs(this.value).replace(/\D/g, '');
                if (last4 && digits.length >= 4) { last4.value = digits.slice(-4); }
            }
        });
    });

    // انتخاب بانک، رنگ حساب را تنظیم می‌کند (کاربر بعدش می‌تواند عوض کند)
    var bankSel = document.getElementById('wallet_bank_code');
    if (bankSel) {
        bankSel.addEventListener('change', function () {
            var opt = this.options[this.selectedIndex];
            var c1 = opt && opt.getAttribute('data-c1');
            if (c1) { document.getElementById('wallet_color').value = c1; }
        });
    }

    // ---------- نمای کارت: زدن روی ردیف حساب ----------
    var cardModal = document.getElementById('bankCardModal');
    if (cardModal) {
        document.querySelectorAll('.wallet-row.js-show-card').forEach(function (row) {
            row.addEventListener('click', function (e) {
                // دکمه‌ی ⋮ کار خودش را دارد
                if (e.target.closest('[data-stop], button, a')) return;

                var v = document.getElementById('bankCardVisual');
                v.style.setProperty('--bc1', this.getAttribute('data-c1') || '#475569');
                v.style.setProperty('--bc2', this.getAttribute('data-c2') || '#2f3b4a');

                var bank = this.getAttribute('data-bank') || '';
                document.getElementById('bankCardTitle').textContent = this.getAttribute('data-name') || 'کارت';
                document.getElementById('bcBank').textContent = bank || (this.getAttribute('data-name') || '');
                document.getElementById('bcKind').textContent = this.getAttribute('data-kind') || '';
                document.getElementById('bcNumber').textContent = this.getAttribute('data-card') || '';
                document.getElementById('bcOwner').textContent = this.getAttribute('data-name') || '';
                document.getElementById('bcBalance').textContent = this.getAttribute('data-balance') || '';

                // ردیف‌های زیر کارت فقط برای چیزهایی که واقعاً پر شده‌اند
                var rows = document.getElementById('bcRows');
                rows.innerHTML = '';
                [['شماره کارت', this.getAttribute('data-card')],
                 ['شماره حساب', this.getAttribute('data-account')],
                 ['شبا', this.getAttribute('data-iban')]].forEach(function (pair) {
                    if (!pair[1]) return;
                    var r = document.createElement('div');
                    r.className = 'bank-card-row';
                    var l = document.createElement('span');
                    l.className = 'bank-card-row-label'; l.textContent = pair[0];
                    var val = document.createElement('span');
                    val.className = 'bank-card-row-value'; val.textContent = pair[1];
                    var cp = document.createElement('button');
                    cp.type = 'button'; cp.className = 'bank-card-copy'; cp.textContent = 'کپی';
                    cp.addEventListener('click', function () {
                        var plain = toLatinDigitsJs(pair[1]).replace(/\s/g, '');
                        var done = function () {
                            cp.textContent = 'کپی شد'; cp.classList.add('done');
                            setTimeout(function () { cp.textContent = 'کپی'; cp.classList.remove('done'); }, 1600);
                        };
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(plain).then(done, function () {});
                        } else {
                            // مرورگرهای قدیمی‌تر یا زمینه‌ی غیرامن
                            var ta = document.createElement('textarea');
                            ta.value = plain; document.body.appendChild(ta); ta.select();
                            try { document.execCommand('copy'); done(); } catch (err) {}
                            document.body.removeChild(ta);
                        }
                    });
                    r.appendChild(l); r.appendChild(val); r.appendChild(cp);
                    rows.appendChild(r);
                });
                if (!rows.children.length) {
                    var empty = document.createElement('p');
                    empty.className = 'hint';
                    empty.style.textAlign = 'center';
                    empty.textContent = 'شماره کارت، حساب یا شبا ثبت نشده — از دکمه‌ی ⋮ اضافه کنید.';
                    rows.appendChild(empty);
                }

                // دو کار روی همین حساب: تعدیل موجودی و دیدن تراکنش‌هایش
                var wid = this.getAttribute('data-id') || '';
                var wname = this.getAttribute('data-name') || '';
                var wbal = this.getAttribute('data-balance') || '';
                var adjBtn = document.getElementById('bcAdjustBtn');
                if (adjBtn) {
                    adjBtn.setAttribute('data-id', wid);
                    adjBtn.setAttribute('data-name', wname);
                    adjBtn.setAttribute('data-balance', wbal);
                }
                var txLink = document.getElementById('bcTxLink');
                if (txLink) {
                    txLink.href = (window.APP_BASE || '') + '/transactions.php?wallet=' + encodeURIComponent(wid);
                }

                // ویرایش کامل همین حساب — رنگ، شماره کارت، شبا و بقیه.
                // دکمه‌ی ⋮ روی ردیف همین کار را می‌کند ولی پیدا کردنش
                // آسان نبود؛ اینجا کنار خود کارت است.
                var editBtn = document.getElementById('bcEditBtn');
                var rowEdit = this.querySelector('.js-edit-wallet');
                if (editBtn) {
                    editBtn.onclick = function () {
                        closeModal('bankCardModal');
                        if (rowEdit) { rowEdit.click(); }
                    };
                }

                openModal('bankCardModal');
            });
        });
    }

    // ---------- تعدیل موجودی حساب ----------
    var adjustForm = document.getElementById('adjustWalletForm');
    if (adjustForm) {
        var adjustHints = {
            add: 'این مبلغ به موجودی حساب اضافه می‌شود.',
            sub: 'این مبلغ از موجودی حساب کم می‌شود.',
            set: 'موجودی واقعی حساب را بنویسید؛ تفاوتش خودکار اعمال می‌شود.'
        };

        document.querySelectorAll('#adjustModeToggle .type-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                document.querySelectorAll('#adjustModeToggle .type-btn').forEach(function (x) {
                    x.classList.remove('active');
                });
                this.classList.add('active');
                var mode = this.getAttribute('data-mode');
                document.getElementById('adjust_mode').value = mode;
                document.getElementById('adjustModeHint').textContent = adjustHints[mode] || '';
                var neg = document.getElementById('adjustNegWrap');
                neg.hidden = mode !== 'set';
                if (mode !== 'set') { document.getElementById('adjust_negative').checked = false; }
            });
        });

        var adjustBtn = document.getElementById('bcAdjustBtn');
        if (adjustBtn) {
            adjustBtn.addEventListener('click', function () {
                closeModal('bankCardModal');
                document.getElementById('adjust_wallet_id').value = this.getAttribute('data-id') || '';
                document.getElementById('adjustWalletTitle').textContent =
                    'تعدیل موجودی: ' + (this.getAttribute('data-name') || '');
                document.getElementById('adjustCurrentHint').textContent =
                    'موجودی فعلی: ' + (this.getAttribute('data-balance') || '') + ' تومان';
                document.getElementById('adjust_amount').value = '';
                document.getElementById('adjust_negative').checked = false;
                document.querySelector('#adjustModeToggle .type-btn[data-mode="add"]').click();
                var m = document.getElementById('adjustWalletMessage');
                if (m) { m.hidden = true; m.classList.remove('show', 'success', 'error'); }
                openModal('adjustWalletModal');
            });
        }

        setupAmountFormatter('adjust_amount');

        adjustForm.addEventListener('submit', function (e) {
            e.preventDefault();
            submitJson(adjustForm, apiUrl('adjust_wallet.php'),
                document.getElementById('adjustWalletMessage'),
                document.getElementById('adjustWalletSubmitBtn'), 'adjust_amount');
        });
    }

    document.querySelectorAll('.js-edit-wallet').forEach(function (btn) {
        btn.addEventListener('click', function () {
            resetWalletForm();
            document.getElementById('walletModalTitle').textContent = 'ویرایش حساب';
            document.getElementById('wallet_id').value = this.getAttribute('data-id');
            document.getElementById('wallet_name').value = this.getAttribute('data-name');
            document.getElementById('wallet_kind').value = this.getAttribute('data-kind');
            document.getElementById('wallet_bank').value = this.getAttribute('data-bank') || '';
            document.getElementById('wallet_last4').value = this.getAttribute('data-last4') || '';
            document.getElementById('wallet_color').value = this.getAttribute('data-color') || '#64748b';
            var bcSel = document.getElementById('wallet_bank_code');
            if (bcSel) bcSel.value = this.getAttribute('data-bank-code') || '';
            var cardEl = document.getElementById('wallet_card_number');
            if (cardEl) cardEl.value = groupDigits(this.getAttribute('data-card') || '');
            var accEl = document.getElementById('wallet_account_number');
            if (accEl) accEl.value = this.getAttribute('data-account') || '';
            var ibanEl = document.getElementById('wallet_iban');
            if (ibanEl) ibanEl.value = groupDigits(this.getAttribute('data-iban') || '');

            var init = parseInt(this.getAttribute('data-init') || '0', 10);
            document.getElementById('wallet_init').value = init ? toPersianDigitsJs(Math.abs(init).toLocaleString('en-US').replace(/,/g,'\u066C')) : '';

            // نوع دلخواه: اگر در فهرست بود انتخابش کن، وگرنه به‌عنوان
            // نوع تازه در جعبه‌ی متن بنشیند تا از دست نرود
            var kl = this.getAttribute('data-kind-label') || '';
            var klSel = document.getElementById('wallet_kind_label');
            var klNew = document.getElementById('wallet_kind_new');
            if (klSel && klNew) {
                var found = false;
                for (var i = 0; i < klSel.options.length; i++) {
                    if (klSel.options[i].value === kl) { klSel.selectedIndex = i; found = true; break; }
                }
                if (!found) { klSel.value = '__new__'; klNew.value = kl; }
                else { klNew.value = ''; }
                walletKindLabelField();
            }

            var extra = document.getElementById('walletExtraActions');
            extra.hidden = false;
            var isActive = this.getAttribute('data-active') === '1';
            var tgl = document.getElementById('walletToggleBtn');
            tgl.textContent = isActive ? 'غیرفعال کردن' : 'فعال کردن';
            tgl.setAttribute('data-id', this.getAttribute('data-id'));
            document.getElementById('walletDeleteBtn').setAttribute('data-id', this.getAttribute('data-id'));

            var m = document.getElementById('walletMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            walletKindFields();
            openModal('walletModal');
        });
    });

    var walletForm = document.getElementById('walletForm');
    if (walletForm) {
        walletForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(walletForm);
            fd.set('initial_balance', toLatinDigitsJs(document.getElementById('wallet_init').value).replace(/[,\u066C]/g, ''));
            submitJson(walletForm, apiUrl('save_wallet.php'),
                document.getElementById('walletMessage'),
                document.getElementById('walletSubmitBtn'), null, fd);
        });
    }

    var walletToggleBtn = document.getElementById('walletToggleBtn');
    if (walletToggleBtn) {
        walletToggleBtn.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('wallet_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('toggle_wallet.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){ if (d.success) { window.location.reload(); } else { alert(d.message || 'خطا'); } })
                .catch(function(){ alert('خطا در ارتباط با سرور.'); });
        });
    }

    var walletDeleteBtn = document.getElementById('walletDeleteBtn');
    if (walletDeleteBtn) {
        walletDeleteBtn.addEventListener('click', function () {
            if (!confirm('این حساب حذف شود؟')) return;
            var fd = new FormData();
            fd.append('wallet_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('delete_wallet.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){
                    if (d.success) { window.location.reload(); }
                    else {
                        var m = document.getElementById('walletMessage');
                        m.hidden = false; m.classList.remove('success');
                        m.classList.add('show','error');
                        m.textContent = d.message || 'قابل حذف نیست.';
                    }
                })
                .catch(function(){ alert('خطا در ارتباط با سرور.'); });
        });
    }

    // ---------- انتقال ----------
    document.querySelectorAll('.js-add-transfer').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('transferModalTitle').textContent = 'انتقال بین حساب‌ها';
            document.getElementById('transfer_id').value = '';
            document.getElementById('transfer_amount').value = '';
            document.getElementById('transfer_fee').value = '';
            document.getElementById('transfer_note').value = '';
            var m = document.getElementById('transferMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('transferModal');
        });
    });

    document.querySelectorAll('.js-edit-transfer').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('transferModalTitle').textContent = 'ویرایش انتقال';
            document.getElementById('transfer_id').value = this.getAttribute('data-id');
            document.getElementById('transfer_from').value = this.getAttribute('data-from');
            document.getElementById('transfer_to').value = this.getAttribute('data-to');
            document.getElementById('transfer_amount').value =
                toPersianDigitsJs(Number(this.getAttribute('data-amount')).toLocaleString('en-US').replace(/,/g,'\u066C'));
            var fee = parseInt(this.getAttribute('data-fee') || '0', 10);
            document.getElementById('transfer_fee').value = fee ? toPersianDigitsJs(fee.toLocaleString('en-US').replace(/,/g,'\u066C')) : '';
            document.getElementById('transfer_note').value = this.getAttribute('data-note') || '';
            setJdpValue('transfer_date_display', 'transfer_date', this.getAttribute('data-date'));
            var m = document.getElementById('transferMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('transferModal');
        });
    });

    var transferForm = document.getElementById('transferForm');
    if (transferForm) {
        transferForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var from = document.getElementById('transfer_from').value;
            var to   = document.getElementById('transfer_to').value;
            var msg  = document.getElementById('transferMessage');
            if (from === to) {
                msg.hidden = false; msg.classList.remove('success');
                msg.classList.add('show','error');
                msg.textContent = 'حساب مبدأ و مقصد نمی‌توانند یکی باشند.';
                return;
            }
            var fd = new FormData(transferForm);
            fd.set('amount', toLatinDigitsJs(document.getElementById('transfer_amount').value).replace(/[,\u066C]/g, ''));
            fd.set('fee',    toLatinDigitsJs(document.getElementById('transfer_fee').value).replace(/[,\u066C]/g, ''));
            submitJson(transferForm, apiUrl('save_transfer.php'), msg,
                document.getElementById('transferSubmitBtn'), null, fd);
        });
    }

    document.querySelectorAll('.js-delete-transfer').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('این انتقال حذف شود؟ موجودی هر دو حساب اصلاح می‌شود.')) return;
            var fd = new FormData();
            fd.append('transfer_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('delete_transfer.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){ if (d.success) { window.location.reload(); } else { alert(d.message || 'خطا'); } })
                .catch(function(){ alert('خطا در ارتباط با سرور.'); });
        });
    });

    // ---------- بودجه‌بندی ----------
    setupAmountFormatter('budget_amount');

    var budgetPeriodSel = document.getElementById('budget_period');
    function budgetCustomToggle() {
        var box = document.getElementById('budgetCustomDates');
        if (box) box.hidden = (budgetPeriodSel.value !== 'custom');
    }
    if (budgetPeriodSel) budgetPeriodSel.addEventListener('change', budgetCustomToggle);

    document.querySelectorAll('.js-add-budget').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('budgetModalTitle').textContent = 'بودجه جدید';
            document.getElementById('budget_id').value = '';
            document.getElementById('budget_category').selectedIndex = 0;
            document.getElementById('budget_period').value = 'monthly';
            document.getElementById('budget_amount').value = '';
            document.getElementById('budgetExtraActions').hidden = true;
            var m = document.getElementById('budgetMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            budgetCustomToggle();
            openModal('budgetModal');
        });
    });

    document.querySelectorAll('.js-edit-budget').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('budgetModalTitle').textContent = 'ویرایش بودجه';
            document.getElementById('budget_id').value = this.getAttribute('data-id');
            document.getElementById('budget_category').value = this.getAttribute('data-category');
            document.getElementById('budget_period').value = this.getAttribute('data-period');
            document.getElementById('budget_amount').value =
                toPersianDigitsJs(Number(this.getAttribute('data-amount')).toLocaleString('en-US').replace(/,/g,'\u066C'));

            var start = this.getAttribute('data-start');
            var end = this.getAttribute('data-end');
            if (start) setJdpValue('budget_start_display', 'budget_start', start);
            if (end) setJdpValue('budget_end_display', 'budget_end', end);

            var extra = document.getElementById('budgetExtraActions');
            extra.hidden = false;
            var isActive = this.getAttribute('data-active') === '1';
            var tgl = document.getElementById('budgetToggleBtn');
            tgl.textContent = isActive ? 'غیرفعال کردن' : 'فعال کردن';
            tgl.setAttribute('data-id', this.getAttribute('data-id'));
            document.getElementById('budgetDeleteBtn').setAttribute('data-id', this.getAttribute('data-id'));

            var m = document.getElementById('budgetMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            budgetCustomToggle();
            openModal('budgetModal');
        });
    });

    var budgetForm = document.getElementById('budgetForm');
    if (budgetForm) {
        budgetForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(budgetForm);
            fd.set('amount', toLatinDigitsJs(document.getElementById('budget_amount').value).replace(/[,\u066C]/g, ''));
            submitJson(budgetForm, apiUrl('save_budget.php'),
                document.getElementById('budgetMessage'),
                document.getElementById('budgetSubmitBtn'), null, fd);
        });
    }

    var budgetToggleBtn = document.getElementById('budgetToggleBtn');
    if (budgetToggleBtn) {
        budgetToggleBtn.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('budget_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('toggle_budget.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){ if (d.success) { window.location.reload(); } else { alert(d.message || 'خطا'); } })
                .catch(function(){ alert('خطا در ارتباط با سرور.'); });
        });
    }

    var budgetDeleteBtn = document.getElementById('budgetDeleteBtn');
    if (budgetDeleteBtn) {
        budgetDeleteBtn.addEventListener('click', function () {
            if (!confirm('این بودجه حذف شود؟')) return;
            var fd = new FormData();
            fd.append('budget_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('delete_budget.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){ if (d.success) { window.location.reload(); } else { alert(d.message || 'خطا'); } })
                .catch(function(){ alert('خطا در ارتباط با سرور.'); });
        });
    }

    // ---------- اهداف پس‌انداز ----------
    setupAmountFormatter('goal_target');
    setupAmountFormatter('entry_amount');

    document.querySelectorAll('.js-add-goal').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('goalModalTitle').textContent = 'هدف جدید';
            document.getElementById('goal_id').value = '';
            document.getElementById('goal_title').value = '';
            document.getElementById('goal_target').value = '';
            document.getElementById('goal_color').value = '#16794f';
            document.getElementById('goal_date').value = '';
            document.getElementById('goal_date_display').value = '';
            document.getElementById('goalExtraActions').hidden = true;
            var m = document.getElementById('goalMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('goalModal');
        });
    });

    document.querySelectorAll('.js-edit-goal').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('goalModalTitle').textContent = 'ویرایش هدف';
            document.getElementById('goal_id').value = this.getAttribute('data-id');
            document.getElementById('goal_title').value = this.getAttribute('data-title');
            document.getElementById('goal_target').value =
                toPersianDigitsJs(Number(this.getAttribute('data-target')).toLocaleString('en-US').replace(/,/g,'\u066C'));
            document.getElementById('goal_color').value = this.getAttribute('data-color') || '#16794f';

            var d = this.getAttribute('data-date');
            if (d) { setJdpValue('goal_date_display', 'goal_date', d); }
            else { document.getElementById('goal_date').value = ''; document.getElementById('goal_date_display').value = ''; }

            var extra = document.getElementById('goalExtraActions');
            extra.hidden = false;
            var isArchived = this.getAttribute('data-archived') === '1';
            var ab = document.getElementById('goalArchiveBtn');
            ab.textContent = isArchived ? 'خارج از بایگانی' : 'بایگانی کردن';
            ab.setAttribute('data-id', this.getAttribute('data-id'));
            document.getElementById('goalDeleteBtn').setAttribute('data-id', this.getAttribute('data-id'));

            var m = document.getElementById('goalMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('goalModal');
        });
    });

    var goalForm = document.getElementById('goalForm');
    if (goalForm) {
        goalForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(goalForm);
            fd.set('target_amount', toLatinDigitsJs(document.getElementById('goal_target').value).replace(/[,\u066C]/g, ''));
            submitJson(goalForm, apiUrl('save_goal.php'),
                document.getElementById('goalMessage'),
                document.getElementById('goalSubmitBtn'), null, fd);
        });
    }

    var goalArchiveBtn = document.getElementById('goalArchiveBtn');
    if (goalArchiveBtn) {
        goalArchiveBtn.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('goal_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('archive_goal.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){ if (d.success) { window.location.reload(); } else { alert(d.message || 'خطا'); } })
                .catch(function(){ alert('خطا در ارتباط با سرور.'); });
        });
    }

    var goalDeleteBtn = document.getElementById('goalDeleteBtn');
    if (goalDeleteBtn) {
        goalDeleteBtn.addEventListener('click', function () {
            if (!confirm('این هدف و کل تاریخچه‌اش حذف شود؟')) return;
            var fd = new FormData();
            fd.append('goal_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('delete_goal.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){ if (d.success) { window.location.reload(); } else { alert(d.message || 'خطا'); } })
                .catch(function(){ alert('خطا در ارتباط با سرور.'); });
        });
    }

    document.querySelectorAll('.js-goal-deposit, .js-goal-withdraw').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var isDeposit = this.classList.contains('js-goal-deposit');
            document.getElementById('goalEntryTitle').textContent =
                (isDeposit ? 'واریز به «' : 'برداشت از «') + this.getAttribute('data-title') + '»';
            document.getElementById('entry_goal_id').value = this.getAttribute('data-id');
            document.getElementById('entry_direction').value = isDeposit ? 'deposit' : 'withdraw';
            document.getElementById('entry_amount').value = '';
            document.getElementById('entry_note').value = '';
            var m = document.getElementById('goalEntryMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('goalEntryModal');
        });
    });

    var goalEntryForm = document.getElementById('goalEntryForm');
    if (goalEntryForm) {
        goalEntryForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(goalEntryForm);
            fd.set('amount', toLatinDigitsJs(document.getElementById('entry_amount').value).replace(/[,\u066C]/g, ''));
            submitJson(goalEntryForm, apiUrl('add_savings_entry.php'),
                document.getElementById('goalEntryMessage'),
                document.getElementById('goalEntrySubmitBtn'), null, fd);
        });
    }

    document.querySelectorAll('.js-goal-history').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var goalId = this.getAttribute('data-id');
            var box = document.getElementById('goalHistory' + goalId);
            if (!box) return;

            if (!box.hidden) { box.hidden = true; return; }

            box.innerHTML = '<p style="text-align:center; color:var(--muted); padding:10px 0;">در حال بارگذاری...</p>';
            box.hidden = false;

            fetch(apiUrl('savings_history.php') + '?goal_id=' + encodeURIComponent(goalId))
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    box.innerHTML = html;
                    box.dataset.loaded = '1';
                    box.querySelectorAll('.js-delete-savings-entry').forEach(function (delBtn) {
                        delBtn.addEventListener('click', function () {
                            if (!confirm('این رکورد حذف شود؟')) return;
                            var fd = new FormData();
                            fd.append('entry_id', this.getAttribute('data-id'));
                            fd.append('csrf_token', csrf());
                            fetch(apiUrl('delete_savings_entry.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                                .then(function (r) { return r.json(); })
                                .then(function (d) { if (d.success) { window.location.reload(); } else { alert(d.message || 'خطا'); } })
                                .catch(function () { alert('خطا در ارتباط با سرور.'); });
                        });
                    });
                })
                .catch(function () {
                    box.innerHTML = '<p style="text-align:center; color:var(--out); padding:10px 0;">خطا در بارگذاری.</p>';
                });
        });
    });

    // ---------- پرداخت جزئی طلب/بدهی ----------
    setupAmountFormatter('payment_amount');

    document.querySelectorAll('.js-debt-payment').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var remaining = parseInt(this.getAttribute('data-remaining'), 10);
            document.getElementById('debtPaymentTitle').textContent = 'ثبت پرداخت — ' + this.getAttribute('data-name');
            document.getElementById('payment_debt_id').value = this.getAttribute('data-id');
            document.getElementById('payment_amount').value = '';
            document.getElementById('payment_note').value = '';
            document.getElementById('paymentRemainingHint').textContent =
                'باقیمانده: ' + toPersianDigitsJs(remaining.toLocaleString('en-US').replace(/,/g,'\u066C')) + ' تومان';
            var m = document.getElementById('debtPaymentMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('debtPaymentModal');
        });
    });

    var debtPaymentForm = document.getElementById('debtPaymentForm');
    if (debtPaymentForm) {
        debtPaymentForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(debtPaymentForm);
            fd.set('amount', toLatinDigitsJs(document.getElementById('payment_amount').value).replace(/[,\u066C]/g, ''));
            var msgEl = document.getElementById('debtPaymentMessage');
            var btnEl = document.getElementById('debtPaymentSubmitBtn');
            btnEl.disabled = true;
            fetch(apiUrl('add_debt_payment.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    msgEl.hidden = false; msgEl.classList.remove('success', 'error');
                    if (d.success) {
                        msgEl.classList.add('show', 'success');
                        msgEl.textContent = d.message || 'ثبت شد.';
                        window.location.reload();
                    } else {
                        msgEl.classList.add('show', 'error');
                        msgEl.textContent = d.message || 'خطایی رخ داد.';
                    }
                })
                .catch(function () {
                    msgEl.hidden = false; msgEl.classList.add('show', 'error');
                    msgEl.textContent = 'خطا در ارتباط با سرور.';
                })
                .finally(function () { btnEl.disabled = false; });
        });
    }

    // ---------- تراکنش دوره‌ای ----------
    setupAmountFormatter('recurring_amount');
    setupAmountFormatter('confirm_amount');

    var recurringToggle = setupTypeToggle('recurringTypeToggle', 'recurring_type', 'recurring_category');

    var recurModeHints = {
        remind:  'فقط یادآوری می‌دهد؛ خودتان تراکنش را ثبت می‌کنید.',
        confirm: 'سررسید که برسد، از شما می‌خواهد تأیید کنید تا ثبت شود.',
        auto:    'سررسید که برسد، خودش بدون نیاز به تأیید ثبت می‌کند.'
    };
    document.querySelectorAll('.recurring-mode-item').forEach(function (item) {
        item.addEventListener('click', function () {
            document.querySelectorAll('.recurring-mode-item').forEach(function (i) { i.classList.remove('active'); });
            this.classList.add('active');
            var mode = this.getAttribute('data-mode');
            document.getElementById('recurring_mode').value = mode;
            document.getElementById('recurringModeHint').textContent = recurModeHints[mode] || '';
        });
    });
    function setRecurringMode(mode) {
        document.querySelectorAll('.recurring-mode-item').forEach(function (i) {
            i.classList.toggle('active', i.getAttribute('data-mode') === mode);
        });
        document.getElementById('recurring_mode').value = mode;
        document.getElementById('recurringModeHint').textContent = recurModeHints[mode] || '';
    }

    document.querySelectorAll('.js-add-recurring').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('recurringModalTitle').textContent = 'قانون جدید';
            document.getElementById('recurring_id').value = '';
            document.getElementById('recurring_title').value = '';
            document.getElementById('recurring_amount').value = '';
            document.getElementById('recurring_wallet').selectedIndex = 0;
            document.getElementById('recurring_frequency').value = 'monthly';
            document.getElementById('recurring_interval').value = '1';
            document.getElementById('recurring_note').value = '';
            setJdpValue('recurring_end_display', 'recurring_end', '');
            document.getElementById('recurring_end_display').value = '';
            document.getElementById('recurringExtraActions').hidden = true;
            if (recurringToggle) recurringToggle.setType('income');
            setRecurringMode('remind');
            var m = document.getElementById('recurringMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('recurringModal');
        });
    });

    document.querySelectorAll('.js-edit-recurring').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('recurringModalTitle').textContent = 'ویرایش قانون';
            document.getElementById('recurring_id').value = this.getAttribute('data-id');
            document.getElementById('recurring_title').value = this.getAttribute('data-title');
            document.getElementById('recurring_amount').value =
                toPersianDigitsJs(Number(this.getAttribute('data-amount')).toLocaleString('en-US').replace(/,/g,'\u066C'));
            document.getElementById('recurring_wallet').value = this.getAttribute('data-wallet');
            document.getElementById('recurring_frequency').value = this.getAttribute('data-frequency');
            document.getElementById('recurring_interval').value = toPersianDigitsJs(this.getAttribute('data-interval'));
            document.getElementById('recurring_note').value = this.getAttribute('data-note') || '';

            if (recurringToggle) recurringToggle.setType(this.getAttribute('data-type'));
            document.getElementById('recurring_category').value = this.getAttribute('data-category') || '';

            setJdpValue('recurring_start_display', 'recurring_start', this.getAttribute('data-start'));
            var end = this.getAttribute('data-end');
            if (end) { setJdpValue('recurring_end_display', 'recurring_end', end); }
            else { document.getElementById('recurring_end').value = ''; document.getElementById('recurring_end_display').value = ''; }

            setRecurringMode(this.getAttribute('data-mode'));

            var extra = document.getElementById('recurringExtraActions');
            extra.hidden = false;
            var isActive = this.getAttribute('data-active') === '1';
            var tgl = document.getElementById('recurringToggleBtn');
            tgl.textContent = isActive ? 'غیرفعال کردن' : 'فعال کردن';
            tgl.setAttribute('data-id', this.getAttribute('data-id'));
            document.getElementById('recurringDeleteBtn').setAttribute('data-id', this.getAttribute('data-id'));

            var m = document.getElementById('recurringMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('recurringModal');
        });
    });

    var recurringForm = document.getElementById('recurringForm');
    if (recurringForm) {
        recurringForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(recurringForm);
            fd.set('amount', toLatinDigitsJs(document.getElementById('recurring_amount').value).replace(/[,\u066C]/g, ''));
            fd.set('interval_count', toLatinDigitsJs(document.getElementById('recurring_interval').value).replace(/[,\u066C]/g, ''));
            submitJson(recurringForm, apiUrl('save_recurring.php'),
                document.getElementById('recurringMessage'),
                document.getElementById('recurringSubmitBtn'), null, fd);
        });
    }

    var recurringToggleBtn = document.getElementById('recurringToggleBtn');
    if (recurringToggleBtn) {
        recurringToggleBtn.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('recurring_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('toggle_recurring.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){ if (d.success) { window.location.reload(); } else { alert(d.message || 'خطا'); } })
                .catch(function(){ alert('خطا در ارتباط با سرور.'); });
        });
    }

    var recurringDeleteBtn = document.getElementById('recurringDeleteBtn');
    if (recurringDeleteBtn) {
        recurringDeleteBtn.addEventListener('click', function () {
            if (!confirm('این قانون حذف شود؟ تراکنش‌های قبلی حذف نمی‌شوند.')) return;
            var fd = new FormData();
            fd.append('recurring_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('delete_recurring.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){ if (d.success) { window.location.reload(); } else { alert(d.message || 'خطا'); } })
                .catch(function(){ alert('خطا در ارتباط با سرور.'); });
        });
    }

    // ---------- تأیید / رد سررسید ----------
    document.querySelectorAll('.js-confirm-recurring').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('confirmRecurringTitle').textContent = 'تأیید — ' + this.getAttribute('data-title');
            document.getElementById('confirm_recurring_id').value = this.getAttribute('data-id');
            document.getElementById('confirm_amount').value =
                toPersianDigitsJs(Number(this.getAttribute('data-amount')).toLocaleString('en-US').replace(/,/g,'\u066C'));
            var m = document.getElementById('confirmRecurringMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('confirmRecurringModal');
        });
    });

    var confirmRecurringForm = document.getElementById('confirmRecurringForm');
    if (confirmRecurringForm) {
        confirmRecurringForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(confirmRecurringForm);
            fd.set('amount', toLatinDigitsJs(document.getElementById('confirm_amount').value).replace(/[,\u066C]/g, ''));
            submitJson(confirmRecurringForm, apiUrl('confirm_recurring.php'),
                document.getElementById('confirmRecurringMessage'),
                document.getElementById('confirmRecurringSubmitBtn'), null, fd);
        });
    }

    document.querySelectorAll('.js-skip-recurring').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('این دوره بدون ثبت تراکنش رد شود؟')) return;
            var fd = new FormData();
            fd.append('recurring_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('skip_recurring.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){ if (d.success) { window.location.reload(); } else { alert(d.message || 'خطا'); } })
                .catch(function(){ alert('خطا در ارتباط با سرور.'); });
        });
    });

    // ---------- تقویم مالی: نمایش جزئیات روز ----------
    document.querySelectorAll('.js-cal-day').forEach(function (cell) {
        cell.addEventListener('click', function () {
            var date = this.getAttribute('data-date');
            var card = document.getElementById('calDayCard');
            var body = document.getElementById('calDayBody');
            var title = document.getElementById('calDayTitle');
            if (!card || !body) return;

            document.querySelectorAll('.cal-cell').forEach(function (c) { c.classList.remove('cal-selected'); });
            this.classList.add('cal-selected');

            card.hidden = false;
            body.innerHTML = '<p style="text-align:center; color:var(--muted); padding:12px 0;">در حال بارگذاری…</p>';

            fetch(apiUrl('day_detail.php') + '?date=' + encodeURIComponent(date))
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    body.innerHTML = html;
                    if (title) { title.textContent = 'جزئیات روز'; }
                    body.querySelectorAll('.tx-row-summary').forEach(function (row) {
                        row.addEventListener('click', function () {
                            var parent = this.closest('.tx-row');
                            if (parent) parent.classList.toggle('expanded');
                        });
                    });
                    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                })
                .catch(function () {
                    body.innerHTML = '<p style="text-align:center; color:var(--out); padding:12px 0;">خطا در بارگذاری.</p>';
                });
        });
    });

    // ---------- ورود اطلاعات از CSV ----------
    var previewBtn = document.getElementById('previewBtn');
    if (previewBtn && window.IMPORT_ROWS) {
        previewBtn.addEventListener('click', function () {
            var map = {};
            document.querySelectorAll('.map-select').forEach(function (sel) {
                map[sel.getAttribute('data-field')] = parseInt(sel.value, 10);
            });

            if (map.date < 0 || map.amount < 0 || map.type < 0) {
                alert('ستون‌های تاریخ، مبلغ و نوع الزامی هستند.');
                return;
            }

            var valid = [], invalid = [];

            window.IMPORT_ROWS.forEach(function (row, idx) {
                var res = parseRow(row, map);
                if (res.ok) { valid.push(res.data); }
                else { invalid.push({ line: idx + 2, error: res.error }); }
            });

            renderPreview(valid, invalid);
            document.getElementById('importPayload').value = JSON.stringify(valid);
            document.getElementById('previewArea').hidden = false;
            document.getElementById('commitBtn').disabled = (valid.length === 0);
        });
    }

    function cell(row, idx) {
        return (idx >= 0 && row[idx] !== undefined) ? String(row[idx]).trim() : '';
    }

    function parseRow(row, map) {
        // ---- تاریخ ----
        var rawDate = toLatinDigitsJs(cell(row, map.date)).replace(/[\/.]/g, '-');
        var m = rawDate.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
        if (!m) { return { ok: false, error: 'تاریخ نامعتبر: ' + (rawDate || 'خالی') }; }

        var y = parseInt(m[1], 10), mo = parseInt(m[2], 10), d = parseInt(m[3], 10);
        var gDate;
        if (y < 1900) {
            if (mo < 1 || mo > 12 || d < 1 || d > 31) { return { ok: false, error: 'تاریخ شمسی نامعتبر' }; }
            var g = jalaliToGregorianJs(y, mo, d);
            gDate = pad4(g[0]) + '-' + pad2(g[1]) + '-' + pad2(g[2]);
        } else {
            gDate = pad4(y) + '-' + pad2(mo) + '-' + pad2(d);
        }

        // ---- مبلغ ----
        var amount = parseInt(toLatinDigitsJs(cell(row, map.amount)).replace(/[^\d]/g, ''), 10);
        if (!amount || amount <= 0) { return { ok: false, error: 'مبلغ نامعتبر' }; }

        // ---- نوع ----
        var rawType = cell(row, map.type).toLowerCase();
        var type = null;
        ['income', 'درآمد', 'دریافت', 'واریز', '+'].forEach(function (w) {
            if (type === null && rawType.indexOf(w) !== -1) type = 'income';
        });
        if (type === null) {
            ['expense', 'هزینه', 'پرداخت', 'برداشت', '-'].forEach(function (w) {
                if (type === null && rawType.indexOf(w) !== -1) type = 'expense';
            });
        }
        if (type === null) { return { ok: false, error: 'نوع نامشخص: ' + (rawType || 'خالی') }; }

        // ---- عنوان ----
        var title = cell(row, map.title);
        if (!title) { title = (type === 'income' ? 'درآمد واردشده' : 'هزینه واردشده'); }

        // ---- دسته‌بندی ----
        var categoryId = null;
        var catName = cell(row, map.category).toLowerCase();
        if (catName && window.IMPORT_CATEGORIES) {
            window.IMPORT_CATEGORIES.forEach(function (c) {
                if (categoryId === null && c.name.toLowerCase() === catName && c.type === type) {
                    categoryId = c.id;
                }
            });
        }

        // ---- حساب ----
        var walletId = window.IMPORT_DEFAULT_WALLET;
        var wName = cell(row, map.wallet).toLowerCase();
        if (wName && window.IMPORT_WALLETS) {
            window.IMPORT_WALLETS.forEach(function (w) {
                if (w.name === wName) walletId = w.id;
            });
        }

        return {
            ok: true,
            data: {
                transaction_date: gDate,
                amount: amount,
                type: type,
                title: title.substring(0, 255),
                category_id: categoryId,
                wallet_id: walletId,
                note: cell(row, map.note).substring(0, 1000) || null
            }
        };
    }

    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function pad4(n) { return ('000' + n).slice(-4); }

    // تبدیل شمسی به میلادی — همان الگوریتم فایل تقویم
    function jalaliToGregorianJs(jy, jm, jd) {
        if (window.JalaliDatePicker && typeof window.JalaliDatePicker.toGregorian === 'function') {
            return window.JalaliDatePicker.toGregorian(jy, jm, jd);
        }
        var sal_a, gy, gm, gd, days;
        jy += 1595;
        days = -355668 + (365 * jy) + (~~(jy / 33) * 8) + ~~(((jy % 33) + 3) / 4) + jd +
               ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
        gy = 400 * ~~(days / 146097);
        days %= 146097;
        if (days > 36524) {
            gy += 100 * ~~(--days / 36524);
            days %= 36524;
            if (days >= 365) days++;
        }
        gy += 4 * ~~(days / 1461);
        days %= 1461;
        if (days > 365) {
            gy += ~~((days - 1) / 365);
            days = (days - 1) % 365;
        }
        gd = days + 1;
        sal_a = [0, 31, ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28,
                 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for (gm = 0; gm < 13 && gd > sal_a[gm]; gm++) gd -= sal_a[gm];
        return [gy, gm, gd];
    }

    function renderPreview(valid, invalid) {
        var summary = document.getElementById('importSummary');
        var table = document.getElementById('previewTable');
        if (!summary || !table) return;

        var html = '<div class="alert ' + (invalid.length ? 'alert-info' : 'alert-success') + '">';
        html += toPersianDigitsJs(valid.length) + ' سطر آماده ثبت';
        if (invalid.length) { html += ' · ' + toPersianDigitsJs(invalid.length) + ' سطر نامعتبر (نادیده گرفته می‌شود)'; }
        html += '</div>';

        if (invalid.length) {
            html += '<details class="import-errors"><summary>مشاهده سطرهای نامعتبر</summary>';
            invalid.slice(0, 30).forEach(function (e) {
                html += '<div class="import-error-row">سطر ' + toPersianDigitsJs(e.line) + ': ' + e.error + '</div>';
            });
            html += '</details>';
        }
        summary.innerHTML = html;

        var rows = '<thead><tr><th>تاریخ</th><th>عنوان</th><th>نوع</th><th>مبلغ</th></tr></thead><tbody>';
        valid.slice(0, 25).forEach(function (v) {
            rows += '<tr>';
            rows += '<td data-label="تاریخ">' + toPersianDigitsJs(v.transaction_date) + '</td>';
            rows += '<td data-label="عنوان">' + escapeHtml(v.title) + '</td>';
            rows += '<td data-label="نوع">' + (v.type === 'income' ? 'درآمد' : 'هزینه') + '</td>';
            rows += '<td data-label="مبلغ">' + toPersianDigitsJs(v.amount.toLocaleString('en-US').replace(/,/g, '\u066C')) + '</td>';
            rows += '</tr>';
        });
        rows += '</tbody>';
        table.innerHTML = rows;
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ---------- پیوست رسید ----------
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-load-attachments');
        if (!btn) return;

        var txId = btn.getAttribute('data-tx-id');
        var box = document.getElementById('attachBox' + txId);

        // اولین بار: باکس را همین‌جا می‌سازیم تا HTML صفحه سبک بماند
        if (!box) {
            box = document.createElement('div');
            box.className = 'attach-box';
            box.id = 'attachBox' + txId;
            box.setAttribute('data-tx-id', txId);
            box.innerHTML =
                '<div class="attach-list"></div>' +
                '<label class="attach-add">' +
                '<input type="file" class="js-attach-input" data-tx-id="' + txId + '" ' +
                'accept="image/jpeg,image/png,image/webp,application/pdf" hidden>' +
                '<span>+ افزودن رسید (عکس یا PDF، حداکثر ۳ مگابایت)</span></label>';
            var host = btn.closest('.tx-row-details') || btn.parentNode;
            host.appendChild(box);
            loadAttachments(txId);
            return;
        }

        if (!box.hidden) { box.hidden = true; return; }
        box.hidden = false;
        loadAttachments(txId);
    });

    function loadAttachments(txId) {
        var box = document.getElementById('attachBox' + txId);
        if (!box) return;
        var list = box.querySelector('.attach-list');
        if (!list) return;

        list.innerHTML = '<p class="hint">در حال بارگذاری…</p>';
        fetch(apiUrl('transaction_attachments.php') + '?transaction_id=' + encodeURIComponent(txId))
            .then(function (r) { return r.text(); })
            .then(function (html) { list.innerHTML = html; })
            .catch(function () { list.innerHTML = '<p class="hint">خطا در بارگذاری.</p>'; });
    }

    document.addEventListener('change', function (e) {
        var input = e.target.closest('.js-attach-input');
        if (!input || !input.files || !input.files[0]) return;

        var txId = input.getAttribute('data-tx-id');
        var fd = new FormData();
        fd.append('transaction_id', txId);
        fd.append('csrf_token', csrf());
        fd.append('file', input.files[0]);

        var box = document.getElementById('attachBox' + txId);
        var list = box ? box.querySelector('.attach-list') : null;
        if (list) list.innerHTML = '<p class="hint">در حال آپلود…</p>';

        fetch(apiUrl('upload_attachment.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                input.value = '';
                if (d.success) { loadAttachments(txId); }
                else {
                    alert(d.message || 'خطا در آپلود.');
                    loadAttachments(txId);
                }
            })
            .catch(function () { alert('خطا در ارتباط با سرور.'); });
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-delete-attachment');
        if (!btn) return;
        if (!confirm('این پیوست حذف شود؟')) return;

        var box = btn.closest('.attach-box');
        var txId = box ? box.getAttribute('data-tx-id') : null;

        var fd = new FormData();
        fd.append('attachment_id', btn.getAttribute('data-id'));
        fd.append('csrf_token', csrf());

        fetch(apiUrl('delete_attachment.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success && txId) { loadAttachments(txId); }
                else if (!d.success) { alert(d.message || 'خطا'); }
            })
            .catch(function () { alert('خطا در ارتباط با سرور.'); });
    });

    // ---------- حساب کاربری من ----------
    var profileForm = document.getElementById('profileForm');
    if (profileForm) {
        profileForm.addEventListener('submit', function (e) {
            e.preventDefault();
            submitJson(profileForm, apiUrl('update_profile.php'),
                document.getElementById('profileMessage'),
                document.getElementById('profileSubmitBtn'));
        });
    }

    var passwordForm = document.getElementById('passwordForm');
    if (passwordForm) {
        passwordForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var np = document.getElementById('pf_new_pass').value;
            var np2 = document.getElementById('pf_new_pass2').value;
            var msg = document.getElementById('passwordMessage');
            if (np !== np2) {
                msg.hidden = false;
                msg.classList.remove('success');
                msg.classList.add('show', 'error');
                msg.textContent = 'رمز جدید و تکرار آن یکسان نیستند.';
                return;
            }
            submitJson(passwordForm, apiUrl('change_password.php'), msg,
                document.getElementById('passwordSubmitBtn'));
        });
    }

    // ماندن در حساب — کلید «همیشه» + چیپ‌های مهلت
    //
    // ⚠ دکمه‌ی «ذخیره» عمداً برداشته شد و هر ضربه بلافاصله ذخیره می‌شود.
    //   با دکمه، کاربر گزینه را می‌زد و می‌رفت و تغییر اعمال نمی‌شد —
    //   یعنی تنظیمی که کار نمی‌کند. اشتباه زدن هم بی‌هزینه است: یک ضربه‌ی
    //   دیگر برش می‌گرداند.
    var stayBox = document.getElementById('stayBox');
    if (stayBox) {
        var stayToggle  = document.getElementById('stayForever');
        var stayChoices = document.getElementById('stayChoices');
        var stayNote    = document.getElementById('stayNote');
        var stayMsg     = document.getElementById('sessionMessage');
        var stayChips   = stayBox.querySelectorAll('.stay-chip');
        // متنِ بخشِ «دستگاه‌های مورد اعتماد» هم همین عدد را می‌گوید؛ اگر
        // به‌روز نشود، کاربر بلافاصله دو حرفِ متناقض روی یک صفحه می‌بیند.
        var stayDevWin  = document.getElementById('deviceWindow');

        // آخرین مهلتِ زمان‌دارِ انتخاب‌شده، تا خاموش کردنِ کلید همان را
        // برگرداند نه یک پیش‌فرضِ دلخواه. بدون این، کاربری که «۸ ساعت»
        // داشت و کلید را روشن و دوباره خاموش می‌کرد، ناگهان روی «۱ دقیقه»
        // می‌افتاد.
        var lastTimed = parseInt(stayBox.getAttribute('data-current'), 10) || 60;

        function stayLabelFor(minutes) {
            var chip = stayBox.querySelector('.stay-chip[data-minutes="' + minutes + '"]');
            return chip ? chip.textContent.trim() : minutes + ' دقیقه';
        }

        function stayRender(minutes) {
            stayChips.forEach(function (c) {
                c.classList.toggle('active', parseInt(c.getAttribute('data-minutes'), 10) === minutes);
            });
            if (minutes === 0) {
                stayChoices.classList.remove('is-open');
                stayNote.innerHTML = 'تا وقتی خودتان <strong>خارج</strong> نشوید، رمز پرسیده نمی‌شود.';
                if (stayDevWin) { stayDevWin.textContent = 'تا وقتی خودتان خارج نشوید'; }
            } else {
                stayChoices.classList.add('is-open');
                stayNote.innerHTML = 'اگر <strong>' + stayLabelFor(minutes) +
                    '</strong> از برنامه استفاده نکنید، دوباره رمز می‌پرسد. ' +
                    'هر بار استفاده، این مهلت را از نو شروع می‌کند.';
                if (stayDevWin) {
                    stayDevWin.textContent = 'تا ' + stayLabelFor(minutes) + ' پس از آخرین استفاده';
                }
            }
        }

        function staySave(minutes) {
            var fd = new FormData();
            fd.append('session_minutes', minutes);
            var token = stayBox.querySelector('input[name="csrf_token"]');
            if (token) { fd.append('csrf_token', token.value); }

            fetch(apiUrl('update_session_pref.php'), {
                method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!stayMsg) { return; }
                    stayMsg.hidden = false;
                    stayMsg.classList.remove('success', 'error');
                    stayMsg.classList.add('show', d.success ? 'success' : 'error');
                    stayMsg.textContent = d.message || (d.success ? 'ذخیره شد.' : 'خطا');
                    if (d.success) {
                        setTimeout(function () { stayMsg.hidden = true; }, 2200);
                    }
                })
                .catch(function () {
                    if (!stayMsg) { return; }
                    stayMsg.hidden = false;
                    stayMsg.classList.add('show', 'error');
                    stayMsg.textContent = 'خطا در ارتباط با سرور.';
                });
        }

        stayToggle.addEventListener('change', function () {
            var minutes = stayToggle.checked ? 0 : lastTimed;
            stayRender(minutes);
            staySave(minutes);
        });

        stayChips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                var minutes = parseInt(chip.getAttribute('data-minutes'), 10);
                lastTimed = minutes;
                stayRender(minutes);
                staySave(minutes);
            });
        });

        stayRender(parseInt(stayBox.getAttribute('data-current'), 10) || 0);
    }

    document.querySelectorAll('.js-revoke-device').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('این دستگاه حذف شود؟ دفعه بعد باید رمز وارد کند.')) return;
            var fd = new FormData();
            fd.append('device_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('revoke_device.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d.success) { window.location.reload(); } else { alert(d.message || 'خطا'); } })
                .catch(function () { alert('خطا در ارتباط با سرور.'); });
        });
    });

    var revokeAllBtn = document.getElementById('revokeAllBtn');
    if (revokeAllBtn) {
        revokeAllBtn.addEventListener('click', function () {
            if (!confirm('همه دستگاه‌ها حذف شوند؟ از همه‌جا خارج می‌شوید.')) return;
            var fd = new FormData();
            fd.append('all', '1');
            fd.append('csrf_token', csrf());
            fetch(apiUrl('revoke_device.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d.success) { window.location.href = 'logout.php'; } else { alert(d.message || 'خطا'); } })
                .catch(function () { alert('خطا در ارتباط با سرور.'); });
        });
    }

    // ---------- قفل اسکرول: فقط لایه‌ی رویی اسکرول شود ----------
    //
    // وقتی شیت یا مودالی باز است، کشیدن انگشت نباید صفحه‌ی زیرش را
    // جابه‌جا کند — کاربر فکر می‌کند دارد فهرست شیت را می‌بندد ولی
    // صفحه‌ی پشت سُر می‌خورد و جای خودش را گم می‌کند. با برگشتن به
    // صفحه، اسکرول دقیقاً از همان‌جا ادامه پیدا می‌کند.
    var scrollLock = { count: 0, y: 0 };
    function lockBodyScroll() {
        if (scrollLock.count++ > 0) return;
        scrollLock.y = window.scrollY || window.pageYOffset || 0;
        document.body.style.position = 'fixed';
        document.body.style.top = '-' + scrollLock.y + 'px';
        document.body.style.left = '0';
        document.body.style.right = '0';
        document.body.style.width = '100%';
    }
    function unlockBodyScroll() {
        if (scrollLock.count === 0) return;
        if (--scrollLock.count > 0) return;
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.left = '';
        document.body.style.right = '';
        document.body.style.width = '';
        window.scrollTo(0, scrollLock.y);
    }

    // هر لایه‌ای که با کلاس show باز/بسته می‌شود، خودکار قفل را می‌گیرد
    // و پس می‌دهد — بدون اینکه لازم باشد هر جای کد یادش باشد.
    (function watchOverlays() {
        var overlays = document.querySelectorAll('.modal-overlay, .sheet-overlay, .more-sheet-overlay');
        if (!overlays.length || !window.MutationObserver) return;
        overlays.forEach(function (el) {
            var wasOpen = el.classList.contains('show');
            if (wasOpen) lockBodyScroll();
            new MutationObserver(function () {
                var isOpen = el.classList.contains('show');
                if (isOpen === wasOpen) return;
                wasOpen = isOpen;
                isOpen ? lockBodyScroll() : unlockBodyScroll();
            }).observe(el, { attributes: true, attributeFilter: ['class'] });
        });
    })();

    // ---------- شیت «بیشتر» در ناوبری پایین ----------
    var moreBtn = document.getElementById('moreTabBtn');
    var moreSheet = document.getElementById('moreSheet');
    if (moreBtn && moreSheet) {
        function closeMoreSheet() { moreSheet.classList.remove('show'); }

        // دکمه‌ی «بیشتر» ضامن است نه فقط بازکننده: با زدن دوباره بسته
        // می‌شود. قبلاً فقط باز می‌کرد و چون شیت تا ۸۲٪ صفحه را می‌گیرد،
        // نوار باریکِ بالای آن تنها راه بستن بود — عملاً گیر می‌کرد.
        moreBtn.addEventListener('click', function (e) {
            e.preventDefault();
            moreSheet.classList.toggle('show');
        });
        moreSheet.addEventListener('click', function (e) {
            if (e.target === moreSheet) closeMoreSheet();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeMoreSheet();
        });
        // دستگیره و دکمه‌ی بستن، هر دو می‌بندند
        moreSheet.querySelectorAll('.more-sheet-grab, .js-close-more').forEach(function (el) {
            el.addEventListener('click', closeMoreSheet);
        });
        // کشیدن دستگیره به پایین هم می‌بندد — حرکت طبیعی روی گوشی
        var dragStart = null;
        var grip = moreSheet.querySelector('.more-sheet-grab');
        if (grip) {
            grip.addEventListener('touchstart', function (e) { dragStart = e.touches[0].clientY; }, { passive: true });
            grip.addEventListener('touchmove', function (e) {
                if (dragStart !== null && e.touches[0].clientY - dragStart > 45) {
                    closeMoreSheet();
                    dragStart = null;
                }
            }, { passive: true });
            grip.addEventListener('touchend', function () { dragStart = null; }, { passive: true });
        }
    }

    // ---------- زیرشیت مدیریت ----------
    var adminSheet = document.getElementById('adminSheet');
    if (adminSheet) {
        function closeAdminSheet() { adminSheet.classList.remove('show'); }
        document.querySelectorAll('.js-open-admin').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (moreSheet) moreSheet.classList.remove('show');
                adminSheet.classList.add('show');
            });
        });
        adminSheet.addEventListener('click', function (e) {
            if (e.target === adminSheet) closeAdminSheet();
        });
        adminSheet.querySelectorAll('.more-sheet-grab, .js-close-admin').forEach(function (el) {
            el.addEventListener('click', closeAdminSheet);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeAdminSheet();
        });
    }

    // ---------- کلید حالت شب / روز ----------
    var themeMedia = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function themeMode() {
        try { return localStorage.getItem('daftar_theme') || 'auto'; }
        catch (e) { return 'auto'; }
    }

    function applyTheme(mode) {
        var sysDark = themeMedia ? themeMedia.matches : false;
        var dark = (mode === 'dark') || (mode === 'auto' && sysDark);
        if (dark) { document.documentElement.setAttribute('data-theme', 'dark'); }
        else { document.documentElement.removeAttribute('data-theme'); }
        document.documentElement.setAttribute('data-theme-mode', mode);
        try { localStorage.setItem('daftar_theme', mode); } catch (e) {}

        var autoBox = document.getElementById('themeAuto');
        if (autoBox) { autoBox.checked = (mode === 'auto'); }

        // نمودارها رنگشان را در جاوااسکریپت گرفته‌اند و خودشان خبر ندارند
        window.__repaintThemedCharts();
    }

    // وقتی گوشی بین حالت شب و روز جابه‌جا می‌شود، اپ هم زنده عوض شود
    if (themeMedia) {
        var onSysChange = function () {
            if (themeMode() === 'auto') { applyTheme('auto'); }
        };
        if (themeMedia.addEventListener) { themeMedia.addEventListener('change', onSysChange); }
        else if (themeMedia.addListener) { themeMedia.addListener(onSysChange); }
    }

    var themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            // با زدن دکمه، از حالت خودکار خارج می‌شویم و صریح انتخاب می‌کنیم
            var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            applyTheme(isDark ? 'light' : 'dark');
        });
    }

    var themeAuto = document.getElementById('themeAuto');
    if (themeAuto) {
        themeAuto.checked = (themeMode() === 'auto');
        themeAuto.addEventListener('change', function () {
            if (this.checked) {
                applyTheme('auto');
            } else {
                var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                applyTheme(isDark ? 'dark' : 'light');
            }
        });
    }

    // ---------- عکس پروفایل ----------
    // ---------- تنظیم و بارگذاری تصویر پروفایل ----------
    //
    // تصویر گالری گوشی تقریباً هیچ‌وقت مربع نیست و صورت آدم هم وسط کادر
    // نیست. پیش از این هرچه انتخاب می‌شد مستقیم بالا می‌رفت و سرور وسط
    // را می‌برید — نتیجه‌اش اغلب نیمی از سر بود. حالا کاربر خودش قاب را
    // می‌بندد و همان چیزی که در دایره می‌بیند ذخیره می‌شود.
    //
    // بدون کتابخانه: یک <canvas> که تصویر را با مقیاس و جابه‌جایی
    // می‌کشد، کشیدن با یک انگشت/ماوس، بزرگ‌نمایی با نوار یا دو انگشت.
    var avatarInput = document.getElementById('avatarInput');
    var cropModal   = document.getElementById('avatarCropModal');

    if (avatarInput && cropModal) {
        var stage    = document.getElementById('cropStage');
        var canvas   = document.getElementById('cropCanvas');
        var zoomEl   = document.getElementById('cropZoom');
        var msgEl    = document.getElementById('avatarMessage');
        var saveBtn  = document.getElementById('cropSave');
        var ctx      = canvas.getContext('2d');

        var OUT = 512;              // اندازه‌ی تصویر ذخیره‌شده (مربع)
        var img = null;             // تصویر بارگذاری‌شده
        var minScale = 1, scale = 1, offX = 0, offY = 0;
        var objectUrl = null;

        function stageSize() {
            // بوم را با اندازه‌ی واقعی پیکسلی صحنه هماهنگ می‌کنیم تا روی
            // صفحه‌های رتینا تار نشود
            var r = stage.getBoundingClientRect();
            var dpr = Math.min(window.devicePixelRatio || 1, 2);
            var css = Math.max(1, Math.round(r.width));
            if (canvas.width !== Math.round(css * dpr)) {
                canvas.width  = Math.round(css * dpr);
                canvas.height = Math.round(css * dpr);
            }
            return { css: css, dpr: dpr };
        }

        // جابه‌جایی نباید بگذارد لبه‌ی تصویر داخل دایره بیفتد
        function clamp() {
            var s = stageSize().css;
            var w = img.naturalWidth * scale, h = img.naturalHeight * scale;
            var maxX = Math.max(0, (w - s) / 2), maxY = Math.max(0, (h - s) / 2);
            offX = Math.min(maxX, Math.max(-maxX, offX));
            offY = Math.min(maxY, Math.max(-maxY, offY));
        }

        function draw() {
            if (!img) return;
            var d = stageSize(), s = d.css;
            clamp();
            ctx.setTransform(d.dpr, 0, 0, d.dpr, 0, 0);
            ctx.clearRect(0, 0, s, s);
            var w = img.naturalWidth * scale, h = img.naturalHeight * scale;
            ctx.drawImage(img, (s - w) / 2 + offX, (s - h) / 2 + offY, w, h);
        }

        function fit() {
            var s = stageSize().css;
            // کوچک‌ترین مقیاسی که تصویر تمام صحنه را بپوشاند
            minScale = Math.max(s / img.naturalWidth, s / img.naturalHeight);
            scale = minScale; offX = 0; offY = 0;
            zoomEl.min = String(minScale);
            zoomEl.max = String(minScale * 4);
            zoomEl.step = String(minScale / 100);
            zoomEl.value = String(scale);
            draw();
        }

        function setScale(next, cx, cy) {
            var s = stageSize().css;
            next = Math.min(minScale * 4, Math.max(minScale, next));
            if (next === scale) return;
            // بزرگ‌نمایی حول نقطه‌ی مرکزِ اشاره، نه گوشه‌ی تصویر
            if (typeof cx === 'number') {
                var k = next / scale;
                offX = cx - k * (cx - offX);
                offY = cy - k * (cy - offY);
            }
            scale = next;
            zoomEl.value = String(scale);
            draw();
        }

        function showMsg(text, kind) {
            if (!msgEl) return;
            msgEl.hidden = false;
            msgEl.classList.remove('success', 'error');
            msgEl.classList.add('show');
            if (kind) msgEl.classList.add(kind);
            msgEl.textContent = text;
        }

        function closeCrop() {
            cropModal.classList.remove('show');
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            img = null;
            avatarInput.value = '';   // تا انتخاب دوباره‌ی همان فایل هم change بدهد
        }

        avatarInput.addEventListener('change', function () {
            if (!this.files || !this.files[0]) return;
            var file = this.files[0];

            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = URL.createObjectURL(file);

            var probe = new Image();
            probe.onload = function () {
                img = probe;
                cropModal.classList.add('show');
                // صحنه باید اول در DOM دیده شود تا اندازه‌اش را بدانیم
                requestAnimationFrame(fit);
            };
            probe.onerror = function () {
                showMsg('این فایل تصویر معتبری نیست.', 'error');
                closeCrop();
            };
            probe.src = objectUrl;
        });

        // ---- کشیدن با انگشت یا ماوس ----
        var pointers = {}, pinchStart = 0, scaleStart = 1;

        stage.addEventListener('pointerdown', function (e) {
            stage.setPointerCapture(e.pointerId);
            pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
            if (Object.keys(pointers).length === 2) {
                var p = Object.keys(pointers).map(function (k) { return pointers[k]; });
                pinchStart = Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y);
                scaleStart = scale;
            }
        });

        stage.addEventListener('pointermove', function (e) {
            var prev = pointers[e.pointerId];
            if (!prev || !img) return;
            var ids = Object.keys(pointers);

            if (ids.length === 2 && pinchStart > 0) {
                pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
                var p = ids.map(function (k) { return pointers[k]; });
                var dist = Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y);
                if (dist > 0) setScale(scaleStart * (dist / pinchStart));
                return;
            }

            offX += e.clientX - prev.x;
            offY += e.clientY - prev.y;
            pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
            draw();
        });

        function endPointer(e) {
            delete pointers[e.pointerId];
            if (Object.keys(pointers).length < 2) pinchStart = 0;
        }
        stage.addEventListener('pointerup', endPointer);
        stage.addEventListener('pointercancel', endPointer);

        stage.addEventListener('wheel', function (e) {
            if (!img) return;
            e.preventDefault();
            setScale(scale * (e.deltaY < 0 ? 1.08 : 1 / 1.08));
        }, { passive: false });

        zoomEl.addEventListener('input', function () { setScale(parseFloat(this.value)); });
        document.getElementById('cropZoomIn').addEventListener('click', function () { setScale(scale * 1.15); });
        document.getElementById('cropZoomOut').addEventListener('click', function () { setScale(scale / 1.15); });
        document.getElementById('cropReset').addEventListener('click', function () { if (img) fit(); });
        document.getElementById('cropCancel').addEventListener('click', closeCrop);
        cropModal.addEventListener('click', function (e) { if (e.target === cropModal) closeCrop(); });
        window.addEventListener('resize', function () { if (img) draw(); });

        // ---- برش و بارگذاری ----
        saveBtn.addEventListener('click', function () {
            if (!img) return;
            var s = stageSize().css;

            var out = document.createElement('canvas');
            out.width = OUT; out.height = OUT;
            var octx = out.getContext('2d');
            // JPEG شفافیت ندارد؛ بدون این، بخش‌های شفاف سیاه درمی‌آیند
            octx.fillStyle = '#ffffff';
            octx.fillRect(0, 0, OUT, OUT);

            // همان تبدیلی که روی صحنه دیده می‌شود، در مقیاس خروجی
            var k = OUT / s;
            var w = img.naturalWidth * scale * k, h = img.naturalHeight * scale * k;
            octx.drawImage(img, (OUT - w) / 2 + offX * k, (OUT - h) / 2 + offY * k, w, h);

            saveBtn.disabled = true;
            showMsg('در حال بارگذاری…');

            out.toBlob(function (blob) {
                if (!blob) {
                    saveBtn.disabled = false;
                    showMsg('ساخت تصویر ناموفق بود.', 'error');
                    return;
                }
                var fd = new FormData();
                fd.append('avatar', blob, 'avatar.jpg');
                fd.append('csrf_token', csrf());

                fetch(apiUrl('upload_avatar.php'), {
                    method: 'POST', body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.success) {
                            window.location.reload();
                        } else {
                            saveBtn.disabled = false;
                            showMsg(d.message || 'خطا در بارگذاری.', 'error');
                            closeCrop();
                        }
                    })
                    .catch(function () {
                        saveBtn.disabled = false;
                        showMsg('خطا در ارتباط با سرور.', 'error');
                        closeCrop();
                    });
            }, 'image/jpeg', 0.9);
        });
    }

    var avatarDeleteBtn = document.getElementById('avatarDeleteBtn');
    if (avatarDeleteBtn) {
        avatarDeleteBtn.addEventListener('click', function () {
            if (!confirm('تصویر پروفایل حذف شود؟')) return;
            var fd = new FormData();
            fd.append('csrf_token', csrf());
            fetch(apiUrl('delete_avatar.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d.success) { window.location.reload(); } else { alert(d.message || 'خطا'); } })
                .catch(function () { alert('خطا در ارتباط با سرور.'); });
        });
    }

    // ---------- مدیریت لیست‌های مرجع (بانک‌ها / انواع دارایی) ----------
    function csrf() {
        var el = document.querySelector('meta[name="csrf-token"]');
        return el ? el.content : '';
    }

    document.querySelectorAll('.js-ref-add').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var kind = this.getAttribute('data-kind');
            var input = document.getElementById(this.getAttribute('data-input'));
            if (!input) return;
            var name = input.value.trim();
            if (name === '') { alert('نام را وارد کنید.'); return; }

            var fd = new FormData();
            fd.append('csrf_token', csrf());
            fd.append('kind', kind);
            fd.append('action', 'add');
            fd.append('name', name);

            // دسته‌بندی درآمد و هزینه دو فهرست جدا هستند و سرور باید
            // بداند این نام برای کدام‌شان است
            var refType = this.getAttribute('data-type');
            if (refType) { fd.append('type', refType); }

            var unitInputId = this.getAttribute('data-unit-input');
            if (unitInputId) {
                var unitInput = document.getElementById(unitInputId);
                if (unitInput && unitInput.value.trim() !== '') {
                    fd.append('unit', unitInput.value.trim());
                }
            }

            // سمتِ شخص (همکار / مشتری / …). اختیاری است؛ سرور خالی را
            // «سایر» می‌گیرد.
            var roleInputId = this.getAttribute('data-role-input');
            if (roleInputId) {
                var roleInput = document.getElementById(roleInputId);
                if (roleInput && roleInput.value.trim() !== '') {
                    fd.append('role', roleInput.value.trim());
                }
            }

            fetch(apiUrl('manage_reference.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) { window.location.reload(); }
                    else { alert(d.message || 'خطایی رخ داد.'); }
                })
                .catch(function () { alert('خطا در ارتباط با سرور.'); });
        });
    });

    document.querySelectorAll('.js-ref-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('حذف شود؟')) return;

            var fd = new FormData();
            fd.append('csrf_token', csrf());
            fd.append('kind', this.getAttribute('data-kind'));
            fd.append('action', 'delete');
            fd.append('id', this.getAttribute('data-id'));

            var chip = this.closest('.ref-chip');

            fetch(apiUrl('manage_reference.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    // فقط برداشتن چیپ کافی نیست: فهرست «پیش‌فرض‌ها» و
                    // فرم‌های دیگرِ همین صفحه هم از همین داده ساخته شده‌اند
                    if (d.success) { if (chip) chip.remove(); window.location.reload(); }
                    else { alert(d.message || 'قابل حذف نیست.'); }
                })
                .catch(function () { alert('خطا در ارتباط با سرور.'); });
        });
    });

    // ---------- چک‌ها ----------
    setupAmountFormatter('add_cheque_amount');
    setupAmountFormatter('edit_cheque_amount');

    function fillBankSelect(selectEl, direction, selectedId) {
        if (!selectEl || !window.BANK_DATA) return;
        while (selectEl.options.length > 1) { selectEl.remove(1); }
        var list = window.BANK_DATA[direction === 'issued' ? 'mine' : 'external'] || [];
        list.forEach(function (b) {
            var opt = document.createElement('option');
            opt.value = b.id;
            opt.textContent = b.name;
            selectEl.appendChild(opt);
        });
        if (selectedId && String(selectedId) !== '0') { selectEl.value = String(selectedId); }
    }

    document.querySelectorAll('.js-add-cheque').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var direction = this.getAttribute('data-direction');
            document.getElementById('add_cheque_direction').value = direction;
            document.getElementById('addChequeTitle').textContent =
                direction === 'received' ? 'ثبت چک دریافتی' : 'ثبت چک صادره';

            document.getElementById('add_cheque_counterparty').value = '';
            document.getElementById('add_cheque_amount').value = '';
            document.getElementById('add_sayadi').value = '';
            document.getElementById('add_cheque_number').value = '';
            document.getElementById('add_cheque_note').value = '';

            var due = document.getElementById('add_cheque_due');
            due.value = '';
            due.closest('.jdp-field').querySelector('.jdp-display').value = '';

            fillBankSelect(document.getElementById('add_cheque_bank'), direction, null);

            var msg = document.getElementById('addChequeMessage');
            if (msg) { msg.hidden = true; msg.classList.remove('show', 'success', 'error'); }

            openModal('addChequeModal');
        });
    });

    function submitJson(form, url, msgEl, btnEl, amountFieldId, preparedFd) {
        var fd = preparedFd || new FormData(form);
        if (amountFieldId) {
            var amtEl = document.getElementById(amountFieldId);
            if (amtEl) fd.set('amount', toLatinDigitsJs(amtEl.value).replace(/[,\u066C]/g, ''));
        }
        if (btnEl) btnEl.disabled = true;

        return fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                msgEl.hidden = false;
                msgEl.classList.remove('success', 'error');
                if (d.success) {
                    msgEl.classList.add('show', 'success');
                    msgEl.textContent = d.message || 'انجام شد.';
                    window.location.reload();
                } else {
                    msgEl.classList.add('show', 'error');
                    msgEl.textContent = d.message || 'خطایی رخ داد.';
                }
            })
            .catch(function () {
                msgEl.hidden = false;
                msgEl.classList.add('show', 'error');
                msgEl.textContent = 'خطا در ارتباط با سرور.';
            })
            .finally(function () { if (btnEl) btnEl.disabled = false; });
    }

    var addChequeForm = document.getElementById('addChequeForm');
    if (addChequeForm) {
        addChequeForm.addEventListener('submit', function (e) {
            e.preventDefault();
            submitJson(addChequeForm, apiUrl('add_cheque.php'),
                document.getElementById('addChequeMessage'),
                document.getElementById('addChequeSubmitBtn'), 'add_cheque_amount');
        });
    }

    document.querySelectorAll('.js-edit-cheque').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('edit_cheque_id').value = this.getAttribute('data-id');
            syncPersonPicker('edit_cheque_counterparty', this.getAttribute('data-counterparty'));
            document.getElementById('edit_cheque_amount').value =
                toPersianDigitsJs(Number(this.getAttribute('data-amount')).toLocaleString('en-US').replace(/,/g, '\u066C'));
            document.getElementById('edit_sayadi').value = this.getAttribute('data-sayadi') || '';
            document.getElementById('edit_cheque_number').value = this.getAttribute('data-cheque-number') || '';
            document.getElementById('edit_cheque_note').value = this.getAttribute('data-note') || '';

            fillBankSelect(document.getElementById('edit_cheque_bank'),
                this.getAttribute('data-direction'), this.getAttribute('data-bank-id'));

            var dueVal = this.getAttribute('data-due-date');
            if (dueVal) {
                setJdpValue('edit_cheque_due_display', 'edit_cheque_due', dueVal);
            } else {
                document.getElementById('edit_cheque_due').value = '';
                document.getElementById('edit_cheque_due_display').value = '';
            }

            var msg = document.getElementById('editChequeMessage');
            if (msg) { msg.hidden = true; msg.classList.remove('show', 'success', 'error'); }

            openModal('editChequeModal');
        });
    });

    var editChequeForm = document.getElementById('editChequeForm');
    if (editChequeForm) {
        editChequeForm.addEventListener('submit', function (e) {
            e.preventDefault();
            submitJson(editChequeForm, apiUrl('update_cheque.php'),
                document.getElementById('editChequeMessage'),
                document.getElementById('editChequeSubmitBtn'), 'edit_cheque_amount');
        });
    }

    var chequeSettleModal = document.getElementById('chequeSettle');
    document.querySelectorAll('.js-toggle-cheque').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var el = this;
            if (chequeSettleModal && el.checked) {
                askSettleWallet('chequeSettle', el, {
                    hint: 'مبلغ چک در موجودی حساب انتخابی اعمال می‌شود.',
                    url: apiUrl('toggle_cheque_settled.php'),
                    idField: 'cheque_id'
                });
                return;
            }
            var fd = new FormData();
            fd.append('cheque_id', el.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            el.disabled = true;

            fetch(apiUrl('toggle_cheque_settled.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) { window.location.reload(); }
                    else { alert(d.message || 'خطایی رخ داد.'); el.checked = !el.checked; el.disabled = false; }
                })
                .catch(function () { alert('خطا در ارتباط با سرور.'); el.checked = !el.checked; el.disabled = false; });
        });
    });

    document.querySelectorAll('.js-delete-cheque').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('آیا از حذف این چک مطمئن هستید؟')) return;
            var card = this.closest('.debt-card');
            var fd = new FormData();
            fd.append('cheque_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());

            fetch(apiUrl('delete_cheque.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) { if (card) card.remove(); window.location.reload(); }
                    else { alert(d.message || 'خطا در حذف.'); }
                })
                .catch(function () { alert('خطا در ارتباط با سرور.'); });
        });
    });

    // ---------- دارایی‌ها ----------
    setupAmountFormatter('add_asset_price');
    setupAmountFormatter('edit_asset_price');

    var addAssetForm = document.getElementById('addAssetForm');
    if (addAssetForm) {
        addAssetForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(addAssetForm);
            fd.set('unit_price', toLatinDigitsJs(document.getElementById('add_asset_price').value).replace(/[,\u066C]/g, ''));
            fd.set('quantity', toLatinDigitsJs(document.getElementById('add_asset_qty').value).replace(/[,\u066C]/g, '').replace('٫', '.'));

            var msgEl = document.getElementById('addAssetMessage');
            var btnEl = document.getElementById('addAssetSubmitBtn');
            btnEl.disabled = true;

            fetch(apiUrl('add_asset.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    msgEl.hidden = false;
                    msgEl.classList.remove('success', 'error');
                    if (d.success) {
                        msgEl.classList.add('show', 'success');
                        msgEl.textContent = d.message || 'ثبت شد.';
                        window.location.reload();
                    } else {
                        msgEl.classList.add('show', 'error');
                        msgEl.textContent = d.message || 'خطایی رخ داد.';
                    }
                })
                .catch(function () {
                    msgEl.hidden = false;
                    msgEl.classList.add('show', 'error');
                    msgEl.textContent = 'خطا در ارتباط با سرور.';
                })
                .finally(function () { btnEl.disabled = false; });
        });
    }

    document.querySelectorAll('.js-edit-asset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('edit_asset_id').value = this.getAttribute('data-id');
            document.getElementById('edit_asset_qty').value = toPersianDigitsJs(this.getAttribute('data-quantity').replace(/\.?0+$/, '') || '0');
            var price = this.getAttribute('data-unit-price');
            document.getElementById('edit_asset_price').value =
                (price && price !== '0') ? toPersianDigitsJs(Number(price).toLocaleString('en-US').replace(/,/g, '\u066C')) : '';
            document.getElementById('edit_asset_note').value = this.getAttribute('data-note') || '';
            setJdpValue('edit_asset_date_display', 'edit_asset_date', this.getAttribute('data-entry-date'));

            var msg = document.getElementById('editAssetMessage');
            if (msg) { msg.hidden = true; msg.classList.remove('show', 'success', 'error'); }

            openModal('editAssetModal');
        });
    });

    var editAssetForm = document.getElementById('editAssetForm');
    if (editAssetForm) {
        editAssetForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(editAssetForm);
            fd.set('unit_price', toLatinDigitsJs(document.getElementById('edit_asset_price').value).replace(/[,\u066C]/g, ''));
            fd.set('quantity', toLatinDigitsJs(document.getElementById('edit_asset_qty').value).replace(/[,\u066C]/g, '').replace('٫', '.'));

            var msgEl = document.getElementById('editAssetMessage');
            var btnEl = document.getElementById('editAssetSubmitBtn');
            btnEl.disabled = true;

            fetch(apiUrl('update_asset.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    msgEl.hidden = false;
                    msgEl.classList.remove('success', 'error');
                    if (d.success) {
                        msgEl.classList.add('show', 'success');
                        msgEl.textContent = d.message || 'ذخیره شد.';
                        window.location.reload();
                    } else {
                        msgEl.classList.add('show', 'error');
                        msgEl.textContent = d.message || 'خطایی رخ داد.';
                    }
                })
                .catch(function () {
                    msgEl.hidden = false;
                    msgEl.classList.add('show', 'error');
                    msgEl.textContent = 'خطا در ارتباط با سرور.';
                })
                .finally(function () { btnEl.disabled = false; });
        });
    }

    document.querySelectorAll('.js-delete-asset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('آیا از حذف این مورد مطمئن هستید؟')) return;
            var row = this.closest('.tx-row');
            var fd = new FormData();
            fd.append('asset_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());

            fetch(apiUrl('delete_asset.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    // فقط برداشتن ردیف کافی نیست: جمع دارایی‌ها و ارزش کل
                    // بالای صفحه از روی همین رکوردها ساخته شده‌اند و
                    // بی‌به‌روزرسانی، عدد قدیمی را نشان می‌دادند — کاربر
                    // فکر می‌کرد حذف اصلاً انجام نشده.
                    if (d.success) {
                        if (row) row.remove();
                        window.location.reload();
                    } else { alert(d.message || 'خطا در حذف.'); }
                })
                .catch(function () { alert('خطا در ارتباط با سرور.'); });
        });
    });

    // ---------- دکمه‌ی بازگشت صفحه‌های فرعی ----------
    // اگر کاربر از داخل خود اپ آمده، برگردیم همان‌جا که بود؛ اگر مستقیم
    // این آدرس را باز کرده (یا از میان‌بر صفحه‌ی اصلی گوشی)، href صفحه‌ی
    // خانه جایگزین است.
    document.querySelectorAll('.js-page-back').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (window.history.length > 1 && document.referrer.indexOf(location.host) !== -1) {
                e.preventDefault();
                window.history.back();
            }
        });
    });

    // ---------- فیلترهای صفحه تراکنش‌ها ----------
    var filterChips = document.querySelectorAll('.filter-chip[data-filter], .seg-item[data-filter]');
    if (filterChips.length > 0) {
        filterChips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                var group = this.getAttribute('data-group');
                document.querySelectorAll('[data-group="' + group + '"]').forEach(function (c) {
                    c.classList.remove('active');
                });
                this.classList.add('active');

                var form = document.getElementById('filterForm');
                var hiddenInput = form.querySelector('input[name="' + group + '"]');
                if (hiddenInput) {
                    hiddenInput.value = this.getAttribute('data-filter');
                }
                form.submit();
            });
        });
    }

    // ---------- مودال‌های عمومی (افزودن/ویرایش) در صفحات مدیریت و ویرایش تراکنش ----------
    function openModal(id) {
        var modal = document.getElementById(id);
        if (modal) modal.classList.add('show');
    }
    function closeModal(id) {
        var modal = document.getElementById(id);
        if (modal) modal.classList.remove('show');
    }
    window.openModal = openModal;
    window.closeModal = closeModal;

    document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal(this.getAttribute('data-modal-open'));
        });
    });
    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var modal = btn.closest('.modal-overlay');
            if (modal) modal.classList.remove('show');
        });
    });
    document.querySelectorAll('.modal-overlay').forEach(function (overlay2) {
        overlay2.addEventListener('click', function (e) {
            if (e.target === overlay2) overlay2.classList.remove('show');
        });
    });

    // ---------- کارت‌های جمع‌شونده (داشبورد) ----------
    document.querySelectorAll('.collapsible-header').forEach(function (header) {
        header.addEventListener('click', function () {
            var card = header.closest('.collapsible-card');
            if (card) card.classList.toggle('collapsed');
        });
    });

    // ---------- ردیف فشرده تراکنش (باز/بسته شدن با کلیک) ----------
    document.querySelectorAll('.tx-row-summary').forEach(function (summary) {
        summary.addEventListener('click', function (e) {
            if (e.target.closest('button')) return;
            var row = summary.closest('.tx-row');
            if (row) row.classList.toggle('expanded');
        });
    });

    // ---------- پیش‌پر کردن فرم ویرایش کاربر (مدیریت کاربران) ----------
    document.querySelectorAll('.js-edit-user').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = document.getElementById('editUserForm');
            if (!form) return;
            form.querySelector('[name="user_id"]').value = this.getAttribute('data-id');
            form.querySelector('[name="full_name"]').value = this.getAttribute('data-full-name');
            form.querySelector('[name="username"]').value = this.getAttribute('data-username');
            var emailField = form.querySelector('[name="email"]');
            if (emailField) emailField.value = this.getAttribute('data-email') || '';
            form.querySelector('[name="role"]').value = this.getAttribute('data-role');
            form.querySelector('[name="password"]').value = '';
            form.querySelector('[name="password_confirm"]').value = '';
            openModal('editUserModal');
        });
    });

    // ---------- پیش‌پر کردن فرم ویرایش دسته‌بندی (مدیریت دسته‌بندی‌ها) ----------
    document.querySelectorAll('.js-edit-category').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = document.getElementById('editCategoryForm');
            if (!form) return;
            form.querySelector('[name="category_id"]').value = this.getAttribute('data-id');
            form.querySelector('[name="name"]').value = this.getAttribute('data-name');
            form.querySelector('[name="type"]').value = this.getAttribute('data-type');
            var iconEl = form.querySelector('[name="icon"]');
            var colorEl = form.querySelector('[name="color"]');
            if (iconEl) iconEl.value = this.getAttribute('data-icon') || '📌';
            if (colorEl) colorEl.value = this.getAttribute('data-color') || '#64748b';
            openModal('editCategoryModal');
        });
    });


    // ---------- کلید بخش معاملات در پروفایل ----------
    var tradesToggle = document.getElementById('tradesToggle');
    if (tradesToggle) {
        tradesToggle.addEventListener('change', function () {
            var fd = new FormData();
            fd.append('csrf_token', csrf());
            fd.append('enabled', this.checked ? '1' : '0');
            var msg = document.getElementById('tradesToggleMsg');
            fetch(apiUrl('toggle_trades.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (msg) {
                        msg.hidden = false;
                        msg.classList.remove('success', 'error');
                        msg.classList.add('show', d.success ? 'success' : 'error');
                        msg.textContent = d.message || (d.success ? 'ذخیره شد.' : 'خطا');
                    }
                    if (!d.success) { tradesToggle.checked = !tradesToggle.checked; return; }
                    // منوی «بیشتر» و نوار کناری با حالت قبلی رندر شده‌اند؛
                    // بی‌تازه‌سازی، دکمه‌ی معاملات تا ناوبری بعدی سر جایش
                    // می‌ماند (یا برعکس، غایب می‌ماند) و کاربر فکر می‌کند
                    // کلید کار نکرد.
                    window.location.reload();
                })
                .catch(function () {
                    tradesToggle.checked = !tradesToggle.checked;
                    if (msg) { msg.hidden = false; msg.classList.add('show', 'error'); msg.textContent = 'خطا در ارتباط با سرور.'; }
                });
        });
    }

    // ---------- نمای دارایی‌ها: روشن/خاموش کردن هر قلم ----------
    //
    // هر قلم (دارایی ثبت‌شده، کالای معاملاتی، مجموع حساب‌ها) یک کلید
    // دارد. خاموش کردنش چیزی را پاک نمی‌کند؛ فقط از جمع کل، درصدها و
    // نمودار بیرونش می‌گذارد — تا بشود پرسید «دارایی‌ام بدون طلا چقدر
    // است؟». انتخاب در همین مرورگر می‌ماند تا هر بار از نو نچینید.
    (function () {
        var list = document.getElementById('assetBreakdown');
        if (!list) { return; }

        var STORE = 'daftar_asset_off';
        var items = [].slice.call(list.querySelectorAll('.asset-item'));
        var totalEl = document.getElementById('assetGrandTotal');
        var noteEl = document.getElementById('assetExcludedNote');
        var unit = totalEl ? (totalEl.querySelector('small') ? totalEl.querySelector('small').textContent : '') : '';

        var off = {};
        try { off = JSON.parse(localStorage.getItem(STORE) || '{}') || {}; } catch (e) { off = {}; }

        var chart = null;
        var chartData = [];
        var dataEl = document.getElementById('assetPortfolioData');
        if (dataEl) {
            try { chartData = JSON.parse(dataEl.textContent) || []; } catch (e) { chartData = []; }
        }

        function money(n) {
            var neg = n < 0;
            var t = toPersianDigitsJs(Math.abs(n).toLocaleString('en-US').replace(/,/g, '\u066C'));
            return (neg ? '\u2212' : '') + t;
        }

        function isOn(key) { return !off[key]; }

        function refresh() {
            var total = 0, excluded = 0;
            items.forEach(function (el) {
                if (isOn(el.getAttribute('data-key'))) {
                    total += parseInt(el.getAttribute('data-value') || '0', 10);
                } else {
                    excluded++;
                }
            });

            if (totalEl) {
                totalEl.innerHTML = '';
                totalEl.appendChild(document.createTextNode(money(total) + ' '));
                var sm = document.createElement('small');
                sm.textContent = unit;
                totalEl.appendChild(sm);
            }

            if (noteEl) {
                noteEl.hidden = excluded === 0;
                noteEl.textContent = excluded === 0 ? ''
                    : toPersianDigitsJs(String(excluded)) + ' قلم از این محاسبه بیرون گذاشته شده.';
            }

            items.forEach(function (el) {
                var on = isOn(el.getAttribute('data-key'));
                var val = parseInt(el.getAttribute('data-value') || '0', 10);
                var pct = (on && total > 0 && val > 0) ? Math.round(val / total * 1000) / 10 : 0;
                el.classList.toggle('asset-item-off', !on);

                // درصد فقط وقتی معنا دارد که جمع کل مثبت باشد. با خالصِ منفیِ
                // بزرگ (بدهی بیشتر از دارایی) جمع منفی می‌شود و «۰٪» گمراه بود.
                var pctEl = el.querySelector('.cat-breakdown-pct');
                if (pctEl) { pctEl.textContent = (on && val > 0 && total > 0) ? toPersianDigitsJs(String(pct)) + '٪' : '—'; }

                var bar = el.querySelector('.cat-breakdown-bar');
                if (bar) { bar.style.width = (on ? pct : 0) + '%'; }
            });

            if (chart) {
                var keep = chartData.filter(function (d) { return isOn(d.key) && d.value > 0; });
                chart.data.labels = keep.map(function (d) { return d.name; });
                chart.data.datasets[0].data = keep.map(function (d) { return d.value; });
                repaintAssetChart(keep);
            }
        }

        function repaintAssetChart(keep) {
            var dark = document.documentElement.getAttribute('data-theme') === 'dark';
            var surface = getComputedStyle(document.documentElement)
                .getPropertyValue('--surface').trim() || '#ffffff';
            chart.data.datasets[0].backgroundColor = keep.map(function (d) { return dark ? d.cd : d.cl; });
            chart.data.datasets[0].borderColor = surface;
            chart.update('none');
        }

        items.forEach(function (el) {
            var box = el.querySelector('.js-asset-include');
            if (!box) { return; }
            var key = el.getAttribute('data-key');
            box.checked = isOn(key);
            box.addEventListener('change', function () {
                if (this.checked) { delete off[key]; } else { off[key] = 1; }
                try { localStorage.setItem(STORE, JSON.stringify(off)); } catch (e) {}
                refresh();
            });
        });

        var canvas = document.getElementById('assetChart');
        if (canvas && chartData.length) {
            var box = canvas.closest('.chart-container');
            // Chart.js ممکن است نرسیده باشد؛ در آن صورت به‌جای یک مستطیل
            // خالی، فهرست رنگی پایین به‌تنهایی کار می‌کند.
            if (!window.Chart) { if (box) box.hidden = true; }
            else {
                chart = new Chart(canvas.getContext('2d'), {
                    type: 'doughnut',
                    data: { labels: [], datasets: [{ data: [], borderWidth: 2 }] },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        cutout: '62%',
                        plugins: { legend: { display: false } }
                    }
                });
                // با عوض شدن حالت شب، رنگ قاچ‌ها هم باید عوض شود
                window.onThemeChange(function () {
                    var keep = chartData.filter(function (d) { return isOn(d.key) && d.value > 0; });
                    repaintAssetChart(keep);
                });
            }
        }

        refresh();
    })();

    // ---------- فیلتر فهرست دارایی‌ها ----------
    // وقتی اقلام زیاد می‌شوند، پیدا کردن یکی‌شان با اسکرول سخت است.
    var assetFilter = document.getElementById('assetFilter');
    if (assetFilter) {
        var assetItems = [].slice.call(document.querySelectorAll('#assetBreakdown .asset-item'));
        var noMatch = document.getElementById('assetNoMatch');
        assetFilter.addEventListener('input', function () {
            var q = toLatinDigitsJs(this.value).trim().toLowerCase();
            var shown = 0;
            assetItems.forEach(function (el) {
                var name = (el.getAttribute('data-name') || '').toLowerCase();
                var hit = q === '' || name.indexOf(q) !== -1;
                el.hidden = !hit;
                if (hit) shown++;
            });
            if (noMatch) noMatch.hidden = shown !== 0;
        });
    }

    // ---------- معاملات (خرید و فروش) ----------
    (function () {
        // روشن کردن بخش از صفحه‌ی خودش (وقتی خاموش است)
        var enableBtn = document.getElementById('enableTradesBtn');
        if (enableBtn) {
            enableBtn.addEventListener('click', function () {
                enableBtn.disabled = true;
                var fd = new FormData();
                fd.append('csrf_token', csrf());
                fd.append('enabled', '1');
                fetch(apiUrl('toggle_trades.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.success) { window.location.reload(); return; }
                        enableBtn.disabled = false;
                        var m = document.getElementById('enableTradesMsg');
                        if (m) { m.hidden = false; m.classList.add('show', 'error'); m.textContent = d.message || 'خطا'; }
                    })
                    .catch(function () { enableBtn.disabled = false; });
            });
        }

        var tradeForm = document.getElementById('tradeForm');
        var assetForm = document.getElementById('assetSellForm');
        // زبانه‌ی «دارایی» فرم خرید ندارد ولی فرم فروش دارایی دارد
        if (!tradeForm && !assetForm) return;

        // مبلغ‌ها هنگام تایپ فارسی و هزارگان‌دار می‌شوند — 1000000 ← ۱٬۰۰۰٬۰۰۰
        setupAmountFormatter('trade_buy_total');
        setupAmountFormatter('trade_side_costs');
        setupAmountFormatter('sell_total');

        // ---- حالت نمایش: کارتی / فهرستی (مثل حالت‌های نمایش ویندوز) ----
        var wrap = document.getElementById('tradesWrap');
        var viewBtns = document.querySelectorAll('.view-switch-btn');
        function applyViewMode(mode) {
            if (!wrap) return;
            wrap.classList.toggle('view-list', mode === 'list');
            viewBtns.forEach(function (b) {
                b.classList.toggle('active', b.getAttribute('data-view-mode') === mode);
            });
            try { localStorage.setItem('tradesViewMode', mode); } catch (e) {}
        }
        var savedMode = 'card';
        try { savedMode = localStorage.getItem('tradesViewMode') || 'card'; } catch (e) {}
        applyViewMode(savedMode);
        viewBtns.forEach(function (b) {
            b.addEventListener('click', function () { applyViewMode(this.getAttribute('data-view-mode')); });
        });
        // در حالت فهرستی، لمس ردیف جزئیاتش را باز می‌کند
        if (wrap) {
            wrap.addEventListener('click', function (e) {
                if (!wrap.classList.contains('view-list')) return;
                if (e.target.closest('button, a, input, select, textarea')) return;
                var card = e.target.closest('.trade-card');
                if (card) card.classList.toggle('expanded');
            });
        }

        function setJdpByHidden(hiddenEl, gDateStr) {
            if (!window.JalaliDatePicker || !gDateStr) return;
            var parts = gDateStr.split('-').map(Number);
            var j = window.JalaliDatePicker.gregorianToJalali(parts[0], parts[1], parts[2]);
            function pad2(n) { return (n < 10 ? '0' : '') + n; }
            hiddenEl.value = gDateStr;
            hiddenEl.closest('.jdp-field').querySelector('.jdp-display').value =
                window.JalaliDatePicker.toFa(j[0]) + '/' + window.JalaliDatePicker.toFa(pad2(j[1])) + '/' + window.JalaliDatePicker.toFa(pad2(j[2]));
        }
        // زبانه‌ی «دارایی» فرم خرید ندارد، پس تاریخ امروز را از هر فرمی که
        // در صفحه هست برمی‌داریم.
        var todayHidden = document.getElementById('trade_buy_date')
                       || document.getElementById('asset_sell_date');
        var todayG = todayHidden ? todayHidden.value : '';

        var addBtn = document.getElementById('addTradeBtn');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                document.getElementById('tradeModalTitle').textContent = 'خرید جدید';
                document.getElementById('trade_id').value = '';
                document.getElementById('trade_title').value = '';
                document.getElementById('trade_qty').value = '1';
                document.getElementById('trade_buy_total').value = '';
                document.getElementById('trade_side_costs').value = '';
                resetSelect(document.getElementById('trade_wallet'));
                document.getElementById('trade_notes').value = '';
                setJdpByHidden(document.getElementById('trade_buy_date'), todayG);
                var m = document.getElementById('tradeMessage');
                m.hidden = true; m.classList.remove('show', 'error', 'success');
                openModal('tradeModal');
            });
        }

        document.querySelectorAll('.js-edit-trade').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('tradeModalTitle').textContent = 'ویرایش معامله';
                document.getElementById('trade_id').value = this.getAttribute('data-id');
                document.getElementById('trade_title').value = this.getAttribute('data-title');
                document.getElementById('trade_qty').value = this.getAttribute('data-qty');
                document.getElementById('trade_buy_total').value = this.getAttribute('data-buy-total');
                var sc = this.getAttribute('data-side-costs');
                document.getElementById('trade_side_costs').value = sc === '0' ? '' : sc;
                document.getElementById('trade_wallet').value = this.getAttribute('data-wallet') || '0';
                document.getElementById('trade_notes').value = this.getAttribute('data-notes') || '';
                setJdpByHidden(document.getElementById('trade_buy_date'), this.getAttribute('data-buy-date'));
                var m = document.getElementById('tradeMessage');
                m.hidden = true; m.classList.remove('show', 'error', 'success');
                openModal('tradeModal');
            });
        });

        tradeForm.addEventListener('submit', function (e) {
            e.preventDefault();
            submitJson(tradeForm, apiUrl('save_trade.php'),
                document.getElementById('tradeMessage'),
                document.getElementById('tradeSubmitBtn'));
        });

        // ---- فروش ----
        var sellForm = document.getElementById('sellForm');
        document.querySelectorAll('.js-sell-trade').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var remaining = this.getAttribute('data-remaining') || '1';
                document.getElementById('sellModalTitle').textContent = 'فروش: ' + this.getAttribute('data-title');
                document.getElementById('sell_trade_id').value = this.getAttribute('data-id');
                document.getElementById('sell_total').value = '';
                document.getElementById('sell_qty').value = remaining;
                resetSelect(document.getElementById('sell_wallet'));
                document.getElementById('sell_notes').value = '';
                document.getElementById('sellRemainingHint').textContent =
                    remaining === '1' ? '' : 'مانده: ' + remaining;
                setJdpByHidden(document.getElementById('sell_date'), todayG);
                var m = document.getElementById('sellMessage');
                m.hidden = true; m.classList.remove('show', 'error', 'success');
                openModal('sellModal');
            });
        });
        if (sellForm) {
            sellForm.addEventListener('submit', function (e) {
                e.preventDefault();
                submitJson(sellForm, apiUrl('sell_trade.php'),
                    document.getElementById('sellMessage'),
                    document.getElementById('sellSubmitBtn'));
            });
        }

        // ---- فروش دارایی (زبانه‌ی «دارایی») ----
        var assetSellForm = document.getElementById('assetSellForm');
        document.querySelectorAll('.js-sell-asset').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var remaining = this.getAttribute('data-remaining') || '1';
                var unit = this.getAttribute('data-unit') || '';
                document.getElementById('assetSellTitle').textContent = 'فروش: ' + this.getAttribute('data-title');
                document.getElementById('asset_sell_id').value = this.getAttribute('data-id');
                document.getElementById('asset_sell_total').value = '';
                document.getElementById('asset_sell_qty').value = remaining;
                resetSelect(document.getElementById('asset_sell_wallet'));
                document.getElementById('asset_sell_notes').value = '';
                document.getElementById('assetSellRemaining').textContent = 'موجودی: ' + remaining + ' ' + unit;
                setJdpByHidden(document.getElementById('asset_sell_date'), todayG);
                var m = document.getElementById('assetSellMessage');
                m.hidden = true; m.classList.remove('show', 'error', 'success');
                openModal('assetSellModal');
            });
        });
        if (assetSellForm) {
            setupAmountFormatter('asset_sell_total');
            assetSellForm.addEventListener('submit', function (e) {
                e.preventDefault();
                submitJson(assetSellForm, apiUrl('sell_asset.php'),
                    document.getElementById('assetSellMessage'),
                    document.getElementById('assetSellSubmitBtn'));
            });
        }

        // ---- حذف ----
        document.querySelectorAll('.js-del-trade').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm('این معامله با همه‌ی فروش‌هایش حذف شود؟ قابل بازگشت نیست.')) return;
                var fd = new FormData();
                fd.append('csrf_token', csrf());
                fd.append('trade_id', this.getAttribute('data-id'));
                fetch(apiUrl('delete_trade.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { if (d.success) { window.location.reload(); } else { alert(d.message || 'خطا'); } })
                    .catch(function () { alert('خطا در ارتباط با سرور.'); });
            });
        });
        document.querySelectorAll('.js-del-sale').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm('این فروش حذف شود؟')) return;
                var fd = new FormData();
                fd.append('csrf_token', csrf());
                fd.append('sale_id', this.getAttribute('data-id'));
                fetch(apiUrl('delete_trade_sale.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { if (d.success) { window.location.reload(); } else { alert(d.message || 'خطا'); } })
                    .catch(function () { alert('خطا در ارتباط با سرور.'); });
            });
        });
    })();

});
