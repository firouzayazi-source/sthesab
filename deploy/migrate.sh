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
#   bash deploy/migrate.sh --baseline   موجودها را «اجراشده» علامت می‌زند
#   bash deploy/migrate.sh --verify     ثبت را با ساختار واقعی می‌سنجد
#
# --baseline برای دیتابیسی است که از قبل کامل است ولی جدول ردیابی ندارد
# (مثل دیتابیسی که از هاست اشتراکی ایمپورت شده). یک بار اجرا می‌شود.
#
# ⚠️ درسی که گران تمام شد: نسخه‌ی اول --baseline کل BASELINE_SET را
# «اجراشده» علامت می‌زد، بدون اینکه بپرسد واقعاً اعمال شده یا نه. آن
# دیتابیسِ ایمپورت‌شده migration_p4 (ستون users.avatar) را نداشت، ولی
# ثبت شد که دارد. نتیجه: --apply می‌گفت «چیزی برای اجرا نیست» و آپلود
# تصویر در اپ خطا می‌داد، و هیچ‌کدام به هم ربط داده نمی‌شدند.
#
# پس حالا هر migration یک «شاهد» دارد (جدول یا ستونی که می‌سازد).
# --baseline فقط چیزی را علامت می‌زند که شاهدش واقعاً در دیتابیس باشد،
# و --verify همیشه می‌تواند ثبت را با واقعیت بسنجد.
# ────────────────────────────────────────────────────────────────────

set -euo pipefail

CONFIG="${CONFIG:-config/config.php}"
DB_NAME="${DB_NAME:-}"
DB_USER="${DB_USER:-}"
MODE="status"

case "${1:-}" in
    --apply)    MODE="apply" ;;
    --baseline) MODE="baseline" ;;
    --verify)   MODE="verify" ;;
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
#   migration_p2 ............... attachments
#   migration_p3 ............... trusted_devices, users.session_hours
#   migration_p4 ............... users.avatar
#   migration_password_reset ... users.email + جدول password_resets
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
    migration_password_reset.sql
    migration_trades.sql
    migration_trades2.sql
    migration_wallet_cards.sql
    migration_money_links.sql
    migration_wallet_kinds.sql
    migration_user_categories.sql
    migration_login_throttle.sql
    migration_people.sql
    migration_api_tokens.sql
    migration_session_window.sql
    migration_household_categories.sql
    migration_reminders.sql
    migration_cheque_status.sql
    migration_asset_prices.sql
    migration_installments.sql
    migration_wallet_encrypt.sql
    migration_plans.sql
    migration_sms_login.sql
    migration_more_categories.sql
    migration_notifications.sql
    migration_schedule.sql
    migration_reminder_plan.sql
    migration_goal_wallet.sql
    migration_onboarding.sql
    migration_wallet_pin.sql
    migration_access_revoke.sql
    migration_discount_codes.sql
    migration_phone_signup.sql
    migration_indexes2.sql
    migration_indexes3.sql
    migration_seed_flag.sql
    migration_app_errors.sql
    migration_category_pin.sql
)

# migration هایی که پیش از راه‌اندازی ردیابی وجود داشتند.
#
# --baseline فقط همین‌ها را «اجراشده» علامت می‌زند. دلیلش یک باگ واقعی
# است که در تست پیدا شد: اگر baseline کل فهرست را علامت بزند، هر
# migration ای که بعد از ساخت دیتابیس اضافه شده باشد هم «اجراشده» ثبت
# می‌شود بدون اینکه اجرا شده باشد — جدولش ساخته نمی‌شود و اپ بی‌سروصدا
# می‌شکند.
#
# معنی baseline این است: «این دیتابیس از دورانی است که ردیابی نبود».
# آنچه از آن دوران است، دقیقاً همین فهرست ثابت است. به این آرایه چیزی
# اضافه نکنید — هر migration تازه باید واقعاً اجرا شود.
BASELINE_SET=(
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

# ---------- بررسی همخوانی فهرست با فایل‌های روی دیسک ----------
# اگر کسی فایل migration تازه‌ای اضافه کند و اینجا ثبتش نکند، باید سروصدا
# کند — نه اینکه بی‌سروصدا نادیده گرفته شود.
known=" ${MIGRATIONS[*]} "
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

# ---------- شاهدِ هر migration ----------
# «اگر این migration واقعاً اجرا شده باشد، این چیز باید در دیتابیس باشد.»
# قالب:  جدول            یا  جدول.ستون
#
# با این می‌شود ثبت را با ساختار واقعی سنجید. بدون آن، جدول ردیابی فقط
# حرف خودش را تکرار می‌کند و اگر یک بار اشتباه ثبت شود، تا ابد اشتباه
# می‌ماند — دقیقاً همان چیزی که با users.avatar پیش آمد.
declare -A SENTINEL=(
    [schema.sql]="users"
    [migration_debts.sql]="debts"
    [migration_settings.sql]="app_settings"
    [migration_cheques_assets.sql]="cheques"
    [migration_indexes.sql]=""            # ایندکس است نه جدول/ستون — شاهد ساده ندارد
    [migration_category_icons.sql]="categories.icon"
    [migration_repair.sql]="wallets"
    [migration_p2.sql]="attachments"
    [migration_p3.sql]="trusted_devices"
    [migration_p4.sql]="users.avatar"
    [migration_password_reset.sql]="password_resets"
    [migration_trades.sql]="trades"
    [migration_trades2.sql]="trade_sales.profit_tx_id"
    [migration_wallet_cards.sql]="wallets.card_number"
    [migration_money_links.sql]="cheques.settle_wallet_id"
    [migration_wallet_kinds.sql]="wallet_kinds"
    [migration_user_categories.sql]="categories.user_id"
    [migration_login_throttle.sql]="login_attempts"
    [migration_people.sql]="people"
    [migration_api_tokens.sql]="api_tokens"
    [migration_session_window.sql]="users.session_minutes"
    # فقط داده را عوض می‌کند (دسته‌های پیش‌فرض) و هیچ جدول یا ستونی
    # نمی‌سازد، پس مثل migration_indexes.sql شاهدِ ساختاری ندارد.
    # ایدمپوتنت است و اجرای دوباره‌اش بی‌خطر.
    [migration_household_categories.sql]=""
    [migration_reminders.sql]="notification_prefs"
    [migration_cheque_status.sql]="cheques.status"
    [migration_asset_prices.sql]="asset_types.current_price"
    [migration_installments.sql]="debts.installment_count"
    # این ستون را نمی‌سازد بلکه **پهن‌تر** می‌کند تا مقدارِ رمزشده جا شود.
    # شاهدش هم باید طول باشد نه وجود، وگرنه همیشه «هست» می‌گفت.
    [migration_wallet_encrypt.sql]="wallets.card_number>=255"
    [migration_notifications.sql]="notifications"
    [migration_schedule.sql]="reminder_occurrences"
    [migration_reminder_plan.sql]="reminders.total_count"
    [migration_goal_wallet.sql]="savings_goals.wallet_id"
    [migration_onboarding.sql]="users.balance_setup_at"
    [migration_wallet_pin.sql]="wallets.pinned"
    [migration_access_revoke.sql]="users.access_revoked_at"
    # ⚠ شاهد **ستونِ payments** است نه خودِ جدولِ discount_codes: آن
    #   ALTER آخرین کارِ فایل است، پس وجودش یعنی کلِ فایل اجرا شده.
    [migration_discount_codes.sql]="payments.discount_code"
    [migration_app_errors.sql]="app_errors"
    [migration_plans.sql]="payments"
    [migration_sms_login.sql]="sms_codes"
    # ⛔ شاهدش «ستون هست» نیست بلکه «ستون NULL می‌پذیرد» است. این
    #    migration ستونی نمی‌سازد، فقط اجازه‌ی NULL می‌دهد — پس شاهدِ
    #    وجود همیشه «هست» می‌گفت و --verify دقیقاً همان دروغی را
    #    می‌گفت که برای گرفتنش ساخته شده. همان درسِ
    #    migration_wallet_encrypt، این بار روی nullable بودن.
    [migration_phone_signup.sql]="users.password_hash:null"
    # شاهدش داده است نه ساختار — توضیحش در sentinel_present.
    [migration_more_categories.sql]="app_settings~setting_key=more_categories_seeded"
    # ⛔ شاهدش خودِ **ایندکس** است، نه خالی. `migration_indexes.sql`ِ قدیمی
    #    شاهد ندارد و --verify هرگز نسنجیدش؛ برای این یکی آن سوراخ بسته
    #    شد (شکلِ ششم در sentinel_present).
    [migration_indexes2.sql]="notifications:idx_notif_unread"
    # ⚠ شاهد ایندکسِ **آخر** است نه اولی: آن `ALTER` آخرین کارِ فایل
    #   است، پس وجودش یعنی کلِ فایل اجرا شده — همان استدلالِ
    #   `payments.discount_code`. اگر روزی بلوکِ چهارمی اضافه شد، این
    #   شاهد هم باید با آن جلو برود.
    [migration_indexes3.sql]="transactions:idx_user_wallet_sum"
    [migration_seed_flag.sql]="users.defaults_seeded_at"
    [migration_category_pin.sql]="category_pins"
)

# آیا شاهد یک migration در دیتابیس هست؟
#   0 = هست    1 = نیست    2 = شاهدی تعریف نشده (قابل سنجش نیست)
sentinel_present() {
    local spec="${SENTINEL[$1]-}"
    [[ -n "$spec" ]] || return 2

    # ⛔ شکلِ سوم: `جدول.ستون>=طول` — برای migration ای که ستون را
    #    **پهن‌تر** می‌کند، نه اینکه بسازد. بدون این، شاهدِ چنین
    #    migration ای همیشه «هست» می‌شد (ستون که از قبل وجود دارد) و
    #    --verify دقیقاً همان دروغی را می‌گفت که برای گرفتنش ساخته شده:
    #    ثبت‌شده ولی اعمال‌نشده. همان چیزی که یک بار با users.avatar شد.
    if [[ "$spec" == *">="* ]]; then
        local need="${spec##*>=}" path="${spec%%>=*}"
        local t="${path%%.*}" c="${path#*.}" len
        len=$(mysql_q -N -e "SELECT COALESCE(MAX(CHARACTER_MAXIMUM_LENGTH), 0)
                             FROM information_schema.columns
                             WHERE table_schema=DATABASE() AND table_name='$t' AND column_name='$c';")
        [[ -n "$len" && "$len" -ge "$need" ]]
        return
    fi

    # ⛔ شکلِ چهارم: `جدول~ستون=مقدار` — برای migration ای که **داده**
    #    اضافه می‌کند، نه ساختار. شاهدِ ساختاری برایش وجود ندارد.
    #
    #    ⚠ و شاهدش عمداً خودِ آن داده نیست: اگر شاهد «دسته‌ی خودرو هست»
    #      می‌بود، کاربری که آن دسته را حذف می‌کند باعث می‌شد --apply
    #      دوباره اجرایش کند و کارِ خودش را پس بزند. پس migration یک
    #      نشانه‌ی جدا در `app_settings` می‌گذارد و شاهد همان است.
    if [[ "$spec" == *"~"* ]]; then
        local rest="${spec#*~}" t="${spec%%~*}"
        local c="${rest%%=*}" v="${rest#*=}" n
        n=$(mysql_q -N -e "SELECT COUNT(*) FROM \`$t\` WHERE \`$c\` = '$v';" 2>/dev/null)
        [[ -n "$n" && "$n" -ge 1 ]]
        return
    fi

    # ⛔ شکلِ پنجم: `جدول.ستون:null` — برای migration ای که ستون را
    #    **nullable** می‌کند، نه اینکه بسازد. بی این شکل، شاهدش باید
    #    «ستون هست» می‌بود که از قبل هم هست، پس --verify همیشه سبز
    #    می‌گفت — همان دروغِ `users.avatar` و همان دلیلی که شکلِ سوم
    #    (`>=طول`) نوشته شد.
    if [[ "$spec" == *":null" ]]; then
        local path="${spec%:null}"
        local t="${path%%.*}" c="${path#*.}" nullable
        nullable=$(mysql_q -N -e "SELECT COALESCE(MAX(IS_NULLABLE), '')
                                  FROM information_schema.columns
                                  WHERE table_schema=DATABASE() AND table_name='$t' AND column_name='$c';")
        [[ "$nullable" == "YES" ]]
        return
    fi

    # ⛔ شکلِ ششم: `جدول:ایندکس` — برای migration ای که فقط **ایندکس**
    #    می‌سازد. تا امروز چنین فایلی شاهدِ خالی می‌گرفت (`""`) و
    #    --verify اصلاً نمی‌سنجیدش؛ یعنی ثبتِ «اجرا شد» هیچ پشتوانه‌ای
    #    نداشت — همان حالتی که `users.avatar` را ساخت. حالا وجودِ خودِ
    #    ایندکس سنجیده می‌شود.
    #
    # ⚠ دو نقطه است نه نقطه، وگرنه از شکلِ `جدول.ستون` قابل تشخیص نبود.
    if [[ "$spec" == *":"* ]]; then
        local t="${spec%%:*}" ix="${spec#*:}" n
        n=$(mysql_q -N -e "SELECT COUNT(*) FROM information_schema.statistics
                           WHERE table_schema=DATABASE() AND table_name='$t' AND index_name='$ix';")
        [[ -n "$n" && "$n" -ge 1 ]]
        return
    fi

    local tbl="${spec%%.*}" col=""
    [[ "$spec" == *.* ]] && col="${spec#*.}"
    local n
    if [[ -n "$col" ]]; then
        n=$(mysql_q -N -e "SELECT COUNT(*) FROM information_schema.columns
                           WHERE table_schema=DATABASE() AND table_name='$tbl' AND column_name='$col';")
    else
        n=$(mysql_q -N -e "SELECT COUNT(*) FROM information_schema.tables
                           WHERE table_schema=DATABASE() AND table_name='$tbl';")
    fi
    [[ "$n" == "1" ]]
}

# هر شاهدی که در فهرست هست باید برای یک migration واقعی باشد
for k in "${!SENTINEL[@]}"; do
    [[ " ${MIGRATIONS[*]} " == *" $k "* ]] || { red "شاهد برای migration ناشناخته: $k"; exit 1; }
done
for f in "${MIGRATIONS[@]}"; do
    [[ -v SENTINEL["$f"] ]] || { red "برای $f شاهدی تعریف نشده — در SENTINEL اضافه کنید."; exit 1; }
done

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
    # فقط migration های پیش از راه‌اندازی ردیابی — نه هر چه در فهرست است.
    # و از آن مهم‌تر: فقط آن‌هایی که شاهدشان واقعاً در دیتابیس هست.
    info "علامت‌زدن migration های دوران پیش از ردیابی، بدون اجرا:"
    missing=()
    for f in "${BASELINE_SET[@]}"; do
        # زیر set -e، «cmd; st=$?» با خروجی غیرصفر اسکریپت را می‌کشد.
        # «cmd || st=$?» شرط است، پس set -e کاری با آن ندارد.
        st=0; sentinel_present "$f" || st=$?
        if (( st == 1 )); then
            missing+=("$f")
            printf '    \033[0;33m·\033[0m %-34s علامت نخورد — «%s» در دیتابیس نیست\n' \
                   "$f" "${SENTINEL[$f]}"
            continue
        fi
        record "$f" "$(sum_of "$f")"
        if (( st == 2 )); then
            printf '    ✓ %-34s (شاهد ندارد — بر اساس فرضِ baseline)\n' "$f"
        else
            echo "    ✓ $f"
        fi
    done

    # هر چه در MIGRATIONS هست ولی در BASELINE_SET نیست، باید واقعاً اجرا شود
    after=()
    for f in "${MIGRATIONS[@]}"; do
        [[ " ${BASELINE_SET[*]} " == *" $f "* ]] || after+=("$f")
    done
    echo
    green "✅ baseline ثبت شد."
    if (( ${#missing[@]} )); then
        warn "این‌ها علامت نخوردند چون ساختارشان واقعاً در دیتابیس نبود:"
        printf '     %s\n' "${missing[@]}"
        info "با --apply واقعاً اجرا می‌شوند."
    fi
    if (( ${#after[@]} )); then
        warn "این migration ها بعد از دوران baseline اضافه شده‌اند و هنوز اجرا نشده‌اند:"
        printf '     %s\n' "${after[@]}"
        info "برای اجرایشان:  bash deploy/migrate.sh --apply"
    else
        info "از این پس فقط migration های تازه اجرا می‌شوند."
    fi
    exit 0
fi

# ---------- وضعیت / اعمال ----------
# «drift» = ثبت شده که اجرا شده، ولی شاهدش در دیتابیس نیست.
# این حالت بی‌سروصداترین خرابی ممکن است: --apply می‌گوید چیزی برای اجرا
# نیست و اپ همان‌جا که به آن ستون نیاز دارد خطا می‌دهد.
# فایل‌هایی که تغییرشان *عادی* است و نباید هشدار بدهند.
#
# `schema.sql` تنها موردش است: قاعده‌ی خودِ پروژه می‌گوید با هر تغییر
# ساختار، هم یک migration تازه بنویس هم schema.sql را برای نصب‌های تازه
# به‌روز کن. پس چک‌سامش عمداً و مرتب عوض می‌شود.
#
# پیش از این همین باعث یک هشدار دائمی بعد از هر استقرار می‌شد که کاری
# هم برایش نمی‌شد کرد — و هشدارِ همیشگی بدتر از نبودنش است، چون آدم را
# عادت می‌دهد هشدارها را نادیده بگیرد. آن‌وقت همین هشدار روی یک
# `migration_*.sql` واقعی — که *واقعاً* خطرناک است — هم دیده نمی‌شود.
CHECKSUM_EXEMPT=( schema.sql )

is_exempt() {
    local f
    for f in "${CHECKSUM_EXEMPT[@]}"; do [[ "$f" == "$1" ]] && return 0; done
    return 1
}

pending=()
changed=()
drift=()
for f in "${MIGRATIONS[@]}"; do
    if is_applied "$f"; then
        if ! is_exempt "$f"; then
            [[ "$(stored_sum "$f")" == "$(sum_of "$f")" ]] || changed+=("$f")
        fi
        st=0; sentinel_present "$f" || st=$?
        (( st == 1 )) && drift+=("$f")
    else
        pending+=("$f")
    fi
done

echo
info "دیتابیس: $DB_NAME"
echo
for f in "${MIGRATIONS[@]}"; do
    if is_applied "$f"; then
        if [[ " ${drift[*]-} " == *" $f "* ]]; then
            printf '  \033[0;31m✗\033[0m %-34s ثبت شده ولی «%s» در دیتابیس نیست\n' \
                   "$f" "${SENTINEL[$f]}"
        elif [[ " ${changed[*]-} " == *" $f "* ]]; then
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

if (( ${#drift[@]} )); then
    red "⛔ این‌ها «اجراشده» ثبت شده‌اند ولی ساختارشان در دیتابیس نیست:"
    printf '     %s\n' "${drift[@]}"
    red "   یعنی جدول ردیابی با واقعیت نمی‌خواند — معمولاً اثر یک --baseline"
    red "   روی دیتابیسی که واقعاً کامل نبوده."
    if [[ "$MODE" == "apply" ]]; then
        info "   با همین اجرا دوباره اعمال می‌شوند (فایل‌ها ایدمپوتنت‌اند)."
        pending+=("${drift[@]}")
        # ترتیب اصلی حفظ شود؛ pending را بر اساس MIGRATIONS مرتب می‌کنیم
        ordered=()
        for m in "${MIGRATIONS[@]}"; do
            [[ " ${pending[*]} " == *" $m "* ]] && ordered+=("$m")
        done
        pending=("${ordered[@]}")
    else
        info "   برای درست‌کردن:  bash deploy/migrate.sh --apply"
    fi
    echo
fi

if [[ "$MODE" == "verify" ]]; then
    if (( ${#drift[@]} == 0 && ${#pending[@]} == 0 )); then
        green "✅ ثبت و ساختار واقعی دیتابیس با هم می‌خوانند."
        exit 0
    fi
    exit 1
fi

# چک‌سامِ فایل‌های معاف را تازه می‌کنیم تا جدول ردیابی واقعیت را بگوید.
# فقط در حالت --apply، چون حالت‌های دیگر عمداً چیزی نمی‌نویسند.
if [[ "$MODE" == "apply" ]]; then
    for f in "${CHECKSUM_EXEMPT[@]}"; do
        is_applied "$f" && record "$f" "$(sum_of "$f")"
    done
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
