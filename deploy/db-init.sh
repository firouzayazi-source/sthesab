#!/usr/bin/env bash
#
# db-init.sh — ساخت ساختار دیتابیس، به ترتیب درست و با توقف روی خطا
#
# چرا اسکریپت جدا؟ چون ترتیب migration ها مهم است و در حلقه‌ی ساده‌ی
# `for f in *.sql; do mysql < $f; done` اگر یکی وسط راه خطا بدهد،
# بقیه بی‌سروصدا ادامه می‌دهند و دیتابیس نیمه‌کاره می‌ماند.
#
# فقط روی دیتابیس همین پروژه کار می‌کند. هیچ دیتابیس دیگری را نمی‌بیند.
#
# اجرا:
#     bash deploy/db-init.sh                 # فقط ترتیب را نشان می‌دهد
#     bash deploy/db-init.sh --apply         # واقعاً اجرا می‌کند

set -euo pipefail

CONFIG="${CONFIG:-config/config.php}"
DB_NAME="${DB_NAME:-}"
DB_USER="${DB_USER:-}"
APPLY=0
[[ "${1:-}" == "--apply" ]] && APPLY=1

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
info()  { printf '\033[0;36m%s\033[0m\n' "$1"; }

# ترتیب زیر با خواندن خود فایل‌ها بررسی شده است:
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
#
# migration_wallets.sql و migration_p1.sql عمداً در این فهرست نیستند.
FILES=(
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

cd "$(dirname "$0")/.."

for f in "${FILES[@]}"; do
    [[ -f "$f" ]] || { red "فایل پیدا نشد: $f"; exit 1; }
done
green "هر ${#FILES[@]} فایل موجود است."

if [[ $APPLY -eq 0 ]]; then
    printf '\n\033[1;33mحالت نمایشی — ترتیب اجرا:\033[0m\n'
    for i in "${!FILES[@]}"; do printf '  %2d. %s\n' "$((i+1))" "${FILES[$i]}"; done
    printf '\n\033[0;33mبرای اجرای واقعی:  bash deploy/db-init.sh --apply\033[0m\n\n'
    exit 0
fi

# اطلاعات اتصال از config.php خوانده می‌شود — همان چیزی که خود اپ استفاده
# می‌کند. این‌طور امکان تایپ اشتباه رمز از بین می‌رود.
# مسیر به‌صورت آرگومان به php داده می‌شود، نه داخل رشته، تا نقل‌قول‌ها امن بمانند.
read_const() { php -r 'require $argv[1]; echo constant($argv[2]);' "$CONFIG" "$1" 2>/dev/null || true; }

if [[ -r "$CONFIG" ]] && command -v php >/dev/null 2>&1; then
    DB_NAME="${DB_NAME:-$(read_const DB_NAME)}"
    DB_USER="${DB_USER:-$(read_const DB_USER)}"
    DB_PASS="$(read_const DB_PASSWORD)"
    [[ -n "$DB_PASS" ]] && info "اطلاعات اتصال از $CONFIG خوانده شد (دیتابیس: $DB_NAME، کاربر: $DB_USER)."
fi

DB_NAME="${DB_NAME:-hesab_db}"
DB_USER="${DB_USER:-hesab_user}"

if [[ -z "${DB_PASS:-}" ]]; then
    info "رمز از $CONFIG خوانده نشد — دستی بدهید."
    read -r -s -p "رمز کاربر $DB_USER: " DB_PASS; echo
fi

if ! mysql --default-character-set=utf8mb4 -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 1" >/dev/null 2>&1; then
    red "اتصال به دیتابیس برقرار نشد — نام کاربر یا رمز درست نیست."
    red "  کاربر: $DB_USER   دیتابیس: $DB_NAME"
    exit 1
fi
green "اتصال به دیتابیس برقرار است."

for f in "${FILES[@]}"; do
    printf '  → %-32s' "$f"
    if err=$(mysql --default-character-set=utf8mb4 -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$f" 2>&1); then
        green "OK"
    else
        # migration_indexes ایدمپوتنت نیست: اجرای دوباره «ایندکس تکراری» می‌دهد
        if grep -qiE "Duplicate key name|Duplicate column name|already exists" <<<"$err"; then
            info "از قبل اعمال شده — رد شد"
        else
            echo
            red "خطا در $f:"
            red "$err"
            red "متوقف شد. دیتابیس نیمه‌کاره است؛ خطا را برطرف کنید و دوباره اجرا کنید."
            exit 1
        fi
    fi
done

echo
green "✅ ساختار دیتابیس کامل شد."
info "بررسی:"
mysql --default-character-set=utf8mb4 -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW TABLES;" 2>/dev/null | sed 's/^/    /'
