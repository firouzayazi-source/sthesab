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
RAN=0

bold() { printf '\n\033[1m═══ %s\033[0m\n' "$1"; }

for t in tests/test_*.php; do
    name=$(basename "$t" .php)
    [[ -n "$FILTER" && "$name" != *"$FILTER"* ]] && continue
    bold "$name"
    RAN=$((RAN+1))
    if ! php "$t"; then
        FAILED+=("$name")
    fi
done

if (( RAN == 0 )); then
    printf '\n\033[0;33mهیچ تستی با فیلتر «%s» پیدا نشد.\033[0m\n\n' "$FILTER"
    exit 1
fi

printf '\n\033[1m%s\033[0m\n' "$(printf '═%.0s' {1..62})"
if (( ${#FAILED[@]} == 0 )); then
    printf '\033[0;32m✅ هر %d مجموعه تست موفق بود.\033[0m\n\n' "$RAN"
    exit 0
fi
printf '\033[0;31m❌ %d مجموعه از %d شکست خورد:\033[0m\n' "${#FAILED[@]}" "$RAN"
printf '   • %s\n' "${FAILED[@]}"
echo
exit 1
