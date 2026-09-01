/// تست ساخته‌شدنِ صفحه‌ها.
///
/// `flutter analyze` فقط نحو و نوع را می‌بیند؛ خطاهای زمانِ ساختِ ویجت
/// (چیدمانِ نامعتبر، تمِ ناقص، وابستگیِ جاافتاده) را نمی‌گیرد. اینجا
/// صفحه‌ها واقعاً pump می‌شوند تا اگر چیزی موقع ساخت بشکند، همین‌جا
/// معلوم شود نه روی گوشی کاربر.
library;

import 'dart:convert';

import 'package:daftar/api/api_client.dart';
import 'package:daftar/api/auth_store.dart';
import 'package:daftar/main.dart';
import 'package:daftar/models/models.dart';
import 'package:daftar/screens/login_screen.dart';
import 'package:daftar/screens/transaction_form.dart';
import 'package:daftar/security/app_lock.dart';
import 'package:daftar/theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// حافظه‌ی امنِ ساختگی — تست نباید به Keystore واقعی دست بزند.
class _MemoryStorage implements FlutterSecureStorage {
  final Map<String, String> _data = {};

  @override
  Future<String?> read({required String key, dynamic iOptions, dynamic aOptions, dynamic lOptions, dynamic wOptions, dynamic mOptions, dynamic webOptions}) async =>
      _data[key];

  @override
  Future<void> write({required String key, required String? value, dynamic iOptions, dynamic aOptions, dynamic lOptions, dynamic wOptions, dynamic mOptions, dynamic webOptions}) async {
    if (value == null) {
      _data.remove(key);
    } else {
      _data[key] = value;
    }
  }

  @override
  Future<void> delete({required String key, dynamic iOptions, dynamic aOptions, dynamic lOptions, dynamic wOptions, dynamic mOptions, dynamic webOptions}) async =>
      _data.remove(key);

  @override
  noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

/// حسگرِ ساختگی که همیشه تأیید می‌کند — تست به سخت‌افزار دست نمی‌زند.
class _AlwaysOkBio implements Biometrics {
  @override
  Future<bool> isAvailable() async => true;

  @override
  Future<bool> authenticate(String reason) async => true;
}

ApiClient _apiReturning(Map<String, Object?> data) => ApiClient(
      baseUrl: 'https://x.test',
      httpClient: MockClient((_) async => http.Response(
            jsonEncode({'ok': true, 'data': data}),
            200,
            headers: {'content-type': 'application/json; charset=utf-8'},
          )),
    );

Widget _wrap(Widget child) => MaterialApp(
      theme: buildTheme(Brightness.light),
      home: Directionality(textDirection: TextDirection.rtl, child: child),
    );

void main() {
  testWidgets('صفحه‌ی ورود ساخته می‌شود و فیلدها هستند', (tester) async {
    final auth = AuthStore(
      api: _apiReturning({}),
      storage: _MemoryStorage(),
    );

    await tester.pumpWidget(_wrap(LoginScreen(auth: auth)));

    expect(find.text('دفتر مالی'), findsOneWidget);
    expect(find.text('نام کاربری یا ایمیل'), findsOneWidget);
    expect(find.text('رمز عبور'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'ورود'), findsOneWidget);
  });

  testWidgets('ورود با فیلد خالی، اعتبارسنجی می‌دهد و درخواستی نمی‌فرستد',
      (tester) async {
    var requests = 0;
    final api = ApiClient(
      baseUrl: 'https://x.test',
      httpClient: MockClient((_) async {
        requests++;
        return http.Response('{"ok":true,"data":{}}', 200);
      }),
    );
    final auth = AuthStore(api: api, storage: _MemoryStorage());
    auth.lastUsername = null;

    await tester.pumpWidget(_wrap(LoginScreen(auth: auth)));
    await tester.tap(find.widgetWithText(FilledButton, 'ورود'));
    await tester.pump();

    expect(find.text('نام کاربری را وارد کنید'), findsOneWidget);
    expect(requests, 0, reason: 'فرمِ ناقص نباید به سرور برود');
  });

  testWidgets('فرم تراکنش ساخته می‌شود و حالت ویرایش حساب را قفل می‌کند',
      (tester) async {
    // در ویرایش، سرور wallet_id را نمی‌پذیرد؛ فرم هم نباید فیلدِ
    // قابل‌تغییر نشان بدهد وگرنه کاربر عوضش می‌کند و هیچ اتفاقی نمی‌افتد.
    final api = _apiReturning({
      'items': <Object>[],
      'total_balance': 0,
      'today': {'iso': '2026-08-31', 'jalali': '۱۴۰۵/۰۶/۰۹'},
    });

    final existing = Transaction(
      id: 7,
      type: 'expense',
      amount: 25000,
      title: 'خرید',
      date: const ApiDate(iso: '2026-08-31', jalali: '۱۴۰۵/۰۶/۰۹'),
      wallet: const Ref(id: 2, name: 'کیف پول'),
    );

    await tester.pumpWidget(
        _wrap(TransactionForm(api: api, existing: existing)));
    await tester.pumpAndSettle();

    expect(find.text('ویرایش تراکنش'), findsOneWidget);
    expect(find.text('کیف پول'), findsOneWidget);
    // آیکن قفل یعنی حساب قابل تغییر نیست
    expect(find.byIcon(Icons.lock_outline), findsOneWidget);
  });

  testWidgets('AuthGate تا وقتی توکن سنجیده نشده، بارگذاری نشان می‌دهد',
      (tester) async {
    final auth = AuthStore(api: _apiReturning({}), storage: _MemoryStorage());

    await tester.pumpWidget(_wrap(AuthGate(auth: auth, lock: AppLock())));

    expect(find.byType(CircularProgressIndicator), findsOneWidget);
  });

  testWidgets('کاربرِ واردنشده صفحه‌ی ورود می‌بیند، نه صفحه‌ی قفل',
      (tester) async {
    // ترتیبِ دروازه‌ها: ورود مقدم بر قفل. نشان دادنِ صفحه‌ی قفل به کسی
    // که اصلاً وارد نشده، فقط او را از صفحه‌ی ورود دور می‌کند.
    final auth = AuthStore(api: _apiReturning({}), storage: _MemoryStorage());
    await auth.restore();

    final lock = AppLock(
      biometrics: _AlwaysOkBio(),
      storage: _MemoryStorage(),
    );
    await lock.enable();
    await lock.restore();

    await tester.pumpWidget(_wrap(AuthGate(auth: auth, lock: lock)));
    await tester.pump();

    expect(find.byType(LoginScreen), findsOneWidget);
  });

  testWidgets('تا وضعیتِ قفل معلوم نشده، هیچ داده‌ای رندر نمی‌شود',
      (tester) async {
    // auth آماده است ولی lock هنوز نه. اگر دروازه فقط auth را می‌سنجید،
    // اینجا HomeScreen با موجودی رندر می‌شد.
    final auth = AuthStore(api: _apiReturning({}), storage: _MemoryStorage());
    await auth.restore();

    final lock = AppLock(
      biometrics: _AlwaysOkBio(),
      storage: _MemoryStorage(),
    );
    // عمداً restore صدا زده نمی‌شود

    await tester.pumpWidget(_wrap(AuthGate(auth: auth, lock: lock)));

    expect(find.byType(CircularProgressIndicator), findsOneWidget);
  });

  test('نام سکو یکی از مقادیری است که سرور می‌پذیرد', () {
    expect(
      const ['android', 'ios', 'web', 'desktop'].contains(currentPlatform()),
      isTrue,
    );
  });
}
