#!/usr/bin/env bash
#
# restore.sh — بازیابی بکاپ دیتابیس دفتر مالی
#
# ─── رعایت قانون جداسازی ───────────────────────────────────────────
# فقط روی دیتابیس همین پروژه کار می‌کند (نامش از config/config.php
# خوانده می‌شود، یا با --into صریحاً داده می‌شود). به دیتابیس، پوشه،
# یا سرویس هیچ پروژه‌ی دیگری دست نمی‌زند و هیچ سرویسی را restart
# نمی‌کند.
# ───────────────────────────────────────────────────────────────────
#
# چرا لازم شد:
#
# `backup.sh` هر شب بکاپ می‌گرفت، ولی هیچ راهِ نوشته‌شده‌ای برای
# برگرداندنش نبود. بکاپی که یک بار بازیابی نشده باشد بکاپ نیست، فرضیه
# است — و روزی که واقعاً لازم شود، بدترین وقت برای کشف کردنِ فرمانِ
# درست است.
#
# دو حالت دارد و **پیش‌فرضش حالتِ بی‌خطر است**:
#
#   --verify   (پیش‌فرض) بکاپ را روی یک دیتابیس موقت بازیابی می‌کند،
#              جدول‌ها و تعداد ردیف‌ها را با دیتابیس زنده می‌سنجد، و
#              آخرش دیتابیس موقت را پاک می‌کند. به داده‌ی زنده اصلاً
#              دست نمی‌زند. این همان کاری است که باید هر چند وقت یک بار
#              انجام دهید تا مطمئن باشید بکاپ‌ها واقعاً کار می‌کنند.
#
#   --into NAME  بازیابی روی دیتابیسی که خودتان نام می‌برید. برای
#              برگرداندن واقعی. اگر نام همان دیتابیس زنده باشد، اول
#              یک بکاپ ایمنی می‌گیرد و بعد تأیید تایپی می‌خواهد.
#
#   --admin    با کاربر مدیرِ دیتابیس (سوکت یونیکس) وصل می‌شود، نه با
#              کاربر اپ. **برای حالت سنجش لازم است**، چون سنجش یک
#              دیتابیس موقت می‌سازد و کاربر اپ طبق قانون جداسازی فقط
#              به دیتابیس خودش دسترسی دارد — و این عمدی است.
#              به کاربر اپ GRANT اضافه ندهید؛ همان کاری است که قانون
#              جداسازی منع می‌کند.
#
# اجرا:
#     bash deploy/restore.sh --list
#     sudo bash deploy/restore.sh --admin                     # آخرین بکاپ را می‌سنجد
#     sudo bash deploy/restore.sh --admin /opt/hesab/backups/x.sql.gz
#     bash deploy/restore.sh --into hesab_db                  # بازیابی واقعی

set -euo pipefail

APP_DIR="${APP_DIR:-/opt/hesab/app}"
BACKUP_DIR="${BACKUP_DIR:-/opt/hesab/backups}"
CONFIG="${CONFIG:-$APP_DIR/config/config.php}"

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
info()  { printf '\033[0;36m%s\033[0m\n' "$1"; }
step()  { printf '\n\033[1m%s\033[0m\n' "$1"; }
plain() { printf '  %s\n' "$1"; }

# ---------- خواندن آرگومان‌ها ----------
TARGET_DB=""
FILE=""
ADMIN=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --list)   LIST=1; shift ;;
        --into)   TARGET_DB="${2:-}"; shift 2 ;;
        --verify) shift ;;
        --admin)  ADMIN=1; shift ;;
        -h|--help)
            sed -n '3,40p' "$0" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *)        FILE="$1"; shift ;;
    esac
done

# ---------- فهرست بکاپ‌ها ----------
if [[ "${LIST:-0}" == "1" ]]; then
    if compgen -G "$BACKUP_DIR/*.sql.gz" > /dev/null; then
        ls -lht "$BACKUP_DIR"/*.sql.gz | awk '{print "  "$9"  "$5"  "$6" "$7" "$8}'
    else
        info "هنوز بکاپی در $BACKUP_DIR نیست."
    fi
    exit 0
fi

# ---------- اطلاعات اتصال ----------
[[ -r "$CONFIG" ]] || { red "خوانده نشد: $CONFIG"; exit 1; }
read_const() { php -r 'require $argv[1]; echo constant($argv[2]);' "$CONFIG" "$1" 2>/dev/null || true; }
DB_NAME="$(read_const DB_NAME)"
DB_USER="$(read_const DB_USER)"
DB_PASS="$(read_const DB_PASSWORD)"
DB_HOST="$(read_const DB_HOST)"
# نام دیتابیس همیشه لازم است (هدفِ مقایسه)، ولی نام کاربر فقط وقتی که
# قرار است با کاربر اپ وصل شویم. با --admin از سوکت می‌رویم.
[[ -n "$DB_NAME" ]] || { red "نام دیتابیس از $CONFIG خوانده نشد."; exit 1; }
if [[ $ADMIN -eq 0 && -z "$DB_USER" ]]; then
    red "کاربر دیتابیس از $CONFIG خوانده نشد."
    exit 1
fi

# ---------- کدام کاربر دیتابیس ----------
#
# پیش‌فرض: همان کاربر اپ (`hesab_user`). برای بازیابی واقعی روی دیتابیس
# خودِ اپ کافی است.
#
# `--admin`: با کاربر مدیرِ دیتابیس از طریق سوکت یونیکس (یعنی همان
# `sudo mysql`). این تنها راهِ درست برای حالتِ سنجش است، چون سنجش یک
# دیتابیس موقت می‌سازد و کاربر اپ طبق **قانون جداسازی** فقط به
# `hesab_db` دسترسی دارد.
#
# ⚠ وسوسه‌ی اشتباه: «خب یک GRANT به hesab_user بدهیم.» نه — همان کاری
# است که قانون جداسازی منع می‌کند. دسترسی کاربر اپ باید دقیقاً
# `hesab_db.*` بماند؛ کسی که دیتابیس موقت می‌سازد باید مدیر باشد، و
# فقط برای همان چند ثانیه.
if [[ $ADMIN -eq 1 ]]; then
    mysql_do()  { mysql --default-character-set=utf8mb4 "$@"; }
    dump_do()   { mysqldump --default-character-set=utf8mb4 "$@"; }
    WHOAMI_DB="مدیر دیتابیس (سوکت یونیکس)"
else
    mysql_do()  { mysql --default-character-set=utf8mb4 -h "${DB_HOST:-localhost}" -u "$DB_USER" -p"$DB_PASS" "$@"; }
    dump_do()   { mysqldump --default-character-set=utf8mb4 -h "${DB_HOST:-localhost}" -u "$DB_USER" -p"$DB_PASS" "$@"; }
    WHOAMI_DB="$DB_USER"
fi

# ---------- پیدا کردن فایل بکاپ ----------
if [[ -z "$FILE" ]]; then
    FILE="$(ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | head -1 || true)"
    [[ -n "$FILE" ]] || { red "هیچ بکاپی در $BACKUP_DIR پیدا نشد."; exit 1; }
    info "آخرین بکاپ انتخاب شد: $(basename "$FILE")"
fi
[[ -r "$FILE" ]] || { red "فایل خوانده نشد: $FILE"; exit 1; }

step "بررسی سلامت فایل"
if ! gzip -t "$FILE" 2>/dev/null; then
    red "⛔ فایل gzip سالم نیست. این بکاپ قابل استفاده نیست."
    exit 1
fi
green "✓ فایل gzip سالم است."

# یک بکاپ خالی هم gzip سالمی دارد — پس محتوا هم سنجیده می‌شود.
TABLES_IN_DUMP=$(zcat "$FILE" | grep -c '^CREATE TABLE' || true)
if (( TABLES_IN_DUMP == 0 )); then
    red "⛔ فایل هیچ جدولی ندارد. این بکاپ خالی است."
    exit 1
fi
plain "جدول‌های داخل فایل: $TABLES_IN_DUMP"

# ================================================================
# حالت بازیابی واقعی
# ================================================================
if [[ -n "$TARGET_DB" ]]; then
    step "بازیابی روی «$TARGET_DB»"

    if [[ "$TARGET_DB" == "$DB_NAME" ]]; then
        red "⚠ این همان دیتابیس زنده‌ی اپ است. همه‌ی داده‌ی فعلی جایگزین می‌شود."
        plain ""
        plain "اول یک بکاپ ایمنی از وضعیت فعلی گرفته می‌شود."
        SAFETY="$BACKUP_DIR/${DB_NAME}_before-restore_$(date +%Y-%m-%d_%H%M).sql.gz"
        mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
        if dump_do --single-transaction --quick --add-drop-table --routines --events \
                "$DB_NAME" | gzip -9 > "$SAFETY"; then
            chmod 600 "$SAFETY"
            green "✓ بکاپ ایمنی: $(basename "$SAFETY")"
        else
            red "⛔ بکاپ ایمنی گرفته نشد — بازیابی انجام نمی‌شود."
            rm -f "$SAFETY"; exit 1
        fi

        plain ""
        printf '  برای ادامه عبارت  restore  را تایپ کنید: '
        read -r CONFIRM
        [[ "$CONFIRM" == "restore" ]] || { info "لغو شد. چیزی تغییر نکرد."; exit 0; }
    fi

    if ! zcat "$FILE" | mysql_do "$TARGET_DB"; then
        red "⛔ بازیابی شکست خورد."
        [[ -n "${SAFETY:-}" ]] && plain "برگرداندن به قبل:  zcat $SAFETY | mysql -u $DB_USER -p $DB_NAME"
        exit 1
    fi
    green "✅ بازیابی روی «$TARGET_DB» انجام شد."
    plain "حالا یک بار وضعیت migration ها را ببینید:  bash deploy/migrate.sh"
    exit 0
fi

# ================================================================
# حالت پیش‌فرض: سنجش بی‌خطر روی دیتابیس موقت
# ================================================================
TMP_DB="${DB_NAME}_restorecheck"

step "سنجش بازیابی روی دیتابیس موقت «$TMP_DB»"
plain "به داده‌ی زنده دست زده نمی‌شود."

# اگر کاربر دیتابیس اجازه‌ی ساختن دیتابیس تازه را نداشته باشد (که طبق
# قانون جداسازی محتمل است — GRANT فقط روی hesab_db.* است)، همین‌جا
# صادقانه گفته می‌شود، نه اینکه با خطای مبهم بمیرد.
plain "کاربر دیتابیس: $WHOAMI_DB"

if ! mysql_do -e "CREATE DATABASE IF NOT EXISTS \`$TMP_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci" 2>/dev/null; then
    red "⛔ دیتابیس موقت ساخته نشد."
    plain ""
    if [[ $ADMIN -eq 1 ]]; then
        plain "با کاربر مدیر هم نشد. یعنی یا سرویس دیتابیس بالا نیست، یا این"
        plain "کاربر سیستمی اجازه‌ی ورود با سوکت ندارد. با sudo اجرا کنید:"
        plain ""
        plain "  sudo bash deploy/restore.sh --admin"
    else
        plain "کاربر «$DB_USER» طبق **قانون جداسازی** فقط به «$DB_NAME» دسترسی"
        plain "دارد، پس نمی‌تواند دیتابیس تازه بسازد. این عمدی است و درست."
        plain ""
        plain "سنجش را با کاربر مدیر اجرا کنید — دسترسی اپ دست‌نخورده می‌ماند:"
        plain ""
        plain "  sudo bash deploy/restore.sh --admin"
        plain ""
        plain "⚠ به «$DB_USER» GRANT ندهید. گشاد کردن دسترسی کاربر اپ بیرون از"
        plain "  «$DB_NAME» دقیقاً همان چیزی است که قانون جداسازی منع می‌کند."
    fi
    exit 1
fi

cleanup_tmp() { mysql_do -e "DROP DATABASE IF EXISTS \`$TMP_DB\`" 2>/dev/null || true; }
trap cleanup_tmp EXIT

if ! zcat "$FILE" | mysql_do "$TMP_DB"; then
    red "⛔ بازیابی روی دیتابیس موقت شکست خورد. این بکاپ سالم نیست."
    exit 1
fi
green "✓ فایل بدون خطا بازیابی شد."

# ---------- مقایسه با دیتابیس زنده ----------
step "مقایسه با دیتابیس زنده"

list_tables() {
    # مرتب‌سازی عمداً با `LC_ALL=C sort` است و نه با ORDER BY دیتابیس:
    # ترتیبِ MySQL از collation می‌آید و با ترتیبِ بایتیِ comm یکی نیست
    # (مثلاً `wallet_kinds` و `wallets`). comm با ورودی نامرتب هشدار
    # می‌دهد ولی *ادامه هم می‌دهد* و نتیجه‌اش غلط است — یعنی می‌توانست
    # یک جدولِ واقعاً گم‌شده را نبیند و بکاپ ناقص را سالم اعلام کند.
    mysql_do -N -e "SELECT table_name FROM information_schema.tables
                    WHERE table_schema = '$1'" | LC_ALL=C sort
}

LIVE_T=$(list_tables "$DB_NAME")
TMP_T=$(list_tables "$TMP_DB")

MISSING=$(comm -23 <(echo "$LIVE_T") <(echo "$TMP_T") || true)
if [[ -n "$MISSING" ]]; then
    red "⛔ این جدول‌ها در بکاپ نیستند:"
    echo "$MISSING" | sed 's/^/    /'
    plain ""
    # شایع‌ترین علت اصلاً خرابی نیست: بکاپ پیش از آخرین migration گرفته
    # شده. بدون این توضیح، کاربر فکر می‌کند بکاپ‌هایش خراب‌اند.
    plain "شایع‌ترین علت: این بکاپ *پیش از* آخرین migration گرفته شده، پس"
    plain "جدول‌های تازه هنوز در آن نیستند. یعنی بکاپ سالم است ولی قدیمی."
    plain ""
    plain "برای مطمئن شدن، یک بکاپ تازه بگیرید و دوباره بسنجید:"
    plain "  sudo bash deploy/backup.sh"
    plain "  sudo bash deploy/restore.sh --admin"
    plain ""
    plain "اگر روی بکاپِ تازه هم این پیام آمد، آن‌وقت واقعاً مشکلی هست."
    exit 1
fi
green "✓ همه‌ی $(echo "$LIVE_T" | wc -l) جدول در بکاپ هست."

# تعداد ردیف‌ها. اختلاف لزوماً خرابی نیست — بکاپ عکسِ یک لحظه‌ی قبل
# است و از آن موقع ممکن است ردیف تازه اضافه شده باشد. پس گزارش
# می‌شود، ولی فقط *کمتر بودنِ فاحش* هشدار است.
DIFFS=0
while read -r t; do
    [[ -z "$t" ]] && continue
    a=$(mysql_do -N -e "SELECT COUNT(*) FROM \`$t\`" "$DB_NAME")
    b=$(mysql_do -N -e "SELECT COUNT(*) FROM \`$t\`" "$TMP_DB")
    if [[ "$a" != "$b" ]]; then
        plain "• $t — زنده: $a ، بکاپ: $b"
        DIFFS=$((DIFFS + 1))
    fi
done <<< "$LIVE_T"

if (( DIFFS == 0 )); then
    green "✓ تعداد ردیف همه‌ی جدول‌ها یکسان است."
else
    info "$DIFFS جدول اختلاف تعداد دارند — اگر از زمان بکاپ داده‌ای اضافه شده، طبیعی است."
fi

step "نتیجه"
green "✅ این بکاپ قابل بازیابی است."
plain "فایل: $(basename "$FILE")"
plain ""
plain "برای بازیابی واقعی (با بکاپ ایمنی و تأیید):"
plain "  bash deploy/restore.sh '$FILE' --into $DB_NAME"
