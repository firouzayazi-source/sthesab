#!/usr/bin/env bash
#
# setup-deploy-key.sh — اتصال دائمی سرور به مخزن خصوصی، بدون پرسیدن رمز
#
# چرا Deploy Key و نه توکن؟
#   - فقط به همین یک مخزن دسترسی دارد، نه به کل حساب گیت‌هاب شما
#   - فقط‌خواندنی است؛ حتی اگر سرور لو برود، کسی نمی‌تواند روی مخزن بنویسد
#   - منقضی نمی‌شود (توکن هر چند ماه باید تمدید شود)
#
# ─── رعایت قانون جداسازی ───────────────────────────────────────────
# این اسکریپت به ~/.ssh/config و ~/.ssh/known_hosts مشترک دست نمی‌زند،
# چون سرویس‌های دیگر سرور هم از آن‌ها استفاده می‌کنند. به‌جایش:
#
#   کلید       → /root/.ssh/hesab_deploy
#   known_hosts → /root/.ssh/hesab_known_hosts
#   تنظیمات    → داخل .git/config خودِ مخزن (core.sshCommand)
#
# یعنی هیچ فایل مشترکی تغییر نمی‌کند و اگر روزی این پروژه را حذف کنید،
# اثری روی گیت بقیه‌ی سرویس‌ها نمی‌ماند.
# ───────────────────────────────────────────────────────────────────
#
# اجرا:
#     bash deploy/setup-deploy-key.sh          # ساخت کلید و نمایش آن
#     bash deploy/setup-deploy-key.sh --clone  # بعد از ثبت کلید در گیت‌هاب

set -euo pipefail

REPO="${REPO:-git@github.com:firouzayazi-source/sthesab.git}"
APP_DIR="${APP_DIR:-/opt/hesab/app}"
KEY="${KEY:-/root/.ssh/hesab_deploy}"
KNOWN="${KNOWN:-/root/.ssh/hesab_known_hosts}"

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
info()  { printf '\033[0;36m%s\033[0m\n' "$1"; }
step()  { printf '\n\033[1m── %s\033[0m\n' "$1"; }

SSH_CMD="ssh -i $KEY -o IdentitiesOnly=yes -o UserKnownHostsFile=$KNOWN"

# ---------- کلید ----------
step "کلید اختصاصی این پروژه"
mkdir -p "$(dirname "$KEY")" && chmod 700 "$(dirname "$KEY")"
if [[ -f "$KEY" ]]; then
    green "کلید از قبل هست: $KEY"
else
    ssh-keygen -t ed25519 -N '' -C "hesab-deploy@$(hostname)" -f "$KEY" >/dev/null
    green "کلید تازه ساخته شد: $KEY"
fi
chmod 600 "$KEY"

# ---------- کلید میزبان گیت‌هاب ----------
step "کلید میزبان گیت‌هاب"
if [[ -s "$KNOWN" ]]; then
    green "از قبل ثبت شده: $KNOWN"
else
    ssh-keyscan -t rsa,ecdsa,ed25519 github.com > "$KNOWN" 2>/dev/null
    green "ثبت شد: $KNOWN"
fi
info "اثر انگشت‌ها را با فهرست رسمی گیت‌هاب مقایسه کنید:"
info "  https://docs.github.com/authentication/keeping-your-account-secure/githubs-ssh-key-fingerprints"
ssh-keygen -lf "$KNOWN" | sed 's/^/    /'

# ---------- کلون ----------
if [[ "${1:-}" == "--clone" ]]; then
    step "آزمایش اتصال"
    if out=$($SSH_CMD -T git@github.com 2>&1); then :; fi
    if grep -q "successfully authenticated" <<<"$out"; then
        green "$out"
    else
        red "گیت‌هاب کلید را نپذیرفت:"
        red "  $out"
        red "مطمئن شوید کلید عمومی را در Deploy keys همین مخزن ثبت کرده‌اید."
        exit 1
    fi

    step "دریافت کد"
    if [[ -d "$APP_DIR/.git" ]]; then
        info "مخزن از قبل هست — فقط تنظیمات به‌روز می‌شود."
    else
        mkdir -p "$(dirname "$APP_DIR")"
        GIT_SSH_COMMAND="$SSH_CMD" git clone "$REPO" "$APP_DIR"
        green "کلون شد: $APP_DIR"
    fi

    # این تنظیم داخل .git/config خودِ مخزن می‌نشیند — هیچ فایل مشترکی را
    # تغییر نمی‌دهد و از این به بعد git pull بدون هیچ پرسشی کار می‌کند.
    git -C "$APP_DIR" config core.sshCommand "$SSH_CMD"
    git -C "$APP_DIR" remote set-url origin "$REPO"
    green "از این پس در $APP_DIR فقط «git pull» کافی است."

    step "بررسی"
    git -C "$APP_DIR" fetch --quiet origin && green "اتصال به مخزن سالم است."
    git -C "$APP_DIR" log -1 --oneline | sed 's/^/    /'
    exit 0
fi

# ---------- راهنمای مرحله‌ی بعد ----------
step "کلید عمومی — این را در گیت‌هاب ثبت کنید"
echo
cat "$KEY.pub"
echo
info "۱. به این آدرس بروید:"
info "   https://github.com/firouzayazi-source/sthesab/settings/keys"
info "۲. دکمه‌ی «Add deploy key»"
info "۳. Title: hesab-vps      Key: خط بالا را کامل کپی کنید"
info "۴. تیک «Allow write access» را نزنید — این کلید باید فقط‌خواندنی بماند."
echo
info "بعد از ثبت، این را اجرا کنید:"
echo "     bash $0 --clone"
echo
