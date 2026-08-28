#!/usr/bin/env bash
#
# migrate.sh — اعمال migration ها با ردیابی، به‌جای اجرای دستی و حدس ترتیب
#
# چرا لازم شد: تا پیش از این هیچ‌جا ثبت نمی‌شد که کدام migration اجرا شده.
# ترتیب درست فقط در ذهن آدم‌ها بود و برای پیدا کردنش باید تک‌تک فایل‌ها را
# می‌خواندی. این اسکریپت آن دانش را در کد و در دیتابیس ثبت می‌کند.
#
# جدول ردیابی: schema_migrations (نام فایل، زمان اجرا، چک‌سام محتوا)
# چک‌سام برای این است که اگر فایلی بعد از اجرا عوض شود، هشدار بدهد.
#
# ─── حالت‌ها ────────────────────────────────────────────────────────
#   bash deploy/migrate.sh              وضعیت را نشان می‌دهد (پیش‌فرض، بی‌خطر)
#   bash deploy/migrate.sh --apply      migration های اجرانشده را اعمال می‌کند
#   bash deploy/migrate.sh --baseline   همه را «اجراشده» علامت می‌زند بدون اجرا
#
# --baseline برای دیتابیسی است که از قبل کامل است ولی جدول ردیابی ندارد
# (مثل دیتابیسی که از هاست اشتراکی ایمپورت شده). یک بار اجرا می‌شود.
# ────────────────────────────────────────────────────────────────────

set -euo pipefail

CONFIG="${CONFIG:-config/config.php}"
DB_NAME="${DB_NAME:-}"
DB_USER="${DB_USER:-}"
MODE="status"

case "${1:-}" in
    --apply)    MODE="apply" ;;
    --baseline) MODE="baseline" ;;
    --status|"") MODE="status" ;;
    -h|--help)  sed -n '2,25p' "$0"; exit 0 ;;
    *) echo "گزینه ناشناخته: $1"; exit 1 ;;
esac

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
info()  { printf '\033[0;36m%s\033[0m\n' "$1"; }
warn()  { printf '\033[0;33m%s\033[0m\n' "$1"; }

cd "$(dirname "$0")/.."

# ---------- ترتیب migration ها ----------
# این فهرست تنها مرجع ترتیب است. با خواندن خود فایل‌ها بررسی شده:
#
#   schema.sql ................. users, categories, transactions, debts, app_settings
#   migration_debts ............ debts (اگر نبود)
#   migration_settings ......... app_settings (اگر نبود)
#   migration_cheques_assets ... cheques, banks, assets, asset_types
#   migration_indexes .......... ایندکس روی transactions/debts/cheques
#                                ← باید بعد از cheques_assets بیاید
#   migration_category_icons ... ستون icon و color روی categories
#   migration_repair ........... wallets, transfers, budgets, savings_*,
#                                debt_payments, recurring_transactions
#                                ← جایگزین کامل migration_wallets و migration_p1
#   migration_p2 ............... attachments
#   migration_p3 ............... trusted_devices, users.session_hours
#   migration_p4 ............... users.avatar
MIGRATIONS=(
    schema.sql
    migration_debts.sql
    migration_settings.sql
    migration_cheques_assets.sql
    migration_indexes.sql
    migration_category_icons.sql
    migration_repair.sql
    migration_p2.sql
    migration_p3.sql
    migration_p4.sql
)

# این دو عمداً اجرا نمی‌شوند: migration_repair.sql جایگزین کامل هر دو است.
SUPERSEDED=( migration_wallets.sql migration_p1.sql )

# ---------- بررسی همخوانی فهرست با فایل‌های روی دیسک ----------
# اگر کسی فایل migration تازه‌ای اضافه کند و اینجا ثبتش نکند، باید سروصدا
# کند — نه اینکه بی‌سروصدا نادیده گرفته شود.
known=" ${MIGRATIONS[*]} ${SUPERSEDED[*]} "
unregistered=()
for f in migration_*.sql; do
    [[ -f "$f" ]] || continue
    [[ "$known" == *" $f "* ]] || unregistered+=("$f")
done
if (( ${#unregistered[@]} )); then
    red "⛔ این فایل‌ها روی دیسک هستند ولی در فهرست ترتیب ثبت نشده‌اند:"
    printf '     %s\n' "${unregistered[@]}"
    red "   آن‌ها را در آرایه‌ی MIGRATIONS همین فایل، در جای درست، اضافه کنید."
    exit 1
fi
for f in "${MIGRATIONS[@]}"; do
    [[ -f "$f" ]] || { red "فایل پیدا نشد: $f"; exit 1; }
done

# ---------- اتصال ----------
read_const() { php -r 'require $argv[1]; echo constant($argv[2]);' "$CONFIG" "$1" 2>/dev/null || true; }
if [[ -r "$CONFIG" ]] && command -v php >/dev/null 2>&1; then
    DB_NAME="${DB_NAME:-$(read_const DB_NAME)}"
    DB_USER="${DB_USER:-$(read_const DB_USER)}"
    DB_PASS="$(read_const DB_PASSWORD)"
fi
DB_NAME="${DB_NAME:-hesab_db}"
DB_USER="${DB_USER:-hesab_user}"
if [[ -z "${DB_PASS:-}" ]]; then
    info "رمز از $CONFIG خوانده نشد — دستی بدهید."
    read -r -s -p "رمز کاربر $DB_USER: " DB_PASS; echo
fi

mysql_q() { mysql --default-character-set=utf8mb4 -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" "$@"; }

if ! mysql_q -e "SELECT 1" >/dev/null 2>&1; then
    red "اتصال به دیتابیس برقرار نشد (کاربر: $DB_USER، دیتابیس: $DB_NAME)."
    exit 1
fi

# ---------- جدول ردیابی ----------
mysql_q -e "
CREATE TABLE IF NOT EXISTS \`schema_migrations\` (
    \`filename\`   VARCHAR(190) NOT NULL,
    \`checksum\`   CHAR(64)     NOT NULL,
    \`applied_at\` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (\`filename\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;" >/dev/null

applied_list=$(mysql_q -N -e "SELECT filename FROM schema_migrations;" 2>/dev/null || true)
is_applied() { grep -qxF "$1" <<<"$applied_list"; }
sum_of() { sha256sum "$1" | cut -d' ' -f1; }
stored_sum() { mysql_q -N -e "SELECT checksum FROM schema_migrations WHERE filename='$1';" 2>/dev/null; }
record() {
    mysql_q -e "INSERT INTO schema_migrations (filename, checksum) VALUES ('$1','$2')
                ON DUPLICATE KEY UPDATE checksum=VALUES(checksum);" >/dev/null
}

# ---------- تشخیص دیتابیس موجودِ بدون ردیابی ----------
# اگر جدول‌های اپ هستند ولی هیچ migration ثبت نشده، یعنی این دیتابیس از
# قبل ساخته شده (مثلاً ایمپورت از هاست). اجرای دوباره‌ی همه‌چیز روی آن
# اشتباه است، پس اسکریپت می‌ایستد و --baseline می‌خواهد.
has_tables=$(mysql_q -N -e "SELECT COUNT(*) FROM information_schema.tables
                            WHERE table_schema=DATABASE() AND table_name='transactions';")
applied_count=$(mysql_q -N -e "SELECT COUNT(*) FROM schema_migrations;")

if [[ "$has_tables" == "1" && "$applied_count" == "0" && "$MODE" != "baseline" ]]; then
    warn "این دیتابیس از قبل جدول‌های اپ را دارد ولی هیچ migration ثبت نشده."
    warn "یعنی پیش از راه‌اندازی ردیابی ساخته شده است."
    echo
    info "اگر مطمئنید ساختارش کامل است (مثلاً از هاست ایمپورت شده):"
    echo "    bash deploy/migrate.sh --baseline"
    echo
    info "این کار همه را «اجراشده» علامت می‌زند بدون اینکه چیزی اجرا شود."
    exit 1
fi

# ---------- baseline ----------
if [[ "$MODE" == "baseline" ]]; then
    info "علامت‌زدن همه به‌عنوان اجراشده، بدون اجرا:"
    for f in "${MIGRATIONS[@]}"; do
        record "$f" "$(sum_of "$f")"
        echo "    ✓ $f"
    done
    green "✅ baseline ثبت شد. از این پس فقط migration های تازه اجرا می‌شوند."
    exit 0
fi

# ---------- وضعیت / اعمال ----------
pending=()
changed=()
for f in "${MIGRATIONS[@]}"; do
    if is_applied "$f"; then
        [[ "$(stored_sum "$f")" == "$(sum_of "$f")" ]] || changed+=("$f")
    else
        pending+=("$f")
    fi
done

echo
info "دیتابیس: $DB_NAME"
echo
for f in "${MIGRATIONS[@]}"; do
    if is_applied "$f"; then
        if [[ " ${changed[*]-} " == *" $f "* ]]; then
            printf '  \033[0;33m~\033[0m %-34s اجرا شده — ولی فایل بعدش تغییر کرده\n' "$f"
        else
            printf '  \033[0;32m✓\033[0m %-34s اجرا شده\n' "$f"
        fi
    else
        printf '  \033[0;36m·\033[0m %-34s در انتظار\n' "$f"
    fi
done
echo

if (( ${#changed[@]} )); then
    warn "⚠️  این فایل‌ها بعد از اجرا تغییر کرده‌اند:"
    printf '     %s\n' "${changed[@]}"
    warn "   تغییر یک migration اجراشده یعنی دیتابیس‌های مختلف ساختار متفاوتی"
    warn "   دارند. به‌جای ویرایش فایل قدیمی، یک migration تازه بنویسید."
    echo
fi

if (( ${#pending[@]} == 0 )); then
    green "چیزی برای اجرا نیست — دیتابیس به‌روز است."
    exit 0
fi

if [[ "$MODE" != "apply" ]]; then
    info "${#pending[@]} migration در انتظار است. برای اجرا:"
    echo "    bash deploy/migrate.sh --apply"
    exit 0
fi

info "اجرای ${#pending[@]} migration در انتظار:"
for f in "${pending[@]}"; do
    printf '  → %-34s' "$f"
    if err=$(mysql_q < "$f" 2>&1); then
        record "$f" "$(sum_of "$f")"
        green "OK"
    elif grep -qiE "Duplicate key name|Duplicate column name|already exists" <<<"$err"; then
        # migration_indexes.sql ایدمپوتنت نیست؛ اگر ایندکس از قبل باشد
        # همین خطا را می‌دهد. یعنی نتیجه‌اش از قبل اعمال شده.
        record "$f" "$(sum_of "$f")"
        info "از قبل اعمال شده — ثبت شد"
    else
        echo
        red "خطا در $f:"
        red "$err"
        red "متوقف شد. migration های قبلی ثبت شده‌اند؛ بعد از رفع خطا دوباره اجرا کنید."
        exit 1
    fi
done

echo
green "✅ همه‌ی migration ها اعمال شدند."
