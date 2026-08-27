# راه‌اندازی روی VPS

سروری که این پروژه رویش می‌نشیند، از قبل سرویس‌های دیگری دارد (دو ربات).
تمام این راهنما بر یک اصل بنا شده:

> **دفتر مالی کاملاً مستقل است و هیچ‌چیز آن نباید به سرویس‌های دیگر سرور دست بزند.**

---

## قانون جداسازی

این پروژه فقط اجازه‌ی این منابع را دارد:

| منبع | مقدار |
|---|---|
| پوشه | `/opt/hesab/app` |
| کاربر سیستمی | `hesab` (بدون shell) |
| سایت nginx | `/etc/nginx/sites-{available,enabled}/hesab` |
| pool مربوط به PHP | `/etc/php/<نسخه>/fpm/pool.d/hesab.conf` |
| سوکت PHP | `/run/php/php-hesab.sock` |
| دیتابیس | `hesab_db` |
| کاربر دیتابیس | `'hesab_user'@'localhost'` — فقط با دسترسی روی `hesab_db.*` |

و اجازه‌ی این‌ها را **ندارد**:

- دست زدن به هر سایت nginx دیگر، یا به `/etc/nginx/nginx.conf`
- دست زدن به هر سرویس systemd دیگر — نه stop، نه restart، نه disable
- دست زدن به `php.ini` سراسری یا pool های دیگر
- خواندن یا نوشتن در پوشه‌ی ربات‌ها
- دسترسی به دیتابیس ربات‌ها (`GRANT` عمداً روی `hesab.*` است، نه `*.*`)
- تغییر قوانین فایروال موجود

سه لایه این قانون را عملاً تضمین می‌کنند:

1. **`deploy/vps-setup.sh`** یک نگهبان مسیر دارد؛ هر نوشتن خارج از فهرست بالا اسکریپت را کامل متوقف می‌کند.
2. **pool اختصاصی PHP** با `open_basedir` محدود به `/opt/hesab/app` است — کد PHP این اپ حتی اگر بخواهد هم نمی‌تواند فایل‌های ربات‌ها را بخواند. `exec`, `shell_exec`, `system` و مشابه‌ها هم غیرفعال‌اند.
3. **کاربر جدا** — PHP این اپ با کاربر `hesab` اجرا می‌شود، نه `www-data` و نه کاربر ربات‌ها.

هیچ‌جای این راهنما `systemctl restart` روی سرویس مشترک نیست؛ فقط `reload` آن هم بعد از `nginx -t` موفق.

---

## مرحله ۰ — گزارش وضعیت سرور (اجباری، فقط خواندنی)

پیش از هر کاری:

```bash
bash deploy/vps-preflight.sh
```

این اسکریپت هیچ چیزی نصب/تغییر/حذف نمی‌کند. فقط گزارش می‌دهد: نسخه‌ی سیستم، پورت‌های اشغال، سایت‌های nginx موجود، pool های PHP، دیتابیس‌های موجود، و سرویس‌های در حال اجرا.

**مهم‌ترین چیزی که باید ببینید: پورت ۸۰ و ۴۴۳ دست کیست.**
اگر یکی از ربات‌ها مستقیم روی پورت ۸۰ webhook دارد، nginx نمی‌تواند آن را بگیرد و باید اول تکلیف آن روشن شود. `vps-setup.sh` هم خودش همین را بررسی می‌کند و اگر پورت دست چیزی غیر از nginx باشد، متوقف می‌شود و ربات را دست نمی‌زند.

---

## مرحله ۱ — پیش‌نیازها

فقط چیزهایی را نصب کنید که preflight گفت نیست:

```bash
sudo apt update
sudo apt install -y nginx mariadb-server git \
     php8.3-fpm php8.3-mysql php8.3-gd php8.3-zip php8.3-mbstring php8.3-curl
```

> نصب پکیج تازه به ربات‌ها کاری ندارد. اما اگر `nginx` از قبل نصب است، دوباره نصبش نکنید.
> `php-gd` برای کوچک‌سازی عکس پروفایل لازم است.

---

## مرحله ۲ — مخزن

روی گیت‌هاب مخزن **خصوصی** بسازید (داده‌ی مالی است). این پروژه از قبل در
`firouzayazi-source/sthesab` هست.

```bash
sudo mkdir -p /opt/hesab/app
sudo chown "$USER":"$USER" /opt/hesab/app
git clone https://github.com/firouzayazi-source/sthesab.git /opt/hesab/app
```

> `config/config.php` و `uploads/` به‌خاطر `.gitignore` وارد گیت نمی‌شوند — عمدی است.

---

## مرحله ۳ — نصب

اول در حالت نمایشی، تا ببینید دقیقاً چه می‌خواهد بکند:

```bash
bash deploy/vps-setup.sh --domain hesab.stland.ir
```

خروجی را کامل بخوانید. هر خط خاکستری یعنی «این دستور اجرا می‌شود». وقتی راضی بودید:

```bash
sudo bash deploy/vps-setup.sh --domain hesab.stland.ir --apply
```

اسکریپت این‌ها را انجام می‌دهد: کاربر `hesab`، دسترسی فایل‌ها، pool اختصاصی PHP، سایت nginx، و `reload`. دیتابیس را عمداً خودش نمی‌سازد — دستورهایش را چاپ می‌کند تا خودتان با رمز دلخواه اجرا کنید.

### دیتابیس

```bash
sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`hesab_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS 'hesab_user'@'localhost' IDENTIFIED BY 'یک-رمز-قوی';"
sudo mysql -e "GRANT ALL PRIVILEGES ON \`hesab_db\`.* TO 'hesab_user'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
```

`GRANT` روی `hesab_db.*` است و نه `*.*` — یعنی این کاربر حتی اگر لو برود، به دیتابیس ربات‌ها دسترسی ندارد.

### تنظیمات

```bash
sudo cp /opt/hesab/app/config/config.example.php /opt/hesab/app/config/config.php
sudo nano /opt/hesab/app/config/config.php
```

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'hesab_db');
define('DB_USER', 'hesab_user');
define('DB_PASSWORD', 'همان رمز قوی');
define('APP_BASE_PATH', '');        // روی ریشه‌ی دامنه است، پس خالی
define('APP_FORCE_HTTPS', true);
define('APP_SECRET_KEY', '...');    // با openssl rand -hex 32 بسازید
```

```bash
sudo chown hesab:hesab /opt/hesab/app/config/config.php
sudo chmod 640 /opt/hesab/app/config/config.php
```

> **`DEPLOY_TOKEN` را روی VPS تعریف نکنید.** `deploy.php` (به‌روزرسان تحت وب) برای هاست اشتراکی بود. اینجا `deploy.sh` را دارید که امن‌تر است. بدون `DEPLOY_TOKEN`، فایل `deploy.php` خودش خطای ۵۰۰ می‌دهد و کاری نمی‌کند.

---

## مرحله ۴ — انتقال داده از هاست اشتراکی

این مرحله را **پیش از تغییر DNS** انجام دهید تا سایت جدید را کامل تست کنید و بعد سوییچ کنید.

### ۴.۱ دیتابیس

از phpMyAdmin هاست فعلی: `Export` → فرمت `SQL` → کل دیتابیس. سپس روی سرور:

```bash
mysql -u hesab_user -p hesab_db < backup.sql
```

بعد بررسی کنید همه‌چیز آمده:

```bash
mysql -u hesab_user -p hesab_db -e "SELECT COUNT(*) AS tx FROM transactions; SELECT COUNT(*) AS users FROM users;"
```

عدد تراکنش‌ها باید با چیزی که در هاست فعلی می‌بینید یکی باشد.

### ۴.۲ فایل‌های آپلودشده

پوشه‌ی `uploads/` (رسیدها و عکس‌های پروفایل) در گیت نیست و باید دستی منتقل شود.
از هاست دانلودش کنید (FTP یا File Manager → فشرده کنید و بگیرید)، بعد:

```bash
sudo -u hesab mkdir -p /opt/hesab/app/uploads/avatars
sudo unzip uploads.zip -d /opt/hesab/app/
sudo chown -R hesab:hesab /opt/hesab/app/uploads
sudo chmod -R 755 /opt/hesab/app/uploads
```

### ۴.۳ migration ها

اگر دیتابیس هاست فعلی همه‌ی migration ها را داشته، لازم نیست دوباره اجرا شوند —
export شامل ساختار کامل است. اگر دیتابیس تازه می‌سازید:

```bash
cd /opt/hesab/app
bash deploy/db-init.sh            # فقط ترتیب را نشان می‌دهد
bash deploy/db-init.sh --apply    # اجرا
```

ترتیب داخل اسکریپت ثبت شده و روی خطا متوقف می‌شود. دو نکته که در آن رعایت شده:

- `migration_repair.sql` جایگزین کامل `migration_wallets.sql` و `migration_p1.sql` است؛ آن دو نباید اجرا شوند.
- `migration_indexes.sql` باید بعد از `migration_cheques_assets.sql` بیاید (روی جدول `cheques` ایندکس می‌زند).

### ۴.۴ تست پیش از سوییچ DNS

روی کامپیوتر خودتان (نه سرور) در فایل hosts یک خط اضافه کنید تا فقط برای خودتان دامنه به سرور جدید اشاره کند:

```
IP_SERVER    hesab.stland.ir
```

سایت را باز کنید، وارد شوید، چند تراکنش و گزارش را چک کنید. وقتی مطمئن شدید، خط را بردارید و DNS واقعی را عوض کنید.

### ۴.۵ گواهی SSL

بعد از اینکه DNS به سرور جدید اشاره کرد:

```bash
sudo certbot --nginx -d hesab.stland.ir
```

certbot فقط سایت `hesab` را تغییر می‌دهد؛ به سایت‌های دیگر کاری ندارد.

---

## کار روزمره

**روی کامپیوتر/گوشی:**

```bash
git add .
git commit -m "توضیح تغییر"
git push
```

**روی سرور:**

```bash
cd /opt/hesab/app && ./deploy.sh
```

`deploy.sh` آخرین نسخه را می‌گیرد، دسترسی‌ها را درست می‌کند، pool مربوط به PHP را reload می‌کند و اگر migration جدیدی بود هشدار می‌دهد. `config/config.php` و `uploads/` را دست نمی‌زند.

---

## اگر چیزی خراب شد

همه‌ی اثر این پروژه روی سرور، همان فهرست «قانون جداسازی» بالاست. برای برگرداندن کامل:

```bash
sudo rm -f /etc/nginx/sites-enabled/hesab /etc/nginx/sites-available/hesab
sudo rm -f /etc/php/*/fpm/pool.d/hesab.conf
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl reload php8.3-fpm
```

سرویس‌های دیگر سرور از این کار هیچ اثری نمی‌بینند.

---

## نکته درباره‌ی سرعت

جابه‌جایی به VPS **مشکل کپی دستی فایل را قطعاً حل می‌کند**، اما تضمینی نیست که تأخیر شبکه کم شود — آن به مسیر شبکه بین شما و دیتاسنتر بستگی دارد، نه به قدرت سرور. بعد از راه‌اندازی، زمان بارگذاری را با هاست فعلی مقایسه کنید.

قطعاً به دست می‌آورید: استقرار با یک دستور، کنترل کامل روی nginx و PHP (فشرده‌سازی و کش درست)، تاریخچه‌ی کامل تغییرات، و بدون محدودیت منابع اشتراکی.
در مقابل، نگهداری سرور (به‌روزرسانی امنیتی، پشتیبان‌گیری، فایروال) به عهده‌ی خودتان است.
