#!/usr/bin/env bash
#
# آی‌پیِ واقعیِ کاربر، وقتی سایت پشتِ کلادفلر می‌رود.
#
# ⛔ چرا لازم است — و چرا نبودش خرابیِ **بی‌صدا**ست:
#
#    پشتِ هر CDN، `REMOTE_ADDR` دیگر آی‌پیِ کاربر نیست بلکه آی‌پیِ لبه
#    است. اپ در شش جا روی همان حساب می‌کند و بدترینش سدِ حدس رمز است:
#    سقفِ «۲۰ تلاش در ۱۵ دقیقه برای هر آی‌پی» یک‌باره برای **همه‌ی
#    کاربران با هم** شمرده می‌شود و ظرف چند دقیقه هیچ‌کس نمی‌تواند وارد
#    شود — با همان پیامِ همیشگی که هیچ نمی‌گوید چرا. بازیابیِ رمز،
#    ثبت‌نام و کدِ پیامکی هم همین‌طور.
#
# ⛔ چرا در nginx و نه در PHP:
#
#    اگر PHP سرآیندِ `CF-Connecting-IP` را بخواند، باید خودش تصمیم
#    بگیرد کِی باورش کند — و آن یعنی نسخه‌ی دومی از همان تصمیم، در
#    حالی که nginx ماژولش را دارد. مهم‌تر: nginx فقط وقتی جایگزین
#    می‌کند که **خودِ اتصال** از یکی از آی‌پی‌های زیر آمده باشد، پس
#    سرآیندِ جعلی از هر جای دیگری بی‌اثر است. با خواندنِ خامِ سرآیند در
#    PHP، هر کسی با یک خطِ curl همه‌ی این سدها را دور می‌زد.
#
# استفاده:
#   sudo bash deploy/nginx-realip.sh            # فقط نمایش
#   sudo bash deploy/nginx-realip.sh --apply    # اعمال و سنجش
#   sudo bash deploy/nginx-realip.sh --remove   # برگرداندن
#
set -euo pipefail

SITE_FILE="${SITE_FILE:-/etc/nginx/sites-available/hesab}"
DOMAIN="${DOMAIN:-hesab.stland.ir}"
NGINX="${NGINX:-nginx}"
RELOAD="${RELOAD:-systemctl reload nginx}"
PROBE_URL="${PROBE_URL:-http://127.0.0.1/__realip}"

BEGIN='# >>> hesab realip — deploy/nginx-realip.sh'
END='# <<< hesab realip'

red()  { printf '\033[0;31m%s\033[0m\n' "$*"; }
grn()  { printf '\033[0;32m%s\033[0m\n' "$*"; }
info() { printf '\033[0;36m%s\033[0m\n' "$*"; }
warn() { printf '\033[0;33m%s\033[0m\n' "$*"; }

# ⚠ فهرستِ پشتیبان. اگر گرفتنِ فهرستِ زنده نشد از این استفاده می‌شود،
#   **با هشدار** — چون فهرستِ کهنه یعنی همان قفلِ دسته‌جمعیِ بالا، فقط
#   دیرتر و برای بخشی از کاربران.
FALLBACK_V4='173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22
141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20
197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13
104.24.0.0/14 172.64.0.0/13 131.0.72.0/22'
FALLBACK_V6='2400:cb00::/32 2606:4700::/32 2803:f800::/32 2405:b500::/32
2405:8100::/32 2a06:98c0::/29 2c0f:f248::/32'

MODE="${1:-}"

if [ ! -f "$SITE_FILE" ]; then
    red "فایل سایت پیدا نشد: $SITE_FILE"
    exit 1
fi

# ---------------------------------------------------------------- فهرست
fetch_ranges() {
    local v4 v6
    v4=$(curl -fsS --max-time 15 https://www.cloudflare.com/ips-v4 2>/dev/null || true)
    v6=$(curl -fsS --max-time 15 https://www.cloudflare.com/ips-v6 2>/dev/null || true)

    # ⛔ فهرستِ ناقص از فهرستِ کهنه بدتر است: نیمی از کاربران آی‌پیِ
    #   لبه می‌گیرند و نیمی نه، و تشخیصش تقریباً ناممکن می‌شود. پس یا
    #   کاملِ زنده، یا کاملِ پشتیبان.
    if [ "$(printf '%s\n' "$v4" | grep -c '/')" -ge 10 ] \
       && [ "$(printf '%s\n' "$v6" | grep -c '/')" -ge 4 ]; then
        printf '%s\n%s\n' "$v4" "$v6"
        return 0
    fi
    warn "⚠ فهرستِ زنده‌ی کلادفلر گرفته نشد؛ فهرستِ پشتیبانِ داخلِ اسکریپت به کار رفت." >&2
    warn "  اگر مدتی بعد کاربران بی‌دلیل قفل شدند، همین اسکریپت را دوباره اجرا کنید." >&2
    printf '%s\n%s\n' "$FALLBACK_V4" "$FALLBACK_V6" | tr ' ' '\n' | grep '/'
}

build_block() {
    local trusted="$1"
    printf '%s\n' "$BEGIN"
    printf '    # آی‌پیِ واقعیِ کاربر از سرآیندِ کلادفلر خوانده می‌شود،\n'
    printf '    # ولی **فقط** وقتی اتصال از خودِ کلادفلر آمده باشد.\n'
    printf '%s\n' "$trusted" | while read -r c; do
        [ -n "$c" ] && printf '    set_real_ip_from %s;\n' "$c"
    done
    printf '    real_ip_header CF-Connecting-IP;\n'
    printf '\n'
    printf '    # سنجشِ همین تنظیم، فقط از روی خودِ سرور.\n'
    printf '    # بیرون بسته است، پس چیزی از کسی لو نمی‌دهد.\n'
    printf '    location = /__realip {\n'
    printf '        allow 127.0.0.1;\n'
    printf '        allow ::1;\n'
    printf '        deny all;\n'
    printf '        default_type text/plain;\n'
    printf '        return 200 "$remote_addr\\n";\n'
    printf '    }\n'
    printf '%s\n' "$END"
}

strip_block() {
    awk -v b="$BEGIN" -v e="$END" '
        index($0, b) { skip = 1 }
        !skip { print }
        index($0, e) { skip = 0 }
    ' "$1"
}

# ---------------------------------------------------------------- حالت‌ها
if [ "$MODE" = "--remove" ]; then
    if ! grep -qF "$BEGIN" "$SITE_FILE"; then
        info "چیزی برای برداشتن نبود."
        exit 0
    fi
    cp "$SITE_FILE" "$SITE_FILE.realip-bak"
    strip_block "$SITE_FILE" > "$SITE_FILE.tmp" && mv "$SITE_FILE.tmp" "$SITE_FILE"
    if ! $NGINX -t >/dev/null 2>&1; then
        cp "$SITE_FILE.realip-bak" "$SITE_FILE"
        red "پیکربندی خراب شد؛ برگردانده شد."
        exit 1
    fi
    $RELOAD
    grn "✅ برداشته شد. آی‌پی دوباره همان آی‌پیِ اتصال است."
    exit 0
fi

TRUSTED=$(fetch_ranges)
COUNT=$(printf '%s\n' "$TRUSTED" | grep -c '/')

if [ "$MODE" != "--apply" ]; then
    info "حالت نمایشی — هیچ چیزی نوشته نمی‌شود. برای اعمال: --apply"
    echo
    info "فایل سایت : $SITE_FILE"
    info "دامنه     : $DOMAIN"
    info "بازه‌ها    : $COUNT مورد"
    echo
    if grep -qF "$BEGIN" "$SITE_FILE"; then
        info "این تنظیم از قبل هست و با --apply تازه می‌شود."
    else
        info "این تنظیم هنوز نیست."
    fi
    echo
    info "چیزی که اضافه می‌شود (کوتاه‌شده):"
    build_block "$TRUSTED" | head -6 | sed 's/^/    /'
    info "    … و $((COUNT - 3)) بازه‌ی دیگر"
    exit 0
fi

# ---------------------------------------------------------------- اعمال
cp "$SITE_FILE" "$SITE_FILE.realip-bak"
info "نسخه‌ی پشتیبان: $SITE_FILE.realip-bak"

BLOCK=$(build_block "$TRUSTED")

# بلوکِ قبلی (اگر بود) برداشته می‌شود تا اجرای دوباره تکراری نسازد، و
# بلوکِ تازه بعد از هر `server_name` گذاشته می‌شود — هم در بلوکِ ۸۰ و هم ۴۴۳.
strip_block "$SITE_FILE" > "$SITE_FILE.tmp"
awk -v block="$BLOCK" '
    { print }
    /^[[:space:]]*server_name[[:space:]]/ { print block }
' "$SITE_FILE.tmp" > "$SITE_FILE.new" && mv "$SITE_FILE.new" "$SITE_FILE"
rm -f "$SITE_FILE.tmp"

restore() {
    cp "$SITE_FILE.realip-bak" "$SITE_FILE"
    $NGINX -t >/dev/null 2>&1 && $RELOAD || true
}

if ! $NGINX -t >/dev/null 2>&1; then
    red "پیکربندی معتبر نیست؛ برگردانده شد."
    $NGINX -t 2>&1 | sed 's/^/    /'
    restore
    exit 1
fi

$RELOAD
sleep 1

# ⛔ «پیکربندی معتبر است» با «درست کار می‌کند» یکی نیست — همان درسی که
#    قاعده‌ی nginx در api/v1 داد. پس خودِ رفتار سنجیده می‌شود، در هر دو
#    جهت، چون هر کدام یک خرابیِ متفاوت را می‌گیرد:
#
#      ۱. سرآیند از یک آی‌پیِ **نامعتبر** نباید باور شود (وگرنه هر کسی
#         با یک curl همه‌ی سدهای آی‌پی را دور می‌زند).
#      ۲. سرآیند از یک آی‌پیِ **معتبر** باید باور شود (وگرنه ماژول
#         بارگذاری نشده یا نامِ سرآیند غلط است و همه‌ی کاربران باز هم
#         یک آی‌پی می‌گیرند — یعنی همان قفلِ دسته‌جمعی).
SPOOF='198.51.100.77'

SEEN=$(curl -s --max-time 10 -H "CF-Connecting-IP: $SPOOF" "$PROBE_URL" | tr -d '\r\n' || true)
if [ "$SEEN" = "$SPOOF" ]; then
    red "سنجش شکست خورد: سرآیندِ جعلی از یک آی‌پیِ نامعتبر باور شد."
    restore
    exit 1
fi
if [ -z "$SEEN" ]; then
    red "سنجش شکست خورد: $PROBE_URL پاسخی نداد."
    restore
    exit 1
fi
info "✓ سرآیند از آی‌پیِ نامعتبر نادیده گرفته می‌شود (دیده شد: $SEEN)"

# حالا موقتاً همین ماشین را «قابل اعتماد» می‌کنیم تا جهتِ دوم هم سنجیده
# شود. ⚠ این حالت فقط چند ثانیه می‌ماند و بعد برداشته می‌شود.
sed "s|    real_ip_header CF-Connecting-IP;|    set_real_ip_from 127.0.0.1;\n    real_ip_header CF-Connecting-IP;|" \
    "$SITE_FILE" > "$SITE_FILE.probe"
cp "$SITE_FILE" "$SITE_FILE.final"
cp "$SITE_FILE.probe" "$SITE_FILE"

OK2=0
if $NGINX -t >/dev/null 2>&1; then
    $RELOAD; sleep 1
    SEEN2=$(curl -s --max-time 10 -H "CF-Connecting-IP: $SPOOF" "$PROBE_URL" | tr -d '\r\n' || true)
    [ "$SEEN2" = "$SPOOF" ] && OK2=1
fi

cp "$SITE_FILE.final" "$SITE_FILE"
rm -f "$SITE_FILE.probe" "$SITE_FILE.final"
$NGINX -t >/dev/null 2>&1 && $RELOAD

if [ "$OK2" != "1" ]; then
    red "سنجش شکست خورد: از آی‌پیِ معتبر هم سرآیند خوانده نشد."
    red "یعنی ماژول real_ip کار نمی‌کند یا نامِ سرآیند نمی‌خواند."
    restore
    exit 1
fi
info "✓ سرآیند از آی‌پیِ معتبر خوانده می‌شود"

echo
grn "✅ اعمال شد — $COUNT بازه‌ی کلادفلر."
echo
info "حالا در پنل کلادفلر رکوردِ hesab را از ابرِ خاکستری به **نارنجی** ببرید."
info "بعدش با این می‌شود دید سرور چه آی‌پی‌ای می‌بیند:"
info "    curl -s $PROBE_URL"
