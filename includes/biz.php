<?php
/**
 * ⛔ محیطِ فروشگاهی (Business Mode) — تنها جای تصمیمِ «این حساب کدام
 *    تجربه را می‌بیند».
 *
 * **خواسته‌ی مالکِ نصب:** «حسابداری شخصی تغییر نمی‌خوام، کاملاً جدا باشه…
 * برای ورود هم بهتره `/store` باشه، یعنی آدرس‌ها هم با هم قاطی نشه.»
 *
 * معماری: **هسته‌ی مشترک، تجربه‌ی جدا.** ورود، نشست، حساب‌ها، لاگ و
 * ممیزی همان‌اند؛ ولی حسابِ فروشگاهی فقط `/store/` را می‌بیند و حسابِ
 * شخصی هیچ چیزی از `/store/` نمی‌بیند.
 *
 * سه قاعده که این فایل را شکل می‌دهند:
 *
 * ۱. **سه نوعِ حساب** (`users.account_type`):
 *    - `personal` — فقط حساب لند (پیش‌فرض).
 *    - `both` — حساب لند **سرِ جایش می‌ماند** و از `/store` هم وارد
 *      فروشگاه می‌شود. **خواسته‌ی مالکِ نصب:** «وقتی قابلیت فروشگاهی کسی
 *      رو فعال می‌کنم حسابلند قبلی سرجاش باشه، با آدرس store بتونه وارد
 *      فروشگاهی بشه.»
 *    - `business` — فقط فروشگاه؛ هیچ صفحه‌ی شخصی‌ای را نمی‌بیند.
 *    ⛔ پولِ مغازه و خانه با این حال قاطی نمی‌شود: فروشگاه دفترِ خودش را
 *       دارد (`biz_accounts`، `biz_products`، …) و هیچ‌کدام در
 *       `walletBalances()` نمی‌نشیند. «یک حساب، یک تجربه» که مرحله‌ی ۱
 *       نوشته بود به همین دلیل بود، و حالا دیوار روی **داده** است نه روی
 *       حساب.
 *
 * ۲. **⛔ صفر کوئری برای صفحه‌های شخصی.** نوعِ حساب هنگامِ ساختنِ نشست یک
 *    بار خوانده و در نشست نوشته می‌شود؛ `gate()` فقط همان را می‌خواند.
 *    صفحه‌های `store/` ولی نوع را **هر بار از دیتابیس** تازه می‌کنند
 *    (`requirePage()`، یک کوئری) — پس روشن و خاموش کردنِ فروشگاه برای یک
 *    حسابِ `personal`/`both` کاربر را از حساب لندش بیرون نمی‌اندازد.
 *    - فقط تغییری که **دروازه** را عوض می‌کند (به `business` یا از آن)
 *      همه‌ی دسترسی‌ها را باطل می‌کند (`revokeAllAccessFor()`)، چون آن
 *      یکی از نشست خوانده می‌شود.
 *
 * ۳. **⛔ دروازه با پیش‌فرضِ بسته** (`gate()`). حسابِ «فقط فروشگاه» فقط به
 *    `store/` و فهرستِ بسته‌ی `SHARED` می‌رسد. پیش‌فرضِ باز یعنی هر
 *    قابلیتِ شخصیِ فردا — و بدتر، اندپوینتِ «معاملات» که پول را دو بار
 *    می‌شمرد — بی‌صدا به حسابِ فروشگاهی نشت می‌کرد.
 */

require_once __DIR__ . '/db.php';

final class Biz
{
    /** ⛔ تنها مرجعِ نوع‌های حساب. کلیدِ اول پیش‌فرض است. */
    public const TYPES = [
        'personal' => 'شخصی',
        'both'     => 'شخصی + فروشگاه',
        'business' => 'فقط فروشگاه',
    ];

    /** ⛔ نوع‌هایی که `/store` را می‌بینند — تنها مرجع. */
    public const STORE_TYPES = ['both', 'business'];

    /** پوشه‌ی محیطِ فروشگاهی، نسبت به `APP_BASE_PATH`. */
    public const DIR = 'store';

    /**
     * ⛔ فهرستِ بسته‌ی اسکریپت‌های بیرون از `store/` که حسابِ فروشگاهی
     *    هنوز حق دارد به آن‌ها برسد. هر چیزِ دیگری بسته است.
     *
     * - `logout.php` — خروج هرگز نباید بن‌بست شود، از هر دری که باشد.
     * - `health.php` — سنجشِ سلامت؛ هیچ داده‌ای از کاربر نمی‌دهد.
     */
    public const SHARED = ['logout.php', 'health.php'];

    /**
     * ⛔ منوی محیطِ فروشگاهی — تنها مرجع، گروه‌بندی‌شده (نوارِ کناریِ
     *    سمتِ راست، مسیرِ نوارِ بالا و فرمانِ سریع همه از همین). فایلی که
     *    هنوز ساخته نشده رندر نمی‌شود (دکمه‌ی بی‌کار از نبودنش بدتر است).
     *
     * **خواسته‌ی مالکِ نصب:** گروه‌های «اصلی / فروشگاه / مالی / گزارش‌ها /
     * سیستم». ⚠ قلم‌هایی از آن فهرست که در فروشگاه صفحه ندارند (اقساط،
     * انتقالِ وجهِ جدا، اعلان‌ها) ساخته نشدند: «انتقال» یکی از نوع‌های
     * «دریافت و پرداخت» است و «نیاز به توجه» روی خودِ داشبورد.
     */
    public const NAV = [
        'اصلی'     => ['index.php' => 'داشبورد', 'search.php' => 'جستجوی سریع'],
        'فروشگاه'  => ['quick-sale.php' => 'فروش سریع', 'sales.php' => 'فاکتورهای فروش', 'purchases.php' => 'فاکتورهای خرید',
                       'products.php' => 'محصولات و موجودی', 'categories.php' => 'دسته‌بندی‌ها', 'parties.php' => 'مشتریان و تأمین‌کنندگان'],
        'مالی'     => ['payments.php' => 'دریافت و پرداخت', 'accounts.php' => 'صندوق و بانک', 'cheques.php' => 'چک‌ها'],
        'گزارش‌ها' => ['reports.php' => 'گزارش‌ها و چاپ'],
        'سیستم'    => ['settings.php' => 'تنظیمات فروشگاه', 'invoice-design.php' => 'طراحی فاکتور', 'print-settings.php' => 'تنظیمات چاپ',
                       'products-io.php' => 'ورود و خروجِ اکسل'],
    ];

    /**
     * منوی پایینِ موبایل — فقط **کلید**ها؛ برچسب از `NAV` می‌آید (فهرستِ
     * دومِ برچسب ساخته نمی‌شود). «+ ثبت» بعد از `TABBAR_NEW_AT` قلم و
     * «بیشتر» (کشوی منو) آخرِ نوار است: داشبورد، حساب‌ها، +، فروش، بیشتر.
     */
    public const TABBAR = ['index.php', 'accounts.php', 'sales.php'];
    public const TABBAR_NEW_AT = 2;

    /** برچسبِ کوتاهِ منوی پایین — فقط وقتی برچسبِ `NAV` برای ۲۰ درصدِ عرض بلند است. */
    public const TABBAR_SHORT = ['accounts.php' => 'حساب‌ها', 'sales.php' => 'فروش'];

    /** صفحه‌ی جزئیات کدام قلمِ منو را روشن می‌کند (مگر صفحه خودش `$navActive` بگذارد). */
    public const NAV_PARENT = [
        'product.php'      => 'products.php',
        'products-cleanup.php' => 'products.php',
        'party.php'        => 'parties.php',
        'invoice.php'      => 'sales.php',
        'invoice-edit.php' => 'sales.php',
        'return.php'       => 'sales.php',
        'payment.php'      => 'payments.php',
    ];

    /**
     * ⛔ کاتالوگِ منوی «+ ثبتِ جدید» (نوارِ بالا و دکمه‌ی وسطِ منوی پایین) — تنها
     *    مرجع. هر فروشگاه از همین فهرست برمی‌دارد و کنار می‌گذارد (`newMenu()`)؛
     *    ترتیبِ نمایش همیشه ترتیبِ همین کاتالوگ است. فرمانِ سریع **همه** را
     *    پیشنهاد می‌دهد، نه فقط انتخاب‌شده‌ها.
     *    کلید = آدرس، مقدار = برچسبِ کامل (فرمانِ سریع، `title`ِ کاشی).
     */
    public const NEW_MENU = [
        'quick-sale.php'              => 'فروش سریع',
        'invoice-edit.php?k=sale'     => 'فاکتور فروش',
        'invoice-edit.php?k=purchase' => 'فاکتور خرید',
        'payment.php?k=receipt'       => 'دریافت از مشتری',
        'payment.php?k=payment'       => 'پرداخت به تأمین‌کننده',
        'payment.php?k=expense'       => 'هزینه‌ی فروشگاه',
        'payment.php?k=income'        => 'درآمدِ متفرقه',
        'payment.php?k=transfer'      => 'انتقال بین صندوق‌ها',
        'payment.php?k=receipt&method=cheque' => 'ثبتِ چکِ دریافتی',
        'payment.php?k=payment&method=cheque' => 'ثبتِ چکِ پرداختی',
        'party.php'                   => 'مشتری یا تأمین‌کننده',
        'product.php'                 => 'محصول',
    ];

    /**
     * کاشیِ هر قلم: نامِ کوتاه، رنگ (`is-tone-*` در `store.css`) و آیکون. ⛔ هر
     * کلیدِ `NEW_MENU` اینجا هست و برعکس (تست می‌سنجد) — قلمِ بی‌کاشی نامرئی بود.
     */
    public const NEW_TILES = [
        'quick-sale.php'              => ['فروش سریع',   'green',  '<path d="M13 3L5 13.5h6L10 21l8-10.5h-6z"/>'],
        'invoice-edit.php?k=sale'     => ['فاکتور فروش', 'green',  '<path d="M12 3l2 1.6 2.5-.4.9 2.4 2.4.9-.4 2.5L21 12l-1.6 2 .4 2.5-2.4.9-.9 2.4-2.5-.4L12 21l-2-1.6-2.5.4-.9-2.4-2.4-.9.4-2.5L3 12l1.6-2-.4-2.5 2.4-.9.9-2.4 2.5.4z"/><path d="M14.2 9.6c-.4-.6-1.2-1-2.2-1-1.3 0-2.2.7-2.2 1.6 0 2.2 4.6 1.2 4.6 3.5 0 .9-1 1.7-2.4 1.7-1 0-1.9-.4-2.3-1.1M12 7.4v1.2M12 15.4v1.2"/>'],
        'invoice-edit.php?k=purchase' => ['فاکتور خرید', 'orange', '<path d="M20 12V8l-8-4.5L4 8v8l8 4.5"/><path d="M4 8l8 4.5L20 8M12 12.5V21"/><path d="M18 15v6M15 18h6"/>'],
        'payment.php?k=receipt'       => ['دریافت',      'teal',   '<path d="M12 4v11M7 10.5l5 5 5-5M5 20h14"/>'],
        'payment.php?k=payment'       => ['پرداخت',      'amber',  '<path d="M12 20V9M7 13.5l5-5 5 5M5 4h14"/>'],
        'payment.php?k=expense'       => ['هزینه',       'red',    '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 11h6"/>'],
        'payment.php?k=income'        => ['درآمد',       'green',  '<circle cx="12" cy="12" r="8.5"/><path d="M12 8v8M8 12h8"/>'],
        'payment.php?k=transfer'      => ['انتقال',      'slate',  '<path d="M7 7h13M16 3l4 4-4 4M17 17H4M8 13l-4 4 4 4"/>'],
        'payment.php?k=receipt&method=cheque' => ['چکِ دریافتی', 'indigo', '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 14h5M15 10.5v3M13.5 12l1.5 1.5 1.5-1.5"/>'],
        'payment.php?k=payment&method=cheque' => ['چکِ پرداختی', 'indigo', '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 14h5M15 13.5v-3M13.5 12l1.5-1.5 1.5 1.5"/>'],
        'party.php'                   => ['شخص جدید',    'blue',   '<circle cx="9.5" cy="8" r="3.5"/><path d="M3 20c.6-3.4 3.2-5.5 6.5-5.5s5.9 2.1 6.5 5.5M18.5 8v6M15.5 11h6"/>'],
        'product.php'                 => ['کالای جدید',  'purple', '<path d="M12 3l4 2.2v4.4L12 12 8 9.6V5.2z"/><path d="M8 9.6l4 2.4v4.6l-4 2.2-4-2.2V12z"/><path d="M16 9.6l4 2.4v4.6l-4 2.2-4-2.2V12z"/>'],
    ];

    /**
     * پیش‌فرضِ کاشی‌ها — شش کارِ روزانه‌ی مغازه. ⛔ «مشتری یا تأمین‌کننده» و
     * «محصول» عمداً نیستند (خواسته‌ی مالکِ نصب: «الزامی نداره… نباشن بهتره»)؛
     * آن دو از فرمِ فاکتور («+» کنارِ کالا) و صفحه‌ی خودشان ساخته می‌شوند، و
     * هر فروشگاه می‌تواند اضافه‌شان کند.
     */
    public const NEW_DEFAULT = [
        'quick-sale.php', 'invoice-edit.php?k=sale', 'invoice-edit.php?k=purchase',
        'payment.php?k=receipt', 'payment.php?k=payment', 'payment.php?k=expense',
    ];

    /** JSONِ خامِ `biz_settings.new_menu` از همان `SELECT *`ِ `settings()`. */
    private static array $newMenuRaw = [];
    /** `biz_settings.rate_round` (migration_rates) — گردکردنِ قیمتِ کالای ارزی. */
    private static array $rateRound = [];

    /** گردکردنِ قیمتِ ارزی (تومان)، یکی از `BizRates::ROUNDS`؛ پیش‌فرض هزار. */
    public static function rateRound(int $userId): int
    {
        self::settings($userId);
        $v = (int)(self::$rateRound[$userId] ?? 1000);
        return in_array($v, [1, 1000, 10000, 100000], true) ? $v : 1000;
    }

    /**
     * ⛔ تنها نویسنده‌ی `rate_round`؛ بعدش قیمتِ همه‌ی کالاهای ارزیِ همین
     *    فروشگاه با گردکردنِ تازه دوباره حساب می‌شود (`BizRates::apply()`).
     * @return array{ok:bool, message:string}
     */
    public static function saveRateRound(int $userId, int $round): array
    {
        require_once __DIR__ . '/biz_rates.php';
        if (!isset(BizRates::ROUNDS[$round])) { return ['ok' => false, 'message' => 'گردکردنِ نامعتبر.']; }
        if (!self::hasColumn('biz_settings', 'rate_round')) {
            return ['ok' => false, 'message' => 'قیمتِ ارزی هنوز راه نیفتاده است (migration_rates).'];
        }
        Database::getConnection()->prepare(
            'INSERT INTO biz_settings (user_id, rate_round) VALUES (:u, :r)
             ON DUPLICATE KEY UPDATE rate_round = VALUES(rate_round)'
        )->execute(['u' => $userId, 'r' => $round]);
        unset(self::$settingsCache[$userId]);
        $n = BizRates::apply($userId);
        return ['ok' => true, 'message' => 'گردکردن «' . BizRates::ROUNDS[$round] . '» شد'
            . ($n > 0 ? ' و قیمتِ ' . toPersianDigits((string)$n) . ' کالا تازه شد.' : '.')];
    }

    /**
     * کلیدهای منوی «+» این فروشگاه، به ترتیبِ کاتالوگ — صفر کوئریِ اضافه.
     * `NULL`، JSONِ خراب یا فهرستی که هیچ کلیدِ شناخته‌ای ندارد ← پیش‌فرض.
     * @return list<string>
     */
    public static function newMenu(int $userId): array
    {
        self::settings($userId);
        $in = json_decode((string)(self::$newMenuRaw[$userId] ?? ''), true);
        if (!is_array($in)) { return self::NEW_DEFAULT; }
        $keep = array_values(array_filter(array_keys(self::NEW_MENU), fn($k) => in_array($k, $in, true)));
        return $keep ?: self::NEW_DEFAULT;
    }

    /**
     * ⛔ تنها نویسنده‌ی چیدمانِ «+». فقط کلیدهای کاتالوگ؛ دست‌کم یکی (منوی خالی
     * دکمه‌ای بود که هیچ کاری نمی‌کرد). همان پیش‌فرض ← `NULL`، تا پیش‌فرضِ فردا برسد.
     * @param string[] $keys
     * @return array{ok:bool, message:string}
     */
    public static function saveNewMenu(int $userId, array $keys): array
    {
        if (!self::hasColumn('biz_settings', 'new_menu')) {
            return ['ok' => false, 'message' => 'چیدمانِ منو هنوز راه نیفتاده است (migration_biz_new_menu).'];
        }
        $keys = array_map('strval', $keys);
        $pick = array_values(array_filter(array_keys(self::NEW_MENU), fn($k) => in_array($k, $keys, true)));
        if (!$pick) { return ['ok' => false, 'message' => 'دست‌کم یک قلم را برای منوی «+» نگه دارید.']; }
        $val = $pick === self::NEW_DEFAULT ? null : json_encode($pick, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        unset(self::$settingsCache[$userId], self::$newMenuRaw[$userId]);
        Database::getConnection()->prepare(
            'INSERT INTO biz_settings (user_id, new_menu) VALUES (:u, :m)
             ON DUPLICATE KEY UPDATE new_menu = VALUES(new_menu)'
        )->execute(['u' => $userId, 'm' => $val]);
        return ['ok' => true, 'message' => 'منوی «+» ذخیره شد (' . toPersianDigits((string)count($pick)) . ' قلم).'];
    }

    /**
     * ⛔ تنها رندرِ منوی «+» — نوارِ بالا و دکمه‌ی وسطِ منوی پایین هر دو همین را
     *    می‌گیرند (دو نسخه دیر یا زود از هم دور می‌افتادند). کاشی‌ها به ترتیبِ
     *    کاتالوگ، و پایینش «چیدمانِ این منو را عوض کنید».
     */
    public static function newMenuHtml(int $userId): string
    {
        $out = '<div class="st-new-grid">';
        foreach (self::newMenu($userId) as $k) {
            [$short, $tone, $svg] = self::NEW_TILES[$k] ?? [self::NEW_MENU[$k], 'slate', '<circle cx="12" cy="12" r="4"/>'];
            $out .= '<a class="st-new-tile is-tone-' . h($tone) . '" href="' . h(self::url($k)) . '" title="' . h(self::NEW_MENU[$k]) . '">'
                  . '<span class="st-new-ico"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $svg . '</svg></span>'
                  . '<span class="st-new-name">' . h($short) . '</span></a>';
        }
        return $out . '</div>'
            . '<a class="st-new-edit" href="' . h(self::url('settings.php')) . '#newmenu">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/></svg>'
            . '<span>چیدمانِ این منو را عوض کنید</span></a>';
    }

    /**
     * قلمِ فعالِ منو که خودِ صفحه تعیین می‌کند — مثلاً صفحه‌ی یک فاکتورِ
     * **خرید** باید «فاکتورهای خرید» را روشن کند، نه «فروش». یک ویژگیِ ایستا
     * است نه متغیرِ سراسری (قاعده ۵۰).
     */
    public static ?string $navActive = null;

    /**
     * آیکونِ خطیِ هر قلمِ منو (نوارِ کناری، منوی پایین و فرمانِ سریع) — فقط
     * شکل، نه فهرستِ صفحه‌ها (آن `NAV` است). یک وزن، یک اندازه.
     * @return array<string,string>
     */
    public static function navIcons(): array
    {
        return [
            'index.php'          => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
            'search.php'         => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
            'products.php'       => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
            'products-io.php'    => '<path d="M12 3v12"/><path d="M8 11l4 4 4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
            'parties.php'        => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14a6 6 0 0 1 3.5 6"/>',
            'reports.php'        => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20H2"/>',
            'settings.php'       => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-2.9-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 3.1 15H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.2-2.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 10 4.1V4a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.9H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
            'print-settings.php' => '<path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
            'invoice-design.php' => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/><path d="M9 13h7M9 17h5"/>',
            'quick-sale.php'     => '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
            'sales.php'          => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
            'purchases.php'      => '<path d="M3 4h2l2.4 11h11L21 7H6.2"/><circle cx="9" cy="19.5" r="1.5"/><circle cx="17" cy="19.5" r="1.5"/>',
            'payments.php'       => '<path d="M7 7h13l-3-3"/><path d="M17 17H4l3 3"/>',
            'accounts.php'       => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><path d="M16 15h2"/>',
            'cheques.php'        => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><path d="M6 14h5"/><path d="M15 11h3"/><path d="M15 14h3"/>',
            'categories.php'     => '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
        ];
    }

    /** @return array<string,string> فایل ← برچسب، به ترتیبِ منو */
    public static function navFlat(): array
    {
        $out = [];
        foreach (self::NAV as $items) { $out += $items; }
        return $out;
    }

    /** قلمِ فعالِ منو برای یک اسکریپت (صفحه‌ی جزئیات → فهرستش). */
    public static function navCurrent(string $page): string
    {
        return self::$navActive ?? (self::NAV_PARENT[$page] ?? $page);
    }

    /** نقش‌هایی که حسابِ فروشگاهی می‌تواند داشته باشد. */
    private const BUSINESS_ROLES = ['user', 'colleague'];

    /** ستونِ نوعِ حساب آمده است؟ (migration_business_mode) */
    public static function available(): bool
    {
        return function_exists('tableHasColumn')
            ? tableHasColumn('users', 'account_type')
            : self::columnProbe();
    }

    private static function columnProbe(): bool
    {
        try {
            Database::getConnection()->query('SELECT account_type FROM users LIMIT 0');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * نوعِ حسابِ یک کاربر از دیتابیس — فقط هنگامِ ساختنِ نشست صدا زده
     * می‌شود، نه روی هر صفحه.
     *
     * ⛔ فقط «ستون ناشناخته» (42S22) یعنی `personal`. هر خطای دیگری بالا
     *    می‌رود: اگر یک قطعیِ گذرا حسابِ فروشگاهی را «شخصی» می‌خواند،
     *    صاحبِ مغازه بی‌صدا وسطِ محیطِ شخصی فرود می‌آمد.
     */
    public static function typeFor(int $userId): string
    {
        try {
            $st = Database::getConnection()->prepare(
                'SELECT account_type FROM users WHERE id = :id LIMIT 1'
            );
            $st->execute(['id' => $userId]);
            $v = $st->fetchColumn();
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '42S22') { return 'personal'; }
            throw $e;
        }
        return is_string($v) && isset(self::TYPES[$v]) ? $v : 'personal';
    }

    /**
     * حسابِ همین نشست «فقط فروشگاه» است؟ — فقط از نشست، بدونِ کوئری.
     * تنها چیزی که دروازه‌ی صفحه‌های شخصی می‌پرسد.
     */
    public static function isStoreOnly(): bool
    {
        return !empty($_SESSION['user_id'])
            && ($_SESSION['account_type'] ?? '') === 'business';
    }

    /** نشستِ جاری به فروشگاه راه دارد؟ — از نشست؛ برای نمایش، نه دروازه. */
    public static function hasStore(): bool
    {
        return !empty($_SESSION['user_id'])
            && in_array($_SESSION['account_type'] ?? '', self::STORE_TYPES, true);
    }

    /**
     * جاهای مجازِ درگاه در طرفِ شخصی — فهرستِ بسته: پایینِ منوی کناریِ
     * دسکتاپ (`nav`، کنارِ «حساب کاربری من») و شیتِ «بیشتر»ِ گوشی (`sheet`).
     * ⚠ کارتِ خانه عمداً نیست — خواسته‌ی مالکِ نصب: «از خانه بردار، همون در
     *   بیشتر باشه؛ در دسکتاپ هم پایین یه جای مناسب».
     */
    public const GATEWAY_SPOTS = ['nav', 'sheet'];

    /**
     * ⛔ درگاهِ «حسابداری فروشگاه» در حساب لندِ شخصی — **تنها** نشانِ فروشگاه
     *    در طرفِ شخصی، و فقط برای حسابی که فروشگاهش روشن است (`both`).
     *
     * **خواسته‌ی مالکِ نصب:** «یک لندینگ به حسابداری حسابلند بذاریم، فقط اونایی
     * فروشگاه فعال دارن ببینن.» پیش از این حسابِ «شخصی + فروشگاه» هیچ راهی از
     * دفترِ شخصی به `/store` نداشت جز تایپِ آدرس.
     *   - **صفر کوئری:** نوعِ حساب از نشست (`hasStore()`)؛ حسابِ شخصی هیچ
     *     بایتی از فروشگاه نمی‌بیند (رشته‌ی خالی) — همان قاعده‌ی «حسابِ شخصی
     *     هیچ چیزی از /store نمی‌بیند».
     *   - طرفِ شخصی خودش آدرس، کلاس یا آیکونِ فروشگاه نمی‌نویسد (قاعده ۷۰)؛
     *     فقط همین تابع را صدا می‌زند، پس همه‌ی نشان‌ها یک‌جا عوض می‌شوند.
     *   - ⚠ نوعِ نشست هنگامِ ورود نوشته می‌شود: کسی که مدیر همین حالا برایش
     *     فروشگاه روشن کرده، درگاه را با ورودِ بعدی (یا یک بار باز کردنِ `/store`)
     *     می‌بیند؛ خاموش کردن هم تا آن وقت درگاه را نگه می‌دارد ولی خودِ `/store`
     *     همان لحظه ۴۰۴ می‌دهد (`requirePage()` از دیتابیس می‌خواند).
     *   - حسابِ «فقط فروشگاه» اصلاً به صفحه‌های شخصی نمی‌رسد (دروازه).
     * @param string $spot یکی از `GATEWAY_SPOTS`: پایینِ منوی کناری، شیتِ «بیشتر»
     */
    public static function personalGateway(string $spot): string
    {
        if (!self::hasStore() || !in_array($spot, self::GATEWAY_SPOTS, true)) { return ''; }
        $url  = h(self::url());
        $icon = h(self::icon('96'));
        $name = 'حسابداری فروشگاه';
        if ($spot === 'nav') {
            // همان شکلِ «حساب کاربری من» در پایینِ منو (`.logout-link`)
            return '<a href="' . $url . '" class="logout-link store-gate-nav">'
                 . '<img src="' . $icon . '" alt="" width="20" height="20" class="store-gate-ico">'
                 . '<span>' . $name . '</span></a>';
        }
        return '<a href="' . $url . '" class="tool-card tool-card-wide store-gate-tool" style="--tc1:#f59e0b; --tc2:#ea580c;">'
             . '<img src="' . $icon . '" alt="" width="26" height="26" class="store-gate-ico">'
             . '<span>' . $name . '</span></a>';
    }

    /**
     * نوع را از دیتابیس تازه می‌کند و در نشست می‌نویسد — فقط در صفحه‌های
     * `store/` (یک کوئری). بدونِ آن، کاربری که مدیر همین حالا برایش
     * فروشگاه روشن کرده تا ورودِ دوباره ۴۰۴ می‌گرفت.
     */
    /** ستونِ `users.avatar` هست؟ — از همان کوئریِ `refreshType()`، بی‌سنجشِ ساختار. */
    public static ?bool $avatarReady = null;

    public static function refreshType(): string
    {
        if (empty($_SESSION['user_id'])) { return 'personal'; }
        $uid = (int)$_SESSION['user_id'];
        // ⛔ تصویرِ شخصی (بالا-چپِ پوسته‌ی فروشگاه) در **همین** کوئری می‌آید —
        //    صفر کوئریِ اضافه. ستونِ `avatar` (migration_p4) یا `account_type`
        //    ممکن است نباشد؛ آن‌وقت همان مسیرِ قبلی.
        try {
            $st = Database::getConnection()->prepare('SELECT account_type, avatar FROM users WHERE id = :id LIMIT 1');
            $st->execute(['id' => $uid]);
            $row = $st->fetch() ?: [];
            $v = $row['account_type'] ?? null;
            $t = is_string($v) && isset(self::TYPES[$v]) ? $v : 'personal';
            $a = $row['avatar'] ?? null;
            if (is_string($a) && $a !== '') { $_SESSION['avatar'] = $a; } else { unset($_SESSION['avatar']); }
            self::$avatarReady = true;
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '42S22') { throw $e; }
            $t = self::typeFor($uid);
            self::$avatarReady = false;
        }
        $_SESSION['account_type'] = $t;
        return $t;
    }

    /** آدرسِ خانه‌ی حساب لند — برای حسابِ `both` در سرآیندِ فروشگاه. */
    public static function personalUrl(): string
    {
        return (defined('APP_BASE_PATH') ? APP_BASE_PATH : '') . '/index.php';
    }

    /** آدرسِ مطلقِ یک صفحه‌ی فروشگاهی (قاعده ۱۶: هرگز نسبی). */
    public static function url(string $page = ''): string
    {
        $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : '';
        return $base . '/' . self::DIR . '/' . ltrim($page, '/');
    }

    /** مسیرِ اسکریپتِ جاری نسبت به ریشه‌ی اپ، مثل `store/index.php`. */
    public static function currentScript(): string
    {
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $base   = defined('APP_BASE_PATH') ? APP_BASE_PATH : '';
        if ($base !== '' && strncmp($script, $base, strlen($base)) === 0) {
            $script = substr($script, strlen($base));
        }
        return ltrim($script, '/');
    }

    /**
     * ⛔ دروازه — از `Auth::initSession()` و بعد از ورودِ خودکار با کوکیِ
     *    دستگاه صدا زده می‌شود.
     *
     * حسابِ شخصی: یک خواندنِ نشست و برگشت. هیچ کوئری، هیچ تغییری.
     * حسابِ فروشگاهی بیرون از `store/` و `SHARED`: اندپوینت ← ۴۰۳ JSON،
     * صفحه ← ریدایرکت به داشبوردِ فروشگاه (نه ۴۰۴: لینکِ کهنه‌ی یک اعلان
     * یا ایمیل نباید بن‌بست شود).
     */
    public static function gate(): void
    {
        if (PHP_SAPI === 'cli' || !self::isStoreOnly()) { return; }

        $script = self::currentScript();
        if (strncmp($script, self::DIR . '/', strlen(self::DIR) + 1) === 0
            || in_array($script, self::SHARED, true)) {
            return;
        }

        if (strncmp($script, 'api/', 4) === 0) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success'    => false,
                'message'    => 'این بخش برای حسابِ فروشگاهی در دسترس نیست.',
                'request_id' => class_exists('Log') ? Log::requestId() : null,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: ' . self::url());
        exit;
    }

    /**
     * سرِ هر صفحه‌ی `store/` (جز ورود و خروج).
     *
     * واردنشده ← صفحه‌ی ورودِ فروشگاه. حسابِ بی‌فروشگاه ← ۴۰۴ — کاربرِ
     * عادی نباید هیچ چیزی از محیطِ فروشگاهی ببیند، حتی اینکه وجود دارد.
     * ⛔ نوع از دیتابیس خوانده می‌شود، نه از نشست (بالای فایل، قاعده‌ی ۲).
     */
    public static function requirePage(): void
    {
        if (!Auth::isLoggedIn()) {
            header('Location: ' . self::url('login.php'));
            exit;
        }
        if (!in_array(self::refreshType(), self::STORE_TYPES, true)) {
            self::notFound();
        }
    }

    /** ۴۰۴ِ خنثی — نه نامِ فروشگاه، نه سربرگ، نه هیچ نشانه‌ای. */
    public static function notFound(): void
    {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
        }
        $home = (defined('APP_BASE_PATH') ? APP_BASE_PATH : '') . '/index.php';
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">'
           . '<title>پیدا نشد</title></head>'
           . '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:48px 16px">'
           . '<p>صفحه‌ای که دنبالش هستید پیدا نشد.</p>'
           . '<p><a href="' . htmlspecialchars($home, ENT_QUOTES) . '">بازگشت</a></p>'
           . '</body></html>';
        exit;
    }

    /** ⛔ تنها قاعده‌ی «این نقش با این نوعِ حساب جور است؟». */
    public static function roleAllowed(string $role, string $type): bool
    {
        return $type !== 'business' || in_array($role, self::BUSINESS_ROLES, true);
    }

    /**
     * ⛔ تنها نویسنده‌ی `users.account_type` — پنلِ مدیر و در پشتیِ خطِ
     *    فرمان هر دو از همین رد می‌شوند.
     *
     * - مدیر و پشتیبان نمی‌توانند «فقط فروشگاه» شوند: دروازه آن‌ها را از
     *   پنلِ مدیریت بیرون نگه می‌داشت و آخرین مدیرِ نصب می‌توانست خودش را
     *   قفل کند. «شخصی + فروشگاه» برای هر نقشی مجاز است.
     * - ⛔ فقط تغییری که دروازه را عوض می‌کند (به `business` یا از آن) همه‌ی
     *   دسترسی‌های آن کاربر را باطل می‌کند (نشست، کوکیِ دستگاه، توکنِ اپ):
     *   دروازه از نشست می‌خواند و بدونِ ابطال، مرورگرِ باز با تجربه‌ی قبلی
     *   می‌ماند. روشن/خاموش کردنِ فروشگاه برای `personal`↔`both` کاربر را
     *   بیرون **نمی‌اندازد** — خواسته‌ی صریح همین بود.
     *
     * @return array{ok:bool, message:string, changed?:bool}
     */
    public static function setType(int $targetId, string $type, ?int $actorId = null): array
    {
        if (!isset(self::TYPES[$type])) {
            return ['ok' => false, 'message' => 'نوعِ حساب معتبر نیست.'];
        }
        if (!self::available()) {
            return ['ok' => false, 'message' => 'migration_business_mode.sql هنوز اجرا نشده است.'];
        }

        $pdo = Database::getConnection();
        $st  = $pdo->prepare('SELECT id, role, account_type FROM users WHERE id = :id LIMIT 1');
        $st->execute(['id' => $targetId]);
        $u = $st->fetch();
        if (!$u) {
            return ['ok' => false, 'message' => 'کاربر مورد نظر یافت نشد.'];
        }
        if (!self::roleAllowed((string)$u['role'], $type)) {
            return ['ok' => false, 'message' => 'حسابِ مدیر یا پشتیبان نمی‌تواند «فقط فروشگاه» شود؛ «شخصی + فروشگاه» را انتخاب کنید.'];
        }
        $from = isset(self::TYPES[$u['account_type']]) ? (string)$u['account_type'] : 'personal';
        if ($from === $type) {
            return ['ok' => true, 'changed' => false, 'message' => 'حساب از قبل ' . self::TYPES[$type] . ' است.'];
        }

        $pdo->prepare('UPDATE users SET account_type = :t WHERE id = :id')
            ->execute(['t' => $type, 'id' => $targetId]);

        $revoke = ($from === 'business' || $type === 'business');
        if ($revoke) {
            require_once __DIR__ . '/functions.php';
            revokeAllAccessFor($targetId);
        }

        if (class_exists('Audit')) {
            Audit::log('user.account_type', 'user', $targetId, ['from' => $from, 'to' => $type], $actorId, $targetId);
        }

        return [
            'ok'      => true,
            'changed' => true,
            'revoked' => $revoke,
            'message' => 'نوعِ حساب «' . self::TYPES[$type] . '» شد'
                . ($revoke ? ' و کاربر از همه‌ی دستگاه‌ها خارج می‌شود.' : '؛ کاربر از حسابش خارج نمی‌شود.'),
        ];
    }

    /* ============================================================
       تنظیماتِ فروشگاه (سربرگِ فاکتور)
       ============================================================ */

    /** سقفِ طولِ هر فیلد — تنها مرجع، هم فرم هم اعتبارسنجی. */
    public const SETTING_LIMITS = [
        'shop_name'      => 120,
        'phone'          => 40,
        'address'        => 300,
        'invoice_footer' => 500,
        // کدهای رسمی (migration_biz_business_info) — قاعده‌شان `BizCommon::CODES`
        'national_id'    => 20,
        'economic_code'  => 20,
        'reg_no'         => 20,
        'postal_code'    => 20,
    ];

    /** کلیدهای کدِ رسمی در `SETTING_LIMITS` — فقط رقم، نه متنِ آزاد. */
    public const SETTING_CODES = ['national_id', 'economic_code', 'reg_no', 'postal_code'];

    /** کشِ همین درخواست — سرآیند و خودِ صفحه هر دو می‌خوانندش. */
    private static array $settingsCache = [];
    /** پالتِ خامِ همان ردیف — در همان کوئریِ `settings()` خوانده می‌شود. */
    private static array $paletteCache = [];

    /**
     * ⛔ تنها مرجعِ رنگِ لهجه‌ی فروشگاه. **اولین کلید پیش‌فرض است** و در
     * `store.css` خودِ `:root` است (بلوکِ `data-st-palette` ندارد).
     * **خواسته‌ی مالکِ نصب:** «Accent سبز (هویت حساب‌لند)» — پس سبز اول آمد
     * و کهربایی (پیش‌فرضِ قبلی) یکی از گزینه‌هاست. چون پیش‌فرض `NULL`
     * ذخیره می‌شد، هر فروشگاهی که رنگ را دست نزده بود حالا سبز است.
     * `theme` رنگِ نوارِ وضعیتِ گوشی است و باید همان `--p-acc`ِ روزِ همان
     * پالت باشد (`test_store_theme` می‌سنجد).
     */
    public const PALETTES = [
        'emerald'  => ['label' => 'سبزِ حساب‌لند', 'theme' => '#047857'],
        'indigo'   => ['label' => 'نیلی',    'theme' => '#4f46e5'],
        'ocean'    => ['label' => 'اقیانوس', 'theme' => '#0369a1'],
        'lilac'    => ['label' => 'یاس',     'theme' => '#7e22ce'],
        'amber'    => ['label' => 'کهربایی', 'theme' => '#b45309'],
        'graphite' => ['label' => 'زغالی',   'theme' => '#1f2937'],
    ];

    /**
     * اسکریپتِ درون‌خطیِ سرآیند — **پیش از رندر** حالتِ شب و فشرده بودنِ
     * نوارِ کناری را روی `<html>` می‌گذارد تا چشمکی از حالتِ دیگر دیده نشود.
     * ⛔ فقط `localStorage` و `matchMedia` را می‌خواند؛ هیچ داده‌ای از
     *    صفحه در آن نیست. بی‌جاوااسکریپت CSS خودش از سیستم پیروی می‌کند.
     */
    public static function bootScript(): string
    {
        return "(function(){var d=document.documentElement,t=null,m=null;"
            . "try{t=localStorage.getItem('st_theme');m=localStorage.getItem('st_side_mini');}catch(e){}"
            . "if(t!=='dark'&&t!=='light'){t=(window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}"
            . "d.setAttribute('data-st-theme',t);if(m==='1'){d.setAttribute('data-st-mini','1');}})();";
    }

    /**
     * ⛔ صفحه‌هایی که پیش‌گیری نمی‌شوند: `logout.php` با GET خارج می‌کند (مکثِ
     *    نشانگر روی «خروج» کاربر را بیرون می‌انداخت). هیچ صفحه‌ی دیگرِ
     *    فروشگاه با GET نمی‌نویسد — همه‌ی نوشتن‌ها POST + CSRF‌اند.
     */
    public const SPEC_SKIP = ['logout.php'];
    /** پیش‌گیری بله، prerender نه: برگه‌ی چاپ سنگین است و کمتر باز می‌شود. */
    public const SPEC_NO_PRERENDER = ['print.php'];

    /**
     * ⛔ تعویضِ فوریِ صفحه در فروشگاه — Speculation Rules (همان سازوکارِ
     * `speculationRulesJson()`ِ حساب لند، با دامنه‌ی `/store/`).
     *
     * - **prerender با `moderate`:** وقتی نشانگر ۲۰۰ms روی لینک می‌ماند (یا
     *   انگشت لمس می‌کند) مرورگر صفحه‌ی بعد را **کامل** در پس‌زمینه می‌سازد؛
     *   زدن فقط آن را نشان می‌دهد. `eager`/`immediate` عمداً نه: هر بازدید
     *   ده صفحه‌ی ناخواسته روی سرور می‌ساخت.
     * - **کهنگی ممکن نیست:** همه‌ی نوشتن‌های فروشگاه فرمِ POST + ریدایرکت‌اند،
     *   یعنی **سندِ تازه** — و پیش‌گرفته‌ها مالِ سندِ قبلی‌اند و دور ریخته
     *   می‌شوند. (حساب لند `fetch`ِ نویسنده دارد و برای همین
     *   `resetSpeculation()` لازم شد؛ اینجا `store.js` هیچ درخواستِ نویسنده‌ای
     *   ندارد.)
     * - HTML همچنان `no-store` است؛ کشِ پیش‌گیری جدا و یک‌بارمصرف است.
     * - سافاری این را ندارد و بی‌صدا نادیده می‌گیرد.
     */
    public static function speculationRulesJson(): string
    {
        $b = (defined('APP_BASE_PATH') ? APP_BASE_PATH : '') . '/' . self::DIR;
        $not = [['selector_matches' => '[download], [target], [onclick], [data-no-prefetch]'],
                ['href_matches' => $b . '/*format=json*']];
        foreach (self::SPEC_SKIP as $p) { $not[] = ['href_matches' => $b . '/' . $p . '*']; }
        $where = ['and' => [['href_matches' => $b . '/*'], ['not' => ['or' => $not]]]];
        $noPre = $not;
        foreach (self::SPEC_NO_PRERENDER as $p) { $noPre[] = ['href_matches' => $b . '/' . $p . '*']; }
        $rules = [
            'prerender' => [['where' => ['and' => [['href_matches' => $b . '/*'], ['not' => ['or' => $noPre]]]],
                             'eagerness' => 'moderate']],
            'prefetch'  => [['where' => $where, 'eagerness' => 'moderate']],
        ];
        return json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * ⛔ برچسب‌های «وب‌اپ» فروشگاه — **تنها** جای آن‌ها؛ پوسته (`biz_head.php`)
     * و صفحه‌ی ورودِ فروشگاه هر دو از همین می‌گیرند (کاربر اغلب آیکون را از
     * همان صفحه‌ی ورود به صفحه‌ی اصلی اضافه می‌کند).
     *
     * بدونِ `<link rel="manifest">`، آیفونِ نصب‌شده هر ناوبری به صفحه‌ی دیگرِ
     * فروشگاه را «بیرون از اپ» می‌دید و در برگه‌ی مرورگر باز می‌کرد (منوی پایین
     * = نوارِ سافاری با ×). جزئیات بالای `assets/store-manifest.php`.
     */
    /**
     * ⛔ آیکونِ **فروشگاه** — جدا از آیکونِ حساب لند (`assets/icons/icon-*`).
     * مالکِ نصب: «فقط برای بخشِ فروشگاه این لوگو». تنها جای نامِ این فایل‌هاست؛
     * مانیفستِ فروشگاه، برچسب‌های وب‌اپ، نشانِ منو و صفحه‌ی ورود از همین می‌خوانند.
     * `maskable` فایلِ جداست (نشان ×۰٫۸۶۵، داخلِ ۸۰٪ِ امنِ اندروید).
     */
    public const ICONS = ['16', '32', '96', '180', '192', '512', '512-maskable'];

    public static function icon(string $size): string
    {
        if (!in_array($size, self::ICONS, true)) { $size = '192'; }
        return iconUrl('store-' . $size . '.png');
    }

    public static function webAppTags(string $title): string
    {
        $b = defined('APP_BASE_PATH') ? APP_BASE_PATH : '';
        return '<link rel="manifest" href="' . h($b . '/assets/store-manifest.php') . '">' . "\n"
             . '    <link rel="icon" type="image/png" sizes="32x32" href="' . h(self::icon('32')) . '">' . "\n"
             . '    <link rel="icon" type="image/png" sizes="16x16" href="' . h(self::icon('16')) . '">' . "\n"
             . '    <link rel="apple-touch-icon" sizes="180x180" href="' . h(self::icon('180')) . '">' . "\n"
             . '    <meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
             . '    <meta name="mobile-web-app-capable" content="yes">' . "\n"
             . '    <meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n"
             . '    <meta name="apple-mobile-web-app-title" content="' . h($title) . '">';
    }

    /** پالتِ این فروشگاه؛ ناشناخته یا خالی → اولین کلید. صفر کوئریِ اضافه. */
    public static function palette(int $userId): string
    {
        self::settings($userId);
        $p = (string)(self::$paletteCache[$userId] ?? '');
        return isset(self::PALETTES[$p]) ? $p : (string)array_key_first(self::PALETTES);
    }

    /** @return array{ok:bool, message:string} */
    public static function savePalette(int $userId, string $key): array
    {
        if (!isset(self::PALETTES[$key])) {
            return ['ok' => false, 'message' => 'این رنگ شناخته نشد.'];
        }
        // ⛔ پیش‌فرض NULL ذخیره می‌شود نه نامش — تا پیش‌فرضِ فردا به همه برسد.
        $val = $key === array_key_first(self::PALETTES) ? null : $key;
        unset(self::$settingsCache[$userId], self::$paletteCache[$userId]);
        Database::getConnection()->prepare(
            'INSERT INTO biz_settings (user_id, palette) VALUES (:u, :p)
             ON DUPLICATE KEY UPDATE palette = VALUES(palette)'
        )->execute(['u' => $userId, 'p' => $val]);
        return ['ok' => true, 'message' => 'رنگِ فروشگاه «' . self::PALETTES[$key]['label'] . '» شد.'];
    }

    public static function settings(int $userId): array
    {
        if (isset(self::$settingsCache[$userId])) { return self::$settingsCache[$userId]; }
        $out = array_fill_keys(array_keys(self::SETTING_LIMITS), '');
        try {
            // ⚠ `*` نه فهرستِ ستون: ستونِ `palette` (migration_biz_theme) روی
            //    نصبِ عقب‌مانده نیست و نبودنش نباید سربرگ را هم خالی کند.
            $st = Database::getConnection()->prepare(
                'SELECT * FROM biz_settings WHERE user_id = :u LIMIT 1'
            );
            $st->execute(['u' => $userId]);
            $row = $st->fetch();
        } catch (PDOException $e) {
            return $out;    // جدول هنوز نیامده
        }
        if ($row) {
            foreach ($out as $k => $_) { $out[$k] = (string)($row[$k] ?? ''); }
        }
        self::$paletteCache[$userId] = $row ? (string)($row['palette'] ?? '') : '';
        self::$invoiceRaw[$userId]   = $row ? (string)($row['invoice_prefs'] ?? '') : '';
        self::$designRaw[$userId]    = $row ? (string)($row['invoice_design'] ?? '') : '';
        self::$lockCache[$userId]    = $row ? (string)($row['lock_date'] ?? '') : '';
        self::$newMenuRaw[$userId]   = $row ? (string)($row['new_menu'] ?? '') : '';
        self::$rateRound[$userId]    = $row ? (int)($row['rate_round'] ?? 1000) : 1000;
        if ($row) { self::$infoReady = array_key_exists('invoice_prefs', $row); }
        return self::$settingsCache[$userId] = $out;
    }

    /* ============================================================
       بستنِ دوره (migration_biz_lock → biz_settings.lock_date)
       ============================================================ */

    private static array $lockCache = [];

    /** آخرین روزِ بسته (میلادی) یا null — از همان `SELECT *`ِ `settings()`، صفر کوئریِ اضافه. */
    public static function lockDate(int $userId): ?string
    {
        self::settings($userId);
        $d = (string)(self::$lockCache[$userId] ?? '');
        return isValidDate($d) ? $d : null;
    }

    /**
     * ⛔ تنها سنجه‌ی «دوره‌ی بسته». هر نوشتنی که عددِ یک روزِ گذشته را عوض
     *    می‌کند (صدور، ابطال، برگشت به پیش‌نویس، برگشت، دریافت/پرداخت و ابطالش،
     *    وصول/برگشت/واگذاریِ چک، انبارگردانی، موجودی و مانده‌ی اول دوره) با
     *    تاریخِ **خودِ آن سند** اینجا را می‌پرسد. تاریخِ تا روزِ قفل → پیامِ خطا.
     *    دلیل: بی‌قفل، یک خریدِ تاریخ‌گذشته بهای تمام‌شده و سودِ ماهی را که
     *    حسابش بسته و گزارشش داده شده بی‌صدا عوض می‌کرد (زنجیره‌ی `recalc()`).
     * @return string|null پیامِ خطا، یا null یعنی «دوره باز است»
     */
    public static function lockError(int $userId, string $date, string $what = 'این سند'): ?string
    {
        $lock = self::lockDate($userId);
        if ($lock === null || $date === '' || $date > $lock) { return null; }
        return 'دوره تا ' . toJalali($lock) . ' بسته است؛ ' . $what . ' با تاریخِ ' . toJalali($date)
            . ' ثبت یا اصلاح نمی‌شود (تنظیماتِ فروشگاه ← بستنِ دوره).';
    }

    /**
     * بستن یا باز کردنِ دوره. `$date` خالی = باز کردنِ همه. ⛔ عقب بردن یا باز
     * کردن (دوره‌ای که بسته بود دوباره باز شود) تأییدِ صریح می‌خواهد — جلوتر
     * بردن نه. قفل تا امروز است، نه آینده: روزِ آینده هنوز سندی ندارد که بسته شود.
     * @return array{ok:bool, message:string}
     */
    public static function saveLock(int $userId, string $date, bool $confirmUnlock = false): array
    {
        if (!self::hasColumn('biz_settings', 'lock_date')) {
            return ['ok' => false, 'message' => 'بستنِ دوره هنوز راه نیفتاده است (migration_biz_lock).'];
        }
        $date = trim($date);
        if ($date !== '' && !isValidDate($date)) { return ['ok' => false, 'message' => 'تاریخِ بستن معتبر نیست.']; }
        if ($date !== '' && $date > date('Y-m-d')) { return ['ok' => false, 'message' => 'دوره فقط تا امروز بسته می‌شود، نه آینده.']; }
        $cur = self::lockDate($userId);
        $unlock = $cur !== null && ($date === '' || $date < $cur);
        if ($unlock && !$confirmUnlock) {
            return ['ok' => false, 'message' => 'برای باز کردنِ دوره‌ای که بسته بود، گزینه‌ی تأیید را بزنید.'];
        }
        unset(self::$settingsCache[$userId], self::$lockCache[$userId]);
        Database::getConnection()->prepare(
            'INSERT INTO biz_settings (user_id, lock_date) VALUES (:u, :d)
             ON DUPLICATE KEY UPDATE lock_date = VALUES(lock_date)'
        )->execute(['u' => $userId, 'd' => $date === '' ? null : $date]);
        if ($date === '') { return ['ok' => true, 'message' => 'همه‌ی دوره‌ها باز شد.']; }
        return ['ok' => true, 'message' => 'دوره تا ' . toJalali($date) . ' بسته شد' . ($unlock ? ' (از ' . toJalali((string)$cur) . ' عقب آمد).' : '.')];
    }

    /** از ردیفِ `SELECT *`ِ `settings()` — بی‌کوئریِ نقشه‌ی ساختار؛ null = هنوز نمی‌دانیم. */
    private static ?bool $infoReady = null;

    /** کدهای رسمی و گزینه‌های فاکتور آمده‌اند؟ (migration_biz_business_info) */
    public static function businessInfoReady(): bool
    {
        return self::$infoReady ??= self::hasColumn('biz_settings', 'invoice_prefs');
    }

    /** ستونی از جدول‌های فروشگاه آمده است؟ — نصبِ migration‌نخورده نشکند. */
    private static function hasColumn(string $table, string $col): bool
    {
        if (function_exists('tableHasColumn')) { return tableHasColumn($table, $col); }
        try {
            Database::getConnection()->query("SELECT `{$col}` FROM `{$table}` LIMIT 0");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /* ============================================================
       گزینه‌های فاکتور — تبِ «فاکتور»ِ تنظیماتِ مالیِ حسابفا
       (migration_biz_business_info → biz_settings.invoice_prefs)
       ============================================================ */

    /**
     * ⛔ تنها مرجعِ گزینه‌های فاکتور. پیش‌فرضِ همه **خاموش** است: هیچ نصبِ
     *    موجودی با آمدنِ این گزینه‌ها رفتارش عوض نمی‌شود.
     *    ⚠ «ثبتِ فاکتور با کمبودِ موجودی» عمداً اینجا نیست: سدِ «موجودی در
     *    هیچ لحظه منفی نمی‌شود» (`BizStock::write()`) تصمیمِ ثابتِ فروشگاه است.
     */
    public const INVOICE_FLAGS = [
        'update_buy_price' => 'پس از صدورِ فاکتورِ خرید، «قیمتِ خرید»ِ کالا به فیِ همان فاکتور به‌روز شود',
        'update_sell_price' => 'پس از صدورِ فاکتورِ فروش، «قیمتِ فروش»ِ کالا به فیِ همان فاکتور به‌روز شود',
        'warn_below_cost'  => 'هشدار وقتی کالا زیرِ بهای خرید فروخته می‌شود',
        'show_profit'      => 'نمایشِ سودِ هر فاکتورِ فروش روی صفحه‌ی فاکتور (روی چاپ نمی‌آید)',
    ];

    private static array $invoiceRaw = [];

    /** JSONِ خامِ طراحیِ فاکتور از همان `SELECT *`ِ `settings()` — صفر کوئریِ اضافه. */
    private static array $designRaw = [];

    public static function invoiceDesignRaw(int $userId): string
    {
        self::settings($userId);
        return self::$designRaw[$userId] ?? '';
    }

    /** بعد از ذخیره‌ی طراحی — همان درخواست مقدارِ تازه را بخواند. */
    public static function forgetSettings(int $userId): void
    {
        unset(self::$settingsCache[$userId], self::$designRaw[$userId], self::$invoiceRaw[$userId]);
    }

    /** @return array{update_buy_price:bool, update_sell_price:bool, warn_below_cost:bool, show_profit:bool, default_party:int} */
    public static function invoicePrefs(int $userId): array
    {
        self::settings($userId);
        $in  = json_decode(self::$invoiceRaw[$userId] ?? '', true);
        $in  = is_array($in) ? $in : [];
        $out = [];
        foreach (self::INVOICE_FLAGS as $k => $_) { $out[$k] = ($in[$k] ?? false) === true; }
        $out['default_party'] = max(0, (int)($in['default_party'] ?? 0));
        return $out;
    }

    /**
     * ⛔ مشتریِ پیش‌فرض فقط اگر طرف‌حسابِ **همین** فروشگاه باشد — شناسه از
     *    فرم می‌آید و نباید مشتریِ فروشگاهِ دیگری روی فاکتور بنشیند.
     */
    public static function saveInvoicePrefs(int $userId, array $in): array
    {
        if (!self::hasColumn('biz_settings', 'invoice_prefs')) {
            return ['ok' => false, 'message' => 'این گزینه‌ها هنوز راه نیفتاده‌اند (migration_biz_business_info).'];
        }
        $clean = [];
        foreach (self::INVOICE_FLAGS as $k => $_) { $clean[$k] = !empty($in[$k]); }
        $party = max(0, (int)($in['default_party'] ?? 0));
        if ($party > 0) {
            $st = Database::getConnection()->prepare('SELECT COUNT(*) FROM biz_parties WHERE id = :id AND user_id = :u AND is_active = 1');
            $st->execute(['id' => $party, 'u' => $userId]);
            if ((int)$st->fetchColumn() === 0) { return ['ok' => false, 'message' => 'مشتریِ پیش‌فرض پیدا نشد.']; }
        }
        $clean['default_party'] = $party;
        unset(self::$settingsCache[$userId], self::$invoiceRaw[$userId]);
        Database::getConnection()->prepare(
            'INSERT INTO biz_settings (user_id, invoice_prefs) VALUES (:u, :p)
             ON DUPLICATE KEY UPDATE invoice_prefs = VALUES(invoice_prefs)'
        )->execute(['u' => $userId, 'p' => json_encode($clean)]);
        return ['ok' => true, 'message' => 'گزینه‌های فاکتور ذخیره شد.'];
    }

    /** @return array{ok:bool, message:string} */
    public static function saveSettings(int $userId, array $in): array
    {
        require_once __DIR__ . '/biz_catalog.php';
        $clean = [];
        foreach (self::SETTING_LIMITS as $k => $max) {
            $raw = (string)($in[$k] ?? '');
            if (in_array($k, self::SETTING_CODES, true)) {
                $c = BizCommon::code($k, $raw);
                if (!$c['ok']) { return ['ok' => false, 'message' => $c['message']]; }
                $clean[$k] = (string)$c['value'];
                continue;
            }
            // پای فاکتور چندخطی است؛ بقیه یک‌خطی‌اند و فاصله‌ی اضافه ندارند
            $v = $k === 'invoice_footer'
                ? trim($raw)
                : trim((string)preg_replace('/\s+/u', ' ', $raw));
            if (mb_strlen($v) > $max) {
                return ['ok' => false, 'message' => 'متنِ یکی از فیلدها بیش از ' . $max . ' نویسه است.'];
            }
            $clean[$k] = $v;
        }
        if ($clean['shop_name'] === '') {
            return ['ok' => false, 'message' => 'نامِ فروشگاه الزامی است.'];
        }

        unset(self::$settingsCache[$userId]);
        $pdo = Database::getConnection();
        $pdo->prepare(
            'INSERT INTO biz_settings (user_id, shop_name, phone, address, invoice_footer)
             VALUES (:u, :n, :p, :a, :f)
             ON DUPLICATE KEY UPDATE shop_name = VALUES(shop_name), phone = VALUES(phone),
                                     address = VALUES(address), invoice_footer = VALUES(invoice_footer)'
        )->execute([
            'u' => $userId, 'n' => $clean['shop_name'], 'p' => $clean['phone'],
            'a' => $clean['address'], 'f' => $clean['invoice_footer'],
        ]);
        // ⚠ کدهای رسمی فقط وقتی ستونشان آمده (نصبِ migration‌نخورده نباید بشکند)
        if (self::hasColumn('biz_settings', 'economic_code')) {
            $pdo->prepare(
                'UPDATE biz_settings SET national_id = :ni, economic_code = :ec, reg_no = :rn, postal_code = :pc WHERE user_id = :u'
            )->execute([
                'ni' => $clean['national_id'] !== '' ? $clean['national_id'] : null,
                'ec' => $clean['economic_code'] !== '' ? $clean['economic_code'] : null,
                'rn' => $clean['reg_no'] !== '' ? $clean['reg_no'] : null,
                'pc' => $clean['postal_code'] !== '' ? $clean['postal_code'] : null,
                'u'  => $userId,
            ]);
        }
        return ['ok' => true, 'message' => 'تنظیماتِ فروشگاه ذخیره شد.'];
    }

    /* ============================================================
       تنظیماتِ چاپ — «تنظیم پرینت» (migration_biz_print)
       ============================================================ */

    /** ⛔ تنها مرجعِ گزینه‌ها؛ کلیدِ اولِ هر گروه پیش‌فرض نیست — `PRINT_DEFAULTS` است. */
    public const PRINT_OPTIONS = [
        'paper'  => ['a4' => 'A4', 'a5' => 'A5', '80mm' => 'رولِ ۸۰ میلی‌متری (فیش‌پرینتر)', '58mm' => 'رولِ ۵۸ میلی‌متری'],
        'orient' => ['portrait' => 'عمودی', 'landscape' => 'افقی'],
        'font'   => ['sm' => 'ریز', 'md' => 'معمولی', 'lg' => 'درشت'],
        'margin' => ['narrow' => 'کم', 'normal' => 'معمولی', 'wide' => 'زیاد'],
    ];

    public const PRINT_FLAGS = [
        'show_header'  => 'سربرگ (نامِ فروشگاه)',
        'show_contact' => 'تلفن و نشانی زیرِ سربرگ',
        'show_date'    => 'تاریخ و ساعتِ چاپ',
        'show_footer'  => 'متنِ پای فاکتور (از تنظیماتِ فروشگاه)',
        'show_sign'    => 'جای امضا در پایینِ برگه',
        'show_balance' => 'مانده‌ی قبلی و مانده‌ی کلِ طرف‌حساب روی فاکتور',
    ];

    public const PRINT_DEFAULTS = [
        'paper' => 'a4', 'orient' => 'portrait', 'font' => 'md', 'margin' => 'normal',
        'show_header' => true, 'show_contact' => true, 'show_date' => true, 'show_footer' => true, 'show_sign' => false,
        'show_balance' => true,
    ];

    private static array $printCache = [];

    /**
     * ⛔ مقدارِ ناشناخته (JSONِ خراب، کلیدِ کهنه) بی‌صدا به پیش‌فرض برمی‌گردد،
     *    نه اینکه برگه‌ی چاپ بشکند. نبودِ ستون (migration نخورده) هم همین.
     * @return array<string,string|bool>
     */
    public static function printPrefs(int $userId): array
    {
        if (isset(self::$printCache[$userId])) { return self::$printCache[$userId]; }
        $raw = null;
        try {
            $st = Database::getConnection()->prepare('SELECT print_prefs FROM biz_settings WHERE user_id = :u LIMIT 1');
            $st->execute(['u' => $userId]);
            $raw = $st->fetchColumn();
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '42S22' && (string)$e->getCode() !== '42S02') { throw $e; }
        }
        $in = is_string($raw) ? json_decode($raw, true) : null;
        return self::$printCache[$userId] = self::cleanPrint(is_array($in) ? $in : []);
    }

    /** @return array<string,string|bool> */
    private static function cleanPrint(array $in): array
    {
        $out = self::PRINT_DEFAULTS;
        foreach (self::PRINT_OPTIONS as $k => $opts) {
            if (isset($in[$k]) && is_string($in[$k]) && isset($opts[$in[$k]])) { $out[$k] = $in[$k]; }
        }
        foreach (self::PRINT_FLAGS as $k => $_) {
            if (array_key_exists($k, $in)) { $out[$k] = (bool)$in[$k]; }
        }
        return $out;
    }

    /**
     * از یک فرمِ چک‌باکسی: گزینه‌ی نبوده یعنی «خاموش» (تنها تعبیرِ
     * بی‌ابهامِ چک‌باکس)؛ مقدارِ ناشناخته‌ی منو رد می‌شود.
     * @return array{ok:bool, message:string}
     */
    public static function savePrintPrefs(int $userId, array $in): array
    {
        $clean = [];
        foreach (self::PRINT_OPTIONS as $k => $opts) {
            $v = (string)($in[$k] ?? '');
            if (!isset($opts[$v])) { return ['ok' => false, 'message' => 'یکی از گزینه‌های چاپ معتبر نیست.']; }
            $clean[$k] = $v;
        }
        foreach (self::PRINT_FLAGS as $k => $_) { $clean[$k] = !empty($in[$k]); }
        unset(self::$printCache[$userId]);
        Database::getConnection()->prepare(
            'INSERT INTO biz_settings (user_id, print_prefs) VALUES (:u, :p)
             ON DUPLICATE KEY UPDATE print_prefs = VALUES(print_prefs)'
        )->execute(['u' => $userId, 'p' => json_encode($clean, JSON_UNESCAPED_UNICODE)]);
        return ['ok' => true, 'message' => 'تنظیماتِ چاپ ذخیره شد.'];
    }
}
