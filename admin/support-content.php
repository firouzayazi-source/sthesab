<?php
/**
 * ⛔ متنِ راهنما و پاسخ‌های آماده — ویرایشگرِ مدیر.
 *
 * **خواسته‌ی مالکِ نصب:** «سیستم مدیریت پاسخ‌های آماده اضافه کن. ادمین
 * بتواند پاسخ‌های آماده را ایجاد / ویرایش / حذف کند.» و برای راهنما هم
 * همان: متنِ اولیه با migration می‌آید ولی باید قابلِ عوض کردن باشد.
 *
 * ⛔ **صفحه‌ی جدا از `admin/support.php`، عمداً.** آنجا میزِ کارِ روزمره
 *    است و روزی ده بار باز می‌شود؛ این یکی ماهی یک بار. با یکی کردنشان،
 *    فهرستِ تیکت لای فرم‌های محتوا گم می‌شد — همان «خزشِ بی‌صدا»ی
 *    `admin/insights.php` که خطاها را به صفحه‌ی خودشان برد.
 *
 * ⛔ **در نوارِ مدیر زبانه‌ی خودش را ندارد.** هفت زبانه از قبل هست و
 *    زبانه‌ی هشتم روی موبایل نوار را می‌شکند؛ راهش یک لینک پایینِ
 *    `admin/support.php` است — جایی که آدم وقتی به آن نیاز دارد
 *    ایستاده. (`admin/_nav.php` صفحه‌ای را که در فهرستش نیست رندر
 *    نمی‌کند، پس اینجا هیچ زبانه‌ای `is-active` نمی‌شود و این درست است.)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/support.php';

Auth::initSession();
Auth::requireAdmin();

$ready = Support::available();
$tab   = (string)getParam('tab', 'articles');
if (!in_array($tab, ['articles', 'canned'], true)) { $tab = 'articles'; }
$editId = (int)getParam('e', '0');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');
    $id     = (int)postParam('id', '0');
    $flash  = 'err';

    if ($action === 'save_article') {
        $flash = Support::saveArticle($id, (string)postParam('category'), (string)postParam('title'),
            (string)postParam('body'), (int)postParam('sort_order', '100'),
            postParam('is_active') !== null) ? 'saved' : 'err';
        $tab = 'articles';
    } elseif ($action === 'delete_article') {
        $flash = Support::deleteArticle($id) ? 'deleted' : 'err';
        $tab = 'articles';
    } elseif ($action === 'save_canned') {
        $flash = Support::saveCanned($id, (string)postParam('title'), (string)postParam('body'),
            (int)postParam('sort_order', '100'), postParam('is_active') !== null) ? 'saved' : 'err';
        $tab = 'canned';
    } elseif ($action === 'delete_canned') {
        $flash = Support::deleteCanned($id) ? 'deleted' : 'err';
        $tab = 'canned';
    }

    header('Location: ' . APP_BASE_PATH . '/admin/support-content.php?tab=' . urlencode($tab)
        . '&done=' . urlencode($flash));
    exit;
}

$done = (string)getParam('done', '');
$msg  = '';
if ($done === 'saved')        { $msg = 'ذخیره شد.'; }
elseif ($done === 'deleted')  { $msg = 'حذف شد.'; }
elseif ($done === 'err')      { $msg = 'انجام نشد — عنوان و متن را خالی نگذارید.'; }

$articles = $ready ? Support::allArticles() : [];
$canned   = $ready ? Support::cannedList(false) : [];

$editArticle = null;
$editCanned  = null;
if ($editId > 0) {
    if ($tab === 'articles') {
        foreach ($articles as $a) { if ((int)$a['id'] === $editId) { $editArticle = $a; break; } }
    } else {
        foreach ($canned as $c) { if ((int)$c['id'] === $editId) { $editCanned = $c; break; } }
    }
}

$pageWide  = true;
$pageTitle = 'راهنما و پاسخ‌های آماده';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/_nav.php';
?>

<a href="<?= APP_BASE_PATH ?>/admin/support.php" class="page-back">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M11 6l-6 6 6 6"/></svg>
    <span>تیکت‌ها</span>
</a>

<?php if ($msg !== ''): ?>
    <div class="sup-flash <?= $done === 'err' ? 'sup-flash-err' : 'sup-flash-ok' ?>"><?= h($msg) ?></div>
<?php endif; ?>

<?php if (!$ready): ?>
    <div class="card sup-empty">
        <h2>جدول‌های پشتیبانی ساخته نشده‌اند</h2>
        <p>یک بار <span class="ltr-num">bash deploy/migrate.sh --apply</span> را روی سرور اجرا کنید.</p>
    </div>
    <?php include __DIR__ . '/../includes/footer.php'; exit; ?>
<?php endif; ?>

<nav class="page-tabs" aria-label="بخش‌ها">
    <a href="<?= APP_BASE_PATH ?>/admin/support-content.php?tab=articles"
       class="page-tab <?= $tab === 'articles' ? 'is-active' : '' ?>">مقاله‌های راهنما</a>
    <a href="<?= APP_BASE_PATH ?>/admin/support-content.php?tab=canned"
       class="page-tab <?= $tab === 'canned' ? 'is-active' : '' ?>">پاسخ‌های آماده</a>
</nav>

<?php if ($tab === 'articles'): ?>
    <div class="card">
        <h2 class="sup-cat-title"><?= $editArticle ? 'ویرایش مقاله' : 'مقاله‌ی تازه' ?></h2>
        <form method="post" action="<?= APP_BASE_PATH ?>/admin/support-content.php?tab=articles">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_article">
            <input type="hidden" name="id" value="<?= (int)($editArticle['id'] ?? 0) ?>">
            <div class="form-group">
                <label for="artCat">دسته‌بندی</label>
                <select id="artCat" name="category">
                    <?php foreach (Support::CATEGORIES as $k => $label): ?>
                        <option value="<?= h($k) ?>" <?= ($editArticle['category'] ?? '') === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="artTitle">عنوان</label>
                <input type="text" id="artTitle" name="title" maxlength="200" required
                       value="<?= h((string)($editArticle['title'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="artBody">متن</label>
                <textarea id="artBody" name="body" rows="8" required><?= h((string)($editArticle['body'] ?? '')) ?></textarea>
                <p class="hint">متنِ ساده؛ خطِ خالی پاراگراف می‌سازد. HTML اجرا نمی‌شود.</p>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="artSort">ترتیب</label>
                    <input type="number" id="artSort" name="sort_order" class="ltr-num"
                           value="<?= (int)($editArticle['sort_order'] ?? 100) ?>">
                </div>
                <div class="form-group">
                    <label class="switch">
                        <input type="checkbox" name="is_active" <?= ($editArticle === null || (int)$editArticle['is_active'] === 1) ? 'checked' : '' ?>>
                        <span class="switch-track"><span class="switch-knob"></span></span>
                        <span class="switch-text">نمایش داده شود</span>
                    </label>
                </div>
            </div>
            <div class="modal-actions">
                <?php if ($editArticle): ?>
                    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/admin/support-content.php?tab=articles">انصراف</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>عنوان</th><th>دسته</th><th>ترتیب</th><th>وضعیت</th><th class="actions-cell">عملیات</th></tr></thead>
            <tbody>
            <?php foreach ($articles as $a): ?>
                <tr>
                    <td><?= h($a['title']) ?></td>
                    <td><?= h(Support::categoryLabel($a['category'])) ?></td>
                    <td class="ltr-num"><?= h(toPersianDigits((string)$a['sort_order'])) ?></td>
                    <td><?= (int)$a['is_active'] === 1 ? 'نمایش' : 'پنهان' ?></td>
                    <td class="actions-cell">
                        <div class="table-actions">
                            <a class="btn btn-secondary btn-sm"
                               href="<?= APP_BASE_PATH ?>/admin/support-content.php?tab=articles&amp;e=<?= (int)$a['id'] ?>">ویرایش</a>
                            <form method="post" action="<?= APP_BASE_PATH ?>/admin/support-content.php?tab=articles"
                                  onsubmit="return confirm('این مقاله حذف شود؟');">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="delete_article">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button type="submit" class="delete-btn">حذف</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$articles): ?>
                <tr><td colspan="5">هنوز مقاله‌ای نیست.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php else: ?>
    <div class="card">
        <h2 class="sup-cat-title"><?= $editCanned ? 'ویرایش پاسخ آماده' : 'پاسخ آماده‌ی تازه' ?></h2>
        <form method="post" action="<?= APP_BASE_PATH ?>/admin/support-content.php?tab=canned">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_canned">
            <input type="hidden" name="id" value="<?= (int)($editCanned['id'] ?? 0) ?>">
            <div class="form-group">
                <label for="cnTitle">عنوان (فقط برای خودتان)</label>
                <input type="text" id="cnTitle" name="title" maxlength="120" required
                       value="<?= h((string)($editCanned['title'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="cnBody">متن پاسخ</label>
                <textarea id="cnBody" name="body" rows="6" required><?= h((string)($editCanned['body'] ?? '')) ?></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="cnSort">ترتیب</label>
                    <input type="number" id="cnSort" name="sort_order" class="ltr-num"
                           value="<?= (int)($editCanned['sort_order'] ?? 100) ?>">
                </div>
                <div class="form-group">
                    <label class="switch">
                        <input type="checkbox" name="is_active" <?= ($editCanned === null || (int)$editCanned['is_active'] === 1) ? 'checked' : '' ?>>
                        <span class="switch-track"><span class="switch-knob"></span></span>
                        <span class="switch-text">در منوی پاسخ بیاید</span>
                    </label>
                </div>
            </div>
            <div class="modal-actions">
                <?php if ($editCanned): ?>
                    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/admin/support-content.php?tab=canned">انصراف</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>عنوان</th><th>ترتیب</th><th>وضعیت</th><th class="actions-cell">عملیات</th></tr></thead>
            <tbody>
            <?php foreach ($canned as $c): ?>
                <tr>
                    <td><?= h($c['title']) ?></td>
                    <td class="ltr-num"><?= h(toPersianDigits((string)$c['sort_order'])) ?></td>
                    <td><?= (int)$c['is_active'] === 1 ? 'فعال' : 'خاموش' ?></td>
                    <td class="actions-cell">
                        <div class="table-actions">
                            <a class="btn btn-secondary btn-sm"
                               href="<?= APP_BASE_PATH ?>/admin/support-content.php?tab=canned&amp;e=<?= (int)$c['id'] ?>">ویرایش</a>
                            <form method="post" action="<?= APP_BASE_PATH ?>/admin/support-content.php?tab=canned"
                                  onsubmit="return confirm('این پاسخ آماده حذف شود؟');">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="delete_canned">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button type="submit" class="delete-btn">حذف</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$canned): ?>
                <tr><td colspan="4">هنوز پاسخ آماده‌ای نیست.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
