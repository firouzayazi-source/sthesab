<?php
/**
 * ربات بله/تلگرام — توکنِ رباتِ مخصوصِ همین نصب (فقط مدیر). منطق همه در `includes/bot.php` (`Bot`).
 *
 * ⛔ توکن هرگز به صفحه برنمی‌گردد — فقط «ذخیره شده»؛ خانه‌ی خالی یعنی «دست نزن».
 * ⛔ رباتِ وب‌هوک‌دار (یعنی رباتِ برنامه‌ی دیگری) رد می‌شود — `Bot::saveConfig()`.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bot.php';

Auth::initSession();
Auth::requireAdmin();
$self = APP_BASE_PATH . '/admin/bot.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Bot::ready()) {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $r = Bot::saveConfig((string)postParam('provider'), (string)postParam('token'));
    redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
}

$pageTitle = 'ربات بله و تلگرام';
include __DIR__ . '/../includes/header.php';
?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if (!Bot::ready()): ?>
<div class="card"><p class="hint">جدولِ ربات هنوز ساخته نشده. روی سرور به‌روزرسانی را با migration اجرا کنید.</p></div>
<?php else: $prov = Bot::provider(); ?>
<div class="card">
    <h2 class="card-title">ربات بله یا تلگرام</h2>
    <p class="hint">
        خلاصه‌ی شبانه‌ی فروشگاه و هشدارها از راهِ همین ربات به صاحبِ فروشگاه می‌رسد (کنارِ ایمیل). برای <?= h(APP_NAME) ?>
        <b>یک رباتِ تازه</b> بسازید (در بله: «بله‌فادر»، در تلگرام: BotFather) و توکنش را این‌جا بگذارید.
        ⛔ توکنِ رباتی را که برنامه‌ی دیگری دارد این‌جا نگذارید: پیام‌هایش را می‌خواند. رباتِ وب‌هوک‌دار پذیرفته نمی‌شود.
    </p>
    <?php if (Bot::configured()): ?><p>وضعیت: روشن · <?= h(Bot::PROVIDERS[$prov]['label']) ?> · <span dir="ltr">@<?= h(Bot::username()) ?></span></p><?php endif; ?>
    <form method="post" action="<?= h($self) ?>">
        <?= Csrf::field() ?>
        <label class="field"><span>پیام‌رسان</span>
            <select name="provider"><option value="">خاموش</option>
                <?php foreach (Bot::PROVIDERS as $k => $p): ?><option value="<?= h($k) ?>"<?= $k === $prov ? ' selected' : '' ?>><?= h($p['label']) ?></option><?php endforeach; ?>
            </select></label>
        <label class="field"><span>توکنِ ربات <?= Bot::configured() ? '<small>(ذخیره شده — خالی بگذارید تا عوض نشود)</small>' : '' ?></span>
            <input type="password" name="token" dir="ltr" autocomplete="off" placeholder="123456:ABC…"></label>
        <button type="submit" class="btn btn-primary">ذخیره و آزمودنِ توکن</button>
    </form>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
