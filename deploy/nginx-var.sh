#!/usr/bin/env bash
#
# nginx-var.sh — بستنِ پوشه‌ی var/ از وب روی نصبِ موجود
#
# ─── رعایت قانون جداسازی ───────────────────────────────────────────
# فقط این فایل را می‌نویسد:
#     /etc/nginx/sites-available/hesab
# و فقط nginx را reload می‌کند (نه stop، نه restart) و فقط بعد از
# `nginx -t` موفق. اگر تست پیکربندی رد شود یا سایت بعدش درست جواب
# ندهد، خودش فایل را برمی‌گرداند.
# ───────────────────────────────────────────────────────────────────
#
# چرا لازم است:
#
# ⛔ `var/` داخلِ ریشه‌ی وب است و سایتِ nginx آن را **نمی‌بست**. آنجا
#    لاگِ JSONِ روزانه است با نامی قابلِ حدس (`var/log/web-1405-…`)،
#    فایل‌های نشست، کلیدِ خصوصیِ VAPIDِ اعلانِ گوشی، و در حالتِ توسعه
#    حتی `var/sms.log` (کدهای ورود). پسوندِ `.log` در هیچ قاعده‌ای نبود.
#
# اجرا:
#     sudo bash deploy/nginx-var.sh            # فقط نشان می‌دهد چه می‌شود
#     sudo bash deploy/nginx-var.sh --apply    # واقعاً اعمال می‌کند
#
# اجرای دوباره‌اش بی‌خطر است.

set -euo pipefail

SITE_NAME="${SITE_NAME:-hesab}"
SITE_FILE="${SITE_FILE:-/etc/nginx/sites-available/${SITE_NAME}}"
DOMAIN="${DOMAIN:-hesab.stland.ir}"
SCHEME="${SCHEME:-https}"
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
    exit 1
fi

step "وضعیت فعلی"
if grep -qE 'location[[:space:]]+\^~[[:space:]]+/var/' "$SITE_FILE"; then
    green "✓ پوشه‌ی var از قبل بسته است — کاری لازم نیست."
    exit 0
fi
info "پوشه‌ی var/ از وب بسته نیست."

LINE='    location ^~ /var/    { deny all; return 404; }'
step "چیزی که اضافه می‌شود"
info "$LINE"
info "درست بعد از قاعده‌ی /tests/ قرار می‌گیرد."

if [[ $APPLY -eq 0 ]]; then
    step "نمایشی بود"
    info "برای اعمال واقعی:  sudo bash deploy/nginx-var.sh --apply"
    exit 0
fi

step "اعمال"
BACKUP="${SITE_FILE}.bak.$(date +%Y%m%d%H%M%S)"
cp -a "$SITE_FILE" "$BACKUP"
chmod 600 "$BACKUP"
info "نسخه‌ی پشتیبان: $BACKUP"

# ⛔ با فایلِ موقت و getline، نه awk -v (همان درسِ nginx-realip.sh).
TMP_BLOCK="$(mktemp)"
TMP_SITE="$(mktemp)"
trap 'rm -f "$TMP_BLOCK" "$TMP_SITE"' EXIT
printf '%s\n' "$LINE" > "$TMP_BLOCK"
awk -v blockfile="$TMP_BLOCK" '
    { print }
    !done && /location[ \t]+\^~[ \t]+\/tests\// {
        while ((getline line < blockfile) > 0) { print line }
        close(blockfile)
        done = 1
    }
' "$SITE_FILE" > "$TMP_SITE"

if ! grep -qE 'location[[:space:]]+\^~[[:space:]]+/var/' "$TMP_SITE"; then
    red "⛔ قاعده‌ی /tests/ در فایل سایت پیدا نشد — هیچ حدسی زده نمی‌شود."
    info "فایل دست‌نخورده ماند."
    exit 1
fi
cat "$TMP_SITE" > "$SITE_FILE"

if ! nginx -t; then
    red "⛔ پیکربندی nginx ایراد دارد — به حالت قبل برگشت."
    cp -a "$BACKUP" "$SITE_FILE"
    exit 1
fi
if ! systemctl reload nginx; then
    red "⛔ reload نشد — به حالت قبل برگشت."
    cp -a "$BACKUP" "$SITE_FILE"
    nginx -t && systemctl reload nginx || true
    exit 1
fi
green "✓ nginx با reload قاعده را گرفت."

# ---------- سنجشِ خودِ رفتار ----------
# ⛔ با --resolve (نه سرآیندِ Host) و با صبر: کارگرهای قدیمی تا صدها
#    میلی‌ثانیه با پیکربندیِ قبلی جواب می‌دهند.
step "سنجش"
ok=0
for _ in $(seq 1 20); do
    varCode="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 5 \
        --resolve "${DOMAIN}:443:127.0.0.1" --resolve "${DOMAIN}:80:127.0.0.1" \
        "${SCHEME}://${DOMAIN}/var/version.txt" 2>/dev/null || true)"
    loginCode="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 5 \
        --resolve "${DOMAIN}:443:127.0.0.1" --resolve "${DOMAIN}:80:127.0.0.1" \
        "${SCHEME}://${DOMAIN}/login.php" 2>/dev/null || true)"
    if [[ "$varCode" == "404" && "$loginCode" == "200" ]]; then ok=1; break; fi
    sleep 0.5
done

if [[ $ok -ne 1 ]]; then
    red "⛔ نتیجه درست نبود — به حالت قبل برگشت."
    info "var/version.txt: ${varCode:-—}   login.php: ${loginCode:-—}"
    cp -a "$BACKUP" "$SITE_FILE"
    nginx -t && systemctl reload nginx || true
    exit 1
fi
green "✅ انجام شد — var/ حالا ۴۰۴ می‌دهد و صفحه‌ی ورود همچنان ۲۰۰."
