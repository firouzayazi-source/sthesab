#!/usr/bin/env bash
#
# گذاشتنِ APK اندروید روی خودِ دامنه، تا نوارِ «دریافت اپ» کار کند.
#
# ⛔ چرا لینکِ GitHub Release کافی نیست: مخزن **خصوصی** است و دارایی‌های
#    Release یک مخزنِ خصوصی برای کاربرِ ناشناس ۴۰۴ می‌دهند. آن لینک روی
#    مرورگرِ خودِ ما (که وارد گیت‌هاب است) کار می‌کند و روی گوشیِ کاربر
#    نه — یعنی بدترین شکلِ خرابی: فقط برای کسی که آزمایشش می‌کند سالم
#    است.
#
# ⛔ و چرا APK داخل گیت نمی‌رود: هر ساخت چند صد کیلوبایت باینری است و
#    مخزن را برای همیشه سنگین می‌کند. اینجا یک فایلِ untracked است که
#    `deploy.sh` هم دست بهش نمی‌زند (`git reset --hard` فایلِ untracked
#    را پاک نمی‌کند و هیچ `git clean` ای در کار نیست).
#
# استفاده:
#   sudo bash deploy/apk-publish.sh /root/daftar-42.apk
#   sudo bash deploy/apk-publish.sh --remove
#
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/hesab/app}"
DEST_DIR="$APP_DIR/download"
DEST="$DEST_DIR/daftar.apk"

red()  { printf '\033[0;31m%s\033[0m\n' "$*"; }
grn()  { printf '\033[0;32m%s\033[0m\n' "$*"; }
info() { printf '\033[0;36m%s\033[0m\n' "$*"; }

if [ "${1:-}" = "--remove" ]; then
    if [ -f "$DEST" ]; then
        rm -f "$DEST"
        grn "✅ برداشته شد. نوارِ پیشنهادِ نصب دیگر رندر نمی‌شود."
    else
        info "چیزی برای برداشتن نبود."
    fi
    exit 0
fi

SRC="${1:-}"
if [ -z "$SRC" ]; then
    red "مسیرِ فایل APK را بدهید."
    echo "  sudo bash deploy/apk-publish.sh /root/daftar-42.apk"
    echo "  sudo bash deploy/apk-publish.sh --remove"
    exit 1
fi

if [ ! -f "$SRC" ]; then
    red "فایل پیدا نشد: $SRC"
    exit 1
fi

# ⛔ سنجشِ اینکه واقعاً APK است، نه فقط اینکه پسوندش .apk است.
#
#    بدون این، یک فایلِ اشتباه (صفحه‌ی HTML خطای گیت‌هاب، یا zip آرتیفکت
#    به‌جای خودِ APK) بی‌سروصدا سرو می‌شد و کاربر روی گوشی فقط
#    «برنامه نصب نشد» می‌دید — پیامی که هیچ نمی‌گوید مشکل از فایل است.
#    APK یک zip است که `AndroidManifest.xml` دارد.
if ! head -c2 "$SRC" | grep -q 'PK'; then
    red "این فایل zip نیست، پس APK هم نیست: $SRC"
    exit 1
fi
if command -v unzip >/dev/null 2>&1; then
    if ! unzip -l "$SRC" 2>/dev/null | grep -q 'AndroidManifest.xml'; then
        red "داخلِ این zip فایلِ AndroidManifest.xml نیست — APK نیست."
        exit 1
    fi
else
    info "⚠ unzip نصب نیست؛ فقط امضای zip سنجیده شد."
fi

mkdir -p "$DEST_DIR"
cp "$SRC" "$DEST.tmp"

# ⚠ جابه‌جاییِ اتمی: با نوشتنِ مستقیم، کسی که دقیقاً همان لحظه دانلود
#   می‌کند نصفه‌ی فایل را می‌گرفت و نصب شکست می‌خورد.
mv -f "$DEST.tmp" "$DEST"

# nginx خودش فایل‌های ثابت را سرو می‌کند و با کاربرِ خودش می‌خواند، پس
# مالکیت مثل بقیه‌ی فایل‌های اپ می‌ماند و فقط باید خواندنی باشد.
chown root:root "$DEST" 2>/dev/null || true
chmod 644 "$DEST"

SIZE=$(du -h "$DEST" | cut -f1)
grn "✅ نصب شد: $DEST  ($SIZE)"
echo
info "حالا روی گوشیِ اندرویدی، همان آدرسِ سایت را باز کنید — نوارِ"
info "«دریافت اپ» بالای صفحه می‌آید. داخلِ خودِ اپ عمداً نمی‌آید."
