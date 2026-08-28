#!/usr/bin/env bash
#
# backup.sh — بکاپ روزانه از دیتابیس دفتر مالی
#
# ─── رعایت قانون جداسازی ───────────────────────────────────────────
# فقط از دیتابیس همین پروژه بکاپ می‌گیرد (mysqldump روی hesab_db با
# کاربر hesab_user که اصلاً به دیتابیس دیگری دسترسی ندارد).
# می‌نویسد فقط در:
#     /opt/hesab/backups           ← فایل‌های بکاپ
#     /etc/cron.d/hesab-backup     ← فقط با --install-cron
# به crontab کاربران، سرویس‌ها، یا بکاپ سرویس‌های دیگر کاری ندارد.
# ───────────────────────────────────────────────────────────────────
#
# مهم: پوشه‌ی بکاپ عمداً بیرون از /opt/hesab/app است تا از طریق وب
# قابل دانلود نباشد. با open_basedir، کد PHP اپ هم آن را نمی‌بیند.
#
# اجرا:
#     bash deploy/backup.sh                 # یک بکاپ همین حالا
#     bash deploy/backup.sh --install-cron  # زمان‌بندی روزانه ساعت ۳ بامداد
#     bash deploy/backup.sh --list          # فهرست بکاپ‌های موجود

set -euo pipefail

APP_DIR="${APP_DIR:-/opt/hesab/app}"
BACKUP_DIR="${BACKUP_DIR:-/opt/hesab/backups}"
CONFIG="${CONFIG:-$APP_DIR/config/config.php}"
KEEP="${KEEP:-14}"

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
info()  { printf '\033[0;36m%s\033[0m\n' "$1"; }

# ---------- فهرست ----------
if [[ "${1:-}" == "--list" ]]; then
    if [[ -d "$BACKUP_DIR" ]]; then
        ls -lh "$BACKUP_DIR"/*.sql.gz 2>/dev/null | awk '{print "  "$9"  "$5"  "$6" "$7" "$8}' \
            || info "هنوز بکاپی گرفته نشده."
    else
        info "پوشه‌ی بکاپ هنوز ساخته نشده."
    fi
    exit 0
fi

# ---------- نصب cron ----------
if [[ "${1:-}" == "--install-cron" ]]; then
    [[ $EUID -ne 0 ]] && { red "برای نصب cron باید با sudo اجرا شود."; exit 1; }
    # فایل اختصاصی این پروژه — به cron بقیه‌ی سرویس‌ها دست نمی‌زند
    cat > /etc/cron.d/hesab-backup <<CRON
# بکاپ روزانه دیتابیس دفتر مالی — فقط مربوط به همین پروژه
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
30 3 * * * root $APP_DIR/deploy/backup.sh >> $BACKUP_DIR/backup.log 2>&1
CRON
    chmod 644 /etc/cron.d/hesab-backup
    mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
    green "زمان‌بندی شد: هر شب ساعت ۳:۳۰ بامداد."
    info "فایل: /etc/cron.d/hesab-backup"
    info "برای لغو:  sudo rm /etc/cron.d/hesab-backup"
    exit 0
fi

# ---------- اطلاعات اتصال ----------
[[ -r "$CONFIG" ]] || { red "خوانده نشد: $CONFIG"; exit 1; }
read_const() { php -r 'require $argv[1]; echo constant($argv[2]);' "$CONFIG" "$1" 2>/dev/null || true; }
DB_NAME="$(read_const DB_NAME)"
DB_USER="$(read_const DB_USER)"
DB_PASS="$(read_const DB_PASSWORD)"
[[ -n "$DB_NAME" && -n "$DB_USER" ]] || { red "اطلاعات دیتابیس از $CONFIG خوانده نشد."; exit 1; }

# ---------- بکاپ ----------
mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
STAMP=$(date +%Y-%m-%d_%H%M)
OUT="$BACKUP_DIR/${DB_NAME}_${STAMP}.sql.gz"
TMP="$OUT.partial"

# --single-transaction: بدون قفل کردن جدول‌ها، پس اپ حین بکاپ کار می‌کند
if ! mysqldump --default-character-set=utf8mb4 --single-transaction --quick \
        --add-drop-table --routines --events \
        -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>"$TMP.err" | gzip -9 > "$TMP"; then
    red "بکاپ شکست خورد: $(head -3 "$TMP.err" 2>/dev/null)"
    rm -f "$TMP" "$TMP.err"
    exit 1
fi
rm -f "$TMP.err"

# فایل ناقص هرگز به‌جای بکاپ سالم ننشیند
if ! gzip -t "$TMP" 2>/dev/null; then
    red "فایل خروجی سالم نیست — دور ریخته شد."
    rm -f "$TMP"; exit 1
fi
mv "$TMP" "$OUT"
chmod 600 "$OUT"

SIZE=$(du -h "$OUT" | cut -f1)
TX=$(zcat "$OUT" | grep -c "^INSERT INTO \`transactions\`" || true)
green "بکاپ گرفته شد: $(basename "$OUT")  ($SIZE)"

# ---------- چرخش ----------
COUNT=$(ls -1 "$BACKUP_DIR"/*.sql.gz 2>/dev/null | wc -l)
if (( COUNT > KEEP )); then
    ls -1t "$BACKUP_DIR"/*.sql.gz | tail -n +$((KEEP+1)) | while read -r old; do
        rm -f "$old"; info "قدیمی حذف شد: $(basename "$old")"
    done
fi
info "تعداد بکاپ موجود: $(ls -1 "$BACKUP_DIR"/*.sql.gz 2>/dev/null | wc -l) (حداکثر $KEEP نگه داشته می‌شود)"
