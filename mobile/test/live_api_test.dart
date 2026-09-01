/// تست در برابرِ **API واقعی**، نه یک ماک.
///
/// این مهم‌ترین تست این پروژه است. ماک فقط چیزی را می‌سنجد که خودم فرض
/// کرده‌ام سرور برمی‌گرداند؛ اگر فرضم غلط باشد، ماک هم غلط است و هر دو
/// با هم سبز می‌مانند. اینجا کلاینت Dart با همان PHP ای حرف می‌زند که
/// روی سرور اجرا می‌شود.
///
/// اجرا:
///     LIVE_API=http://127.0.0.1:8090 flutter test test/live_api_test.dart
///
/// بدون متغیر `LIVE_API` تست‌ها **رد می‌شوند، نه اینکه سبز شوند** — یک
/// تست سبزِ اجرانشده بدتر از نبودنش است.
library;

import 'dart:io';

import 'package:daftar/api/api_client.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final baseUrl = Platform.environment['LIVE_API'];
  final username = Platform.environment['LIVE_USER'] ?? 'ali';
  final password = Platform.environment['LIVE_PASS'];

  if (baseUrl == null || baseUrl.isEmpty) {
    test('API واقعی', () {
      markTestSkipped('LIVE_API تعریف نشده — تست در برابر سرور واقعی رد شد');
    }, skip: 'LIVE_API تعریف نشده');
    return;
  }

  late ApiClient api;

  setUp(() => api = ApiClient(baseUrl: baseUrl));
  tearDown(() => api.close());

  test('ping بدون توکن جواب می‌دهد و تاریخ هر دو شکل را دارد', () async {
    final data = await api.ping();

    expect(data['api_version'], 1);
    // اگر سرور روزی فقط یکی از دو شکل تاریخ را بفرستد، اپ باید همین‌جا
    // بفهمد — نه وقتی کاربر تاریخِ خالی می‌بیند.
    final today = data['today'] as Map;
    expect(today['iso'], isA<String>());
    expect(today['jalali'], isA<String>());
    expect(today['iso'], matches(RegExp(r'^\d{4}-\d{2}-\d{2}$')));
  });

  test('بدون توکن، اندپوینت محافظت‌شده ۴۰۱ می‌دهد', () async {
    await expectLater(
      api.me(),
      throwsA(isA<ApiException>()
          .having((e) => e.code, 'code', 'unauthenticated')),
    );
  });

  test('رمز غلط، کدِ invalid_credentials می‌دهد', () async {
    await expectLater(
      api.login(username: '__no_such_user__', password: 'x'),
      throwsA(isA<ApiException>().having(
          (e) => e.code, 'code', anyOf('invalid_credentials', 'too_many_attempts'))),
    );
  });

  group('با ورود واقعی', () {
    setUp(() async {
      if (password == null) return;
      final result = await api.login(
        username: username,
        password: password,
        deviceName: 'تست خودکار',
        platform: 'android',
      );
      api.token = result.token;
    });

    tearDown(() async {
      if (password == null) return;
      // توکنِ تست را باطل کن تا در جدول جا نماند
      try {
        await api.logout();
      } catch (_) {}
    });

    test('مسیر کاملِ پول: ثبت، خواندن، ویرایش، حذف', () async {
      if (password == null) {
        markTestSkipped('LIVE_PASS تعریف نشده');
        return;
      }

      final id = await api.createTransaction(
        type: 'expense',
        amount: 12500,
        title: '__تست اپ__',
        dateIso: (await api.ping())['today']['iso'] as String,
        note: 'از تست خودکار',
      );
      expect(id, greaterThan(0));

      final tx = await api.transaction(id);
      expect(tx.amount, 12500);
      // مبلغ باید int باشد، نه double — با BIGINT سرور می‌خواند
      expect(tx.amount, isA<int>());
      expect(tx.title, '__تست اپ__');
      expect(tx.date.jalali, isNotEmpty);

      await api.updateTransaction(
        id: id,
        type: 'income',
        amount: 4000,
        title: '__تست ویرایش__',
        dateIso: tx.date.iso,
      );

      final after = await api.transaction(id);
      expect(after.type, 'income');
      expect(after.amount, 4000);

      await api.deleteTransaction(id);

      await expectLater(
        api.transaction(id),
        throwsA(isA<ApiException>().having((e) => e.code, 'code', 'not_found')),
      );
    });

    test('داشبورد و حساب‌ها و دسته‌ها شکل درست دارند', () async {
      if (password == null) {
        markTestSkipped('LIVE_PASS تعریف نشده');
        return;
      }

      final d = await api.dashboard();
      expect(d.income, isA<int>());
      expect(d.totalBalance, isA<int>());
      expect(d.net, d.income - d.expense);

      final w = await api.wallets();
      expect(w.totalBalance, isA<int>());
      // شماره‌ی کارت و شبا نباید در فهرست حساب‌ها بیایند — حساس‌اند
      // و سرور عمداً نمی‌فرستدشان.

      final c = await api.categories(type: 'expense');
      expect(c.every((e) => e.type == 'expense'), isTrue);
    });
  });
}
