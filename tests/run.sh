#!/usr/bin/env bash
#
# run.sh — اجرای همه‌ی تست‌ها
#
# بدون Composer و بدون هیچ پکیج خارجی. تنها نیاز: php، و برای تست
# هم‌خوانی تقویم، node. اگر node نباشد آن یک تست رد می‌شود نه شکست.
#
#     bash tests/run.sh          همه‌ی تست‌ها
#     bash tests/run.sh jalali   فقط تست‌هایی که نامشان jalali دارد
#
# کد خروج ۰ یعنی همه موفق. هر چیز دیگری یعنی شکست — برای CI مناسب است.

set -uo pipefail
cd "$(dirname "$0")/.."

FILTER="${1:-}"
FAILED=()
BLOCKED=()
RAN=0

bold() { printf '\n\033[1m═══ %s\033[0m\n' "$1"; }

for t in tests/test_*.php; do
    name=$(basename "$t" .php)
    [[ -n "$FILTER" && "$name" != *"$FILTER"* ]] && continue
    bold "$name"
    RAN=$((RAN+1))
    php "$t"
    rc=$?
    # ⛔ کد ۲ یعنی «اجرا نشد» نه «شکست خورد» — دو چیزِ کاملاً متفاوت با دو
    #    راهِ حلِ متفاوت، و تا امروز هر دو **سبز** دیده می‌شدند (پایین‌تر).
    case "$rc" in
        0) ;;
        2) BLOCKED+=("$name") ;;
        *) FAILED+=("$name") ;;
    esac
done

if (( RAN == 0 )); then
    printf '\n\033[0;33mهیچ تستی با فیلتر «%s» پیدا نشد.\033[0m\n\n' "$FILTER"
    exit 1
fi

printf '\n\033[1m%s\033[0m\n' "$(printf '═%.0s' {1..62})"

if (( ${#FAILED[@]} > 0 )); then
    printf '\033[0;31m❌ %d مجموعه از %d شکست خورد:\033[0m\n' "${#FAILED[@]}" "$RAN"
    printf '   • %s\n' "${FAILED[@]}"
    echo
    exit 1
fi

# ⛔ «اجرا نشد» هم یک شکست است. یک بار MariaDB خوابیده بود و همین اسکریپت
#    «✅ هر ۴۲ مجموعه تست موفق بود» چاپ کرد، در حالی که حدود ۲۵ مجموعه
#    اصلاً به دیتابیس نرسیده بودند. اگر اینجا صفر برگردد، همان push ای
#    که قرار بود این اسکریپت جلویش را بگیرد بی‌مانع انجام می‌شود.
if (( ${#BLOCKED[@]} > 0 )); then
    printf '\033[0;31m⛔ %d مجموعه از %d اصلاً اجرا نشد (زیرساخت لازم نبود):\033[0m\n' \
        "${#BLOCKED[@]}" "$RAN"
    printf '   • %s\n' "${BLOCKED[@]}"
    printf '\n   \033[0;33mعلتِ رایج: سرویس دیتابیس بالا نیست، یا config/config.php ساخته نشده.\033[0m\n'
    printf '   \033[0;90mاین شکست نیست ولی تأیید هم نیست — هیچ‌کدام از این مجموعه‌ها چیزی را نسنجیدند.\033[0m\n\n'
    exit 1
fi

printf '\033[0;32m✅ هر %d مجموعه تست موفق بود.\033[0m\n\n' "$RAN"
exit 0
