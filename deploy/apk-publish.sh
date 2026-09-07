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
#   sudo bash deploy/apk-publish.sh --from-github          # آخرین نسخه
#   sudo bash deploy/apk-publish.sh --from-github build-11 # نسخه‌ی مشخص
#   sudo bash deploy/apk-publish.sh /root/daftar-11.apk    # فایلِ آماده
#   sudo bash deploy/apk-publish.sh --remove
#
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/hesab/app}"
DEST_DIR="$APP_DIR/download"
DEST="$DEST_DIR/daftar.apk"
GH_REPO="${GH_REPO:-firouzayazi-source/sthesab}"
GH_API="${GH_API:-https://api.github.com}"

red()  { printf '\033[0;31m%s\033[0m\n' "$*"; }
grn()  { printf '\033[0;32m%s\033[0m\n' "$*"; }
info() { printf '\033[0;36m%s\033[0m\n' "$*"; }

TMPDIR_APK=""
CURL_CFG=""
cleanup() {
    [ -n "$CURL_CFG" ] && rm -f "$CURL_CFG"
    [ -n "$TMPDIR_APK" ] && rm -rf "$TMPDIR_APK"
    return 0
}
trap cleanup EXIT

usage() {
    echo "  sudo bash deploy/apk-publish.sh --from-github"
    echo "  sudo bash deploy/apk-publish.sh --from-github build-11"
    echo "  sudo bash deploy/apk-publish.sh /root/daftar-11.apk"
    echo "  sudo bash deploy/apk-publish.sh --remove"
}

if [ "${1:-}" = "--remove" ]; then
    if [ -f "$DEST" ]; then
        rm -f "$DEST"
        grn "✅ برداشته شد. نوارِ پیشنهادِ نصب دیگر رندر نمی‌شود."
    else
        info "چیزی برای برداشتن نبود."
    fi
    exit 0
fi

# ---------------------------------------------------------------------
# گرفتن از GitHub Release
#
# ⛔ توکن هرگز روی خطِ فرمان نمی‌رود. هر کاربرِ دیگری روی همین سرور
#    `ps aux` را می‌بیند و روی این VPS سرویس‌های دیگری هم هستند — همان
#    دلیلی که `perf-report.sh` رمزِ دیتابیس را با `--defaults-extra-file`
#    می‌فرستد نه با `mysql -pرمز`. اینجا هم سرآیندِ Authorization داخلِ
#    یک فایلِ موقتِ ۶۰۰ می‌رود و با `--config` خوانده می‌شود.
gh_fetch() {
    local tag="${1:-}"
    local token="${GH_TOKEN:-${GITHUB_TOKEN:-}}"

    if ! command -v curl >/dev/null 2>&1; then
        red "curl نصب نیست."
        exit 1
    fi

    if [ -z "$token" ]; then
        info "مخزن خصوصی است، پس یک توکنِ گیت‌هاب لازم است (فقط خواندنِ همین مخزن)."
        info "ساختنش: github.com/settings/tokens  →  Fine-grained  →  Contents: Read-only"
        printf 'توکن گیت‌هاب: '
        read -rs token
        echo
    fi
    if [ -z "$token" ]; then
        red "توکنی وارد نشد."
        exit 1
    fi

    CURL_CFG="$(mktemp)"
    chmod 600 "$CURL_CFG"
    {
        printf 'header = "Authorization: Bearer %s"\n' "$token"
        printf 'header = "X-GitHub-Api-Version: 2022-11-28"\n'
        printf 'silent\nshow-error\nlocation\n'
    } > "$CURL_CFG"
    # ⚠ عمداً بدونِ `fail`: کدِ وضعیت خودش خوانده می‌شود تا بشود گفت
    #   مشکل از توکن است یا از دسترسیِ مخزن یا از خودِ نسخه.

    local rel_url
    if [ -n "$tag" ]; then
        rel_url="$GH_API/repos/$GH_REPO/releases/tags/$tag"
    else
        rel_url="$GH_API/repos/$GH_REPO/releases/latest"
    fi

    TMPDIR_APK="$(mktemp -d)"
    local meta="$TMPDIR_APK/release.json"

    info "خواندن نسخه از گیت‌هاب…"
    local code
    code=$(curl --config "$CURL_CFG" -H 'Accept: application/vnd.github+json' \
                -o "$meta" -w '%{http_code}' "$rel_url" || echo 000)

    # ⛔ گیت‌هاب برای مخزنِ خصوصیِ دور از دسترس **۴۰۴** می‌دهد، نه ۴۰۳ —
    #   عمداً، تا وجودِ مخزن لو نرود. پس یک «۴۰۴» به‌تنهایی سه معنیِ
    #   کاملاً جدا دارد و گفتنِ «توکن را بررسی کنید» یعنی رها کردنِ کاربر
    #   وسطِ همان سه احتمال. اینجا هر سه از هم جدا می‌شوند.
    if [ "$code" != "200" ]; then
        if [ "$code" = "401" ]; then
            red "توکن معتبر نیست (۴۰۱). احتمالاً ناقص کپی شده یا منقضی شده است."
        elif [ "$code" = "404" ]; then
            local rc
            rc=$(curl --config "$CURL_CFG" -H 'Accept: application/vnd.github+json' \
                      -o /dev/null -w '%{http_code}' "$GH_API/repos/$GH_REPO" || echo 000)
            if [ "$rc" != "200" ]; then
                red "توکن به مخزن $GH_REPO دسترسی ندارد."
                echo
                info "در فرمِ ساختِ توکن، این دو با هم لازم‌اند:"
                info "  • Repository access → Only select repositories → sthesab را تیک بزنید"
                info "  • Permissions → Repository permissions → Contents → Read-only"
                info "توکنِ موجود را می‌شود ویرایش کرد؛ ساختنِ توکنِ تازه لازم نیست."
            elif [ -n "$tag" ]; then
                red "نسخه‌ای به نامِ «$tag» پیدا نشد."
                info "بدونِ نام اجرا کنید تا آخرین نسخه گرفته شود."
            else
                red "هیچ نسخه‌ی منتشرشده‌ای پیدا نشد."
                info "اگر نسخه‌ها draft یا pre-release اند، نامشان را صریح بدهید."
            fi
        else
            red "خواندنِ نسخه شکست خورد (کد $code)."
        fi
        exit 1
    fi

    # نامِ نسخه و شناسه‌ی اولین فایلِ .apk — بدونِ jq، چون روی سرور نصب
    # نیست. فضای خالی حذف می‌شود (پاسخِ گیت‌هاب چندخطی است)، بعد فقط
    # بخشِ assets نگه داشته می‌شود تا متنِ توضیحِ نسخه با آن قاطی نشود،
    # و آخر روی `{` شکسته می‌شود تا هر دارایی یک خط شود.
    local rel_tag assets chunk asset_id asset_name compact
    compact=$(tr -d ' \t\r\n' < "$meta")
    rel_tag=$(printf '%s' "$compact" | grep -o '"tag_name":"[^"]*"' \
              | head -n1 | sed 's/.*:"//; s/"$//')
    assets="${compact#*\"assets\":\[}"
    chunk=$(printf '%s' "$assets" | tr '{' '\n' | grep -m1 '"name":"[^"]*\.apk"' || true)
    asset_id=$(printf '%s' "$chunk" | grep -o '"id":[0-9]*' | head -n1 | cut -d: -f2)
    asset_name=$(printf '%s' "$chunk" | grep -o '"name":"[^"]*\.apk"' \
                 | head -n1 | sed 's/.*:"//; s/"$//')

    if [ -z "$asset_id" ]; then
        red "در نسخه‌ی ${rel_tag:-?} هیچ فایلِ .apk ای نبود."
        exit 1
    fi

    info "دریافت $asset_name از نسخه‌ی $rel_tag …"
    SRC="$TMPDIR_APK/$asset_name"
    # ⚠ بدونِ `fail` در پیکربندی، خطای HTTP یک بدنه‌ی JSON می‌دهد و curl
    #   موفق برمی‌گردد؛ پس کد اینجا هم صریح سنجیده می‌شود، وگرنه همان
    #   متنِ خطا به‌جای APK ذخیره می‌شد.
    code=$(curl --config "$CURL_CFG" -H 'Accept: application/octet-stream' \
                -o "$SRC" -w '%{http_code}' \
                "$GH_API/repos/$GH_REPO/releases/assets/$asset_id" || echo 000)
    if [ "$code" != "200" ]; then
        red "دانلودِ $asset_name شکست خورد (کد $code)."
        exit 1
    fi
}

SRC=""
if [ "${1:-}" = "--from-github" ]; then
    gh_fetch "${2:-}"
else
    SRC="${1:-}"
    if [ -z "$SRC" ]; then
        red "مسیرِ فایل APK را بدهید، یا با --from-github از گیت‌هاب بگیرید."
        usage
        exit 1
    fi
    if [ ! -f "$SRC" ]; then
        red "فایل پیدا نشد: $SRC"
        exit 1
    fi
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
