<?php
/**
 * اسکریپت کوچک صفحه‌های ورود/بازیابی.
 *
 * چرا لازم شد: فرستادن ایمیل از سرور حدود دو ثانیه طول می‌کشد (اتصال
 * SMTP، STARTTLS، احراز هویت). در آن دو ثانیه صفحه هیچ واکنشی نشان
 * نمی‌داد، پس کاربر فکر می‌کرد دکمه کار نکرده و دوباره می‌زد. هر بار
 * زدن یک درخواست تازه است و سقف «۳ درخواست در ساعت» را می‌سوزاند —
 * یعنی کاربری که فقط بی‌تاب بود، خودش را از بازیابی محروم می‌کرد.
 *
 * این صفحه‌ها app.js را لود نمی‌کنند (عمداً سبک‌اند)، پس اسکریپت
 * همین‌جا و کوچک است. بدون جاوااسکریپت هم فرم مثل قبل کار می‌کند —
 * فقط این بازخورد را ندارد.
 */
?>
<script>
(function () {
    var form = document.querySelector('form.auth-form');
    if (!form) return;

    var btn = form.querySelector('button[type="submit"]');
    if (!btn) return;

    var busy = false;
    form.addEventListener('submit', function (e) {
        // اگر مرورگر ورودی الزامی را نپذیرفته، هنوز چیزی فرستاده نشده
        if (form.checkValidity && !form.checkValidity()) { return; }

        if (busy) { e.preventDefault(); return; }
        busy = true;

        btn.disabled = true;
        btn.dataset.label = btn.textContent;
        btn.textContent = btn.getAttribute('data-busy') || 'لطفاً صبر کنید…';

        // اگر کاربر با دکمه‌ی بازگشت مرورگر برگشت، دکمه نباید قفل بماند
        window.addEventListener('pageshow', function () {
            busy = false;
            btn.disabled = false;
            if (btn.dataset.label) { btn.textContent = btn.dataset.label; }
        });
    });
})();

// شمارش معکوس «دوباره بفرستید» — تا کاربر پشت سر هم درخواست ندهد
(function () {
    var link = document.getElementById('resendLink');
    var note = document.getElementById('resendNote');
    if (!link || !note) return;

    var left = parseInt(link.getAttribute('data-wait') || '60', 10);
    link.hidden = true;

    // ارقام فارسی، مثل بقیه‌ی اپ
    function fa(n) {
        return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
    }

    function tick() {
        if (left <= 0) {
            note.hidden = true;
            link.hidden = false;
            return;
        }
        note.textContent = 'اگر تا چند دقیقه چیزی نرسید، ' + fa(left) + ' ثانیه دیگر می‌توانید دوباره درخواست بدهید.';
        left--;
        setTimeout(tick, 1000);
    }
    tick();
})();
</script>
