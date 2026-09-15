<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Auth::requireAdmin();

$pdo = Database::getConnection();

/*
 * این پنل فقط **دسته‌های پیش‌فرضِ برنامه** را مدیریت می‌کند
 * (`categories.user_id IS NULL`) — همان‌هایی که همه‌ی کاربران می‌بینند.
 *
 * دسته‌های شخصیِ کاربران عمداً اینجا نمی‌آیند: هر کاربر خودش از صفحه‌ی
 * «فهرست‌های من» می‌سازدشان و پاکشان می‌کند. اگر اینجا هم فهرست می‌شدند،
 * مدیر می‌توانست ناخواسته دسته‌ی شخصیِ کسی را حذف کند و تراکنش‌های او
 * بی‌دسته می‌ماند.
 *
 * روی نصبی که migration_user_categories هنوز اجرا نشده، ستون نیست و
 * شرط خالی می‌ماند — یعنی همان رفتار قبلی.
 */
$defaultsOnly = tableHasColumn('categories', 'user_id') ? ' AND user_id IS NULL' : '';

$error = '';
$reopenModal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');

    if ($action === 'create') {
        $name = postParam('name');
        $type = postParam('type');
        $icon = postParam('icon', 'default');
        $color = postParam('color', '#64748b');
        if (!array_key_exists($icon, categoryIconMap())) { $icon = 'default'; }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) { $color = '#64748b'; }

        if ($name === '') {
            $error = 'نام دسته‌بندی الزامی است.';
        } elseif (mb_strlen($name) > 100) {
            $error = 'نام دسته‌بندی نباید بیشتر از ۱۰۰ کاراکتر باشد.';
        } elseif (!in_array($type, ['income', 'expense'], true)) {
            $error = 'نوع دسته‌بندی نامعتبر است.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO categories (user_id, name, type, icon, color, is_active) VALUES (NULL, :name, :type, :icon, :color, 1)');
                $stmt->execute(['name' => $name, 'type' => $type, 'icon' => $icon, 'color' => $color]);
                redirectWithMessage('categories.php', 'success', 'دسته‌بندی جدید اضافه شد.');
            } catch (PDOException $e) {
                error_log('Create Category Error: ' . $e->getMessage());
                $error = 'خطایی در ثبت دسته‌بندی رخ داد.';
            }
        }

        if ($error !== '') {
            $reopenModal = 'add';
        }
    } elseif ($action === 'update') {
        $targetId = (int)postParam('category_id');
        $name = postParam('name');
        $type = postParam('type');
        $icon = postParam('icon', 'default');
        $color = postParam('color', '#64748b');
        if (!array_key_exists($icon, categoryIconMap())) { $icon = 'default'; }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) { $color = '#64748b'; }

        $targetStmt = $pdo->prepare('SELECT id FROM categories WHERE id = :id' . $defaultsOnly);
        $targetStmt->execute(['id' => $targetId]);

        if (!$targetStmt->fetch()) {
            $error = 'دسته‌بندی مورد نظر یافت نشد.';
        } elseif ($name === '') {
            $error = 'نام دسته‌بندی الزامی است.';
        } elseif (mb_strlen($name) > 100) {
            $error = 'نام دسته‌بندی نباید بیشتر از ۱۰۰ کاراکتر باشد.';
        } elseif (!in_array($type, ['income', 'expense'], true)) {
            $error = 'نوع دسته‌بندی نامعتبر است.';
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE categories SET name = :name, type = :type, icon = :icon, color = :color WHERE id = :id' . $defaultsOnly);
                $stmt->execute(['name' => $name, 'type' => $type, 'icon' => $icon, 'color' => $color, 'id' => $targetId]);
                redirectWithMessage('categories.php', 'success', 'دسته‌بندی بروزرسانی شد.');
            } catch (PDOException $e) {
                error_log('Update Category Error: ' . $e->getMessage());
                $error = 'خطایی در بروزرسانی دسته‌بندی رخ داد.';
            }
        }

        if ($error !== '') {
            $reopenModal = 'edit';
        }
    } elseif ($action === 'toggle_status') {
        $targetId = (int)postParam('category_id');
        $targetStmt = $pdo->prepare('SELECT is_active FROM categories WHERE id = :id' . $defaultsOnly);
        $targetStmt->execute(['id' => $targetId]);
        $cat = $targetStmt->fetch();

        if (!$cat) {
            redirectWithMessage('categories.php', 'error', 'دسته‌بندی مورد نظر یافت نشد.');
        }

        $newStatus = (int)$cat['is_active'] === 1 ? 0 : 1;
        $stmt = $pdo->prepare('UPDATE categories SET is_active = :status WHERE id = :id' . $defaultsOnly);
        $stmt->execute(['status' => $newStatus, 'id' => $targetId]);

        redirectWithMessage('categories.php', 'success', $newStatus === 1 ? 'دسته‌بندی فعال شد.' : 'دسته‌بندی غیرفعال شد.');
    } elseif ($action === 'privatize') {
        // ⛔ «شخصی‌سازی» — دسته از فهرستِ عمومی برداشته می‌شود و برای هر
        //    کاربری که واقعاً از آن استفاده کرده یک نسخه‌ی شخصی می‌ماند.
        //    منطقش در `privatizeDefaultCategory()` است، نه اینجا: همان
        //    قاعده‌ی «تنها یک مرجع» — وگرنه مسیرِ دومی (خط فرمان، یا
        //    صفحه‌ی فردا) نسخه‌ی خودش را می‌ساخت و دیر یا زود از این
        //    دور می‌افتاد.
        $res = privatizeDefaultCategory((int)postParam('category_id'));
        redirectWithMessage('categories.php', $res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($action === 'delete') {
        $targetId = (int)postParam('category_id');

        // ⛔ شمارش روی **همه‌ی** جدول‌های ارجاع‌دهنده است، نه فقط
        //    `transactions` — و این یک باگِ واقعیِ بی‌صدا را می‌بندد:
        //    `budgets.category_id` کلیدِ خارجی با `ON DELETE CASCADE`
        //    دارد، پس حذفِ دسته‌ای که هیچ تراکنشی ندارد ولی روی آن
        //    بودجه بسته شده، **بودجه‌ی کاربران را هم با خودش می‌برد**،
        //    بی‌هیچ خطایی و بی‌آنکه مدیر بفهمد. (`transactions` و
        //    `recurring_transactions` از نوعِ SET NULL اند، یعنی آنجا
        //    ردیف می‌ماند ولی بی‌دسته می‌شود.)
        $usage = categoryUsage($targetId);

        if ($usage['rows'] > 0) {
            $parts = [];
            foreach ($usage['per'] as $t => $n) { $parts[] = $t . ': ' . toPersianDigits($n); }
            redirectWithMessage('categories.php', 'error',
                'روی این دسته‌بندی ' . toPersianDigits($usage['rows']) . ' ردیف ثبت شده و قابل حذف نیست ('
                . implode('، ', $parts) . '). می‌توانید آن را غیرفعال یا شخصی‌سازی کنید.');
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM categories WHERE id = :id' . $defaultsOnly);
            $stmt->execute(['id' => $targetId]);
            redirectWithMessage('categories.php', 'success', 'دسته‌بندی حذف شد.');
        } catch (PDOException $e) {
            error_log('Delete Category Error: ' . $e->getMessage());
            redirectWithMessage('categories.php', 'error', 'خطایی در حذف دسته‌بندی رخ داد.');
        }
    }
}

$categories = $pdo->query('SELECT id, name, type, icon, color, is_active, created_at FROM categories WHERE 1=1' . $defaultsOnly . ' ORDER BY type, name')->fetchAll();

// چهار کوئری برای کلِ فهرست، نه یکی به‌ازای هر ردیف.
$usageMap = $defaultsOnly !== '' ? categoryUsageMap() : [];

// ستونِ «استفاده» و دکمه‌ی شخصی‌سازی جدول را پنج‌ستونه می‌کنند؛ با عرضِ
// خواندنِ ۷۲۰ پیکسل، همان ستونِ عملیات له می‌شد — قاعده ۳۱.
$pageWide  = true;
$pageTitle = 'دسته‌بندی‌ها';
include __DIR__ . '/../includes/header.php';
?>

<?php include __DIR__ . '/_nav.php'; ?>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">دسته‌بندی‌های تراکنش</h2>
        <button type="button" class="btn btn-primary btn-sm" data-modal-open="addCategoryModal">+ دسته‌بندی جدید</button>
    </div>

    <p class="hint" style="margin:-4px 0 14px;">
        این‌ها دسته‌بندی‌های <strong>پیش‌فرضِ برنامه</strong>اند و همه‌ی کاربران می‌بینندشان.
        دسته‌ای که فقط به کارِ یک نفر می‌آید، با <strong>شخصی‌سازی</strong> از این فهرست
        برداشته می‌شود و برای هر کاربری که از آن استفاده کرده به دسته‌بندی شخصیِ خودش
        تبدیل می‌شود — تراکنش‌ها و بودجه‌هایش دست‌نخورده می‌مانند.
        دو دسته‌ی هم‌معنا (مثلاً «حمل و نقل» و «حمل‌ونقل») با <strong>ادغام</strong> یکی
        می‌شوند و همه‌ی ردیف‌هایشان زیرِ یک نام جمع می‌شود.
    </p>

    <?php if ($error && $reopenModal !== 'add' && $reopenModal !== 'edit'): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>نام</th>
                    <th>نوع</th>
                    <th>استفاده</th>
                    <th>وضعیت</th>
                    <th class="actions-cell">عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr><td colspan="5" class="empty-row">هنوز دسته‌بندی‌ای ثبت نشده است.</td></tr>
                <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td data-label="نام">
                                <span style="display:inline-flex; align-items:center; gap:8px;">
                                    <span class="cat-icon cat-icon-sm" style="background: <?= h($cat['color'] ?: '#64748b') ?>22; color: <?= h($cat['color'] ?: '#64748b') ?>;"><?= categoryIconSvg($cat['icon'], 15) ?></span>
                                    <?= h($cat['name']) ?>
                                </span>
                            </td>
                            <td data-label="نوع">
                                <span class="type-tag type-tag-<?= h($cat['type']) ?>"><?= typeLabel($cat['type']) ?></span>
                            </td>
                            <?php $use = $usageMap[(int)$cat['id']] ?? ['users' => 0, 'rows' => 0]; ?>
                            <td data-label="استفاده">
                                <?php if ($use['rows'] === 0): ?>
                                    <span class="hint">هیچ‌کس</span>
                                <?php else: ?>
                                    <span class="hint">
                                        <?= toPersianDigits($use['users']) ?> کاربر ·
                                        <?= toPersianDigits($use['rows']) ?> ردیف
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td data-label="وضعیت">
                                <span class="status-badge <?= (int)$cat['is_active'] === 1 ? 'status-active' : 'status-inactive' ?>">
                                    <?= (int)$cat['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
                                </span>
                            </td>
                            <td data-label="عملیات" class="actions-cell">
                                <div class="table-actions">
                                    <button type="button" class="btn btn-secondary btn-sm js-edit-category"
                                        data-id="<?= (int)$cat['id'] ?>"
                                        data-name="<?= h($cat['name']) ?>"
                                        data-icon="<?= h($cat['icon']) ?>"
                                        data-color="<?= h($cat['color']) ?>"
                                        data-type="<?= h($cat['type']) ?>">ویرایش</button>

                                    <form method="POST" style="display:inline;">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">
                                            <?= (int)$cat['is_active'] === 1 ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?>
                                        </button>
                                    </form>
                                    <?php
                                    /*
                                     * ⛔ اینجا عمداً `confirm()` است و «لغو» نشده:
                                     *    `Undo` یک ردیف و فرزندانِ CASCADE اش را عکس
                                     *    می‌گیرد، ولی شخصی‌سازی چند جدول و چند **کاربر**
                                     *    را با هم عوض می‌کند — همان دلیلی که حذفِ کاربر
                                     *    هم `confirm()` نگه داشت.
                                     *
                                     * و متنِ تأیید **عددِ واقعی** را می‌گوید، نه یک
                                     * «مطمئنید؟» خالی: مدیر باید پیش از زدن بداند به
                                     * چند نفر دست می‌زند.
                                     */
                                    $confirmMsg = $use['rows'] === 0
                                        ? 'این دسته‌بندی از فهرست عمومی برداشته می‌شود. هیچ‌کس از آن استفاده نکرده، پس برای کسی نسخه‌ی شخصی ساخته نمی‌شود. ادامه؟'
                                        : 'این دسته‌بندی از فهرست عمومی برداشته می‌شود و برای ' . toPersianDigits($use['users'])
                                          . ' کاربری که از آن استفاده کرده‌اند به دسته‌بندی شخصیِ خودشان تبدیل می‌شود. تراکنش‌هایشان دست‌نخورده می‌ماند. ادامه؟';
                                    ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?= h($confirmMsg) ?>');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="privatize">
                                        <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">شخصی‌سازی</button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('آیا از حذف این دسته‌بندی مطمئن هستید؟ فقط دسته‌بندی بدون هیچ ردیفی قابل حذف است.');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                                        <button type="submit" class="delete-btn">حذف</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- مودال افزودن دسته‌بندی -->
<div class="modal-overlay <?= $reopenModal === 'add' ? 'show' : '' ?>" id="addCategoryModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>افزودن دسته‌بندی جدید</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <?php if ($error && $reopenModal === 'add'): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="POST" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label>نام دسته‌بندی</label>
                <input type="text" name="name" required value="<?= $reopenModal === 'add' ? h(postParam('name')) : '' ?>">
            </div>
            <div class="form-group">
                <label>نوع</label>
                <select name="type">
                    <option value="income" <?= ($reopenModal === 'add' && postParam('type') === 'income') ? 'selected' : '' ?>>درآمد</option>
                    <option value="expense" <?= ($reopenModal === 'add' && postParam('type') === 'expense') ? 'selected' : '' ?>>هزینه</option>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>آیکن</label>
                    <select name="icon">
                        <?php foreach (categoryIconMap() as $ikey => $idata): ?>
                            <option value="<?= h($ikey) ?>"><?= h($idata['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>رنگ</label>
                    <input type="color" name="color" value="#64748b" style="height:46px; padding:4px;">
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال ویرایش دسته‌بندی -->
<div class="modal-overlay <?= $reopenModal === 'edit' ? 'show' : '' ?>" id="editCategoryModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>ویرایش دسته‌بندی</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <?php if ($error && $reopenModal === 'edit'): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="POST" id="editCategoryForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="category_id" value="<?= $reopenModal === 'edit' ? h(postParam('category_id')) : '' ?>">
            <div class="form-group">
                <label>نام دسته‌بندی</label>
                <input type="text" name="name" required value="<?= $reopenModal === 'edit' ? h(postParam('name')) : '' ?>">
            </div>
            <div class="form-group">
                <label>نوع</label>
                <select name="type">
                    <option value="income" <?= ($reopenModal === 'edit' && postParam('type') === 'income') ? 'selected' : '' ?>>درآمد</option>
                    <option value="expense" <?= ($reopenModal === 'edit' && postParam('type') === 'expense') ? 'selected' : '' ?>>هزینه</option>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>آیکن</label>
                    <select name="icon">
                        <?php foreach (categoryIconMap() as $ikey => $idata): ?>
                            <option value="<?= h($ikey) ?>"><?= h($idata['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>رنگ</label>
                    <input type="color" name="color" value="#64748b" style="height:46px; padding:4px;">
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
