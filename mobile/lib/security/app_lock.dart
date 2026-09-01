/// قفل اپ — **اختیاری، و پیش‌فرض خاموش**.
///
/// این قفل با «ورود به حساب» یکی نیست و نباید با آن قاطی شود:
///
/// - **ورود** یک بار انجام می‌شود و توکنش ۹۰ روز اعتبار دارد.
/// - **قفل** هر بار که اپ باز می‌شود جلوی صفحه می‌ایستد، و توکن را
///   دست نمی‌زند.
///
/// چرا پیش‌فرض خاموش: اپ حسابداری فقط وقتی کار می‌کند که کاربر همان
/// لحظه‌ی خرید مبلغ را ثبت کند. هر مانعی بین او و فرمِ ثبت، بعد از چند
/// هفته به رها شدنِ اپ می‌انجامد. برخلاف اپ بانکی، اینجا نمی‌شود پول
/// جابه‌جا کرد؛ ریسک «دیده شدن» است نه «برداشته شدن».
///
/// ⚠ **قفل هرگز کاربر را از حساب بیرون نمی‌اندازد.** اگر حسگر خراب شود
/// یا کاربر انصراف بزند، فقط پشت صفحه‌ی قفل می‌ماند و می‌تواند دوباره
/// تلاش کند.
library;

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:local_auth/local_auth.dart';
// متنِ پنجره‌ی احراز هویت از این دو می‌آید. `local_auth` خودش صادرشان
// نمی‌کند، پس صریح در pubspec هم هستند — وگرنه پنجره‌ی اثر انگشت روی
// یک اپِ کاملاً فارسی، انگلیسی بالا می‌آمد.
import 'package:local_auth_android/local_auth_android.dart';
import 'package:local_auth_darwin/local_auth_darwin.dart';

/// نتیجه‌ی یک تلاش برای باز کردن قفل.
enum UnlockOutcome {
  /// باز شد.
  success,

  /// کاربر خودش انصراف داد — پیام خطا لازم نیست.
  canceled,

  /// دستگاه دیگر نمی‌تواند قفل را اجرا کند (حسگر یا رمزِ گوشی برداشته
  /// شده). در این حالت قفل **خودکار خاموش می‌شود** — وگرنه کاربر برای
  /// همیشه پشت دری می‌ماند که کلیدش دیگر وجود ندارد.
  unavailable,

  /// تشخیص نداد یا خطای موقت.
  failed,
}

/// مرزی که پشتش دیگر باز کردن قفل ممکن نیست، پس قفل باید خاموش شود.
const _fatalCodes = {
  LocalAuthExceptionCode.noCredentialsSet,
  LocalAuthExceptionCode.noBiometricHardware,
  LocalAuthExceptionCode.noBiometricsEnrolled,
};

/// لایه‌ی نازکی روی `local_auth` تا بشود در تست جایش چیز دیگری گذاشت.
abstract class Biometrics {
  Future<bool> isAvailable();

  /// در صورت موفقیت true. خطاها به [LocalAuthException] تبدیل می‌شوند.
  Future<bool> authenticate(String reason);
}

class PlatformBiometrics implements Biometrics {
  final LocalAuthentication _auth;

  PlatformBiometrics([LocalAuthentication? auth])
      : _auth = auth ?? LocalAuthentication();

  @override
  Future<bool> isAvailable() async {
    try {
      // `isDeviceSupported` یعنی حسگر **یا** رمز/الگوی خودِ گوشی هست.
      // عمداً `canCheckBiometrics` نیست: گوشیِ بدونِ حسگر ولی دارای پین
      // هم می‌تواند قفل داشته باشد.
      return await _auth.isDeviceSupported();
    } catch (_) {
      return false;
    }
  }

  @override
  Future<bool> authenticate(String reason) => _auth.authenticate(
        localizedReason: reason,
        // ⚠ false یعنی رمز/الگوی خودِ گوشی هم پذیرفته می‌شود. اگر
        // biometricOnly بود، کاربری که انگشتش زخم شده یا Face ID اش
        // کار نمی‌کند هیچ راهی نداشت.
        biometricOnly: false,
        authMessages: const [
          AndroidAuthMessages(
            signInTitle: 'باز کردن دفتر مالی',
            cancelButton: 'انصراف',
          ),
          IOSAuthMessages(cancelButton: 'انصراف'),
        ],
      );
}

class AppLock extends ChangeNotifier {
  static const _kEnabled = 'app_lock_enabled';

  /// اگر کاربر کمتر از این مدت بیرون بوده باشد، قفل نمی‌خواهد.
  ///
  /// **این مهلت برای راحتی نیست، برای کارکردن است:** ثبت یک تراکنش
  /// معمولاً یعنی رفتن به ماشین‌حساب یا پیامک بانک و برگشتن. بدون این
  /// مهلت، همان یک ثبت دو بار احراز هویت می‌خواست و کاربر قفل را خاموش
  /// می‌کرد — یعنی نتیجه‌اش امنیتِ کمتر می‌شد، نه بیشتر.
  static const defaultGrace = Duration(seconds: 30);

  final Biometrics _bio;
  final FlutterSecureStorage _storage;

  /// قابل تزریق است **فقط برای اینکه آزمودنی باشد** — با ۳۰ ثانیه‌ی
  /// واقعی، شاخه‌ی «بیرون ماند و قفل شد» در هیچ تستی اجرا نمی‌شد، و
  /// همان شاخه کل دلیلِ وجودِ قفل است.
  final Duration grace;

  bool _enabled = false;
  bool _locked = false;
  bool _busy = false;
  bool _loading = true;
  DateTime? _leftAt;

  /// `null` یعنی هنوز از دستگاه نپرسیده‌ایم.
  ///
  /// عمداً `bool` ساده نیست: با `false` نمی‌شد «نپرسیده‌ام» را از
  /// «پرسیدم و نمی‌تواند» جدا کرد، و هر کسی که پیش از `restore()` سراغِ
  /// قفل می‌آمد بی‌سروصدا جوابِ «در دسترس نیست» می‌گرفت.
  bool? _supportedCache;

  AppLock({
    Biometrics? biometrics,
    FlutterSecureStorage? storage,
    this.grace = defaultGrace,
  })  : _bio = biometrics ?? PlatformBiometrics(),
        // همان گزینه‌های `AuthStore`. تنظیمِ قفل حساس نیست، ولی درست
        // مثل توکن در شروعِ اپ خوانده می‌شود؛ با accessibility پیش‌فرض
        // ممکن بود پیش از اولین بازگشاییِ گوشی خوانده نشود و قفلِ روشن
        // بی‌سروصدا خاموش به نظر برسد.
        _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(),
              iOptions: IOSOptions(
                accessibility: KeychainAccessibility.first_unlock,
              ),
            );

  /// کاربر قفل را روشن کرده است.
  bool get enabled => _enabled;

  /// همین حالا باید صفحه‌ی قفل نشان داده شود.
  bool get locked => _locked;

  /// دستگاه اصلاً می‌تواند قفل داشته باشد (حسگر یا رمزِ گوشی).
  ///
  /// پیش از `restore()` مقدارش `false` است؛ رابط کاربری همیشه بعد از آن
  /// ساخته می‌شود.
  bool get supported => _supportedCache ?? false;

  /// یک بار از دستگاه می‌پرسد و جواب را نگه می‌دارد.
  Future<bool> _ensureSupported() async =>
      _supportedCache ??= await _bio.isAvailable();

  /// یک احراز هویت در جریان است — دکمه باید غیرفعال شود.
  bool get busy => _busy;

  /// تا وقتی true است هنوز معلوم نیست قفل روشن است یا نه.
  ///
  /// ⚠ **دروازه باید منتظرش بماند.** بدون این، بین ساخته شدنِ `AppLock` و
  /// تمام شدنِ `restore()` مقدار `locked` هنوز false است — یعنی صفحه‌ی
  /// خانه با موجودی و تراکنش‌ها یک لحظه رندر می‌شد و بعد صفحه‌ی قفل
  /// می‌آمد. همان یک لحظه دقیقاً چیزی است که قفل قرار بود نگذارد دیده شود.
  bool get loading => _loading;

  /// در شروع اپ. اگر قفل روشن باشد، **قفل‌شده** بالا می‌آید.
  Future<void> restore() async {
    final can = await _ensureSupported();
    try {
      _enabled = await _storage.read(key: _kEnabled) == '1';
    } catch (_) {
      _enabled = false;
    }
    // روشن بودنِ قفل روی گوشی‌ای که دیگر نمی‌تواند احراز هویت کند،
    // یعنی درِ بی‌کلید. همان‌جا خاموشش کن.
    if (_enabled && !can) {
      _enabled = false;
      await _persist();
    }
    _locked = _enabled;
    _loading = false;
    notifyListeners();
  }

  /// روشن کردنِ قفل — **اول یک بار آزموده می‌شود، بعد ذخیره.**
  ///
  /// وگرنه کاربر کلید را می‌زد، اپ را می‌بست، و تازه آن‌وقت می‌فهمید
  /// احراز هویت روی این گوشی کار نمی‌کند — پشتِ دری که خودش قفل کرده.
  Future<UnlockOutcome> enable() async {
    if (!await _ensureSupported()) return UnlockOutcome.unavailable;

    final outcome = await _prompt('برای روشن کردن قفل، هویتتان را تأیید کنید');
    if (outcome == UnlockOutcome.success) {
      _enabled = true;
      _locked = false;
      await _persist();
      notifyListeners();
    }
    return outcome;
  }

  /// خاموش کردنِ قفل.
  ///
  /// عمداً احراز هویت نمی‌خواهد: برای رسیدن به این کلید، کاربر همین حالا
  /// از صفحه‌ی قفل رد شده. پرسیدنِ دوباره فقط نمایش است و چیزی را امن‌تر
  /// نمی‌کند.
  Future<void> disable() async {
    _enabled = false;
    _locked = false;
    await _persist();
    notifyListeners();
  }

  /// تلاش برای باز کردنِ صفحه‌ی قفل.
  Future<UnlockOutcome> unlock() async {
    final outcome = await _prompt('برای باز کردن دفتر مالی');
    if (outcome == UnlockOutcome.success) {
      _locked = false;
      _leftAt = null;
      notifyListeners();
    }
    return outcome;
  }

  // ---------------------------------------------------------------
  // چرخه‌ی عمر
  // ---------------------------------------------------------------

  /// اپ به پس‌زمینه رفت. فقط زمان را نگه می‌داریم.
  ///
  /// ⚠ همین‌جا قفل نمی‌کنیم: خودِ پنجره‌ی اثر انگشت اپ را موقتاً از
  /// جلو می‌برد، و قفل کردن در این لحظه یعنی حلقه‌ی بی‌پایانِ احراز
  /// هویت. تصمیم به بازگشت موکول می‌شود.
  void onPaused() {
    if (!_enabled || _busy) return;
    _leftAt ??= DateTime.now();
  }

  /// اپ برگشت. اگر بیشتر از [grace] بیرون بوده، قفل می‌شود.
  void onResumed() {
    if (!_enabled || _busy) return;
    final left = _leftAt;
    if (left == null) return;
    _leftAt = null;
    if (DateTime.now().difference(left) >= grace && !_locked) {
      _locked = true;
      notifyListeners();
    }
  }

  /// خروج از حساب، قفل را هم به حالت اولش برمی‌گرداند.
  void reset() {
    _locked = false;
    _leftAt = null;
    notifyListeners();
  }

  // ---------------------------------------------------------------

  Future<UnlockOutcome> _prompt(String reason) async {
    if (_busy) return UnlockOutcome.failed;
    _busy = true;
    notifyListeners();

    try {
      final ok = await _bio.authenticate(reason);
      return ok ? UnlockOutcome.success : UnlockOutcome.failed;
    } on LocalAuthException catch (e) {
      if (_fatalCodes.contains(e.code)) {
        // کلید دیگر وجود ندارد؛ در را باز بگذار و قفل را خاموش کن.
        _enabled = false;
        _locked = false;
        await _persist();
        return UnlockOutcome.unavailable;
      }
      if (e.code == LocalAuthExceptionCode.userCanceled ||
          e.code == LocalAuthExceptionCode.systemCanceled) {
        return UnlockOutcome.canceled;
      }
      return UnlockOutcome.failed;
    } catch (_) {
      return UnlockOutcome.failed;
    } finally {
      _busy = false;
      notifyListeners();
    }
  }

  Future<void> _persist() async {
    try {
      await _storage.write(key: _kEnabled, value: _enabled ? '1' : '0');
    } catch (_) {
      // نوشتن که نشد، دست‌کم حالتِ همین اجرا درست است
    }
  }
}
