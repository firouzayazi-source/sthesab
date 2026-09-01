/// آیا خودِ اپ — همان‌طور که در main.dart ساخته می‌شود — بالا می‌آید؟
///
/// ⚠ این تست عمداً `DaftarApp` واقعی را pump می‌کند، نه یک MaterialApp
/// دست‌ساز. تست‌های صفحه‌ها هر کدام قالبِ خودشان را می‌سازند و همان باعث
/// شد یک خرابیِ کامل از دید همه‌شان پنهان بماند.
library;

import 'package:daftar/api/api_client.dart';
import 'package:daftar/api/auth_store.dart';
import 'package:daftar/main.dart';
import 'package:daftar/security/app_lock.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class _MemoryStorage implements FlutterSecureStorage {
  final Map<String, String> _d = {};
  @override
  Future<String?> read({required String key, dynamic iOptions, dynamic aOptions, dynamic lOptions, dynamic wOptions, dynamic mOptions, dynamic webOptions}) async => _d[key];
  @override
  Future<void> write({required String key, required String? value, dynamic iOptions, dynamic aOptions, dynamic lOptions, dynamic wOptions, dynamic mOptions, dynamic webOptions}) async {}
  @override
  Future<void> delete({required String key, dynamic iOptions, dynamic aOptions, dynamic lOptions, dynamic wOptions, dynamic mOptions, dynamic webOptions}) async {}
  @override
  noSuchMethod(Invocation i) => super.noSuchMethod(i);
}

class _NoBio implements Biometrics {
  @override
  Future<bool> isAvailable() async => false;
  @override
  Future<bool> authenticate(String reason) async => false;
}

void main() {
  testWidgets('اپ بالا می‌آید و صفحه‌ی ورود را نشان می‌دهد', (tester) async {
    final api = ApiClient(
      baseUrl: 'https://x.test',
      httpClient: MockClient((_) async => http.Response('{"ok":true,"data":{}}', 200)),
    );
    final auth = AuthStore(api: api, storage: _MemoryStorage());
    final lock = AppLock(biometrics: _NoBio(), storage: _MemoryStorage());

    await tester.pumpWidget(DaftarApp(auth: auth, lock: lock));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull, reason: 'شروع اپ نباید خطا بدهد');
    expect(find.byType(Scaffold), findsWidgets);
  });
}
