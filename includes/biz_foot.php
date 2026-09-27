<?php
/**
 * پوسته‌ی محیطِ فروشگاهی — پایینِ صفحه و منوی پایینِ موبایل.
 * همان فهرستِ `Biz::NAV`ِ سرآیند؛ فهرستِ دوم ساخته نمی‌شود.
 */
if (!defined('APP_BASE_PATH')) { http_response_code(404); exit; }
// ⚠ خودش حساب می‌کند، نه از `biz_head.php` قرض بگیرد (test_dead_code گرفت)
$__bizFootPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
?>
</main>
<nav class="st-tabbar" aria-label="منوی فروشگاه">
    <?php foreach (Biz::NAV as $__file => $__label):
        if (!is_file(__DIR__ . '/../' . Biz::DIR . '/' . $__file)) { continue; } ?>
        <a href="<?= h(Biz::url($__file)) ?>"
           class="st-tab<?= $__bizFootPage === $__file ? ' is-active' : '' ?>"><?= h($__label) ?></a>
    <?php endforeach; ?>
</nav>
</body>
</html>
