/// نگهداری توکن و وضعیت ورود.
///
/// ⚠ توکن در **حافظه‌ی امنِ سیستم‌عامل** می‌نشیند، نه در فایل ساده یا
/// SharedPreferences معمولی: روی اندروید AES-GCM با کلیدِ محافظت‌شده در
/// Keystore، و روی iOS Keychain. توکن ۹۰ روز اعتبار دارد و دسترسی به داده‌ی
/// مالیِ کاربر می‌دهد؛ اگر جایی بنشیند که پشتیبان‌گیریِ خودکار یا یک اپ
/// دیگر بتواند بخواندش، همان‌قدر بد است که رمز را ذخیره کرده باشیم.
library;

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../models/models.dart';
import 'api_client.dart';

class AuthStore extends ChangeNotifier {
  static const _kToken = 'api_token';
  static const _kUsername = 'last_username';

  final ApiClient api;
  final FlutterSecureStorage _storage;

  User? _user;
  bool _loading = true;

  /// نام کاربریِ آخرین ورود — فقط برای پر کردنِ فرم، حساس نیست.
  String? lastUsername;

  AuthStore({required this.api, FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              // پیش‌فرضِ اندروید از نسخه‌ی ۱۰ به بعد خودش رمزگذاری‌شده است
              aOptions: AndroidOptions(),
              iOptions: IOSOptions(
                accessibility: KeychainAccessibility.first_unlock,
              ),
            ) {
    api.onUnauthenticated = _onTokenRejected;
  }

  User? get user => _user;
  bool get isLoggedIn => _user != null;

  /// تا وقتی true است هنوز معلوم نیست کاربر وارد هست یا نه.
  bool get loading => _loading;

  /// در شروع اپ: توکن ذخیره‌شده را برمی‌دارد و می‌سنجد.
  ///
  /// اگر توکن باشد ولی سرور ردش کند، بی‌سروصدا پاک می‌شود و کاربر
  /// صفحه‌ی ورود را می‌بیند — نه یک اپ نیمه‌کار که هر درخواستش خطا بدهد.
  Future<void> restore() async {
    _loading = true;
    notifyListeners();

    try {
      lastUsername = await _storage.read(key: _kUsername);
      final saved = await _storage.read(key: _kToken);
      if (saved != null && saved.isNotEmpty) {
        api.token = saved;
        _user = await api.me();
      }
    } on ApiException catch (e) {
      // شبکه نبود ≠ توکن باطل است. توکن را فقط وقتی پاک کن که سرور
      // واقعاً ردش کرده باشد؛ وگرنه کاربرِ آفلاین بی‌دلیل بیرون می‌افتد.
      if (e.isUnauthenticated) {
        await _clear();
      }
    } catch (_) {
      // هر خطای غیرمنتظره: کاربر را بیرون نینداز، فقط وارد نکن
    }

    _loading = false;
    notifyListeners();
  }

  Future<void> login({
    required String username,
    required String password,
    String? deviceName,
    String? platform,
  }) async {
    final result = await api.login(
      username: username,
      password: password,
      deviceName: deviceName,
      platform: platform,
    );

    api.token = result.token;
    _user = result.user;
    lastUsername = result.user.username;

    await _storage.write(key: _kToken, value: result.token);
    await _storage.write(key: _kUsername, value: result.user.username);

    notifyListeners();
  }

  /// خروجِ خواسته‌ی کاربر — توکن را روی سرور هم باطل می‌کند.
  Future<void> logout() async {
    try {
      await api.logout();
    } catch (_) {
      // اگر شبکه نبود هم باید از این دستگاه بیرون برود
    }
    await _clear();
  }

  void _onTokenRejected() {
    // در میانه‌ی یک درخواست صدا زده می‌شود؛ پاک کردن را به بعد از همان
    // فریم موکول می‌کنیم تا وسط ساختِ ویجت‌ها notifyListeners نزنیم.
    Future.microtask(_clear);
  }

  Future<void> _clear() async {
    api.token = null;
    _user = null;
    await _storage.delete(key: _kToken);
    notifyListeners();
  }
}
