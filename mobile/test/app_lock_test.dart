/// تست قفل اپ.
///
/// مهم‌ترین چیزی که اینجا سنجیده می‌شود **پیش‌فرض** است: قفل باید خاموش
/// باشد. اگر روزی کسی مقدار اولیه را عوض کند، همه‌ی کاربران ناگهان پشت
/// یک صفحه‌ی احراز هویت می‌افتند — و این دقیقاً همان چیزی است که
/// نمی‌خواستیم.
library;

import 'package:daftar/security/app_lock.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:local_auth/local_auth.dart';

class _MemoryStorage implements FlutterSecureStorage {
  final Map<String, String> data = {};

  @override
  Future<String?> read({required String key, dynamic iOptions, dynamic aOptions, dynamic lOptions, dynamic wOptions, dynamic mOptions, dynamic webOptions}) async =>
      data[key];

  @override
  Future<void> write({required String key, required String? value, dynamic iOptions, dynamic aOptions, dynamic lOptions, dynamic wOptions, dynamic mOptions, dynamic webOptions}) async {
    if (value == null) {
      data.remove(key);
    } else {
      data[key] = value;
    }
  }

  @override
  Future<void> delete({required String key, dynamic iOptions, dynamic aOptions, dynamic lOptions, dynamic wOptions, dynamic mOptions, dynamic webOptions}) async =>
      data.remove(key);

  @override
  noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

/// حسگرِ ساختگی — تست نباید به سخت‌افزار واقعی دست بزند.
class _FakeBio implements Biometrics {
  bool available;

  /// اگر ست شود، `authenticate` این را پرتاب می‌کند.
  LocalAuthException? throws;

  /// نتیجه‌ی احراز هویت وقتی خطایی پرتاب نمی‌شود.
  bool result;

  int calls = 0;

  _FakeBio({this.available = true, this.result = true});

  @override
  Future<bool> isAvailable() async => available;

  @override
  Future<bool> authenticate(String reason) async {
    calls++;
    final e = throws;
    if (e != null) throw e;
    return result;
  }
}

AppLock _lock(_FakeBio bio, _MemoryStorage store,
        {Duration grace = const Duration(milliseconds: 40)}) =>
    AppLock(biometrics: bio, storage: store, grace: grace);

void main() {
  test('پیش‌فرض خاموش است و اپ قفل بالا نمی‌آید', () async {
    // ⚠ مهم‌ترین تستِ این فایل. اگر بشکند یعنی همه‌ی کاربران ناگهان
    // پشت یک صفحه‌ی احراز هویت افتاده‌اند — چیزی که صریحاً نمی‌خواستیم.
    final store = _MemoryStorage();
    final lock = _lock(_FakeBio(), store);

    expect(store.data, isEmpty, reason: 'نصبِ تازه: هیچ تنظیمی ذخیره نشده');

    await lock.restore();

    expect(lock.enabled, isFalse, reason: 'قفل نباید پیش‌فرض روشن باشد');
    expect(lock.locked, isFalse, reason: 'کاربرِ تازه نباید پشت قفل بیفتد');
  });

  test('مقدارِ ناشناخته در حافظه، «روشن» خوانده نمی‌شود', () async {
    // نبودِ تنظیم و مقدارِ خراب هر دو باید «خاموش» معنی شوند. اگر روزی
    // شرطِ خواندن به `!= '0'` عوض شود، حافظه‌ی خالی «روشن» می‌شود.
    for (final value in ['', '0', 'true', 'yes', 'روشن']) {
      final store = _MemoryStorage();
      store.data['app_lock_enabled'] = value;
      final lock = _lock(_FakeBio(), store);

      await lock.restore();

      expect(lock.enabled, isFalse, reason: 'مقدار «$value» نباید روشن باشد');
    }
  });

  test('تا وقتی وضعیت قفل معلوم نشده، loading است', () async {
    // دروازه روی همین منتظر می‌ماند. بدون آن، صفحه‌ی خانه با موجودی یک
    // لحظه رندر می‌شد و بعد صفحه‌ی قفل می‌آمد.
    final store = _MemoryStorage();
    store.data['app_lock_enabled'] = '1';
    final lock = _lock(_FakeBio(), store);

    expect(lock.loading, isTrue);
    expect(lock.locked, isFalse, reason: 'هنوز خوانده نشده');

    await lock.restore();

    expect(lock.loading, isFalse);
    expect(lock.locked, isTrue);
  });

  test('روشن کردن قفل، اول احراز هویت می‌خواهد', () async {
    final bio = _FakeBio(result: false);
    final store = _MemoryStorage();
    final lock = _lock(bio, store);
    await lock.restore();

    final outcome = await lock.enable();

    expect(outcome, UnlockOutcome.failed);
    expect(bio.calls, 1);
    expect(lock.enabled, isFalse,
        reason: 'احراز هویتِ ناموفق نباید قفل را روشن کند — '
            'وگرنه کاربر پشت دری می‌ماند که کلیدش کار نمی‌کند');
    expect(store.data['app_lock_enabled'], isNull);
  });

  test('قفلِ روشن‌شده بعد از باز و بسته شدنِ اپ می‌ماند', () async {
    final store = _MemoryStorage();
    await _lock(_FakeBio(), store).enable();

    // اجرای تازه‌ی اپ، با همان حافظه
    final again = _lock(_FakeBio(), store);
    await again.restore();

    expect(again.enabled, isTrue);
    expect(again.locked, isTrue, reason: 'شروعِ اپ با قفلِ روشن = قفل‌شده');
  });

  test('بازگشتِ سریع قفل نمی‌خواهد', () async {
    final lock = _lock(_FakeBio(), _MemoryStorage());
    await lock.enable();
    expect(lock.locked, isFalse);

    // رفتن و برگشتنِ فوری — مثل رفتن به ماشین‌حساب و برگشتن
    lock.onPaused();
    lock.onResumed();

    expect(lock.locked, isFalse,
        reason: 'بازگشتِ فوری نباید دوباره احراز هویت بخواهد');
  });

  test('بازگشت بعد از مهلت، قفل می‌کند', () async {
    // شاخه‌ای که کل دلیلِ وجودِ قفل است: گوشی از دست کاربر درآمده.
    final lock = _lock(_FakeBio(), _MemoryStorage(),
        grace: const Duration(milliseconds: 20));
    await lock.enable();

    lock.onPaused();
    await Future<void>.delayed(const Duration(milliseconds: 60));
    lock.onResumed();

    expect(lock.locked, isTrue);
  });

  test('نمایشِ همان لحظه‌ی خروج، هنوز قفل نیست', () async {
    // ⚠ قفل باید در **بازگشت** بیفتد نه در خروج. اگر onPaused خودش قفل
    // می‌کرد، پنجره‌ی اثر انگشت — که خودش اپ را موقتاً کنار می‌زند —
    // یک قفلِ تازه می‌ساخت و حلقه‌ی بی‌پایان درست می‌شد.
    final lock = _lock(_FakeBio(), _MemoryStorage());
    await lock.enable();

    lock.onPaused();

    expect(lock.locked, isFalse);
  });

  test('کاربر انصراف داد: قفل باز نمی‌شود ولی خاموش هم نمی‌شود', () async {
    final store = _MemoryStorage();
    final bio = _FakeBio();
    final lock = _lock(bio, store);
    await lock.enable();

    bio.throws = const LocalAuthException(
        code: LocalAuthExceptionCode.userCanceled);
    lock.onPaused();
    lock.onResumed();

    final outcome = await lock.unlock();

    expect(outcome, UnlockOutcome.canceled);
    expect(lock.enabled, isTrue, reason: 'انصراف، تنظیم کاربر را عوض نمی‌کند');
  });

  test('حسگر و رمزِ گوشی برداشته شد: قفل خاموش می‌شود، نه اینکه کاربر گیر بیفتد',
      () async {
    // بدترین حالتِ ممکن: کاربر قفل را روشن کرده، بعد رمز گوشی را
    // برداشته. اگر همین‌جا در باز نشود، هیچ راهی به داده‌اش ندارد.
    final store = _MemoryStorage();
    final bio = _FakeBio();
    final lock = _lock(bio, store);
    await lock.enable();
    lock.onPaused();
    lock.onResumed();

    bio.throws = const LocalAuthException(
        code: LocalAuthExceptionCode.noCredentialsSet);

    final outcome = await lock.unlock();

    expect(outcome, UnlockOutcome.unavailable);
    expect(lock.locked, isFalse, reason: 'کاربر نباید بیرونِ داده‌اش بماند');
    expect(lock.enabled, isFalse, reason: 'قفلِ بی‌کلید باید خاموش شود');
    expect(store.data['app_lock_enabled'], '0');
  });

  test('گوشیِ بی‌قابلیت: قفلِ ذخیره‌شده هنگام شروع خاموش می‌شود', () async {
    final store = _MemoryStorage();
    store.data['app_lock_enabled'] = '1';

    final lock = _lock(_FakeBio(available: false), store);
    await lock.restore();

    expect(lock.supported, isFalse);
    expect(lock.enabled, isFalse);
    expect(lock.locked, isFalse);
  });

  test('روی گوشیِ بی‌قابلیت اصلاً نمی‌شود قفل را روشن کرد', () async {
    final bio = _FakeBio(available: false);
    final lock = _lock(bio, _MemoryStorage());
    await lock.restore();

    expect(await lock.enable(), UnlockOutcome.unavailable);
    expect(bio.calls, 0, reason: 'وقتی دستگاه نمی‌تواند، نباید حتی بپرسد');
  });

  test('خاموش کردن قفل، احراز هویت نمی‌خواهد', () async {
    final bio = _FakeBio();
    final lock = _lock(bio, _MemoryStorage());
    await lock.enable();
    final callsAfterEnable = bio.calls;

    await lock.disable();

    expect(lock.enabled, isFalse);
    expect(bio.calls, callsAfterEnable,
        reason: 'کاربر همین حالا از قفل رد شده؛ پرسیدنِ دوباره فقط نمایش است');
  });

  test('قفلِ خاموش، چرخه‌ی عمر را نادیده می‌گیرد', () async {
    final lock = _lock(_FakeBio(), _MemoryStorage());
    await lock.restore();

    lock.onPaused();
    lock.onResumed();

    expect(lock.locked, isFalse);
  });
}
