# راه‌اندازی روی VPS

هدف: دیگر هیچ فایلی دستی کپی نشود. هر تغییر با یک `git pull` روی سرور می‌نشیند.

---

## یک‌بار برای همیشه — راه‌اندازی اولیه

### ۱. مخزن گیت بسازید

روی گیت‌هاب یک مخزن **خصوصی** بسازید (چون داده مالی است، حتماً خصوصی).

روی کامپیوتر یا گوشی، داخل پوشه‌ی پروژه:

```bash
git init
git add .
git commit -m "نسخه اولیه"
git remote add origin git@github.com:USERNAME/hesab.git
git push -u origin main
```

> `config/config.php` و پوشه‌ی `uploads/` به‌خاطر `.gitignore` وارد گیت نمی‌شوند — این عمدی است.

### ۲. پیش‌نیازها روی VPS

```bash
sudo apt update
sudo apt install -y nginx php-fpm php-mysql php-gd php-zip php-mbstring php-curl mariadb-server git
```

> `php-gd` برای کوچک‌سازی عکس پروفایل لازم است. بدون آن آپلود عکس محدود می‌شود.

### ۳. دیتابیس

```bash
sudo mysql -e "CREATE DATABASE hesab CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci;"
sudo mysql -e "CREATE USER 'hesab'@'localhost' IDENTIFIED BY 'یک-رمز-قوی';"
sudo mysql -e "GRANT ALL PRIVILEGES ON hesab.* TO 'hesab'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
```

### ۴. انتقال داده از هاست فعلی

از phpMyAdmin هاست اشتراکی، کل دیتابیس را Export کنید (فرمت SQL). سپس:

```bash
# فایل را روی سرور بگذارید، بعد:
mysql -u hesab -p hesab < backup.sql
```

پوشه‌ی `uploads/` را هم از هاست دانلود و روی سرور در `/var/www/hesab/uploads` بگذارید.

### ۵. گرفتن پروژه

```bash
sudo mkdir -p /var/www/hesab
sudo chown -R $USER:$USER /var/www/hesab
git clone git@github.com:USERNAME/hesab.git /var/www/hesab
cd /var/www/hesab
```

### ۶. تنظیمات

```bash
cp config/config.example.php config/config.php
nano config/config.php
```

این مقادیر را بگذارید:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'hesab');
define('DB_USER', 'hesab');
define('DB_PASS', 'همان رمز قوی');
define('APP_BASE_PATH', '');        // چون روی ریشه‌ی دامنه است، خالی
define('APP_FORCE_HTTPS', true);
```

### ۷. nginx

```bash
sudo cp nginx.conf.example /etc/nginx/sites-available/hesab
sudo nano /etc/nginx/sites-available/hesab     # دامنه و نسخه PHP را عوض کنید
sudo ln -s /etc/nginx/sites-available/hesab /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### ۸. گواهی SSL

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d hesab.example.com
```

### ۹. اجرای migration ها

از phpMyAdmin یا مستقیم:

```bash
for f in migration_*.sql; do echo "→ $f"; mysql -u hesab -p hesab < "$f"; done
```

### ۱۰. اجازه اجرای اسکریپت استقرار

```bash
chmod +x deploy.sh
```

---

## کار روزمره — از این به بعد

**روی گوشی یا کامپیوتر:**

```bash
git add .
git commit -m "توضیح تغییر"
git push
```

**روی سرور:**

```bash
cd /var/www/hesab && ./deploy.sh
```

تمام. دیگر خبری از zip و کپی دستی نیست.

اسکریپت خودش:
- آخرین نسخه را می‌گیرد
- دسترسی فایل‌ها را درست می‌کند
- کش PHP را پاک می‌کند
- اگر migration جدیدی بود، هشدار می‌دهد
- `config/config.php` و `uploads/` را دست نمی‌زند

---

## استقرار خودکار (اختیاری)

اگر نمی‌خواهید هر بار SSH بزنید، روی سرور یک وب‌هوک بگذارید که با هر push خودش `deploy.sh` را اجرا کند. این کار امنیت‌سنجی جدا لازم دارد؛ هر وقت خواستید بگویید تا با هم راه بیندازیم.

---

## نکته‌ی مهم درباره‌ی سرعت

جابه‌جایی به VPS **مشکل کپی کردن فایل را قطعاً حل می‌کند**، اما تضمینی نیست که آن تأخیر ۴۱۵ میلی‌ثانیه‌ی شبکه کم شود — آن به مسیر شبکه بین گوشی شما و دیتاسنتر بستگی دارد، نه به قدرت سرور.

پیش از انتقال کامل، این را اندازه بگیرید: بعد از راه‌اندازی، `profile_page.php` را روی VPS باز کنید و عدد تأخیر را با هاست فعلی مقایسه کنید.

در عوض این‌ها را قطعاً به دست می‌آورید:

- استقرار با یک دستور به‌جای کپی دستی
- کنترل کامل روی nginx و PHP (فشرده‌سازی و کش درست، بدون دور زدن محدودیت‌ها)
- تاریخچه‌ی کامل تغییرات و امکان برگشت به هر نسخه
- بدون محدودیت منابع اشتراکی

در مقابل، نگهداری سرور (به‌روزرسانی امنیتی، پشتیبان‌گیری، فایروال) به عهده‌ی خودتان است.
