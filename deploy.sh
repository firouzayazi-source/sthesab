#!/usr/bin/env bash
#
# deploy.sh — استقرار روی VPS با یک دستور
#
# استفاده:  ./deploy.sh
#
# کاری که می‌کند:
#   ۱. آخرین نسخه را از گیت می‌گیرد
#   ۲. دسترسی فایل‌ها را درست می‌کند
#   ۳. پوشه‌های لازم را می‌سازد
#   ۴. کش PHP را پاک می‌کند تا تغییرات فوراً اعمال شوند
#
# config/config.php و uploads/ هرگز دست نمی‌خورند (در .gitignore هستند).

set -euo pipefail

APP_DIR="${APP_DIR:-/opt/hesab/app}"
APP_USER="${APP_USER:-hesab}"   # کاربر اختصاصی این اپ — نه www-data، نه کاربر سرویس‌های دیگر

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
info()  { printf '\033[0;36m%s\033[0m\n' "$1"; }

cd "$APP_DIR" || { red "پوشه $APP_DIR پیدا نشد."; exit 1; }

# ---------- ۱. هشدار درباره تغییرات محلی ----------
if ! git diff --quiet || ! git diff --cached --quiet; then
    red "روی سرور تغییرات ذخیره‌نشده وجود دارد:"
    git status --short
    read -r -p "این تغییرات دور ریخته شوند؟ (y/N) " answer
    if [[ "${answer,,}" != "y" ]]; then
        red "لغو شد."
        exit 1
    fi
    git reset --hard
fi

# ---------- ۲. دریافت آخرین نسخه ----------
info "در حال دریافت از گیت..."
BEFORE=$(git rev-parse --short HEAD)
git fetch --all --quiet
BRANCH=$(git rev-parse --abbrev-ref HEAD)
git reset --hard "origin/${BRANCH}" --quiet
AFTER=$(git rev-parse --short HEAD)

if [[ "$BEFORE" == "$AFTER" ]]; then
    info "تغییری نبود (نسخه $AFTER)."
else
    green "به‌روزرسانی شد: $BEFORE → $AFTER"
    echo
    info "تغییرات این استقرار:"
    git log --oneline "${BEFORE}..${AFTER}" | sed 's/^/  /'
    echo
fi

# ---------- ۳. پوشه‌های لازم ----------
mkdir -p uploads/avatars

# محافظت از پوشه آپلود در برابر اجرای کد
cat > uploads/.htaccess << 'HTACCESS'
php_flag engine off
Options -ExecCGI -Indexes
<FilesMatch "\.(php|phtml|php3|php4|php5|phar|cgi|pl)$">
    Require all denied
</FilesMatch>
HTACCESS

# ---------- ۴. دسترسی‌ها ----------
# نسخه‌ی مستقرشده، برای نمایش در پروفایل و صفحه‌ی حریم خصوصی.
# ⚠ از git خوانده می‌شود ولی اینجا نوشته می‌شود نه هنگام نمایش: pool این
#   اپ exec/shell_exec را غیرفعال کرده (قانون جداسازی سرور)، پس PHP
#   خودش نمی‌تواند git را صدا بزند.
mkdir -p var
printf '%s · %s\n' "$(git rev-parse --short HEAD 2>/dev/null || echo '?')" \
                    "$(date '+%Y-%m-%d')" > var/version.txt 2>/dev/null || true

info "تنظیم دسترسی‌ها..."
# کد متعلق به root می‌ماند تا نه PHP بتواند تغییرش دهد و نه git خطای
# «مالکیت مشکوک» بدهد. فقط uploads متعلق به کاربر اپ است.
chown -R root:root . 2>/dev/null || true
find . -type d -not -path './.git/*' -exec chmod 755 {} \;
find . -type f -not -path './.git/*' -exec chmod 644 {} \;
# گیت بیت اجرا را ردیابی می‌کند؛ بدون این خط، git pull بعدی می‌شکند.
find . -name '*.sh' -not -path './.git/*' -exec chmod 755 {} \;
if id -u "$APP_USER" >/dev/null 2>&1; then
    chown -R "$APP_USER":"$APP_USER" uploads

    # ⚠️ پوشه‌ی نشست‌ها هم مثل uploads باید به کاربر اپ برگردد.
    #
    # این خط یک بار جا افتاده بود و باگی ساخت که هیچ ردی در لاگ نداشت:
    # `session.save_path` این pool داخل خودِ پوشه‌ی اپ است
    # (var/sessions)، پس `chown -R root:root .` بالاتر آن را هم مال root
    # می‌کرد و PHP — که با کاربر اپ اجرا می‌شود — دیگر نمی‌توانست فایل
    # نشست بنویسد.
    #
    # نتیجه‌اش این بود: هر درخواست یک نشستِ خالیِ تازه می‌گرفت، پس توکن
    # CSRF هرگز نمی‌ماند و صفحه‌ی ورود در حلقه‌ی «نشست شما منقضی شده بود»
    # گیر می‌کرد. کاربر اصلاً نمی‌توانست وارد شود و هیچ خطایی هم دیده
    # نمی‌شد. هر بار اجرای این اسکریپت دوباره خرابش می‌کرد.
    mkdir -p var/sessions
    chown -R "$APP_USER":"$APP_USER" var
    chmod 700 var/sessions

    # و واقعاً آزموده می‌شود — مثل config.php. اگر ننویسد، سکوت بدترین
    # حالت است چون اپ بالا می‌آید ولی هیچ‌کس نمی‌تواند وارد شود.
    if sudo -u "$APP_USER" test -w var/sessions 2>/dev/null; then
        :
    else
        red "⛔ کاربر $APP_USER نمی‌تواند در var/sessions بنویسد."
        red "   با این وضع هیچ‌کس نمی‌تواند وارد شود («نشست شما منقضی شده بود»)."
        red "   بررسی کنید:  ls -ld $APP_DIR/var $APP_DIR/var/sessions"
    fi
else
    red "کاربر $APP_USER وجود ندارد — اول deploy/vps-setup.sh را اجرا کنید."
fi
chmod +x deploy.sh 2>/dev/null || true

# تنظیمات فقط برای خود سرور خوانده شود.
#
# ⚠️ ترتیب مهم است: اول گروه به کاربر اپ، بعد 640. اگر chown شکست بخورد
# و 640 اجرا شود، فایل مال root:root می‌ماند و PHP (که با $APP_USER
# اجرا می‌شود) دیگر نمی‌تواند بخواند — کل اپ ۵۰۰ می‌دهد. یک بار همین
# در deploy/mail-setup.sh اتفاق افتاد و سایت خوابید. پس آخرش واقعاً
# می‌آزماییم و اگر خوانده نشد، برمی‌گردیم به 644.
if [[ -f config/config.php ]]; then
    if chown root:"$APP_USER" config/config.php 2>/dev/null \
       && chmod 640 config/config.php 2>/dev/null \
       && sudo -u "$APP_USER" test -r config/config.php 2>/dev/null; then
        :
    else
        chmod 644 config/config.php 2>/dev/null || true
        red "config.php نتوانست 640 بماند — 644 شد تا اپ بالا بماند."
    fi
fi

# ---------- ۵. پاک کردن کش PHP ----------
if command -v systemctl >/dev/null 2>&1; then
    for svc in php8.4-fpm php8.3-fpm php8.2-fpm php8.1-fpm php-fpm; do
        if systemctl list-units --type=service --all 2>/dev/null | grep -q "$svc"; then
            systemctl reload "$svc" >/dev/null 2>&1 && info "کش $svc پاک شد (reload، نه restart)." && break
        fi
    done
fi

# ---------- ۶. وضعیت migration ----------
# پیش از این فقط هشدار می‌داد که «فایلی عوض شده، یادت باشد اجرا کنی».
# حالا migrate.sh دقیقاً می‌داند کدام اجرا شده و کدام نه، پس واقعیت را
# نشان می‌دهیم نه حدس را. اجرا نمی‌کنیم — تغییر ساختار دیتابیس باید
# تصمیم آگاهانه باشد، نه اثر جانبی یک deploy.
if [[ -x deploy/migrate.sh ]]; then
    echo
    info "وضعیت migration:"
    bash deploy/migrate.sh 2>&1 | sed 's/^/  /' || true
fi

echo
green "✅ استقرار کامل شد."
