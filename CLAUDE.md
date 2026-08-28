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
assets/       style.css، app.js، jalali-datepicker.js، آیکون‌ها، فونت وزیرمتن
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

- `schema.sql` جداول پایه را می‌سازد: `users`, `categories`, `transactions`, `debts`, `app_settings`. دسته‌بندی‌های پیش‌فرض را فقط وقتی درج می‌کند که جدول `categories` خالی باشد — بدون این شرط، اجرای دوباره روی دیتابیس دارای داده همه را تکراری اضافه می‌کرد.
- بقیه‌ی جداول و ستون‌ها با فایل‌های `migration_*.sql` اضافه شده‌اند (کیف‌پول، بودجه، چک، دارایی، پس‌انداز، تکرارشونده، آیکون دسته‌ها، ایندکس‌ها).
- **ترتیب اجرا الفبایی نیست.** ترتیب درست (با خواندن خود فایل‌ها بررسی شده) در `deploy/db-init.sh` ثبت شده است؛ برای دیتابیس تازه همان را اجرا کنید.
- `migration_repair.sql` **جایگزین کامل** `migration_wallets.sql` و `migration_p1.sql` است (همان جدول‌ها را می‌سازد، ولی idempotent). آن دو فایل برای نصب تازه اجرا نشوند.
- `migration_indexes.sql` باید **بعد از** `migration_cheques_assets.sql` بیاید، چون روی جدول `cheques` ایندکس می‌زند. این فایل idempotent نیست (`ADD INDEX` بدون `IF NOT EXISTS`) و اجرای دوباره‌اش خطای «ایندکس تکراری» می‌دهد — `db-init.sh` این خطا را می‌شناسد و رد می‌کند.
- همه‌ی فایل‌ها به‌جز `migration_indexes.sql` ایدمپوتنت‌اند؛ روی MariaDB 10.11 تست شده که اجرای کامل روی یک دیتابیس دارای داده، داده را تغییر نمی‌دهد.
- **هیچ جدول ردیابی migration وجود ندارد** — این نخستین بدهی فنی است که برای ارتقاء باید حل شود.
- تغییر ساختار جدید = یک فایل `migration_*.sql` تازه (idempotent بنویسید: `IF NOT EXISTS` / بررسی ستون)، به‌علاوه به‌روزرسانی `schema.sql` برای نصب‌های تازه.

---

## بررسی وضعیت هنگام ایمپورت (۱۴۰۵/۰۶/۰۵)

انجام شد روی کل سورس:

- ✅ `php -l` روی هر ۸۶ فایل PHP — بدون خطای نحوی (با PHP 8.4)
- ✅ اسکن کلیدواژه‌های محرمانه — هیچ رمز، توکن یا کلیدی در سورس نیست؛ فقط `config.example.php`
- ✅ هر ۴۸ اندپوینت `api/` بررسی ورود کاربر دارند
- ✅ ۲۰۴ فراخوانی `prepare()` در برابر ۸ `query()` (بدون پارامتر ورودی) — تزریق SQL دیده نشد
- ✅ **فونت اضافه شد** — `assets/fonts/Vazirmatn.woff2`، نسخه‌ی variable و subset‌شده (۸۵ KB در یک درخواست، به‌جای ۱۵۳ KB در سه). جزئیات در `assets/fonts/README.md`.
- ⚠️ **بدون ردیابی migration** (بالا توضیح داده شد).
- ℹ️ `deploy.php` یک به‌روزرسان تحت وب است که با `DEPLOY_TOKEN` و `hash_equals` محافظت می‌شود. توکن باید طولانی و تصادفی باشد؛ روی VPS بهتر است به‌جای آن از `deploy.sh` استفاده شود.

---

## ⛔ قانون سرور — استقلال کامل پروژه

روی VPS مقصد، سرویس‌های دیگری (دو ربات) در حال کارند. **دفتر مالی کاملاً مستقل است.**

هیچ کد، اسکریپت یا دستوری در این مخزن اجازه ندارد:

- به پوشه، فایل، سرویس systemd، یا دیتابیس هیچ سرویس دیگری روی سرور دست بزند
- سرویس مشترکی را `stop` یا `restart` کند (فقط `reload`، آن هم بعد از تست موفق)
- فایل‌های پیکربندی مشترک را تغییر دهد: `nginx.conf`، `php.ini` سراسری، سایت‌های nginx دیگر، pool های دیگر، قوانین فایروال موجود
- کاربر دیتابیسی با دسترسی گسترده‌تر از `hesab.*` بسازد

منابعی که این پروژه **فقط** حق دارد استفاده کند:

| منبع | مقدار |
|---|---|
| پوشه | `/opt/hesab/app` |
| کاربر سیستمی | `hesab` |
| سایت nginx | `/etc/nginx/sites-{available,enabled}/hesab` |
| pool مربوط به PHP | `/etc/php/<نسخه>/fpm/pool.d/hesab.conf` |
| سوکت | `/run/php/php-hesab.sock` |
| دیتابیس / کاربر | `hesab_db` / `'hesab_user'@'localhost'` با `GRANT ... ON hesab_db.*` |
| بکاپ | `/opt/hesab/backups` و `/etc/cron.d/hesab-backup` |

سه لایه‌ای که این قانون را عملاً تضمین می‌کنند:

1. `deploy/vps-setup.sh` تابع `assert_allowed()` دارد؛ هر نوشتن خارج از فهرست بالا اسکریپت را متوقف می‌کند.
2. pool اختصاصی PHP با `open_basedir=/opt/hesab/app:/tmp:/usr/share/php` و `disable_functions` شامل `exec,shell_exec,system,proc_open,popen`.
3. PHP این اپ با کاربر `hesab` اجرا می‌شود، نه `www-data`.

هنگام افزودن هر اسکریپت یا قابلیت مربوط به سرور، همین قانون رعایت شود.

---

## نصب و استقرار

- نصب روی هاست اشتراکی: `README.md`
- راه‌اندازی روی VPS: `DEPLOY.md` — شامل قانون جداسازی و انتقال داده از هاست اشتراکی
- گزارش وضعیت سرور پیش از نصب (فقط خواندنی): `deploy/vps-preflight.sh`
- ساخت ساختار دیتابیس با ترتیب درست: `deploy/db-init.sh`
- نصب روی VPS (پیش‌فرض نمایشی؛ با `--apply` اجرا می‌شود): `deploy/vps-setup.sh`
- به‌روزرسانی روی سرور: `./deploy.sh`
- بکاپ روزانه دیتابیس: `deploy/backup.sh --install-cron`
- `deploy.php` فقط برای هاست اشتراکی بود؛ روی VPS `DEPLOY_TOKEN` تعریف نشود تا غیرفعال بماند.

`config/config.php` و `uploads/` هرگز وارد گیت نمی‌شوند و در استقرار دست‌نخورده می‌مانند.

## پیش از هر push

```bash
find . -name '*.php' -not -path './.git/*' -exec php -l {} \; | grep -v 'No syntax errors'
```
خروجی باید خالی باشد.
