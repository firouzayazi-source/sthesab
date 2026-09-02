#!/usr/bin/env bash
#
# session-clean.sh — پاک کردن فایل‌های نشستِ منقضی
#
# ─── رعایت قانون جداسازی ───────────────────────────────────────────
# فقط داخل /opt/hesab/app/var/sessions را می‌خواند و پاک می‌کند.
# می‌نویسد فقط در:
#     /etc/cron.d/hesab-sessions   ← فقط با --install-cron
# به نشست‌های PHP سرویس‌های دیگر (/var/lib/php/sessions یا هر pool
# دیگری) دست نمی‌زند و اصلاً سراغشان نمی‌رود.
# ───────────────────────────────────────────────────────────────────
#
# ⚠ چرا این اسکریپت لازم است — سه چیز دست به دست هم داده‌اند:
#
#   ۱. `session.save_path` این pool داخل خودِ پروژه است (hesab.conf)،
#      پس کرونِ `sessionclean` دبیان — که فقط مسیرِ داخل php.ini را
#      می‌بیند — هرگز سراغش نمی‌آید.
#   ۲. روی اوبونتو `session.gc_probability = 0` است، یعنی خودِ PHP هم
#      هیچ‌وقت جمعشان نمی‌کند.
#   ۳. `Auth::initSession()` مقدار `gc_maxlifetime` را روی یک ماه
#      می‌گذارد تا نشستِ کاربری که مهلت بلند انتخاب کرده زودتر نمیرد.
#
# نتیجه: پوشه‌ی نشست **هرگز پاک نمی‌شود**. با یک کاربر نامرئی است؛ با
# صدها کاربر، ده‌ها هزار فایل در یک پوشه جمع می‌شود و آن‌وقت خودِ
# باز کردن پوشه کند می‌شود، اینود تمام می‌شود، یا دیسک پر می‌شود.
# خرابی‌اش هم بی‌صداست: یک روز سایت کند می‌شود و کسی ربطش را پیدا
# نمی‌کند.
#
# ⚠ مهلت پاک‌سازی عمداً از مهلتِ خودِ اپ **بلندتر** است. اپ با
# `session_minutes` تصمیم می‌گیرد چه کسی هنوز وارد است؛ این اسکریپت
# فقط آشغال را می‌برد. اگر کوتاه‌تر بود، کاربرِ واردی را بیرون
# می‌انداخت — یعنی همان چیزی که هیچ‌کس ربطش را به یک کرون پیدا نمی‌کند.
#
# اجرا:
#     bash deploy/session-clean.sh                 # نمایشی — فقط گزارش
#     bash deploy/session-clean.sh --apply         # واقعاً پاک کن
#     sudo bash deploy/session-clean.sh --install-cron
#     bash deploy/session-clean.sh --status        # فقط آمار

set -euo pipefail

APP_DIR="${APP_DIR:-/opt/hesab/app}"
SESSION_DIR="${SESSION_DIR:-$APP_DIR/var/sessions}"

# روز. باید از بلندترین مهلتِ محدودِ Auth::SESSION_WINDOWS (یک ماه)
# بیشتر باشد، وگرنه نشستِ کاربرِ فعال پاک می‌شود.
KEEP_DAYS="${KEEP_DAYS:-35}"

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
info()  { printf '\033[0;36m%s\033[0m\n' "$1"; }
warn()  { printf '\033[0;33m%s\033[0m\n' "$1"; }

# ---------- نصب cron ----------
if [[ "${1:-}" == "--install-cron" ]]; then
    [[ $EUID -ne 0 ]] && { red "برای نصب cron باید با sudo اجرا شود."; exit 1; }
    cat > /etc/cron.d/hesab-sessions <<CRON
# پاک‌سازی نشست‌های منقضی دفتر مالی — فقط مربوط به همین پروژه
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
15 4 * * * root $APP_DIR/deploy/session-clean.sh --apply >/dev/null 2>&1
CRON
    chmod 644 /etc/cron.d/hesab-sessions
    green "زمان‌بندی شد: هر شب ساعت ۴:۱۵ بامداد."
    info "فایل: /etc/cron.d/hesab-sessions"
    info "برای لغو:  sudo rm /etc/cron.d/hesab-sessions"
    exit 0
fi

# ---------- بررسی پوشه ----------
if [[ ! -d "$SESSION_DIR" ]]; then
    red "پوشه‌ی نشست پیدا نشد: $SESSION_DIR"
    info "اگر مسیر نصب فرق دارد:  APP_DIR=/path/to/app bash $0"
    exit 1
fi

# ⛔ محافظِ آخر در برابر پاک کردنِ جای اشتباه.
#
# اگر روزی SESSION_DIR با متغیر محیطی به مسیر دیگری اشاره کند (اشتباه
# تایپی، یا کپی شدنِ این اسکریپت جای دیگر)، این شرط جلوی حذف را
# می‌گیرد. بدون آن، یک `SESSION_DIR=/` کافی بود تا فاجعه شود.
case "$SESSION_DIR" in
    */var/sessions) : ;;
    *)
        red "امتناع: مسیر باید به var/sessions ختم شود، ولی این است:"
        red "  $SESSION_DIR"
        exit 1
        ;;
esac

# ---------- آمار ----------
total=$(find "$SESSION_DIR" -maxdepth 1 -type f -name 'sess_*' 2>/dev/null | wc -l)
stale=$(find "$SESSION_DIR" -maxdepth 1 -type f -name 'sess_*' -mtime "+$KEEP_DAYS" 2>/dev/null | wc -l)
bytes=$(du -sk "$SESSION_DIR" 2>/dev/null | cut -f1)

echo
info "پوشه‌ی نشست: $SESSION_DIR"
printf '  فایل‌های نشست         %s\n' "$total"
printf '  منقضی (بیش از %s روز)  %s\n' "$KEEP_DAYS" "$stale"
printf '  حجم پوشه              %s KB\n' "${bytes:-؟}"
echo

if [[ "${1:-}" == "--status" ]]; then
    exit 0
fi

if [[ "$stale" -eq 0 ]]; then
    green "چیزی برای پاک کردن نیست."
    exit 0
fi

if [[ "${1:-}" != "--apply" ]]; then
    warn "حالت نمایشی — چیزی پاک نشد."
    info "برای پاک کردن واقعی:  bash $0 --apply"
    exit 0
fi

# ---------- پاک‌سازی ----------
find "$SESSION_DIR" -maxdepth 1 -type f -name 'sess_*' -mtime "+$KEEP_DAYS" -delete

after=$(find "$SESSION_DIR" -maxdepth 1 -type f -name 'sess_*' 2>/dev/null | wc -l)
green "پاک شد: $((total - after)) فایل   (باقی‌مانده: $after)"
