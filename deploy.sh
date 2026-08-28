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
else
    red "کاربر $APP_USER وجود ندارد — اول deploy/vps-setup.sh را اجرا کنید."
fi
chmod +x deploy.sh 2>/dev/null || true

# تنظیمات فقط برای خود سرور خوانده شود
if [[ -f config/config.php ]]; then
    chown root:"$APP_USER" config/config.php 2>/dev/null || true
    chmod 640 config/config.php
fi

# ---------- ۵. پاک کردن کش PHP ----------
if command -v systemctl >/dev/null 2>&1; then
    for svc in php8.4-fpm php8.3-fpm php8.2-fpm php8.1-fpm php-fpm; do
        if systemctl list-units --type=service --all 2>/dev/null | grep -q "$svc"; then
            systemctl reload "$svc" >/dev/null 2>&1 && info "کش $svc پاک شد (reload، نه restart)." && break
        fi
    done
fi

# ---------- ۶. یادآوری migration ----------
PENDING=$(git diff --name-only "${BEFORE}..${AFTER}" 2>/dev/null | grep -E '^migration_.*\.sql$' || true)
if [[ -n "$PENDING" ]]; then
    echo
    red "⚠️  این migration ها در این نسخه تغییر کرده‌اند — یادتان باشد اجرایشان کنید:"
    echo "$PENDING" | sed 's/^/  /'
fi

echo
green "✅ استقرار کامل شد."
