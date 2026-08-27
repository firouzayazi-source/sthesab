<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Auth::requireAdmin();

$pdo = Database::getConnection();
$currentUserId = Auth::userId();

function countOtherActiveAdmins(PDO $pdo, int $excludeUserId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM users WHERE role = "admin" AND is_active = 1 AND id != :id');
    $stmt->execute(['id' => $excludeUserId]);
    return (int)$stmt->fetch()['cnt'];
}

$error = '';
$reopenModal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');

    if ($action === 'create') {
        $fullName = postParam('full_name');
        $username = postParam('username');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        $role = postParam('role', 'user');
        if (!in_array($role, ['admin', 'user'], true)) {
            $role = 'user';
        }

        if ($fullName === '' || $username === '' || $password === '') {
            $error = 'تمام فیلدهای الزامی را پر کنید.';
        } elseif (mb_strlen($fullName) > 100) {
            $error = 'نام و نام خانوادگی نباید بیشتر از ۱۰۰ کاراکتر باشد.';
        } elseif (mb_strlen($username) > 50) {
            $error = 'نام کاربری نباید بیشتر از ۵۰ کاراکتر باشد.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.]+$/', $username)) {
            $error = 'نام کاربری فقط می‌تواند شامل حروف انگلیسی، عدد، نقطه و آندرلاین باشد.';
        } elseif (mb_strlen($password) < 6) {
            $error = 'رمز عبور باید حداقل ۶ کاراکتر باشد.';
        } elseif ($password !== $passwordConfirm) {
            $error = 'رمز عبور و تکرار آن یکسان نیستند.';
        } else {
            $checkStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username');
            $checkStmt->execute(['username' => $username]);
            if ($checkStmt->fetch()) {
                $error = 'این نام کاربری قبلاً استفاده شده است.';
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('INSERT INTO users (full_name, username, password_hash, role, is_active) VALUES (:full_name, :username, :password_hash, :role, 1)');
                    $stmt->execute([
                        'full_name'     => $fullName,
                        'username'      => $username,
                        'password_hash' => $hash,
                        'role'          => $role,
                    ]);
                    redirectWithMessage('users.php', 'success', 'کاربر جدید با موفقیت ساخته شد.');
                } catch (PDOException $e) {
                    error_log('Create User Error: ' . $e->getMessage());
                    $error = 'خطایی در ساخت کاربر رخ داد.';
                }
            }
        }

        if ($error !== '') {
            $reopenModal = 'add';
        }
    } elseif ($action === 'update') {
        $targetId = (int)postParam('user_id');
        $fullName = postParam('full_name');
        $username = postParam('username');
        $role = postParam('role', 'user');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        if (!in_array($role, ['admin', 'user'], true)) {
            $role = 'user';
        }

        $targetStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
        $targetStmt->execute(['id' => $targetId]);
        $targetUser = $targetStmt->fetch();

        if (!$targetUser) {
            $error = 'کاربر مورد نظر یافت نشد.';
        } elseif ($fullName === '' || $username === '') {
            $error = 'نام و نام کاربری الزامی است.';
        } elseif (mb_strlen($fullName) > 100) {
            $error = 'نام و نام خانوادگی نباید بیشتر از ۱۰۰ کاراکتر باشد.';
        } elseif (mb_strlen($username) > 50) {
            $error = 'نام کاربری نباید بیشتر از ۵۰ کاراکتر باشد.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.]+$/', $username)) {
            $error = 'نام کاربری فقط می‌تواند شامل حروف انگلیسی، عدد، نقطه و آندرلاین باشد.';
        } elseif ($password !== '' && mb_strlen($password) < 6) {
            $error = 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد.';
        } elseif ($password !== '' && $password !== $passwordConfirm) {
            $error = 'رمز عبور و تکرار آن یکسان نیستند.';
        } elseif ($targetUser['role'] === 'admin' && $role === 'user' && (int)$targetUser['id'] === $currentUserId) {
            $error = 'نمی‌توانید نقش مدیریتی خودتان را تغییر دهید.';
        } elseif ($targetUser['role'] === 'admin' && $role === 'user' && countOtherActiveAdmins($pdo, $targetId) < 1) {
            $error = 'حداقل باید یک مدیر فعال در سیستم باقی بماند.';
        } else {
            $dupStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username AND id != :id');
            $dupStmt->execute(['username' => $username, 'id' => $targetId]);
            if ($dupStmt->fetch()) {
                $error = 'این نام کاربری قبلاً استفاده شده است.';
            } else {
                try {
                    if ($password !== '') {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare('UPDATE users SET full_name = :full_name, username = :username, role = :role, password_hash = :password_hash WHERE id = :id');
                        $stmt->execute([
                            'full_name'     => $fullName,
                            'username'      => $username,
                            'role'          => $role,
                            'password_hash' => $hash,
                            'id'            => $targetId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET full_name = :full_name, username = :username, role = :role WHERE id = :id');
                        $stmt->execute([
                            'full_name' => $fullName,
                            'username'  => $username,
                            'role'      => $role,
                            'id'        => $targetId,
                        ]);
                    }
                    redirectWithMessage('users.php', 'success', 'اطلاعات کاربر بروزرسانی شد.');
                } catch (PDOException $e) {
                    error_log('Update User Error: ' . $e->getMessage());
                    $error = 'خطایی در بروزرسانی کاربر رخ داد.';
                }
            }
        }

        if ($error !== '') {
            $reopenModal = 'edit';
        }
    } elseif ($action === 'toggle_status') {
        $targetId = (int)postParam('user_id');

        if ($targetId === $currentUserId) {
            redirectWithMessage('users.php', 'error', 'نمی‌توانید وضعیت حساب خودتان را تغییر دهید.');
        }

        $targetStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
        $targetStmt->execute(['id' => $targetId]);
        $targetUser = $targetStmt->fetch();

        if (!$targetUser) {
            redirectWithMessage('users.php', 'error', 'کاربر مورد نظر یافت نشد.');
        }

        $newStatus = (int)$targetUser['is_active'] === 1 ? 0 : 1;

        if ($targetUser['role'] === 'admin' && $newStatus === 0 && countOtherActiveAdmins($pdo, $targetId) < 1) {
            redirectWithMessage('users.php', 'error', 'حداقل باید یک مدیر فعال در سیستم باقی بماند.');
        }

        $stmt = $pdo->prepare('UPDATE users SET is_active = :status WHERE id = :id');
        $stmt->execute(['status' => $newStatus, 'id' => $targetId]);

        redirectWithMessage('users.php', 'success', $newStatus === 1 ? 'کاربر فعال شد.' : 'کاربر غیرفعال شد.');
    } elseif ($action === 'delete') {
        $targetId = (int)postParam('user_id');

        if ($targetId === $currentUserId) {
            redirectWithMessage('users.php', 'error', 'نمی‌توانید حساب خودتان را حذف کنید.');
        }

        $targetStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
        $targetStmt->execute(['id' => $targetId]);
        $targetUser = $targetStmt->fetch();

        if (!$targetUser) {
            redirectWithMessage('users.php', 'error', 'کاربر مورد نظر یافت نشد.');
        }

        if ($targetUser['role'] === 'admin' && countOtherActiveAdmins($pdo, $targetId) < 1) {
            redirectWithMessage('users.php', 'error', 'حداقل باید یک مدیر فعال در سیستم باقی بماند.');
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute(['id' => $targetId]);
            redirectWithMessage('users.php', 'success', 'کاربر حذف شد.');
        } catch (PDOException $e) {
            redirectWithMessage('users.php', 'error', 'این کاربر دارای تراکنش ثبت‌شده است و قابل حذف نیست. می‌توانید آن را غیرفعال کنید.');
        }
    } elseif ($action === 'update_login_setting') {
        $requireFullLogin = postParam('require_full_login') === '1' ? '1' : '0';
        setSetting('require_full_login', $requireFullLogin);
        redirectWithMessage('users.php', 'success', 'تنظیمات ورود بروزرسانی شد.');
    }
}

$users = $pdo->query('SELECT id, full_name, username, role, is_active, created_at FROM users ORDER BY created_at ASC')->fetchAll();
$requireFullLoginSetting = getSetting('require_full_login', '0') === '1';

$pageTitle = 'مدیریت کاربران';
include __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <h2 class="card-title">تنظیمات ورود</h2>
    <p style="font-size:13px; color:var(--color-gray-500); margin-bottom:12px;">
        به‌صورت پیش‌فرض، بعد از اولین ورود موفق روی هر دستگاه، نام کاربری همان‌جا ذخیره می‌شود و دفعات بعد فقط رمز عبور پرسیده می‌شود (تا یک ساعت هر ورود معتبر است). با فعال‌کردن این گزینه، این قابلیت خاموش می‌شود و همه همیشه باید نام کاربری و رمز عبور را کامل وارد کنند.
    </p>
    <form method="POST">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="update_login_setting">
        <label style="display:flex; align-items:center; gap:8px; margin-bottom:12px; cursor:pointer;">
            <input type="checkbox" name="require_full_login" value="1" style="width:auto;" <?= $requireFullLoginSetting ? 'checked' : '' ?>>
            <span>الزام به وارد کردن نام کاربری هنگام ورود</span>
        </label>
        <button type="submit" class="btn btn-secondary btn-sm">ذخیره تنظیمات</button>
    </form>
</div>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">کاربران سیستم</h2>
        <button type="button" class="btn btn-primary btn-sm" data-modal-open="addUserModal">+ کاربر جدید</button>
    </div>

    <?php if ($error && $reopenModal !== 'add' && $reopenModal !== 'edit'): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="table-wrapper">
        <table class="data-table users-table">
            <thead>
                <tr>
                    <th>نام و نام خانوادگی</th>
                    <th>نام کاربری</th>
                    <th>نقش</th>
                    <th>وضعیت</th>
                    <th>تاریخ عضویت</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="6" class="empty-row">کاربری یافت نشد.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td data-label="نام"><?= h($u['full_name']) ?></td>
                            <td data-label="نام کاربری"><?= h($u['username']) ?></td>
                            <td data-label="نقش"><?= $u['role'] === 'admin' ? 'مدیر' : 'کاربر' ?></td>
                            <td data-label="وضعیت">
                                <span class="status-badge <?= (int)$u['is_active'] === 1 ? 'status-active' : 'status-inactive' ?>">
                                    <?= (int)$u['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
                                </span>
                            </td>
                            <td data-label="تاریخ عضویت"><?= toJalali(substr($u['created_at'], 0, 10)) ?></td>
                            <td data-label="عملیات">
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-secondary btn-sm js-edit-user"
                                        data-id="<?= (int)$u['id'] ?>"
                                        data-full-name="<?= h($u['full_name']) ?>"
                                        data-username="<?= h($u['username']) ?>"
                                        data-role="<?= h($u['role']) ?>">ویرایش</button>

                                    <?php if ((int)$u['id'] !== $currentUserId): ?>
                                        <form method="POST" style="display:inline;">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">
                                                <?= (int)$u['is_active'] === 1 ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?>
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('آیا از حذف این کاربر مطمئن هستید؟ این عملیات قابل بازگشت نیست.');">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <button type="submit" class="delete-btn">حذف</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:var(--color-gray-500); align-self:center;">(حساب شما)</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- مودال افزودن کاربر -->
<div class="modal-overlay <?= $reopenModal === 'add' ? 'show' : '' ?>" id="addUserModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>افزودن کاربر جدید</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <?php if ($error && $reopenModal === 'add'): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="POST" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label>نام و نام خانوادگی</label>
                <input type="text" name="full_name" required value="<?= $reopenModal === 'add' ? h(postParam('full_name')) : '' ?>">
            </div>
            <div class="form-group">
                <label>نام کاربری</label>
                <input type="text" name="username" required placeholder="فقط حروف انگلیسی و عدد" value="<?= $reopenModal === 'add' ? h(postParam('username')) : '' ?>">
            </div>
            <div class="form-group">
                <label>نقش</label>
                <select name="role">
                    <option value="user" <?= ($reopenModal === 'add' && postParam('role') === 'user') ? 'selected' : '' ?>>کاربر</option>
                    <option value="admin" <?= ($reopenModal === 'add' && postParam('role') === 'admin') ? 'selected' : '' ?>>مدیر</option>
                </select>
            </div>
            <div class="form-group">
                <label>رمز عبور</label>
                <input type="password" name="password" required placeholder="حداقل ۶ کاراکتر">
            </div>
            <div class="form-group">
                <label>تکرار رمز عبور</label>
                <input type="password" name="password_confirm" required>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال ویرایش کاربر -->
<div class="modal-overlay <?= $reopenModal === 'edit' ? 'show' : '' ?>" id="editUserModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>ویرایش کاربر</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <?php if ($error && $reopenModal === 'edit'): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="POST" id="editUserForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="user_id" value="<?= $reopenModal === 'edit' ? h(postParam('user_id')) : '' ?>">
            <div class="form-group">
                <label>نام و نام خانوادگی</label>
                <input type="text" name="full_name" required value="<?= $reopenModal === 'edit' ? h(postParam('full_name')) : '' ?>">
            </div>
            <div class="form-group">
                <label>نام کاربری</label>
                <input type="text" name="username" required value="<?= $reopenModal === 'edit' ? h(postParam('username')) : '' ?>">
            </div>
            <div class="form-group">
                <label>نقش</label>
                <select name="role">
                    <option value="user" <?= ($reopenModal === 'edit' && postParam('role') === 'user') ? 'selected' : '' ?>>کاربر</option>
                    <option value="admin" <?= ($reopenModal === 'edit' && postParam('role') === 'admin') ? 'selected' : '' ?>>مدیر</option>
                </select>
            </div>
            <div class="form-group">
                <label>رمز عبور جدید (اختیاری)</label>
                <input type="password" name="password" placeholder="خالی بگذارید تا تغییر نکند">
            </div>
            <div class="form-group">
                <label>تکرار رمز عبور جدید</label>
                <input type="password" name="password_confirm">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
