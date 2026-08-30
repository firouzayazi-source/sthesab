#!/usr/bin/env bash
#
# nginx-perf.sh — روشن کردن HTTP/2 روی سایتِ «دفتر مالی»
#
# ─── رعایت قانون جداسازی ───────────────────────────────────────────
# فقط این فایل را می‌نویسد:
#     /etc/nginx/sites-available/hesab
# و فقط nginx را reload می‌کند (نه stop، نه restart) و فقط بعد از
# `nginx -t` موفق. به nginx.conf، سایت‌های دیگر، و سرویس ربات‌ها دست
# نمی‌زند. اگر تست پیکربندی رد شود، خودش فایل را برمی‌گرداند.
# ───────────────────────────────────────────────────────────────────
#
# چرا:
#
# بلوکِ HTTPS این سایت را certbot ساخته، و certbot معمولاً HTTP/2 را
# روشن نمی‌کند. با HTTP/1.1 مرورگر برای گرفتن فایل‌های صفحه چند اتصال
# جدا باز می‌کند و هر کدام رفت‌وبرگشت خودش را دارد. روی شبکه‌ی موبایل
# با تأخیر بالا، همین باعث می‌شود صفحه کند حس شود — حتی وقتی خودِ
# سرور در چند میلی‌ثانیه جواب داده.
#
# با HTTP/2 همه‌ی آن فایل‌ها روی یک اتصال و موازی می‌آیند.
#
# اجرا:
#     sudo bash deploy/nginx-perf.sh            # فقط گزارش می‌دهد
#     sudo bash deploy/nginx-perf.sh --apply    # روشن می‌کند
#
# اجرای دوباره‌اش بی‌خطر است: اگر از قبل روشن باشد، کاری نمی‌کند.

set -euo pipefail

SITE_NAME="${SITE_NAME:-hesab}"
SITE_FILE="${SITE_FILE:-/etc/nginx/sites-available/${SITE_NAME}}"
APPLY=0
[[ "${1:-}" == "--apply" ]] && APPLY=1

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
info()  { printf '\033[0;36m%s\033[0m\n' "$1"; }
step()  { printf '\n\033[1m%s\033[0m\n' "$1"; }
plain() { printf '  %s\n' "$1"; }

if [[ "$(id -u)" != "0" ]]; then
    red "⛔ این اسکریپت باید با sudo اجرا شود."
    exit 1
fi
[[ -f "$SITE_FILE" ]] || { red "⛔ فایل سایت پیدا نشد: $SITE_FILE"; exit 1; }

# ---------- گزارش وضعیت ----------
step "وضعیت فعلی"
plain "فایل سایت: $SITE_FILE"

NGX_VER="$(nginx -v 2>&1 | sed -E 's#.*/([0-9.]+).*#\1#')"
plain "نسخه‌ی nginx: ${NGX_VER:-نامعلوم}"

# nginx از ۱.۲۵.۱ به بعد دستور جدا `http2 on;` دارد؛ پیش از آن،
# HTTP/2 به‌صورت پارامتر روی خط listen نوشته می‌شد.
ver_ge() { printf '%s\n%s\n' "$2" "$1" | sort -V -C; }
NEW_SYNTAX=0
if [[ -n "$NGX_VER" ]] && ver_ge "$NGX_VER" "1.25.1"; then NEW_SYNTAX=1; fi

if ! grep -qE '^\s*listen\s+443' "$SITE_FILE"; then
    red "⛔ بلوک HTTPS در این فایل نیست."
    plain "یعنی هنوز گواهی TLS نصب نشده (certbot اجرا نشده)."
    plain "بدون HTTPS، HTTP/2 هم معنا ندارد. چیزی تغییر نکرد."
    exit 1
fi

if grep -qE '^\s*http2\s+on\s*;' "$SITE_FILE" || grep -qE '^\s*listen\s+.*443.*http2' "$SITE_FILE"; then
    green "✓ HTTP/2 از قبل روشن است — کاری لازم نیست."
    exit 0
fi
plain "HTTP/2 روشن نیست."

step "چه چیزی عوض می‌شود"
if [[ $NEW_SYNTAX -eq 1 ]]; then
    plain "به بلوکِ HTTPS این خط اضافه می‌شود:   http2 on;"
else
    plain "خطِ  listen 443 ssl;  به  listen 443 ssl http2;  تبدیل می‌شود."
fi
plain ""
plain "اثرش: فایل‌های هر صفحه روی یک اتصال و موازی می‌آیند، به‌جای چند"
plain "اتصال جدا. روی شبکه‌ی موبایل با تأخیر بالا محسوس است."

if [[ $APPLY -eq 0 ]]; then
    step "نمایشی بود"
    plain "برای اعمال واقعی:  sudo bash deploy/nginx-perf.sh --apply"
    exit 0
fi

step "اعمال"
BACKUP="${SITE_FILE}.bak.$(date +%Y%m%d%H%M%S)"
cp -a "$SITE_FILE" "$BACKUP"; chmod 600 "$BACKUP"
plain "نسخه‌ی پشتیبان: $BACKUP"

NEW_SYNTAX=$NEW_SYNTAX python3 - "$SITE_FILE" <<'PY'
import os, re, sys

path = sys.argv[1]
new_syntax = os.environ.get('NEW_SYNTAX') == '1'
text = open(path, encoding='utf-8').read()

if new_syntax:
    # درست بعد از اولین `listen ... 443 ...;` اضافه می‌شود تا داخل همان
    # server block بماند.
    m = re.search(r'^([ \t]*)listen[^\n]*\b443\b[^\n]*;[ \t]*$', text, re.M)
    if not m:
        sys.stderr.write('خط listen 443 پیدا نشد.\n'); sys.exit(2)
    indent = m.group(1)
    text = text[:m.end()] + f'\n{indent}http2 on;' + text[m.end():]
else:
    def add(mo):
        line = mo.group(0)
        return line if 'http2' in line else line.replace(' ssl', ' ssl http2', 1)
    text2 = re.sub(r'^[ \t]*listen[^\n]*\b443\b[^\n]*ssl[^\n]*;[ \t]*$', add, text, flags=re.M)
    if text2 == text:
        sys.stderr.write('خط listen 443 ssl پیدا نشد.\n'); sys.exit(2)
    text = text2

open(path, 'w', encoding='utf-8').write(text)
PY

if ! nginx -t; then
    red "⛔ پیکربندی nginx ایراد دارد — به حالت قبل برگشت."
    cp -a "$BACKUP" "$SITE_FILE"; exit 1
fi
green "✓ پیکربندی nginx سالم است."

if systemctl reload nginx; then
    green "✓ nginx با reload تنظیم تازه را گرفت."
else
    red "⛔ reload نشد — به حالت قبل برگشت."
    cp -a "$BACKUP" "$SITE_FILE"
    nginx -t && systemctl reload nginx || true
    exit 1
fi

green "✅ انجام شد."
plain ""
plain "بررسی از بیرون سرور (مثلاً از لپ‌تاپ خودتان):"
plain "  curl -sI --http2 https://hesab.stland.ir/login.php | head -1"
plain "باید با HTTP/2 شروع شود، نه HTTP/1.1."
