#!/usr/bin/env bash
#
# nginx-api.sh — افزودن آدرس تمیزِ api/v1 به سایتِ nginx روی نصبِ موجود
#
# ─── رعایت قانون جداسازی ───────────────────────────────────────────
# فقط این فایل را می‌نویسد:
#     /etc/nginx/sites-available/hesab
# و فقط nginx را reload می‌کند (نه stop، نه restart) و فقط بعد از
# `nginx -t` موفق. به nginx.conf، سایت‌های دیگر، و سرویس ربات‌ها دست
# نمی‌زند. اگر تست پیکربندی رد شود، خودش فایل را برمی‌گرداند.
# ───────────────────────────────────────────────────────────────────
#
# چرا لازم است:
#
# api/v1 بدون این قاعده هم کاملاً کار می‌کند، ولی با آدرسِ
# `/api/v1/index.php/transactions`. این قاعده اجازه می‌دهد اپ همان را
# به شکل `/api/v1/transactions` صدا بزند.
#
# ⚠ پشتیبانی از هر دو شکل عمدی است و باید بماند: اگر روزی certbot یا
# کسی فایل سایت را بازنویسی کند، این قاعده از دست می‌رود — و اپ‌هایی که
# روی گوشی مردم نصب شده‌اند نباید با آن بخوابند. برای همین اپ باید
# آدرسِ index.php را بلد باشد و از آن استفاده کند اگر تمیزش جواب نداد.
#
# `deploy/vps-setup.sh` این قاعده را برای نصب‌های تازه دارد. این اسکریپت
# همان را روی سروری که از قبل راه‌اندازی شده اضافه می‌کند.
#
# اجرا:
#     sudo bash deploy/nginx-api.sh            # فقط نشان می‌دهد چه می‌شود
#     sudo bash deploy/nginx-api.sh --apply    # واقعاً اعمال می‌کند
#
# اجرای دوباره‌اش بی‌خطر است: اگر قاعده از قبل باشد، کاری نمی‌کند.

set -euo pipefail

SITE_NAME="${SITE_NAME:-hesab}"
SITE_FILE="${SITE_FILE:-/etc/nginx/sites-available/${SITE_NAME}}"
APPLY=0
[[ "${1:-}" == "--apply" ]] && APPLY=1

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
info()  { printf '  %s\n' "$1"; }
step()  { printf '\n\033[1m%s\033[0m\n' "$1"; }

if [[ "$(id -u)" != "0" ]]; then
    red "⛔ این اسکریپت باید با sudo اجرا شود."
    exit 1
fi

if [[ ! -f "$SITE_FILE" ]]; then
    red "⛔ فایل سایت پیدا نشد: $SITE_FILE"
    info "یعنی این سرور با deploy/vps-setup.sh راه‌اندازی نشده. چیزی تغییر نکرد."
    exit 1
fi

step "وضعیت فعلی"
info "فایل سایت: $SITE_FILE"

if grep -qE '^\s*location\s+\^~\s+/api/v1/' "$SITE_FILE"; then
    green "✓ قاعده‌ی api/v1 از قبل هست — کاری لازم نیست."
    exit 0
fi
info "قاعده‌ی /api/v1/ وجود ندارد."

BLOCK='    # ---- api/v1 : آدرس تمیز برای اپ‌های موبایل ----
    location ^~ /api/v1/ {
        try_files $uri $uri/ /api/v1/index.php$is_args$args;
    }
'

step "چیزی که اضافه می‌شود"
printf '%s' "$BLOCK" | sed 's/^/  /'
info ""
info "پیش از قاعده‌ی عمومیِ فایل‌های ثابت قرار می‌گیرد تا بر آن اولویت بگیرد."

if [[ $APPLY -eq 0 ]]; then
    step "نمایشی بود"
    info "برای اعمال واقعی:  sudo bash deploy/nginx-api.sh --apply"
    exit 0
fi

step "اعمال"

BACKUP="${SITE_FILE}.bak.$(date +%Y%m%d%H%M%S)"
cp -a "$SITE_FILE" "$BACKUP"
chmod 600 "$BACKUP"
info "نسخه‌ی پشتیبان: $BACKUP"

python3 - "$SITE_FILE" <<'PY'
import re, sys

path = sys.argv[1]
with open(path, encoding='utf-8') as fh:
    text = fh.read()

block = (
    '    # ---- api/v1 : آدرس تمیز برای اپ‌های موبایل ----\n'
    '    location ^~ /api/v1/ {\n'
    '        try_files $uri $uri/ /api/v1/index.php$is_args$args;\n'
    '    }\n\n'
)

# پیش از اولین location داخل بلوکِ server قرار می‌گیرد. اگر هیچ‌کدام از
# لنگرهای شناخته‌شده نبود، هیچ حدسی زده نمی‌شود — با خطا بیرون می‌آید تا
# فایل نیمه‌کاره نماند.
for pattern in (
    r'^[ \t]*location\s*=\s*/sw\.js',
    r'^[ \t]*location\s+~\*\s+\\?\.\(css\|js',
    r'^[ \t]*location\s+/\s*\{',
):
    m = re.search(pattern, text, re.M)
    if m:
        text = text[:m.start()] + block + text[m.start():]
        with open(path, 'w', encoding='utf-8') as fh:
            fh.write(text)
        sys.exit(0)

sys.stderr.write('جای مناسبی برای درج قاعده پیدا نشد.\n')
sys.exit(2)
PY

if ! nginx -t; then
    red "⛔ پیکربندی nginx ایراد دارد — به حالت قبل برگشت."
    cp -a "$BACKUP" "$SITE_FILE"
    exit 1
fi
green "✓ پیکربندی nginx سالم است."

# reload و نه restart: سایت‌های دیگرِ همین nginx قطع نمی‌شوند.
if systemctl reload nginx; then
    green "✓ nginx با reload قاعده‌ی تازه را گرفت."
else
    red "⛔ reload نشد — به حالت قبل برگشت."
    cp -a "$BACKUP" "$SITE_FILE"
    nginx -t && systemctl reload nginx || true
    exit 1
fi

green "✅ انجام شد."
info ""
info "بررسی:  curl -s https://hesab.stland.ir/api/v1/ping"
info 'باید {"ok":true,...} بدهد.'
