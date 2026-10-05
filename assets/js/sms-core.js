/**
 * ⛔ هسته‌ی پیامکِ بانک — **تنها** پارسرِ پیامک در کلِ پروژه.
 *
 * از `app.js` جدا شد (مهر ۱۴۰۵) تا **دو** مصرف‌کننده یک فایل را بخوانند،
 * نه دو نسخه:
 *   ۱. خودِ سایت (`header.php` پیش از `app.js` بارش می‌کند).
 *   ۲. کارگرِ پس‌زمینه‌ی اپ اندروید (`assets/sms-worker.html` در یک WebViewِ
 *      نامرئی): پیامکی که می‌رسد **بی‌باز کردنِ اپ** ثبت می‌شود. همین فایل از
 *      همین دامنه گرفته می‌شود، پس هر اصلاحِ پارسر بی‌APKِ تازه به گوشی هم
 *      می‌رسد و هیچ پارسرِ دومی (جاوا) ساخته نشد — قاعده ۱۲.
 *
 * ⛔ خالص و بی‌DOM: هیچ چیزی جز `window.JalaliDatePicker` (تاریخ) نمی‌خواند.
 *    `smsWorker()` (پایینِ فایل) فقط همین توابع را کنارِ هم می‌گذارد.
 */

/* ============================================================
   خواندنِ پیامکِ بانک
   ------------------------------------------------------------
   داده‌ی واقعیِ کاربر ایرانی در پیامکِ بانک است، ولی PWA و TWA هیچ
   دسترسی‌ای به SMS ندارند. همین محدودیت به سودِ ماست: کاربر پیامک را
   می‌چسباند و **هیچ چیزی به سرور نمی‌رود** — فقط فیلدهای همین فرم پر
   می‌شوند. اگر روزی وسوسه شدید این را به یک اندپوینت بدهید، بدانید که
   دارید متنِ خامِ پیامکِ بانکِ کاربر را روی سیم می‌فرستید.

   ⛔ این تابع عمداً **خالص** است: هیچ DOM ای نمی‌خواند و نمی‌نویسد،
      پس در node آزمودنی است (tests/test_sms_parse.php). خرابیِ یک
      پارسر بی‌صداست — مبلغِ «مانده» را به‌جای مبلغِ تراکنش برمی‌دارد و
      کاربر تازه در گزارشِ آخر ماه می‌فهمد.

   ⛔ واحد پول: پیامکِ بانکِ ایرانی عملاً همیشه ریال است و اپ تومان.
      «تومان» و «تومن»ِ صریح از تقسیم معاف‌اند (بلوبانک تومان
      می‌نویسد)؛ در هر حالتِ دیگر — از جمله وقتی واحد اصلاً در متن
      نیست — عدد بر ۱۰ تقسیم می‌شود و پیام روی صفحه می‌گوید که ریال
      فرض شده. نسخه‌ی قبلی در نبودِ واحد دست نمی‌زد تا «حدس نزند»، ولی
      آن هم خودش یک حدس بود — فقط ساکت‌تر و عملاً همیشه غلط.
   ============================================================ */
(function () {
    'use strict';

    // ⚠ بانک‌های تازه ادبیاتِ خودشان را دارند و با فهرستِ رسمی گرفته
    //   نمی‌شوند. بلوبانک به‌جای «برداشت» می‌نویسد «پرید» و به‌جای
    //   «واریز» می‌نویسد «نشست». پیامکش کاملاً معتبر است ولی پارسر
    //   هیچ کلمه‌ی جهتی پیدا نمی‌کرد و می‌گفت «تراکنش نیست» — یعنی
    //   قابلیت برای کاربرِ آن بانک اصلاً کار نمی‌کرد.
    // ⚠ کلمه‌های انگلیسی (پیامکِ انگلیسیِ ملت، پاسارگاد، …) کوچک‌حرف‌اند،
    //   چون `normalize()` متن را کوچک می‌کند.
    var OUT_WORDS = ['برداشت', 'خرید', 'خريد', 'پرداخت', 'انتقال به', 'کسر',
                     'بدهکار', 'حواله', 'قسط', 'پرید', 'کم شد',
                     'withdraw', 'purchase', 'payment', 'debited'];
    var IN_WORDS  = ['واریز', 'واريز', 'دریافت', 'دريافت', 'وصول', 'بستانکار',
                     'عودت', 'انتقال از', 'نشست', 'اضافه شد',
                     'deposit', 'credited', 'received'];
    // ⛔ عبارتِ «شما»دار **بر هر کلمه‌ی دیگری مقدم است**: «انتقال از حساب
    //    شما به حساب ۱۲۳» برداشت است، ولی کلمه‌ی «انتقال از» در فهرستِ
    //    واریز است و چون زودتر می‌آمد پیامک **واریز** خوانده می‌شد — پولی
    //    که رفته بود، در دفتر آمده ثبت می‌شد.
    var OUT_STRONG = ['از حساب شما', 'از کارت شما', 'از حسابت', 'از کارتت', 'از سپرده شما'];
    var IN_STRONG  = ['به حساب شما', 'به کارت شما', 'به حسابت', 'به کارتت', 'به سپرده شما'];
    // اعدادی که کنارِ این کلمه‌ها می‌آیند مبلغِ تراکنش **نیستند**.
    var NOT_AMOUNT = ['مانده', 'موجودی', 'موجودي', 'باقیمانده', 'باقيمانده', 'اعتبار', 'سقف',
                      'balance', 'avail'];

    // ⛔ رمزِ یک‌بارمصرف هرگز تراکنش نیست، حتی وقتی «خرید» و «مبلغ» دارد:
    //    «رمز پویا برای خرید ۱,۰۰۰,۰۰۰ ریال: ۴۸۲۹۱۳» پیش از پرداخت می‌رسد و
    //    پرداخت شاید هرگز انجام نشود. ثبتِ خودکارش یعنی هزینه‌ای که نبوده.
    var OTP_RE = /رمز\s*(پویا|دوم|یک\s*بار)|کد\s*(تایید|تأیید|فعال\s*سازی|امنیتی|یک\s*بار)|one[\s\-]?time|\botp\b|verification code/;
    // ⛔ آگهیِ بانک («جشنواره… با خرید بالای ۱,۰۰۰,۰۰۰ تومان») کلمه‌ی جهت و
    //    مبلغ و واحد دارد، یعنی هر سه نشانه‌ی تراکنش را.
    var PROMO_RE = /جشنواره|جایزه|قرعه|تخفیف|لغو\s*\d{2}|کلیک کنید/;
    var RIAL_RE  = /^[\s:：]*(ریال|ريال|irr|rls|rial)(?![a-z])/;
    var TOMAN_RE = /^[\s:：]*(تومان|تومن|irt|toman)(?![a-z])/;

    // ⛔ عددی که **بلافاصله** بعد از یکی از این برچسب‌ها می‌آید یک
    //    شناسه است، نه پول: «حساب: 0377803217328»، «پیگیری: 123456».
    //
    //    **گزارشِ مالکِ نصب (پیامکِ تجارت):** «حساب: 0377803217328 /
    //    برداشت: 209,000 ریال» — شماره‌ی حساب **یک کاراکتر** به کلمه‌ی
    //    «برداشت» نزدیک‌تر بود تا خودِ مبلغ، پس به‌عنوان مبلغ نشست:
    //    ۳۷٬۷۸۰٬۳۲۱٬۷۳۳ تومان. رتبه‌بندیِ فاصله درست کار کرده بود؛ خطا
    //    این بود که آن عدد اصلاً نامزد نباید می‌شد.
    // ⚠ فقط وقتی که برچسب **دقیقاً پیش از** عدد است (فقط `:`/فاصله
    //   بینشان): «انتقال به حساب مبلغ 500,000» مبلغ را از دست نمی‌دهد.
    var ID_LABEL = /(حساب|شماره|کارت|شبا|پیگیری|رهگیری|مرجع|ارجاع|سند|ترمینال|پایانه|کد|acc|account|card|ref|trace|terminal|no\.?)[\s:：#\-]*$/;

    function normalize(s) {
        s = String(s || '');
        // ارقام فارسی و عربی → لاتین
        s = s.replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 0x06F0; })
             .replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 0x0660; });
        // ی و ک عربی → فارسی، نیم‌فاصله → فاصله
        // ⚠ «−» (منهای یونیکد) → «-»: پیامکِ بعضی بانک‌ها علامتِ برداشت را
        //   با همان می‌نویسد. و کوچک‌حرف برای پیامکِ انگلیسی.
        return s.replace(/ي/g, 'ی').replace(/ك/g, 'ک')
                .replace(/‌/g, ' ').replace(/[−–]/g, '-').toLowerCase();
    }

    /** نخستین جایی که یکی از کلمه‌های فهرست دیده می‌شود (یا 1-). */
    function firstIndexOf(text, words) {
        var best = -1;
        for (var i = 0; i < words.length; i++) {
            var at = text.indexOf(words[i]);
            if (at !== -1 && (best === -1 || at < best)) { best = at; }
        }
        return best;
    }

    /**
     * تبدیل تاریخِ شمسیِ داخلِ پیامک به میلادی.
     * ⚠ از همان jalali-datepicker.js استفاده می‌کند، نه یک تبدیلِ
     *   تازه — وگرنه می‌شد پیاده‌سازیِ سومِ تقویم، بیرون از پوششِ
     *   test_jalali_parity، و دیر یا زود یک روز اختلاف پیدا می‌کرد.
     */
    function jalaliToIso(jy, jm, jd) {
        var api = (typeof window !== 'undefined') && window.JalaliDatePicker;
        if (!api || !api.jalaliToGregorian) { return null; }
        if (jm < 1 || jm > 12 || jd < 1 || jd > 31) { return null; }
        var g = api.jalaliToGregorian(jy, jm, jd);
        var back = api.gregorianToJalali(g[0], g[1], g[2]);
        // رفت‌وبرگشت نخواند یعنی تاریخ اصلاً وجود ندارد (۳۱ آبان)
        if (back[0] !== jy || back[1] !== jm || back[2] !== jd) { return null; }
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        return g[0] + '-' + p(g[1]) + '-' + p(g[2]);
    }

    /** تاریخِ میلادیِ پیامکِ انگلیسی («2024-10-03») — با رفت‌وبرگشت. */
    function gregorianIso(y, mo, d) {
        if (mo < 1 || mo > 12 || d < 1 || d > 31) { return null; }
        var dt = new Date(Date.UTC(y, mo - 1, d));
        if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== mo - 1 || dt.getUTCDate() !== d) { return null; }
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        return y + '-' + p(mo) + '-' + p(d);
    }

    function findDate(text) {
        var re = /(\d{2,4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})(?!\d)/g, m, iso;
        while ((m = re.exec(text)) !== null) {
            var jy = parseInt(m[1], 10);
            if (jy < 100) { jy += 1400; }          // ۰۳/۰۶/۱۲
            iso = null;
            if (jy >= 1300 && jy <= 1500) {
                iso = jalaliToIso(jy, parseInt(m[2], 10), parseInt(m[3], 10));
            } else if (jy >= 2000 && jy <= 2100 && m[1].length === 4) {
                iso = gregorianIso(jy, parseInt(m[2], 10), parseInt(m[3], 10));
            }
            if (iso) { return { iso: iso, at: m.index, len: m[0].length }; }
        }
        // ⚠ شکلِ فشرده‌ی پارسیان و چند بانکِ دیگر: «14050712 13:00». فقط
        //   هشت رقمِ تنها که با ۱۳/۱۴ شروع شود و برچسبِ شناسه پیشش نباشد
        //   — شماره‌ی حسابِ هشت‌رقمی همیشه برچسبِ «حساب» دارد.
        var c = /(^|[^\d])(1[34]\d{2})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])(?![\d,،٬])/g;
        while ((m = c.exec(text)) !== null) {
            var at = m.index + m[1].length;
            if (ID_LABEL.test(text.slice(Math.max(0, at - 22), at))) { continue; }
            iso = jalaliToIso(parseInt(m[2], 10), parseInt(m[3], 10), parseInt(m[4], 10));
            if (iso) { return { iso: iso, at: at, len: 8 }; }
        }
        return null;
    }

    // شکل‌های رایجِ شماره‌ی کارت در پیامک بانک. جداکننده‌ی سه‌رقمیِ
    // مبلغ (`,` و `٬`) عمداً در هیچ‌کدام نیست، و `/` هم نیست تا تاریخ
    // سالم بماند.
    var CARD_MASKS = [
        /(?:\d{2,6}[\s\-]){0,3}\d{0,6}[*.•x×]{2,}[\s\-]*\d{0,6}/g,  // 6037-9975-****-1234
        /\d{4}[\s\-]\d{4}[\s\-]\d{4}[\s\-]\d{4}/g,                  // 6037 9975 1111 1234
        /\d{16}/g                                                    // 6037997511111234
    ];

    /**
     * ⛔ آیا شماره‌ای که در `at` است مالِ **طرفِ مقابل** است؟
     *
     *    «انتقال از حساب شما **به حساب** 1234567» — آن ۱۲۳۴۵۶۷ حسابِ گیرنده
     *    است، نه حسابِ کاربر. پیش از این همان را چهار رقمِ حساب می‌خواند و
     *    اگر کاربر حسابی با همان چهار رقم داشت، برداشت روی **آن** حساب
     *    می‌نشست. قاعده: در برداشت «به …» مقصد است و در واریز «از …» مبدأ؛
     *    «واریز به حساب: ۱۲۳۴» (حسابِ خودِ کاربر) و «برداشت از حساب» دست
     *    نمی‌خورند.
     */
    function isCounterparty(text, at, type) {
        var head = text.slice(Math.max(0, at - 24), at);
        var re = type === 'expense' ? /(^|[\s:])(به|to)\s*(حساب|کارت|سپرده|شبا|acc|account|card)([^\n]*)$/
               : (type === 'income' ? /(^|[\s:])(از|from)\s*(حساب|کارت|سپرده|شبا|acc|account|card)([^\n]*)$/ : null);
        var m = re ? head.match(re) : null;
        if (!m) { return false; }
        // «از حساب شما به حساب ۱۲۳» — **نزدیک‌ترین** برچسب تصمیم می‌گیرد: اگر
        // حرفِ اضافه‌ی دیگری («کارت به کارت **از** ۶۰۳۷…») میانِ این برچسب و
        // عدد است، برچسبِ آن.
        return !/(^|\s)(از|به|from|to)(\s|$)/.test(m[4]);
    }

    function findCardTail(text, type) {
        // 6037****1234 یا ...1234 یا «کارت 1234»
        // ⚠ ماسکی که برچسبِ «حساب» دارد («حساب: ...4455») شماره‌ی حساب
        //   است نه کارت — `findAcctTail()` آن را می‌خواند.
        var tries = [
            /[*.•x×]{2,}[\s\-]*(\d{4})(?!\d)/g,
            /(?:کارت|card)[^\d\n]{0,12}(\d{4})(?!\d)/g,
            /(?:\d{4}[\s\-]){3}(\d{4})(?!\d)|\d{12}(\d{4})(?!\d)/g
        ];
        for (var i = 0; i < tries.length; i++) {
            var re = tries[i], m;
            while ((m = re.exec(text)) !== null) {
                // ⚠ آغازِ کلِ شماره، نه ماسک: «به کارت 6037-99**-****-1234»
                var start = m.index;
                while (start > 0 && /[\d\s\-]/.test(text.charAt(start - 1)) && text.charAt(start - 1) !== '\n') { start--; }
                var head = text.slice(Math.max(0, start - 16), start);
                if (i === 0 && /(حساب|acc|account|a\/c)[^\n]*$/.test(head) && !/(کارت|card)[^\n]*$/.test(head)) { continue; }
                if (isCounterparty(text, start, type)) { continue; }
                return m[1] || m[2];
            }
        }
        return null;
    }

    /**
     * چهار رقمِ آخرِ **شماره‌ی حساب** («حساب: 0377803217328» یا
     * «حساب 0377***7328»). همان نقشِ `findCardTail` را برای بانک‌هایی
     * دارد که پیامکشان به‌جای کارت، حساب را می‌گوید (تجارت، ملی، …).
     * ⚠ جداکننده‌ی سه‌رقمی عمداً در کلاسِ کاراکتر نیست: «مانده حساب:
     *   50,000,000» یک مبلغ است، نه شماره‌ی حساب.
     */
    function findAcctTail(text, type) {
        var re = /(?:حساب|acc|account|a\/c)[^\d\n,،٬*•x×]{0,10}([\d*.•x×][\d\-.*•x×]{4,30}\d)(?![\d,،٬])/g, m;
        while ((m = re.exec(text)) !== null) {
            if (isCounterparty(text, m.index + m[0].length - m[1].length, type)) { continue; }
            var groups = m[1].match(/\d+/g) || [];
            var last = groups.length ? groups[groups.length - 1] : '';
            return last.length < 4 ? null : last.slice(-4);
        }
        return null;
    }

    /**
     * «مانده»ی حساب بعد از همین تراکنش، به **تومان** — یا `null`.
     *
     * ⛔ `known` فقط وقتی درست است که واحد روشن باشد (کنارِ خودِ مانده، یا
     *    کنارِ مبلغِ همین پیامک). هم‌ترازیِ موجودیِ حساب روی همین عدد
     *    سوار است؛ با واحدِ حدسی، موجودیِ حساب ده‌برابر می‌شد.
     */
    function findBalance(text, amountUnit) {
        var re = /(مانده|موجودی|باقیمانده|balance|avail)[^\d\n\-]{0,16}(-?)\s*(\d{1,3}(?:[,،٬]\d{3})+|\d+)(\s*[:：]?\s*(ریال|ريال|تومان|تومن|irr|rls|rial|irt|toman)(?![a-z]))?/;
        var m = text.match(re);
        if (!m) { return null; }
        var val = parseInt(m[3].replace(/[,،٬]/g, ''), 10);
        if (isNaN(val)) { return null; }
        var tail = text.slice(m.index + m[0].length, m.index + m[0].length + 12);
        var neg = m[2] !== '' || /^\s*(منفی|بدهکار|-)/.test(tail);
        var unit = m[5] ? (/تومان|تومن|irt|toman/.test(m[5]) ? 'toman' : 'rial') : amountUnit;
        if (unit !== 'toman') { val = Math.round(val / 10); }
        return { value: neg ? -val : val, known: unit === 'toman' || unit === 'rial' };
    }

    /**
     * شماره‌ی کارت را با فاصله جای‌گزین می‌کند تا ارقامش به‌عنوان مبلغ
     * خوانده نشوند. طولِ متن دست‌نخورده می‌ماند، پس اندیسِ تاریخ که
     * روی متنِ اصلی حساب شده هنوز معتبر است.
     *
     * ⚠ بدون این، «واریز به 6037-9975-****-1234 مبلغ 500,000 ریال»
     *   عددِ 6037 را برمی‌داشت: چهار رقمِ اولِ کارت هم ≥ ۱۰۰۰ است و هم
     *   بلافاصله بعد از کلمه‌ی جهت می‌آید، یعنی هر دو نشانه‌ی «مبلغ» را
     *   دارد.
     */
    function blankCards(text) {
        var blank = function (s) { return new Array(s.length + 1).join(' '); };
        for (var i = 0; i < CARD_MASKS.length; i++) {
            text = text.replace(CARD_MASKS[i], blank);
        }
        return text;
    }

    /** واحدِ پولی که بلافاصله بعد از عدد آمده: `'rial'`، `'toman'` یا `null`. */
    function unitAfter(after) {
        return RIAL_RE.test(after) ? 'rial' : (TOMAN_RE.test(after) ? 'toman' : null);
    }

    /**
     * ⛔ مبلغِ **علامت‌دار** — تنها نشانه‌ی جهت در پیامکِ پاسارگاد، مهر و
     *    «انتقال»ِ ملت: «-1,250,000» یا «2,000,000-» یا «+5,000,000».
     *
     * ⚠ علامت فقط وقتی جهت است که عدد **سه‌رقم‌جدا** باشد و علامت به رقمِ
     *   دیگری نچسبیده باشد: «849-800-1234567» (شماره‌ی حساب)، «0705-12:30»
     *   (تاریخ و ساعت) و «-12:30» هیچ‌کدام جداکننده ندارند. و عددِ کنارِ
     *   «مانده» حذف است — «مانده: -1,000,000» بدهیِ حساب است، نه برداشت.
     *
     * @returns {?{type:string, value:number, at:number, len:number, unit:?string}}
     */
    function findSigned(text) {
        var scan = blankCards(text);
        var re = /(^|[^\d+\-])([+\-])\s?(\d{1,3}(?:[,،٬]\d{3})+)(?![\d,،٬])|(^|[^\d,،٬])(\d{1,3}(?:[,،٬]\d{3})+)\s?([+\-])(?![\d])/g, m;
        while ((m = re.exec(scan)) !== null) {
            var lead = m[2] !== undefined;
            var num = lead ? m[3] : m[5];
            var sign = lead ? m[2] : m[6];
            var at = scan.indexOf(num, m.index);
            var before = scan.slice(Math.max(0, at - 22), at);
            if (firstIndexOf(before, NOT_AMOUNT) !== -1 || ID_LABEL.test(before.replace(/[+\-]\s*$/, ''))) { continue; }
            var val = parseInt(num.replace(/[,،٬]/g, ''), 10);
            if (!val) { continue; }
            return { type: sign === '-' ? 'expense' : 'income', value: val, at: at, len: num.length,
                     unit: unitAfter(scan.slice(at + num.length, at + num.length + 14).replace(/^\s?[+\-]/, '')) };
        }
        return null;
    }

    /**
     * انتخابِ مبلغ از میانِ همه‌ی عددهای پیامک.
     *
     * ⚠ خطرناک‌ترین بخش: پیامکِ بانک چند عدد دارد (مبلغ، مانده، شماره‌ی
     *   حساب، چهار رقمِ کارت، تاریخ، ساعت، کد پیگیری). برداشتنِ «مانده»
     *   یا شماره‌ی حساب به‌جای مبلغ هیچ خطایی نمی‌دهد و فقط عددِ غلط
     *   ثبت می‌شود.
     *
     * ⛔ به همین دلیل هیچ عددی «به‌طور پیش‌فرض» مبلغ نیست: باید دست‌کم
     *   یکی از سه نشانه را داشته باشد — جداکننده‌ی سه‌رقمی، واحدِ پول
     *   کنارش، یا نزدیکیِ کلمه‌ی جهت/«مبلغ». شماره‌ی حسابِ ده‌رقمی هیچ
     *   کدام را ندارد.
     */
    function findAmount(text, dirAt, dateRange) {
        var scan = blankCards(text);
        var re = /\d{1,3}(?:[,،٬]\d{3})+|\d+/g, m, cands = [];
        while ((m = re.exec(scan)) !== null) {
            var at = m.index, len = m[0].length;
            if (dateRange && at >= dateRange.at && at < dateRange.at + dateRange.len) { continue; }
            var val = parseInt(m[0].replace(/[,،٬]/g, ''), 10);
            if (!val || val < 1000) { continue; }        // ساعت و روز و ماه

            var before = scan.slice(Math.max(0, at - 22), at);
            var after  = scan.slice(at + len, at + len + 14);
            if (firstIndexOf(before, NOT_AMOUNT) !== -1) { continue; }
            if (ID_LABEL.test(before)) { continue; }

            var unit = unitAfter(after);
            var grouped = /[,،٬]/.test(m[0]);
            // ⚠ عددِ ده‌رقمی به بالا بی‌جداکننده و بی‌واحد (و بی‌«مبلغ»)
            //   شماره‌ی حساب یا کدِ پیگیری است، حتی اگر برچسبش نیامده
            //   باشد: هیچ بانکی مبلغِ میلیاردی را بدونِ جداکننده و بدونِ
            //   «ریال» نمی‌نویسد.
            if (!grouped && !unit && len >= 10 && before.indexOf('مبلغ') === -1) { continue; }
            // ⛔ آنچه مبلغ را از بقیه‌ی عددها جدا می‌کند **فاصله** است،
            //    نه اینکه قبل یا بعدِ کلمه‌ی جهت باشد.
            //
            //    بانک‌های سنتی می‌نویسند «برداشت ۵۰۰,۰۰۰» ولی بلوبانک
            //    می‌نویسد «۵۰۰,۰۰۰ تومان پرید» — عدد پیش از کلمه‌ی جهت.
            //    پس شرطِ یک‌طرفه‌ی قبلی برای آن بانک کار نمی‌کرد.
            //
            //    ⚠ دو راهِ ساده‌تر را آزمودم و هر دو غلط بودند:
            //      • دوطرفه‌ی هم‌ارزش → در «کارمزد ۵,۰۰۰ ریال، واریز
            //        ۴۵,۰۰۰,۰۰۰ ریال» کارمزد برنده می‌شد (زودتر در متن).
            //      • «بعد همیشه مقدم بر قبل» → در «۲۵۰,۰۰۰ تومان پرید.
            //        کارمزد ۵,۰۰۰ تومان» کارمزد برنده می‌شد.
            //    فاصله هر دو را درست جواب می‌دهد، چون در هر دو متن مبلغِ
            //    اصلی به کلمه‌ی جهت **چسبیده** و کارمزد یک عبارت دورتر
            //    است.
            var dist = dirAt === -1 ? 9999
                     : (at > dirAt ? at - dirAt : dirAt - (at + len));
            if (dist < 0) { dist = 0; }
            var near = dirAt !== -1 && dist <= 24;
            // «مبلغ ۵۰۰۰۰۰» حتی اگر از کلمه‌ی جهت دور باشد، خودش
            // صریح‌ترین نشانه است.
            if (before.indexOf('مبلغ') !== -1) { near = true; dist = 0; }

            if (!grouped && !unit && !near) { continue; }

            cands.push({ value: val, at: at, unit: unit, grouped: grouped,
                         near: near, dist: dist });
        }
        if (!cands.length) { return null; }

        // اولویت: نزدیکِ کلمه‌ی جهت → کنارِ واحد پول → نزدیک‌تر →
        // سه‌رقم‌جداشده → زودتر در متن.
        //
        // ⚠ «کنارِ واحد پول» **پیش از** فاصله است، و این یک باگِ واقعی
        //   بود: عددی که «ریال» کنارش نوشته شده قوی‌ترین نشانه‌ی مبلغ
        //   است و نباید به عددِ بی‌واحدی ببازد که فقط یک کاراکتر
        //   نزدیک‌تر است. دو حالتِ آینه‌ای (کارمزد پیش و پس از مبلغ) هر
        //   دو واحد دارند، پس فاصله همچنان بینشان داوری می‌کند.
        return cands.slice().sort(function (a, b) {
            if (a.near !== b.near)     { return a.near ? -1 : 1; }
            if (!!a.unit !== !!b.unit) { return a.unit ? -1 : 1; }
            if (a.dist !== b.dist)     { return a.dist - b.dist; }
            if (a.grouped !== b.grouped) { return a.grouped ? -1 : 1; }
            return a.at - b.at;
        })[0];
    }

    /**
     * @param {string} raw متنِ خامِ پیامک
     * @returns {{ok:boolean, type:?string, amount:?number, currency:?string,
     *            date:?string, card4:?string, note:?string, reason:string}}
     *          amount همیشه **تومان** است، مگر currency تهی باشد که یعنی
     *          واحد در پیامک نبود و عدد دست‌نخورده برگشته.
     */
    window.parseBankSms = function (raw) {
        var text = normalize(raw);
        var fail = function (why) {
            return { ok: false, type: null, amount: null, currency: null,
                     date: null, card4: null, acct4: null, balance: null,
                     balanceKnown: false, note: null, promo: false, reason: why };
        };
        if (text.replace(/\s/g, '') === '') { return fail('متنی وارد نشده.'); }

        if (OTP_RE.test(text)) { return fail('رمزِ یک‌بارمصرف است، نه تراکنش.'); }

        // ترتیبِ جهت: عبارتِ «شما»دار → کلمه‌ی جهت → علامتِ مبلغ.
        var type = null, dirAt = -1;
        var pickDir = function (outAt, inAt) {
            // ⚠ تساوی به نفعِ برداشت: برداشتِ ثبت‌نشده بهتر از واریزِ خیالی است.
            if (outAt !== -1 && (inAt === -1 || outAt <= inAt)) { type = 'expense'; dirAt = outAt; }
            else if (inAt !== -1) { type = 'income'; dirAt = inAt; }
        };
        pickDir(firstIndexOf(text, OUT_STRONG), firstIndexOf(text, IN_STRONG));
        if (type === null) { pickDir(firstIndexOf(text, OUT_WORDS), firstIndexOf(text, IN_WORDS)); }

        var dateHit = findDate(text);
        var hit = null;
        var signed = findSigned(text);
        if (type === null && signed) {
            type = signed.type; dirAt = signed.at;
            hit = { value: signed.value, unit: signed.unit };
        }

        // ⛔ بدون نشانه‌ی جهت، متن اصلاً تراکنش نیست — پیامکِ «مانده حساب
        //    شما …» هم یک عددِ درشت دارد و بدونِ این شرط همان مانده به
        //    عنوان مبلغِ تراکنش پر می‌شد. نه خطایی، نه نشانه‌ای.
        if (type === null) {
            return fail('برداشت یا واریز در این متن پیدا نشد.');
        }

        if (!hit) { hit = findAmount(text, dirAt, dateHit); }
        if (!hit) { return fail('مبلغی در این متن پیدا نشد.'); }

        // ⛔ نبودِ واحد یعنی **ریال**، نه «نمی‌دانم».
        //
        //    نسخه‌ی قبلی عمداً حدس نمی‌زد و عدد را دست‌نخورده می‌گذاشت.
        //    استدلالش درست بود (حدسِ غلط یعنی ده‌برابر شدنِ مبلغ) ولی
        //    فرضش غلط بود: پیامکِ بانکِ ایرانی عملاً همیشه ریال است، پس
        //    «دست نزدن» هم خودش یک حدس بود — و همان حدسِ اشتباه، فقط
        //    ساکت‌تر. کاربر هر بار باید یک صفر دستی کم می‌کرد.
        //
        //    حالا فقط «تومان» و «تومن» صریح از تقسیم معاف‌اند. وقتی
        //    واحد در متن نبود، `currency` مقدارِ جدای `rial_assumed`
        //    می‌گیرد تا پیامِ روی صفحه بگوید فرض شده و کاربر بتواند
        //    بسنجد — تفاوتش با نسخه‌ی قبل این است که عددِ **محتمل‌تر**
        //    در فیلد می‌نشیند، نه عددِ ده‌برابر.
        var amount = hit.value;
        var currency = hit.unit === 'toman' ? 'toman'
                     : (hit.unit === 'rial' ? 'rial' : 'rial_assumed');
        if (currency !== 'toman') { amount = Math.round(amount / 10); }

        var noteM = text.match(/(?:بابت|پذیرنده|شرح)[:：\s]+([^\n\r]{2,40})/);
        var bal = findBalance(text, hit.unit);
        var card4 = findCardTail(text, type), acct4 = findAcctTail(text, type);

        // ⛔ آگهی: کلمه‌ی تبلیغ **و** هیچ نشانِ حساب (مانده، کارت، حساب،
        //    تاریخ). پیامکِ واقعی‌ای که پانوشتِ «جشنواره» دارد خوانده
        //    می‌شود ولی `promo` آن را از ثبتِ خودکار بیرون می‌گذارد.
        var promo = PROMO_RE.test(text);
        if (promo && !bal && !card4 && !acct4 && !dateHit) {
            return fail('آگهیِ بانک است، نه تراکنش.');
        }

        return {
            ok: true,
            type: type,
            amount: amount,
            currency: currency,
            date: dateHit ? dateHit.iso : null,
            card4: card4,
            acct4: acct4,
            balance: bal ? bal.value : null,
            balanceKnown: bal ? bal.known : false,
            note: noteM ? noteM[1].trim() : null,
            promo: promo,
            reason: ''
        };
    };

    /**
     * ⛔ آیا این خواندن آن‌قدر قطعی هست که **بدونِ تأیید** ثبت شود؟
     *
     *    این تنها جای این تصمیم است. تا امروز آخرین تپ دستِ کاربر بود و
     *    دلیلش هم نوشته شده: خرابیِ پارسر بی‌صداست، و ثبتِ خودکارِ یک
     *    برداشتِ غلط دفتر را بی‌آنکه کسی بفهمد خراب می‌کند. آن دلیل هنوز
     *    درست است — پس آنچه عوض شد «اعتماد به پارسر» نیست، بلکه این
     *    است که فقط **زیرمجموعه‌ی قطعیِ** خواندن‌ها خودکار می‌شود و
     *    بقیه دقیقاً مثل قبل یک تپ می‌خواهند.
     *
     * ⛔ سه شرط، و هیچ‌کدام اختیاری نیست:
     *
     *    ۱. **واحد پول در متن نوشته شده باشد.** `rial_assumed` یعنی
     *       واحد نبود و ریال **حدس** زده شد؛ حدسِ غلط یعنی مبلغِ
     *       ده‌برابر — بزرگ‌ترین خطای ممکنِ این مسیر. (و همین شرط
     *       خودبه‌خود قوی‌ترین نشانه‌ی مبلغ را هم تضمین می‌کند: عددی
     *       که «ریال» یا «تومان» کنارش نوشته شده، نه عددی که فقط
     *       جداکننده دارد یا نزدیکِ کلمه‌ی جهت است.)
     *    ۲. **مبلغ مثبت و نوع روشن باشد.**
     *    ۳. **حسابِ مقصد حدس نباشد.** بدونِ آن `resolveWalletId()` پول
     *       را در حسابِ پیش‌فرض می‌نشاند — که برای یک ثبتِ **دستی**
     *       انتخابِ درستی است (پول گم نشود) ولی برای یک ثبتِ **خودکار**
     *       یک حدسِ ساکت است.
     *
     * ⚠ شرطِ سوم عمداً «کارت خواند» نیست بلکه «مقصد مبهم نیست»، و
     *   تصمیمش با فراخوان است نه اینجا. دلیلش یک اشتباهِ واقعی در
     *   نسخه‌ی اول است: آن نسخه تطبیقِ کارت را **لازم** می‌دانست، ولی
     *   شیتِ ثبت وقتی کاربر فقط **یک** حساب دارد اصلاً `<select>`ی
     *   نمی‌سازد (یک `input hidden` می‌گذارد) — یعنی هیچ‌وقت تطبیقی رخ
     *   نمی‌داد و این قابلیت برای رایج‌ترین حالتِ ممکن، یعنی کاربرِ
     *   تک‌حسابی، **هرگز کار نمی‌کرد**. در مرورگر دیده شد، نه در بازبینی.
     *   با یک حساب هم اصلاً ابهامی نیست: مقصد فقط همان یکی است.
     *
     * ⚠ تاریخ عمداً شرط نیست: پیامکِ بانک همان لحظه می‌رسد، پس نبودنش
     *   یعنی «امروز» — که درست است. شرط کردنش این قابلیت را عملاً
     *   خاموش می‌کرد.
     *
     * @param {object} r   خروجیِ parseBankSms
     * @param {boolean} walletCertain مقصد قطعی است (کارت خواند، یا کاربر
     *                                فقط یک حساب دارد)
     * @returns {{ok: boolean, why: string}}
     */
    window.smsAutoOk = function (r, walletCertain) {
        if (!r || !r.ok) { return { ok: false, why: 'پیامک خوانده نشد.' }; }
        // ⚠ مگر پیامک **شکلِ پیامکِ بانک** را داشته باشد (خطِ «مانده»):
        //   ملی، ملت، صادرات، پاسارگاد، پارسیان، رفاه، مهر… هیچ‌کدام واحد
        //   نمی‌نویسند و عرفِ بانکیِ ایران (و دستورِ بانک مرکزی برای پیامکِ
        //   تراکنش) ریال است؛ بانک‌هایی که تومان می‌نویسند (بلو، ویپاد) همیشه
        //   «تومان» را صریح می‌نویسند. بی‌این استثنا ثبتِ خودکار برای بیشترِ
        //   بانک‌های کشور **هرگز** رخ نمی‌داد. متنِ آزادِ بی‌مانده («برداشت
        //   ۲۵۰,۰۰۰») همچنان تأیید می‌خواهد.
        if (r.currency === 'rial_assumed' && r.balance === null) {
            return { ok: false, why: 'واحد پول در پیامک نوشته نشده بود.' };
        }
        if (!(r.amount > 0)) { return { ok: false, why: 'مبلغ خوانده نشد.' }; }
        if (r.promo) { return { ok: false, why: 'متن شبیهِ آگهی است.' }; }
        if (r.type !== 'income' && r.type !== 'expense') {
            return { ok: false, why: 'برداشت یا واریز بودنش روشن نیست.' };
        }
        if (!walletCertain) {
            return { ok: false, why: 'حساب از روی شماره‌ی کارت یا حساب پیدا نشد.' };
        }
        return { ok: true, why: '' };
    };

    /**
     * ⛔ پیامک مالِ کدام حساب است؟ — تنها جای این تصمیم.
     *
     *    ترتیب از قطعی به کم‌قطعی: چهار رقمِ آخرِ **کارت** → چهار رقمِ
     *    آخرِ **حساب** → **نامِ بانک**، اگر کاربر فقط **یک** حساب از آن
     *    بانک دارد → کاربر اصلاً یک حساب دارد. هر تطبیقی که بیش از یک
     *    حساب را بدهد «پیدا نشد» است، نه «اولی»: پولِ حسابِ اشتباه
     *    خرابیِ بی‌صداست.
     *
     * ⚠ `how` به فراخواننده می‌گوید چقدر مطمئن است: هم‌ترازیِ مانده فقط
     *   با `card`/`acct`/`bank` انجام می‌شود، نه با `single` — کاربری که
     *   فقط «کیف پولِ» نقدی دارد نباید موجودیِ بانکش روی کیف پول بنشیند.
     *
     * ⚠ کلیدِ بانک فقط با پیشوندِ «بانک» پذیرفته می‌شود — «دی» در «ماه
     *   دی» و «ملی» در «کد ملی» هم هست — مگر کلیدی که با `!` شروع شود
     *   (`walletSmsKeys()` در PHP تصمیم می‌گیرد کدام).
     *
     * @param {string} raw     متنِ پیامک
     * @param {object} r       خروجیِ parseBankSms
     * @param {Array}  wallets [{id, card4, acct4, banks: [..]}]
     * @returns {{id:number, how:?string}}
     */
    window.smsMatchWallet = function (raw, r, wallets) {
        var none = { id: 0, how: null };
        if (!Array.isArray(wallets) || !wallets.length || !r) { return none; }
        var pick = function (field, val, how) {
            if (!val) { return null; }
            var hits = wallets.filter(function (w) { return w && w[field] === val; });
            return hits.length === 1 ? { id: +hits[0].id, how: how } : null;
        };
        var got = pick('card4', r.card4, 'card') || pick('acct4', r.acct4, 'acct');
        if (got) { return got; }

        var text = normalize(raw);
        var esc = function (s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); };
        var bankHits = wallets.filter(function (w) {
            return (w.banks || []).some(function (k) {
                var loose = k.charAt(0) === '!';
                var key = normalize(loose ? k.slice(1) : k).trim();
                if (!key) { return false; }
                var word = esc(key) + '(?![\\u0600-\\u06FF])';
                var re = loose
                    ? new RegExp('(^|[^\\u0600-\\u06FF])' + word)
                    : new RegExp('بانک\\s*' + word);
                return re.test(text);
            });
        });
        if (bankHits.length === 1) { return { id: +bankHits[0].id, how: 'bank' }; }
        if (bankHits.length > 1) { return none; }

        if (wallets.length === 1) { return { id: +wallets[0].id, how: 'single' }; }
        return none;
    };


    /**
     * ⛔ کلیدِ ثبتِ خودکار — **پیش‌فرض روشن**، و این یک بار برعکس بود.
     *
     *    تا دیروز «خاموش، و قابل مذاکره نیست» نوشته شده بود؛ خواستِ صریحِ
     *    مالکِ نصب آن را کنار گذاشت: «پیش‌فرض نوتیف و اطلاع‌رسانی و تمام
     *    قابلیت‌ها باید فعال باشد». خطرِ اصلی‌اش سرِ جایش است و سه سد
     *    دارد که هیچ‌کدام با این تغییر عوض نشد: `smsAutoOk()` فقط خواندنِ
     *    **قطعی** را می‌پذیرد (واحدِ پولِ نوشته‌شده + مقصدِ روشن)، نگهبانِ
     *    تکراری (`smsFingerprint`)، و نوارِ «لغو» بعد از هر ثبت.
     *
     * ⛔ حالا **فقط `'0'`** یعنی خاموش — «کاربر عمداً خاموشش کرده».
     *    `'1'`ِ قدیمی (کسی که دستی روشنش کرده بود) و «هیچ‌چیز» هر دو روشن‌اند.
     *
     * ⚠ اینجا (نه داخلِ `DOMContentLoaded`) تعریف شده تا در node
     *   آزمودنی باشد: آنجا `localStorage` خالی است، یعنی دقیقاً همان
     *   حالتِ «کاربر هیچ‌وقت دست نزده» — و جواب باید `true` باشد.
     *
     * ⚠ و `try/catch` لازم است: در پنجره‌ی ناشناس یا با بستنِ داده‌ی
     *   سایت، خودِ **خواندن** استثنا پرتاب می‌کند. شکستش عمداً هنوز به
     *   سمتِ «خاموش» است — جایی که حتی انتخابِ کاربر خوانده نمی‌شود،
     *   نوشتنِ خودکار در دفترِ او حدس است نه تصمیم.
     */

    window.SMS_AUTO_KEY = 'daftar_sms_auto_on';
    window.smsAutoEnabled = function () {
        try { return localStorage.getItem(window.SMS_AUTO_KEY) !== '0'; }
        catch (e) { return false; }
    };

    /**
     * اثرِ انگشتِ یک پیامک — فقط برای اینکه یک پیامک **دو بار** ثبت نشود.
     *
     * ⛔ چرا لازم است: اعلانِ اندروید ممکن است دو بار زده شود و هر بار
     *    همان `#sms=` را باز می‌کند. با ثبتِ خودکار، تپِ دوم یک تراکنشِ
     *    تکراری می‌سازد و کاربر هیچ‌وقت نمی‌فهمد از کجا آمده. پاک شدنِ
     *    فرگمنت جلوی *تازه‌سازی* را می‌گیرد، ولی جلوی تپِ دوم را نه.
     *
     * ⚠ این یک هشِ امنیتی نیست و نباید جایی به‌عنوان کلیدِ امنیتی به کار
     *   رود؛ FNV-1a ۳۲ بیتی است و فقط باید متن‌های متفاوت را از هم جدا
     *   کند. مهم‌تر اینکه **هرگز از مرورگر بیرون نمی‌رود**: کنارِ همان
     *   قاعده‌ای که متنِ پیامک را در فرگمنت نگه می‌دارد، این هم فقط در
     *   localStorage همان دستگاه می‌ماند.
     */
    window.smsFingerprint = function (raw) {
        var s = normalize(String(raw || '')).replace(/\s+/g, ' ').trim();
        var h = 0x811c9dc5;
        for (var i = 0; i < s.length; i++) {
            h ^= s.charCodeAt(i);
            h = (h + ((h << 1) + (h << 4) + (h << 7) + (h << 8) + (h << 24))) >>> 0;
        }
        return ('0000000' + h.toString(16)).slice(-8);
    };

    /**
     * فرگمنتی که اپ اندروید می‌سازد → فهرستِ متنِ پیامک‌ها.
     *
     * ⛔ دو شکل، و هر دو **فرگمنت‌اند نه query** (قاعده ۱۹):
     *    - `#sms=<متن>` — یک پیامک، از تپ روی اعلان.
     *    - `#smsq=<JSON آرایه>` — چند پیامک، از صندوقِ پیامک هنگامِ باز
     *      شدنِ اپ (`HesabLauncherActivity`). **گزارشِ مالکِ نصب:** «پیامک
     *      برداشت فرستادم هیچ اتفاقی نیفتاد… می‌خوام تمام پیامک‌های حساب
     *      روی اپ بالا بیاد». با فقط اعلان، پیامکی که کاربر رویش تپ نکرده
     *      بود (یا اعلانش را رام بلعیده بود) هرگز به دفتر نمی‌رسید.
     *
     * ⚠ خالص و بیرون از `DOMContentLoaded` — آزمودنی در node، مثل
     *   `parseBankSms()`. ورودیِ خراب `[]` می‌دهد نه استثنا: این مسیر در
     *   هر بارگذاری اجرا می‌شود و استثنایش کلِ ثبتِ تراکنش را می‌خواباند.
     * ⚠ سقفِ `SMS_HASH_MAX`: فرگمنت از بیرون می‌آید و آرایه‌ی هزارتایی
     *   یعنی هزار بار باز شدنِ شیت.
     */
    window.SMS_HASH_MAX = 20;
    window.smsHashDecode = function (hash) {
        var h = String(hash || '');
        var out = [];
        try {
            if (h.indexOf('#smsq=') === 0) {
                var arr = JSON.parse(decodeURIComponent(h.slice(6)));
                if (!Array.isArray(arr)) { return []; }
                for (var i = 0; i < arr.length && out.length < window.SMS_HASH_MAX; i++) {
                    if (typeof arr[i] === 'string' && arr[i].trim()) { out.push(arr[i]); }
                }
            } else if (h.indexOf('#sms=') === 0) {
                var one = decodeURIComponent(h.slice(5));
                if (one.trim()) { out.push(one); }
            }
        } catch (e) { return []; }
        return out;
    };
})();

/**
 * ⛔ کارگرِ اپ اندروید — یک پیامک → تصمیمِ کامل، با **همان** سه تابعِ مسیرِ وب
 *    (`parseBankSms` → `smsMatchWallet` → `smsAutoOk`). جاوا فقط این را صدا
 *    می‌زند و نتیجه را می‌فرستد؛ هیچ تصمیمی در لایه‌ی بومی گرفته نمی‌شود.
 *
 * ⛔ آنچه به سرور می‌رود **فیلدهای خوانده‌شده‌اند، نه متن** (`post`): نوع،
 *    مبلغ، تاریخ، حساب، مانده و یک شرحِ کوتاه — همان چیزی که یک تراکنشِ
 *    ثبت‌شده به‌هرحال دارد. متنِ خامِ پیامک هرگز در `post` نیست (قاعده ۱۹).
 *
 * ⚠ `receivedIso`: روزِ رسیدنِ پیامک (از گوشی). وقتی پیامک تاریخ ندارد (ملی
 *   و ملت فقط «0705-12:30» می‌نویسند) همان روز ثبت می‌شود نه «امروزِ سرور» —
 *   پیامکِ دیشب که امروز صبح فرستاده شد، مالِ دیشب است.
 *
 * @param {string} raw     متنِ پیامک
 * @param {Array}  wallets همان `walletSmsKeys()`ِ سرور
 * @param {string=} receivedIso  YYYY-MM-DD
 * @returns {{ok:boolean, why:string, fp:string, label:string, post:?object}}
 */
window.smsWorker = function (raw, wallets, receivedIso) {
    var fp = window.smsFingerprint(raw);
    var r = window.parseBankSms(raw);
    if (!r || !r.ok) { return { ok: false, why: (r && r.reason) || 'پیامک خوانده نشد.', fp: fp, label: '', post: null }; }
    var m = window.smsMatchWallet(raw, r, Array.isArray(wallets) ? wallets : []);
    var v = window.smsAutoOk(r, !!m.id);
    var digits = String(r.amount).replace(/\B(?=(\d{3})+(?!\d))/g, '٬')
        .replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(+d); });
    var label = (r.type === 'income' ? 'واریز ' : 'برداشت ') + digits + ' تومان';
    if (!v.ok) { return { ok: false, why: v.why, fp: fp, label: label, post: null }; }
    return {
        ok: true, why: '', fp: fp, label: label,
        post: {
            type: r.type, amount: r.amount, wallet_id: m.id, how: m.how,
            date: r.date || (/^\d{4}-\d{2}-\d{2}$/.test(String(receivedIso || '')) ? receivedIso : ''),
            // ⛔ مانده فقط وقتی حساب با شماره یا نامِ بانک پیدا شد — نه «تک‌حساب»
            //    (همان شرطِ `smsProcessBatch()` در app.js)
            balance: (m.how === 'card' || m.how === 'acct' || m.how === 'bank') && r.balanceKnown && r.balance !== null ? r.balance : null,
            note: r.note || ''
        }
    };
};
