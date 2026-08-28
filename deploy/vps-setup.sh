#!/usr/bin/env bash
#
# vps-setup.sh — راه‌اندازی «دفتر مالی» روی VPS، بدون دست زدن به هیچ سرویس دیگر
#
# ─── قانون این اسکریپت ────────────────────────────────────────────────
# روی این سرور سرویس‌های دیگری (از جمله دو ربات) در حال کار هستند.
# این اسکریپت فقط اجازه دارد به این مسیرها بنویسد:
#
#     /opt/hesab/app                       ← فایل‌های پروژه
#     /etc/nginx/sites-available/hesab     ← فقط همین یک فایل
#     /etc/nginx/sites-enabled/hesab       ← فقط همین یک لینک
#     /etc/php/<v>/fpm/pool.d/hesab.conf   ← pool اختصاصی
#     دیتابیس hesab_db و کاربر 'hesab_user'@'localhost'
#     کاربر سیستمی hesab
#
# هر تلاش برای نوشتن خارج از این فهرست، اسکریپت را متوقف می‌کند.
# هیچ سرویسی restart نمی‌شود (فقط reload، آن هم بعد از تست موفق).
# هیچ چیزی حذف نمی‌شود. هیچ فایل پیکربندی مشترکی (php.ini، nginx.conf،
# سایت‌های دیگر، قوانین فایروال موجود) تغییر نمی‌کند.
# ──────────────────────────────────────────────────────────────────────
#
# اجرا:
#     bash deploy/vps-setup.sh --domain hesab.stland.ir            # فقط نشان می‌دهد چه می‌کند
#     sudo bash deploy/vps-setup.sh --domain hesab.stland.ir --apply   # واقعاً اجرا می‌کند
#
# پیش از --apply حتماً یک بار بدون آن اجرا کنید و خروجی را بخوانید.

set -euo pipefail

APP_DIR="${APP_DIR:-/opt/hesab/app}"
APP_USER="${APP_USER:-hesab}"
DB_NAME="${DB_NAME:-hesab_db}"
DB_USER="${DB_USER:-hesab_user}"
SITE_NAME="${SITE_NAME:-hesab}"
REPO_URL="${REPO_URL:-}"
DOMAIN=""
APPLY=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain) DOMAIN="${2:-}"; shift 2 ;;
        --repo)   REPO_URL="${2:-}"; shift 2 ;;
        --apply)  APPLY=1; shift ;;
        -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
        *) echo "گزینه ناشناخته: $1"; exit 1 ;;
    esac
done

[[ -z "$DOMAIN" ]] && { echo "دامنه را بدهید:  --domain hesab.stland.ir"; exit 1; }

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
info()  { printf '\033[0;36m%s\033[0m\n' "$1"; }
step()  { printf '\n\033[1m── %s\033[0m\n' "$1"; }

# ---------- نگهبان مسیرها ----------
# هر نوشتنی باید از این تابع رد شود. مسیر خارج از فهرست مجاز = توقف کامل.
PHP_VER=""
assert_allowed() {
    local path="$1" p
    for p in "$APP_DIR" \
             "/etc/nginx/sites-available/$SITE_NAME" \
             "/etc/nginx/sites-enabled/$SITE_NAME" \
             "/etc/php/${PHP_VER}/fpm/pool.d/${SITE_NAME}.conf"; do
        [[ -n "$p" && ( "$path" == "$p" || "$path" == "$p"/* ) ]] && return 0
    done
    red "⛔ توقف: تلاش برای نوشتن خارج از محدوده‌ی مجاز این پروژه:"
    red "   $path"
    red "   این یعنی باگ در اسکریپت. هیچ تغییری اعمال نشد."
    exit 1
}

run() {   # اجرای دستور — در حالت نمایشی فقط چاپ می‌شود
    if [[ $APPLY -eq 1 ]]; then
        eval "$@"
    else
        printf '  \033[0;90m$ %s\033[0m\n' "$*"
    fi
}

write_file() {   # write_file <path> <<< محتوا
    local path="$1"
    assert_allowed "$path"
    if [[ $APPLY -eq 1 ]]; then
        mkdir -p "$(dirname "$path")"
        cat > "$path"
        green "  نوشته شد: $path"
    else
        printf '  \033[0;90m→ می‌نویسد: %s\033[0m\n' "$path"
        cat > /dev/null
    fi
}

if [[ $APPLY -eq 0 ]]; then
    printf '\n\033[1;33m*** حالت نمایشی — هیچ تغییری اعمال نمی‌شود ***\033[0m\n'
    printf '\033[0;33mبرای اجرای واقعی، بعد از خواندن خروجی، --apply را اضافه کنید.\033[0m\n'
elif [[ $EUID -ne 0 ]]; then
    red "برای --apply باید با sudo اجرا شود."; exit 1
fi

# ---------- ۰. بررسی تداخل ----------
step "۰. بررسی تداخل با سرویس‌های موجود"

for port in 80 443; do
    if command -v ss >/dev/null 2>&1 && ss -lnt 2>/dev/null | awk '{print $4}' | grep -qE "[:.]$port\$"; then
        holder=$(ss -lntp 2>/dev/null | grep -E "[:.]$port\s" | grep -oP 'users:\(\("\K[^"]+' | head -1)
        if [[ "$holder" == "nginx" ]]; then
            info "پورت $port در اختیار nginx است — خوب است، فقط یک server block اضافه می‌کنیم."
        else
            red "⚠️  پورت $port در اختیار «${holder:-نامشخص}» است، نه nginx."
            red "    اگر این یکی از ربات‌های شماست، متوقفش نمی‌کنیم."
            red "    اسکریپت اینجا می‌ایستد تا خودتان تصمیم بگیرید."
            exit 1
        fi
    fi
done

SITE_FILE="/etc/nginx/sites-available/$SITE_NAME"
SKIP_NGINX=0
if [[ -e "$SITE_FILE" ]]; then
    if grep -q "ssl_certificate" "$SITE_FILE" 2>/dev/null; then
        # certbot این فایل را برای HTTPS بازنویسی کرده. بازنویسی دوباره‌ی آن
        # با قالب HTTP، گواهی را از پیکربندی حذف می‌کند و سایت از HTTPS
        # می‌افتد. پس دست نمی‌زنیم.
        SKIP_NGINX=1
        info "سایت «$SITE_NAME» گواهی SSL دارد (کار certbot) — دست‌نخورده می‌ماند."
        info "اگر عمداً می‌خواهید از نو ساخته شود: mv $SITE_FILE $SITE_FILE.bak"
        info "و بعد از اجرای دوباره‌ی این اسکریپت، certbot را دوباره بزنید."
    else
        info "سایت nginx به نام «$SITE_NAME» از قبل هست — بازنویسی می‌شود (فقط همین فایل)."
    fi
fi

for f in /etc/nginx/sites-enabled/*; do
    [[ -e "$f" ]] || continue
    [[ "$(basename "$f")" == "$SITE_NAME" ]] && continue
    if grep -qE "server_name[^;]*\b${DOMAIN//./\\.}\b" "$f" 2>/dev/null; then
        red "⛔ دامنه $DOMAIN از قبل در سایت دیگری تعریف شده: $f"
        red "    به آن فایل دست نمی‌زنیم. دامنه‌ی دیگری انتخاب کنید یا خودتان بررسی کنید."
        exit 1
    fi
done
green "تداخلی پیدا نشد."

# ---------- ۱. نسخه PHP ----------
step "۱. تشخیص نسخه PHP"
if compgen -G "/etc/php/*/fpm" >/dev/null 2>&1; then
    PHP_VER=$(basename "$(ls -d /etc/php/*/fpm 2>/dev/null | sort -V | tail -1 | xargs dirname)")
    green "php-fpm نسخه $PHP_VER پیدا شد."
elif command -v php >/dev/null 2>&1; then
    PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
    info "php-fpm نصب نیست ولی PHP $PHP_VER هست. لازم است نصب شود:"
    info "  sudo apt install php${PHP_VER}-fpm php${PHP_VER}-mysql php${PHP_VER}-gd php${PHP_VER}-mbstring php${PHP_VER}-zip"
    [[ $APPLY -eq 1 ]] && { red "اول php-fpm را نصب کنید، بعد دوباره اجرا کنید."; exit 1; }
else
    red "PHP نصب نیست. اول نصبش کنید (نسخه ۸.۰ به بالا):"
    red "  sudo apt install php-fpm php-mysql php-gd php-mbstring php-zip"
    exit 1
fi

# ---------- ۲. کاربر سیستمی ----------
step "۲. کاربر سیستمی اختصاصی «$APP_USER»"
if id -u "$APP_USER" >/dev/null 2>&1; then
    green "کاربر $APP_USER از قبل هست."
else
    info "ساخته می‌شود — کاربر بدون shell، فقط برای اجرای این اپ."
    run "useradd --system --home-dir '$APP_DIR' --shell /usr/sbin/nologin '$APP_USER'"
fi

# ---------- ۳. فایل‌های پروژه ----------
step "۳. فایل‌های پروژه در $APP_DIR"
assert_allowed "$APP_DIR"
if [[ -d "$APP_DIR/.git" ]]; then
    green "مخزن از قبل هست — با ./deploy.sh به‌روزش کنید."
elif [[ -n "$REPO_URL" ]]; then
    run "mkdir -p '$APP_DIR'"
    run "git clone '$REPO_URL' '$APP_DIR'"
else
    info "آدرس مخزن را با --repo بدهید، یا فایل‌ها را دستی در $APP_DIR بگذارید."
fi
# مدل مالکیت:
#   کد        → root، فقط‌خواندنی برای اپ. یعنی PHP نمی‌تواند کد خودش را
#                عوض کند، و git هم بدون خطای «مالکیت مشکوک» کار می‌کند.
#   uploads   → کاربر اپ، چون تنها جایی است که باید در آن بنویسد.
#   config.php → root:hesab با 640؛ اپ می‌خواند، کاربران دیگر سرور نه.
run "mkdir -p '$APP_DIR/uploads/avatars'"
run "chown -R root:root '$APP_DIR'"
run "find '$APP_DIR' -type d -not -path '*/.git/*' -exec chmod 755 {} +"
run "find '$APP_DIR' -type f -not -path '*/.git/*' -exec chmod 644 {} +"
# بیت اجرای اسکریپت‌ها باید برگردد: گیت مود فایل را ردیابی می‌کند و
# بدون این، هر git pull بعدی با «تغییرات محلی» شکست می‌خورد.
run "find '$APP_DIR' -name '*.sh' -not -path '*/.git/*' -exec chmod 755 {} +"
run "chown -R '$APP_USER':'$APP_USER' '$APP_DIR/uploads'"
run "chmod 755 '$APP_DIR/uploads' '$APP_DIR/uploads/avatars'"
# پوشه‌ی نشست‌ها — فقط کاربر اپ، هیچ‌کس دیگر
run "mkdir -p '$APP_DIR/var/sessions'"
run "chown -R '$APP_USER':'$APP_USER' '$APP_DIR/var'"
run "chmod 700 '$APP_DIR/var/sessions'"

# ---------- ۴. دیتابیس ----------
step "۴. دیتابیس"
info "دسترسی کاربر دیتابیس فقط روی «$DB_NAME» است — نه روی دیتابیس ربات‌ها."
cat <<SQL

  # این‌ها را خودتان اجرا کنید (رمز را جای YOUR_STRONG_PASSWORD بگذارید):
  sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci;"
  sudo mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY 'YOUR_STRONG_PASSWORD';"
  sudo mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
  sudo mysql -e "FLUSH PRIVILEGES;"

  # توجه: GRANT فقط روی $DB_NAME.* است، نه *.* — این عمدی است.
SQL

# ---------- ۵. pool اختصاصی php-fpm ----------
step "۵. pool اختصاصی php-fpm"
info "این pool تازه است و pool های موجود (اگر باشند) دست‌نخورده می‌مانند."
info "با open_basedir، کد PHP این اپ حتی به‌طور تصادفی هم نمی‌تواند بیرون از $APP_DIR را بخواند."
write_file "/etc/php/${PHP_VER}/fpm/pool.d/${SITE_NAME}.conf" <<POOL
; pool اختصاصی «دفتر مالی»
; این فایل فقط به این پروژه مربوط است و روی pool های دیگر اثری ندارد.
[${SITE_NAME}]
user  = ${APP_USER}
group = ${APP_USER}

listen = /run/php/php-${SITE_NAME}.sock
listen.owner = www-data
listen.group = www-data
listen.mode  = 0660

; سرور ۳.۸ گیگ RAM دارد و postgres، docker و uvicorn هم رویش هستند.
; ondemand یعنی تا وقتی کسی سایت را باز نکند، هیچ پروسه‌ای زنده نیست.
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 30s
pm.max_requests = 500

; حصار امنیتی: PHP این اپ فقط همین مسیرها را می‌بیند.
; هر تلاشی برای خواندن مسیر ربات‌ها یا هر جای دیگر سرور، خطا می‌دهد.
php_admin_value[open_basedir] = ${APP_DIR}:/tmp:/usr/share/php
php_admin_value[upload_tmp_dir] = /tmp

; نشست‌ها داخل خود پروژه ذخیره می‌شوند، نه در /var/lib/php/sessions که
; بین همه‌ی اپ‌های PHP سرور مشترک است. دو دلیل:
;   ۱. مسیر پیش‌فرض بیرون از open_basedir است و session_start شکست می‌خورد
;   ۲. جداسازی: کوکی نشست کاربران این اپ در قلمرو خودش می‌ماند
php_admin_value[session.save_path] = ${APP_DIR}/var/sessions
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen
php_admin_value[upload_max_filesize] = 12M
php_admin_value[post_max_size] = 14M
php_admin_value[memory_limit] = 192M
php_admin_flag[expose_php] = off
php_admin_value[date.timezone] = Asia/Tehran
POOL

# ---------- ۶. سایت nginx ----------
step "۶. سایت nginx"
if [[ $SKIP_NGINX -eq 1 ]]; then
    info "رد شد — سایت گواهی‌دار موجود دست‌نخورده ماند."
else
info "فقط یک server block تازه برای $DOMAIN اضافه می‌شود."
write_file "/etc/nginx/sites-available/${SITE_NAME}" <<NGINX
# دفتر مالی — این فایل فقط به همین پروژه مربوط است.
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${APP_DIR};
    index index.php;

    # پس از گرفتن گواهی، certbot خودش این بلاک را به HTTPS ارتقا می‌دهد:
    #   sudo certbot --nginx -d ${DOMAIN}

    gzip on;
    gzip_vary on;
    gzip_min_length 512;
    gzip_comp_level 5;
    gzip_types text/plain text/css text/javascript application/javascript
               application/json application/manifest+json image/svg+xml;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    client_max_body_size 12M;

    location ~* \.(css|js|woff2?|png|jpe?g|webp|svg|ico)\$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
        try_files \$uri =404;
    }

    location ^~ /uploads/ {
        location ~ \.php\$ { deny all; }
        expires 30d;
        add_header Cache-Control "private";
    }

    # deploy.php فقط برای هاست اشتراکی بود؛ روی VPS جای git pull را نمی‌گیرد.
    location = /deploy.php { deny all; return 404; }

    # deploy/ ابزار خط فرمان دارد (از جمله بازنشانی رمز) — از وب مسدود
    location ^~ /deploy/ { deny all; return 404; }
    location ^~ /tests/  { deny all; return 404; }

    location ~ ^/(config|\.git)/ { deny all; return 404; }
    location ~ /\.(?!well-known)  { deny all; return 404; }
    location ~ \.(sql|md|sh)\$    { deny all; return 404; }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php-${SITE_NAME}.sock;
        fastcgi_read_timeout 60;
    }

    location / { try_files \$uri \$uri/ =404; }
}
NGINX

assert_allowed "/etc/nginx/sites-enabled/${SITE_NAME}"
run "ln -sfn '/etc/nginx/sites-available/${SITE_NAME}' '/etc/nginx/sites-enabled/${SITE_NAME}'"
fi

# ---------- ۷. اعمال ----------
step "۷. اعمال تغییرات"
info "reload می‌کنیم نه restart — سرویس‌های در حال کار قطع نمی‌شوند."
run "nginx -t"
run "systemctl reload nginx"
run "systemctl reload php${PHP_VER}-fpm"

# ---------- ۸. باقی‌مانده ----------
step "۸. کارهایی که خودتان باید انجام دهید"
cat <<NEXT

  ۱. تنظیمات:
       sudo -u $APP_USER cp $APP_DIR/config/config.example.php $APP_DIR/config/config.php
       sudo nano $APP_DIR/config/config.php
     مقادیر: DB_NAME=$DB_NAME  DB_USER=$DB_USER  DB_PASSWORD=…
             APP_BASE_PATH=''   APP_FORCE_HTTPS=true
             APP_SECRET_KEY را با این عوض کنید:  $(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' 2>/dev/null || echo 'openssl rand -hex 32')
       sudo chown root:$APP_USER $APP_DIR/config/config.php
       sudo chmod 640 $APP_DIR/config/config.php

  ۲. انتقال داده از هاست فعلی — بخش «انتقال از هاست اشتراکی» در DEPLOY.md

  ۳. گواهی SSL:
       sudo certbot --nginx -d $DOMAIN

  ۴. آزمایش: آدرس https://$DOMAIN را باز کنید.

NEXT

if [[ $APPLY -eq 0 ]]; then
    printf '\033[1;33mهیچ تغییری اعمال نشد (حالت نمایشی).\033[0m\n\n'
else
    green "✅ انجام شد."
fi
