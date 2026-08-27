# راهنمای توسعه — دفتر مالی (پروژه حسابداری)

اپلیکیشن حسابداری شخصی/خانوادگی، چندکاربره، فارسی و راست‌به‌چپ، با تاریخ شمسی.
PHP خام (بدون فریم‌ورک و بدون Composer) + MySQL/MariaDB + PDO.

**پیش‌نیاز اجرا:** PHP ≥ ۸.۰ (تست‌شده تا ۸.۴)، افزونه‌های `pdo_mysql`, `gd`, `zlib`, `mbstring`.

---

## نقشه‌ی پروژه

```
config/       تنظیمات (فقط config.example.php در گیت است؛ config.php محرمانه و ignore شده)
includes/     لایه‌ی مشترک — همه‌ی صفحات از اینجا شروع می‌شوند
api/          ۴۸ اندپوینت JSON برای فراخوانی‌های AJAX
admin/        صفحات ویژه‌ی مدیر (کاربران، دسته‌بندی‌ها، تراکنش همه کاربران)
assets/       style.css، app.js، jalali-datepicker.js، آیکون‌ها، (fonts خالی است — پایین را ببینید)
*.php (ریشه)  صفحات کاربر
*.sql         schema.sql + ۱۱ فایل migration
```

### فایل‌های کلیدی `includes/`

| فایل | نقش |
|---|---|
| `db.php` | کلاس `Database::getConnection()` — PDO تک‌نمونه‌ای، `ERRMODE_EXCEPTION`، `EMULATE_PREPARES=false`. همچنین فشرده‌سازی gzip خروجی را روشن می‌کند و باید **پیش از هر خروجی** لود شود. |
| `auth.php` | کلاس `Auth` — نشست، ورود، «دستگاه مورد اعتماد» با کوکی، نقش‌ها. متدهای پرکاربرد: `initSession()`, `requireLogin()`, `requireAdmin()`, `userId()`, `isAdmin()`. |
| `csrf.php` | کلاس `Csrf` — `token()`, `field()`, `verifyOrFail()` با `hash_equals`. |
| `functions.php` | ~۹۰ تابع کمکی (۱۰۸۸ خط) — بزرگ‌ترین فایل پروژه و مرکز منطق دامنه. |
| `header.php` / `sidebar.php` / `footer.php` | قالب مشترک صفحات. |
| `add_tx_sheet.php` / `edit_tx_modal.php` | فرم‌های مشترک ثبت و ویرایش تراکنش. |

### توابع مهم `functions.php`

- **تاریخ شمسی:** `toJalali()`, `jalaliToGregorian()`, `gregorianToJalali()`, `today()`, `startOfWeek()`, `startOfJalaliMonth()`, `startOfJalaliYear()`, `jalaliMonthLength()`, `jalaliWithWeekday()`
- **ورودی/خروجی:** `postParam()`, `getParam()`, `jsonResponse()`, `h()` (escape)، `sanitizeAmount()`, `isValidDate()`, `toPersianDigits()`, `formatMoney()`
- **دامنه:** `walletBalances()`, `totalBalance()`, `budgetStatuses()`, `savingsGoalsWithProgress()`, `debtRemaining()`, `processRecurringTransactions()`, `financialEvents()`, `safeToSpend()`, `spendingInsights()`, `monthComparison()`
- **تنظیمات:** `getSetting()` / `setSetting()` روی جدول `app_settings`

---

## قواعد کدنویسی (هنگام افزودن قابلیت رعایت شود)

### الگوی استاندارد یک اندپوینت `api/`

هر فایل نویسنده‌ی داده در `api/` دقیقاً همین ترتیب را دارد:

```php
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();   // همیشه از اینجا، هرگز از ورودی کاربر
```

### قواعدی که نباید شکسته شوند

1. **جداسازی کاربران:** هر کوئری روی داده‌ی کاربر باید `WHERE user_id = :uid` داشته باشد. `user_id` همیشه از `Auth::userId()` می‌آید، هرگز از `$_POST`. تنها استثناء `admin/all-transactions.php` است که فقط-خواندنی است.
2. **کوئری‌ها:** فقط `prepare()` + پارامتر نام‌دار. اگر ناچار به ساختن پویای `WHERE` شدید، مثل `transactions.php` عمل کنید: قطعه‌های ثابت در آرایه، مقادیر همیشه bind. رشته‌ی ورودی کاربر هرگز داخل SQL درج نشود.
3. **CSRF:** هر اندپوینتی که چیزی می‌نویسد `Csrf::verifyOrFail()` دارد. اندپوینت‌های فقط-خواندنی (`dashboard_stats`, `day_detail`, `category_transactions`, `savings_history`, `transaction_attachments`, `view_attachment`) عمداً ندارند — اگر روزی نویسنده شدند، باید اضافه شود.
4. **خروجی HTML:** هر مقدار متغیر با `h()` یا `htmlspecialchars` فرار داده شود.
5. **مسیرها:** لینک‌ها و آدرس دارایی‌ها با ثابت `APP_BASE_PATH` ساخته شوند تا نصب در زیرپوشه نشکند.
6. **تاریخ:** ذخیره در دیتابیس میلادی (`DATE`)، نمایش شمسی. تبدیل فقط با توابع خود پروژه — مبدأ الگوریتم PHP و `assets/js/jalali-datepicker.js` باید یکی بماند.
7. **پول:** ورودی با `sanitizeAmount()` پاک شود (ارقام فارسی و جداکننده‌ها را می‌فهمد) و با `formatMoney()` نمایش داده شود.

### دیتابیس و migration

- `schema.sql` جداول پایه را می‌سازد: `users`, `categories`, `transactions`, `debts`, `app_settings`.
- بقیه‌ی جداول و ستون‌ها با فایل‌های `migration_*.sql` اضافه شده‌اند (کیف‌پول، بودجه، چک، دارایی، پس‌انداز، تکرارشونده، آیکون دسته‌ها، ایندکس‌ها).
- **ترتیب اجرا الفبایی نیست و در فایل‌ها ثبت نشده.** برای دیتابیس تازه، `schema.sql` و سپس migrationها به ترتیب `p1 → p2 → p3 → p4` و بعد بقیه اجرا شوند. **هیچ جدول ردیابی migration وجود ندارد** — این نخستین بدهی فنی است که برای ارتقاء باید حل شود.
- تغییر ساختار جدید = یک فایل `migration_*.sql` تازه (idempotent بنویسید: `IF NOT EXISTS` / بررسی ستون)، به‌علاوه به‌روزرسانی `schema.sql` برای نصب‌های تازه.

---

## بررسی وضعیت هنگام ایمپورت (۱۴۰۵/۰۶/۰۵)

انجام شد روی کل سورس:

- ✅ `php -l` روی هر ۸۶ فایل PHP — بدون خطای نحوی (با PHP 8.4)
- ✅ اسکن کلیدواژه‌های محرمانه — هیچ رمز، توکن یا کلیدی در سورس نیست؛ فقط `config.example.php`
- ✅ هر ۴۸ اندپوینت `api/` بررسی ورود کاربر دارند
- ✅ ۲۰۴ فراخوانی `prepare()` در برابر ۸ `query()` (بدون پارامتر ورودی) — تزریق SQL دیده نشد
- ⚠️ **`assets/fonts/` خالی است.** `style.css` سه فایل `Vazirmatn-Regular/Medium/Bold.woff2` را صدا می‌زند که در خروجی zip نبودند. تا وقتی اضافه نشوند، فونت به قلم پیش‌فرض سیستم می‌افتد. فایل‌ها را در `assets/fonts/` بگذارید.
- ⚠️ **بدون ردیابی migration** (بالا توضیح داده شد).
- ℹ️ `deploy.php` یک به‌روزرسان تحت وب است که با `DEPLOY_TOKEN` و `hash_equals` محافظت می‌شود. توکن باید طولانی و تصادفی باشد؛ روی VPS بهتر است به‌جای آن از `deploy.sh` استفاده شود.

---

## نصب و استقرار

- نصب روی هاست اشتراکی: `README.md`
- راه‌اندازی روی VPS با nginx و گیت: `DEPLOY.md`
- به‌روزرسانی روی سرور: `./deploy.sh` (یا `deploy.php?token=...` روی هاست اشتراکی)

`config/config.php` و `uploads/` هرگز وارد گیت نمی‌شوند و در استقرار دست‌نخورده می‌مانند.

## پیش از هر push

```bash
find . -name '*.php' -not -path './.git/*' -exec php -l {} \; | grep -v 'No syntax errors'
```
خروجی باید خالی باشد.
