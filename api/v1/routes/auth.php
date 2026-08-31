<?php
/**
 * ورود، خروج، و شناسه‌ی کاربر.
 *
 * اعتبارسنجی رمز از `Auth::verifyCredentials()` می‌گذرد — همان تابعی که
 * صفحه‌ی ورود وب هم استفاده می‌کند. پس سدِ حدس رمز (`LoginThrottle`)
 * اینجا هم دقیقاً همان‌طور کار می‌کند و لازم نبود دوباره نوشته شود.
 */

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/auth.php';

/** GET ping — برای اینکه اپ بفهمد سرور در دسترس و کدام نسخه است. */
function v1Ping(array $params): void
{
    Api::ok([
        'api_version' => Api::VERSION,
        'today'       => Api::date(today()),
        'auth_ready'  => ApiAuth::available(),
    ]);
}

/**
 * POST auth/login  {username, password, device_name?, platform?}
 *   → {token, expires_in_days, user}
 *
 * رشته‌ی توکن فقط همین یک بار دیده می‌شود؛ اپ باید در حافظه‌ی امنِ
 * سیستم‌عامل نگهش دارد (Keystore روی اندروید، Keychain روی iOS) نه در
 * فایل ساده.
 */
function v1AuthLogin(array $params): void
{
    if (!ApiAuth::available()) {
        Api::fail('api_unavailable', 'سرویس هنوز آماده نیست. migration اجرا نشده است.', 503);
    }

    $identifier = Api::input('username');
    $password   = (string)(Api::body()['password'] ?? '');

    if ($identifier === '' || $password === '') {
        Api::fail('missing_credentials', 'نام کاربری و رمز عبور را وارد کنید.', 422);
    }

    $result = Auth::verifyCredentials($identifier, $password);

    if (!($result['success'] ?? false)) {
        // پیام دست‌نخورده از لایه‌ی مشترک می‌آید تا وجود یا نبودِ حساب لو
        // نرود. حالت «قفل شده» کد جدا دارد تا اپ بتواند فرقش را بگذارد.
        $locked = !empty($result['locked']);
        Api::fail(
            $locked ? 'too_many_attempts' : 'invalid_credentials',
            $result['message'],
            $locked ? 429 : 401
        );
    }

    $user  = $result['user'];
    $token = ApiAuth::issue(
        (int)$user['id'],
        Api::input('device_name'),
        Api::input('platform')
    );

    Api::ok([
        'token'           => $token,
        'expires_in_days' => ApiAuth::TOKEN_DAYS,
        'user'            => [
            'id'        => (int)$user['id'],
            'username'  => $user['username'],
            'full_name' => $user['full_name'],
            'role'      => $user['role'],
        ],
    ]);
}

/** POST auth/logout — فقط توکنِ همین دستگاه را باطل می‌کند. */
function v1AuthLogout(array $params): void
{
    Api::requireUser();
    ApiAuth::revokeCurrent();
    Api::ok(['revoked' => true]);
}

/** GET me */
function v1Me(array $params): void
{
    Api::requireUser();
    $u = ApiAuth::user();

    Api::ok([
        'id'        => (int)$u['id'],
        'username'  => $u['username'],
        'full_name' => $u['full_name'],
        'role'      => $u['role'],
    ]);
}
