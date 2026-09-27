<?php
/**
 * پوسته‌ی محیطِ فروشگاهی — پایینِ صفحه و منوی پایینِ موبایل.
 * کلیدها از `Biz::TABBAR` و برچسب‌ها از `Biz::NAV` (فهرستِ دومِ برچسب نیست)؛
 * بقیه‌ی قلم‌ها در کشوی «منو» — همان نوارِ کناریِ سرآیند.
 */
if (!defined('APP_BASE_PATH')) { http_response_code(404); exit; }
// ⚠ خودش حساب می‌کند، نه از `biz_head.php` قرض بگیرد (test_dead_code گرفت)
$__bizFootPage = Biz::navCurrent(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
$__bizFootNav  = Biz::navFlat();
?>
</main>
</div>
<nav class="st-tabbar" aria-label="منوی پایین">
    <?php foreach (Biz::TABBAR as $__file):
        if (!isset($__bizFootNav[$__file]) || !is_file(__DIR__ . '/../' . Biz::DIR . '/' . $__file)) { continue; } ?>
        <a href="<?= h(Biz::url($__file)) ?>"
           class="st-tab<?= $__bizFootPage === $__file ? ' is-active' : '' ?>"><?= h($__bizFootNav[$__file]) ?></a>
    <?php endforeach; ?>
    <label for="stNavToggle" class="st-tab<?= in_array($__bizFootPage, Biz::TABBAR, true) ? '' : ' is-active' ?>" role="button">منو</label>
</nav>
</body>
</html>
