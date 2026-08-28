#!/usr/bin/env bash
# mail-setup.sh — تنظیم ارسال ایمیل (بازیابی رمز) داخل config/config.php
#
# چرا این اسکریپت هست: تنظیم ایمیل یعنی افزودن ۹ ثابت به config.php.
# ویرایش دستی آن فایل روی گوشی با nano هم دردسر است هم خطرناک — یک
# پرانتز جاافتاده کل اپ را با خطای ۵۰۰ می‌خواباند. اینجا مقادیر پرسیده
# می‌شوند، فایل قبلش بکاپ می‌گیرد، و در پایان با php -l بررسی می‌شود؛
# اگر نحو خراب شود، بکاپ خودکار برمی‌گردد.
#
# ⛔ قانون استقلال پروژه: این اسکریپت فقط به config/config.php همین اپ
#    دست می‌زند. هیچ سرویس، فایل یا دیتابیس دیگری روی سرور لمس نمی‌شود.
#
# استفاده:
#   bash deploy/mail-setup.sh              پرسش‌وپاسخ
#   bash deploy/mail-setup.sh --show       فقط نمایش تنظیمات فعلی
#   bash deploy/mail-setup.sh --check      فقط آزمایش باز بودن مسیر SMTP

set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="$APP_DIR/config/config.php"

RED=$'\033[0;31m'; GRN=$'\033[0;32m'; YLW=$'\033[0;33m'; CYN=$'\033[0;36m'; NC=$'\033[0m'
say()  { printf '%s\n' "$*"; }
ok()   { printf '%s%s%s\n' "$GRN" "$*" "$NC"; }
warn() { printf '%s%s%s\n' "$YLW" "$*" "$NC"; }
die()  { printf '%s%s%s\n' "$RED" "$*" "$NC" >&2; exit 1; }
info() { printf '%s%s%s\n' "$CYN" "$*" "$NC"; }

[[ -f "$CONFIG" ]] || die "config/config.php پیدا نشد: $CONFIG"
command -v php >/dev/null || die "php روی این سرور پیدا نشد."

# ---------------------------------------------------------------
# نمایش وضعیت فعلی — بدون لو دادن رمز
show_current() {
  php -r '
    require "'"$CONFIG"'";
    $rows = [
      "APP_URL", "MAIL_METHOD", "MAIL_FROM", "MAIL_FROM_NAME",
      "SMTP_HOST", "SMTP_PORT", "SMTP_SECURE", "SMTP_USER", "SMTP_PASS", "SMTP_EHLO",
    ];
    foreach ($rows as $k) {
      if (!defined($k)) { printf("  %-16s \033[0;31mتعریف نشده\033[0m\n", $k); continue; }
      $v = constant($k);
      if ($k === "SMTP_PASS") { $v = $v === "" ? "" : "•••••• (" . strlen($v) . " کاراکتر)"; }
      if ($v === "" || $v === null) { printf("  %-16s \033[0;33mخالی\033[0m\n", $k); continue; }
      printf("  %-16s %s\n", $k, $v);
    }
  '
}

# ---------------------------------------------------------------
# آیا از این سرور به هاست/پورت SMTP می‌شود وصل شد؟
# روی سرورهای ایران این اولین چیزی است که می‌شکند، پس قبل از تنظیم می‌پرسیم.
probe() {
  local host="$1" port="$2"
  timeout 8 bash -c "exec 3<>/dev/tcp/$host/$port && head -c 120 <&3" 2>/dev/null
}

check_route() {
  local host="$1" port="$2"
  printf '  %s:%s ... ' "$host" "$port"
  local banner
  banner="$(probe "$host" "$port")"
  if [[ -n "$banner" ]]; then
    ok "باز است — ${banner%%$'\r'*}"
    return 0
  fi
  printf '%sبسته یا بی‌پاسخ%s\n' "$RED" "$NC"
  return 1
}

if [[ "${1:-}" == "--show" ]]; then
  say ''; info 'تنظیمات فعلی ایمیل:'; say ''
  show_current; say ''
  exit 0
fi

if [[ "${1:-}" == "--check" ]]; then
  say ''; info 'آزمایش مسیر خروجی SMTP از این سرور:'; say ''
  check_route smtp.gmail.com 587
  check_route smtp.gmail.com 465
  check_route mail.stland.ir 587
  check_route mail.stland.ir 465
  say ''
  say 'هر کدام «باز» بود، همان را در تنظیم استفاده کنید.'
  say 'اگر هیچ‌کدام باز نبود، ارائه‌دهنده‌ی VPS پورت‌های ۲۵/۴۶۵/۵۸۷ را بسته'
  say 'و باید از او بخواهید باز کند.'
  say ''
  exit 0
fi

# ---------------------------------------------------------------
say ''
info '── تنظیم ارسال ایمیل برای بازیابی رمز ──'
say ''
say 'وضعیت فعلی:'
show_current
say ''

read -r -p 'ادامه بدهم و مقادیر تازه بگیرم؟ [y/N] ' go
[[ "$go" == [yY] ]] || { say 'کاری انجام نشد.'; exit 0; }

say ''
say 'آدرس مطلق اپ — لینک بازیابی رمز با همین ساخته می‌شود.'
read -r -p '  APP_URL [https://hesab.stland.ir]: ' APP_URL_IN
APP_URL_IN="${APP_URL_IN:-https://hesab.stland.ir}"
APP_URL_IN="${APP_URL_IN%/}"

say ''
say 'سرور SMTP. نمونه‌ها:'
say '  Gmail        smtp.gmail.com    پورت 587    tls'
say '  ایمیل دامنه  mail.stland.ir    پورت 587    tls'
read -r -p '  SMTP_HOST: ' SMTP_HOST_IN
[[ -n "$SMTP_HOST_IN" ]] || die 'SMTP_HOST خالی بود.'
read -r -p '  SMTP_PORT [587]: ' SMTP_PORT_IN
SMTP_PORT_IN="${SMTP_PORT_IN:-587}"
read -r -p '  SMTP_SECURE (tls | ssl | none) [tls]: ' SMTP_SECURE_IN
SMTP_SECURE_IN="${SMTP_SECURE_IN:-tls}"

say ''
say 'قبل از ادامه، مسیر را آزمایش می‌کنم:'
if ! check_route "$SMTP_HOST_IN" "$SMTP_PORT_IN"; then
  say ''
  warn 'از این سرور به آن هاست/پورت راهی نیست.'
  say 'تنظیم را ذخیره می‌کنم ولی ایمیل ارسال نخواهد شد.'
  read -r -p 'باز هم ادامه بدهم؟ [y/N] ' go2
  [[ "$go2" == [yY] ]] || { say 'کاری انجام نشد.'; exit 0; }
fi

say ''
read -r -p '  SMTP_USER (معمولاً همان آدرس ایمیل): ' SMTP_USER_IN
[[ -n "$SMTP_USER_IN" ]] || die 'SMTP_USER خالی بود.'
say ''
say 'برای Gmail رمز خود حساب کار نمی‌کند — باید App Password ۱۶ حرفی'
say 'بسازید (نیازمند فعال بودن تأیید دومرحله‌ای در حساب گوگل).'
read -r -s -p '  SMTP_PASS (نمایش داده نمی‌شود): ' SMTP_PASS_IN; say ''
[[ -n "$SMTP_PASS_IN" ]] || die 'SMTP_PASS خالی بود.'

say ''
read -r -p "  MAIL_FROM [$SMTP_USER_IN]: " MAIL_FROM_IN
MAIL_FROM_IN="${MAIL_FROM_IN:-$SMTP_USER_IN}"
read -r -p '  MAIL_FROM_NAME [دفتر مالی]: ' MAIL_FROM_NAME_IN
MAIL_FROM_NAME_IN="${MAIL_FROM_NAME_IN:-دفتر مالی}"

# EHLO از روی دامنه‌ی APP_URL — بعضی سرورها EHLO ناهم‌خوان را رد می‌کنند
SMTP_EHLO_IN="$(printf '%s' "$APP_URL_IN" | sed -E 's#^https?://##; s#/.*$##')"
[[ -n "$SMTP_EHLO_IN" ]] || SMTP_EHLO_IN='localhost'

# ---------------------------------------------------------------
BACKUP="$CONFIG.bak.$(date +%Y%m%d-%H%M%S)"
cp -p "$CONFIG" "$BACKUP" || die 'گرفتن بکاپ از config.php نشد.'
say ''
say "بکاپ گرفته شد: $BACKUP"

# نوشتن با PHP انجام می‌شود نه با sed: ثابت‌های موجود جایگزین و
# نبوده‌ها افزوده می‌شوند، و مقادیر با var_export فرار داده می‌شوند تا
# رمزی که ' یا \ دارد فایل را نشکند.
CFG_PATH="$CONFIG" \
V_APP_URL="$APP_URL_IN" \
V_SMTP_HOST="$SMTP_HOST_IN" \
V_SMTP_PORT="$SMTP_PORT_IN" \
V_SMTP_SECURE="$SMTP_SECURE_IN" \
V_SMTP_USER="$SMTP_USER_IN" \
V_SMTP_PASS="$SMTP_PASS_IN" \
V_SMTP_EHLO="$SMTP_EHLO_IN" \
V_MAIL_FROM="$MAIL_FROM_IN" \
V_MAIL_FROM_NAME="$MAIL_FROM_NAME_IN" \
php -r '
$path = getenv("CFG_PATH");
$src  = file_get_contents($path);

$vals = [
    "APP_URL"        => getenv("V_APP_URL"),
    "MAIL_METHOD"    => "smtp",
    "MAIL_FROM"      => getenv("V_MAIL_FROM"),
    "MAIL_FROM_NAME" => getenv("V_MAIL_FROM_NAME"),
    "SMTP_HOST"      => getenv("V_SMTP_HOST"),
    "SMTP_PORT"      => (int)getenv("V_SMTP_PORT"),
    "SMTP_SECURE"    => getenv("V_SMTP_SECURE"),
    "SMTP_USER"      => getenv("V_SMTP_USER"),
    "SMTP_PASS"      => getenv("V_SMTP_PASS"),
    "SMTP_EHLO"      => getenv("V_SMTP_EHLO"),
];

$add = [];
foreach ($vals as $k => $v) {
    $line = "define(" . var_export($k, true) . ", " . var_export($v, true) . ");";
    // define موجود را جایگزین کن (هر جای فایل، با هر فاصله‌گذاری)
    $re = "/^[ \t]*define\s*\(\s*[\x27\"]" . preg_quote($k, "/") . "[\x27\"]\s*,.*?\)\s*;[ \t]*$/mi";
    if (preg_match($re, $src)) {
        $src = preg_replace($re, str_replace("$", "\\$", $line), $src, 1);
    } else {
        $add[] = $line;
    }
}

if ($add) {
    // تگ بستن پایانی اگر باشد برداشته شود، وگرنه کد بعدش اجرا نمی‌شود
    $src = preg_replace("/\?>\s*$/", "", rtrim($src));
    $src = rtrim($src) . "\n\n// ---- ارسال ایمیل (deploy/mail-setup.sh) ----\n"
         . implode("\n", $add) . "\n";
}
file_put_contents($path, $src);
' || { cp -p "$BACKUP" "$CONFIG"; die 'نوشتن تنظیمات شکست خورد — بکاپ برگردانده شد.'; }

# نحو باید سالم باشد، وگرنه کل اپ ۵۰۰ می‌دهد
if ! php -l "$CONFIG" >/dev/null 2>&1; then
  cp -p "$BACKUP" "$CONFIG"
  die 'فایل بعد از ویرایش نحو درستی نداشت — بکاپ برگردانده شد. چیزی تغییر نکرد.'
fi

# رمز SMTP داخل این فایل است؛ نباید برای بقیه خواندنی بماند
chmod 640 "$CONFIG" 2>/dev/null || true

say ''
ok 'تنظیمات ذخیره شد.'
say ''
show_current
say ''
info 'حالا آزمایش واقعی ارسال:'
say "  php deploy/user-admin.php --test-mail $MAIL_FROM_IN"
say ''
read -r -p 'همین حالا آزمایش کنم؟ [Y/n] ' t
if [[ "$t" != [nN] ]]; then
  say ''
  php "$APP_DIR/deploy/user-admin.php" --test-mail "$MAIL_FROM_IN"
fi
say ''
say "اگر نتیجه بد بود، برگرداندن به حالت قبل:  cp -p $BACKUP $CONFIG"
say ''
