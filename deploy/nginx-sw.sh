#!/usr/bin/env bash
#
# nginx-sw.sh — افزودن استثنای سرویس‌ورکر به سایتِ nginx روی نصبِ موجود
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
# سایتِ اپ یک قاعده‌ی عمومی دارد که به همه‌ی فایل‌های .js کشِ «یک سال،
# immutable» می‌دهد. برای CSS و JS نسخه‌دار درست است، ولی `sw.js` نسخه
# ندارد و آدرسش هرگز عوض نمی‌شود — با آن هدر، نسخه‌ی تازه‌ی سرویس‌ورکر
# ممکن است تا مدت‌ها به مرورگر نرسد و کاربر با نسخه‌ی قدیمی بماند.
#
# `deploy/vps-setup.sh` این استثنا را برای نصب‌های تازه دارد. این اسکریپت
# همان را روی سروری که از قبل راه‌اندازی شده اضافه می‌کند.
#
# اجرا:
#     sudo bash deploy/nginx-sw.sh            # فقط نشان می‌دهد چه می‌شود
#     sudo bash deploy/nginx-sw.sh --apply    # واقعاً اعمال می‌کند
#
# اجرای دوباره‌اش بی‌خطر است: اگر استثنا از قبل باشد، کاری نمی‌کند.

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

if grep -qE '^\s*location\s*=\s*/sw\.js' "$SITE_FILE"; then
    green "✓ استثنای سرویس‌ورکر از قبل هست — کاری لازم نیست."
    exit 0
fi
info "استثنای /sw.js وجود ندارد."

# ---------- بلوکی که اضافه می‌شود ----------
BLOCK='    # سرویس‌ورکر باید پیش از قاعده‌ی عمومیِ .js بیاید و هرگز کش نشود.
    location = /sw.js {
        add_header Cache-Control "no-cache";
        try_files $uri =404;
    }
'

step "چیزی که اضافه می‌شود"
printf '%s' "$BLOCK" | sed 's/^/  /'
info ""
info "درست پیش از قاعده‌ی عمومیِ فایل‌های ثابت قرار می‌گیرد."

if [[ $APPLY -eq 0 ]]; then
    step "نمایشی بود"
    info "برای اعمال واقعی:  sudo bash deploy/nginx-sw.sh --apply"
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
    '    # سرویس‌ورکر باید پیش از قاعده‌ی عمومیِ .js بیاید و هرگز کش نشود.\n'
    '    location = /sw.js {\n'
    '        add_header Cache-Control "no-cache";\n'
    '        try_files $uri =404;\n'
    '    }\n\n'
)

# درست پیش از قاعده‌ی عمومیِ فایل‌های ثابت. اگر آن قاعده پیدا نشد،
# هیچ حدسی زده نمی‌شود — با خطا بیرون می‌آید تا فایل نیمه‌کاره نماند.
m = re.search(r'^[ \t]*location\s+~\*\s+\\?\.\(css\|js', text, re.M)
if not m:
    sys.stderr.write('قاعده‌ی عمومیِ فایل‌های ثابت پیدا نشد.\n')
    sys.exit(2)

text = text[:m.start()] + block + text[m.start():]

with open(path, 'w', encoding='utf-8') as fh:
    fh.write(text)
PY

# ---------- تست پیکربندی پیش از reload ----------
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
info "بررسی:  curl -sI https://hesab.stland.ir/sw.js | grep -i cache-control"
info "باید «no-cache» بدهد، نه «immutable»."
