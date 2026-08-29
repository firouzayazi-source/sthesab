<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد حساب کاربری خود شوید.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405);
}

Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();
$kind   = postParam('kind');   // bank_mine | bank_external | asset_type | wallet_kind
$action = postParam('action'); // add | delete

$pdo = Database::getConnection();

// ---------- افزودن ----------
if ($action === 'add') {
    $name = postParam('name');

    if ($name === '' || mb_strlen($name) > 100) {
        jsonResponse(['success' => false, 'message' => 'نام الزامی است و باید کمتر از ۱۰۰ کاراکتر باشد.'], 422);
    }

    try {
        if ($kind === 'bank_mine' || $kind === 'bank_external') {
            $scope = $kind === 'bank_mine' ? 'mine' : 'external';
            $stmt = $pdo->prepare('SELECT id FROM banks WHERE user_id = :user_id AND scope = :scope AND name = :name');
            $stmt->execute(['user_id' => $userId, 'scope' => $scope, 'name' => $name]);
            if ($stmt->fetch()) {
                jsonResponse(['success' => false, 'message' => 'این بانک قبلاً در همین لیست ثبت شده است.'], 422);
            }

            $ins = $pdo->prepare('INSERT INTO banks (user_id, scope, name) VALUES (:user_id, :scope, :name)');
            $ins->execute(['user_id' => $userId, 'scope' => $scope, 'name' => $name]);

            jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'name' => $name, 'message' => 'بانک اضافه شد.']);
        }

        if ($kind === 'asset_type') {
            $unit = postParam('unit', 'عدد');
            if ($unit === '' || mb_strlen($unit) > 30) {
                $unit = 'عدد';
            }

            $stmt = $pdo->prepare('SELECT id FROM asset_types WHERE user_id = :user_id AND name = :name');
            $stmt->execute(['user_id' => $userId, 'name' => $name]);
            if ($stmt->fetch()) {
                jsonResponse(['success' => false, 'message' => 'این نوع دارایی قبلاً ثبت شده است.'], 422);
            }

            $ins = $pdo->prepare('INSERT INTO asset_types (user_id, name, unit) VALUES (:user_id, :name, :unit)');
            $ins->execute(['user_id' => $userId, 'name' => $name, 'unit' => $unit]);

            jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'name' => $name, 'message' => 'نوع دارایی اضافه شد.']);
        }

        if ($kind === 'wallet_kind') {
            if (!tableExists('wallet_kinds')) {
                jsonResponse(['success' => false, 'message' => 'جدول انواع حساب هنوز ساخته نشده. روی سرور:  bash deploy/migrate.sh --apply'], 500);
            }
            if (mb_strlen($name) > 60) {
                jsonResponse(['success' => false, 'message' => 'نام نوع حساب باید کوتاه‌تر از ۶۰ کاراکتر باشد.'], 422);
            }

            $stmt = $pdo->prepare('SELECT id FROM wallet_kinds WHERE user_id = :user_id AND name = :name');
            $stmt->execute(['user_id' => $userId, 'name' => $name]);
            if ($stmt->fetch()) {
                jsonResponse(['success' => false, 'message' => 'این نوع حساب قبلاً ثبت شده است.'], 422);
            }

            $ins = $pdo->prepare('INSERT INTO wallet_kinds (user_id, name) VALUES (:user_id, :name)');
            $ins->execute(['user_id' => $userId, 'name' => $name]);

            jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'name' => $name, 'message' => 'نوع حساب اضافه شد.']);
        }

        jsonResponse(['success' => false, 'message' => 'نوع نامعتبر است.'], 422);
    } catch (PDOException $e) {
        error_log('Reference add error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'خطایی در ثبت رخ داد.'], 500);
    }
}

// ---------- حذف ----------
if ($action === 'delete') {
    $id = (int)postParam('id');
    if ($id <= 0) {
        jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
    }

    try {
        if ($kind === 'bank_mine' || $kind === 'bank_external') {
            $scope = $kind === 'bank_mine' ? 'mine' : 'external';

            $own = $pdo->prepare('SELECT id FROM banks WHERE id = :id AND user_id = :user_id AND scope = :scope');
            $own->execute(['id' => $id, 'user_id' => $userId, 'scope' => $scope]);
            if (!$own->fetch()) {
                jsonResponse(['success' => false, 'message' => 'مورد یافت نشد.'], 404);
            }

            $inUse = referenceInUseCount('cheques', 'bank_id', $id, $userId);
            if ($inUse > 0) {
                jsonResponse([
                    'success' => false,
                    'message' => 'این بانک روی ' . toPersianDigits($inUse) . ' چک ثبت شده و قابل حذف نیست.',
                ], 409);
            }

            $del = $pdo->prepare('DELETE FROM banks WHERE id = :id AND user_id = :user_id');
            $del->execute(['id' => $id, 'user_id' => $userId]);

            jsonResponse(['success' => true, 'message' => 'بانک حذف شد.']);
        }

        if ($kind === 'asset_type') {
            $own = $pdo->prepare('SELECT id FROM asset_types WHERE id = :id AND user_id = :user_id');
            $own->execute(['id' => $id, 'user_id' => $userId]);
            if (!$own->fetch()) {
                jsonResponse(['success' => false, 'message' => 'مورد یافت نشد.'], 404);
            }

            $inUse = referenceInUseCount('assets', 'asset_type_id', $id, $userId);
            if ($inUse > 0) {
                jsonResponse([
                    'success' => false,
                    'message' => 'روی این نوع دارایی ' . toPersianDigits($inUse) . ' رکورد ثبت شده و قابل حذف نیست.',
                ], 409);
            }

            $del = $pdo->prepare('DELETE FROM asset_types WHERE id = :id AND user_id = :user_id');
            $del->execute(['id' => $id, 'user_id' => $userId]);

            jsonResponse(['success' => true, 'message' => 'نوع دارایی حذف شد.']);
        }

        if ($kind === 'wallet_kind') {
            if (!tableExists('wallet_kinds')) {
                jsonResponse(['success' => false, 'message' => 'مورد یافت نشد.'], 404);
            }

            $own = $pdo->prepare('SELECT name FROM wallet_kinds WHERE id = :id AND user_id = :user_id');
            $own->execute(['id' => $id, 'user_id' => $userId]);
            $kindName = $own->fetchColumn();
            if ($kindName === false) {
                jsonResponse(['success' => false, 'message' => 'مورد یافت نشد.'], 404);
            }

            // اگر حسابی همین نوع را دارد، حذفش اسم آن حساب را بی‌معنا می‌کند
            if (tableHasColumn('wallets', 'kind_label')) {
                $cnt = $pdo->prepare('SELECT COUNT(*) FROM wallets WHERE user_id = :u AND kind_label = :n');
                $cnt->execute(['u' => $userId, 'n' => $kindName]);
                $inUse = (int)$cnt->fetchColumn();
                if ($inUse > 0) {
                    jsonResponse([
                        'success' => false,
                        'message' => 'این نوع روی ' . toPersianDigits($inUse) . ' حساب ثبت شده و قابل حذف نیست.',
                    ], 409);
                }
            }

            $del = $pdo->prepare('DELETE FROM wallet_kinds WHERE id = :id AND user_id = :user_id');
            $del->execute(['id' => $id, 'user_id' => $userId]);

            jsonResponse(['success' => true, 'message' => 'نوع حساب حذف شد.']);
        }

        jsonResponse(['success' => false, 'message' => 'نوع نامعتبر است.'], 422);
    } catch (PDOException $e) {
        error_log('Reference delete error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'خطایی در حذف رخ داد.'], 500);
    }
}

jsonResponse(['success' => false, 'message' => 'عملیات نامعتبر است.'], 422);
