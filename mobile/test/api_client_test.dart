/// تست کلاینت با یک سرور ساختگی.
///
/// اینجا رفتارِ *لایه‌ی انتقال* سنجیده می‌شود: پاکت پاسخ، خطاها، توکن، و
/// مهم‌تر از همه **تشخیص خودکارِ شکل آدرس**. تستِ در برابرِ API واقعی
/// جداست (test/live_api_test.dart) و آن یکی قراردادِ سرور را می‌سنجد.
library;

import 'dart:convert';

import 'package:daftar/api/api_client.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// سروری که هر درخواست را ثبت می‌کند و پاسخ دلخواه می‌دهد.
class _Fake {
  final List<Uri> seen = [];
  final List<Map<String, String>> headers = [];

  /// اگر true باشد، آدرسِ تمیز صفحه‌ی ۴۰۴ـِ nginx می‌دهد (یعنی قاعده‌ی
  /// rewrite روی سرور نیست) — همان چیزی که واقعاً روی سرور اتفاق افتاد.
  bool cleanUrlBroken = false;

  Map<String, Object?> body = {'ok': true, 'data': {}};
  int status = 200;

  http.Client client() => MockClient((req) async {
        seen.add(req.url);
        headers.add(req.headers);

        final isClean = !req.url.path.endsWith('index.php');
        if (cleanUrlBroken && isClean) {
          return http.Response(
            '<html><head><title>404 Not Found</title></head></html>',
            404,
            headers: {'content-type': 'text/html'},
          );
        }

        return http.Response(
          jsonEncode(body),
          status,
          headers: {'content-type': 'application/json; charset=utf-8'},
        );
      });
}

void main() {
  group('پاکت پاسخ', () {
    test('data را باز می‌کند', () async {
      final fake = _Fake()
        ..body = {
          'ok': true,
          'data': {'api_version': 1}
        };
      final api = ApiClient(baseUrl: 'https://x.test', httpClient: fake.client());

      expect(await api.ping(), {'api_version': 1});
    });

    test('خطا را با code پرتاب می‌کند', () async {
      final fake = _Fake()
        ..status = 401
        ..body = {
          'ok': false,
          'error': {'code': 'invalid_credentials', 'message': 'رمز اشتباه است.'}
        };
      final api = ApiClient(baseUrl: 'https://x.test', httpClient: fake.client());

      await expectLater(
        api.login(username: 'a', password: 'b'),
        throwsA(isA<ApiException>()
            .having((e) => e.code, 'code', 'invalid_credentials')
            .having((e) => e.message, 'message', 'رمز اشتباه است.')),
      );
    });

    test('توکنِ رد شده onUnauthenticated را صدا می‌زند', () async {
      final fake = _Fake()
        ..status = 401
        ..body = {
          'ok': false,
          'error': {'code': 'unauthenticated', 'message': 'وارد شوید.'}
        };
      final api = ApiClient(baseUrl: 'https://x.test', httpClient: fake.client());

      var called = false;
      api.onUnauthenticated = () => called = true;

      await expectLater(api.me(), throwsA(isA<ApiException>()));
      expect(called, isTrue);
    });
  });

  group('توکن', () {
    test('وقتی هست به شکل Bearer می‌رود', () async {
      final fake = _Fake();
      final api = ApiClient(baseUrl: 'https://x.test', httpClient: fake.client())
        ..token = 'sel.val';

      await api.me();
      expect(fake.headers.last['Authorization'], 'Bearer sel.val');
    });

    test('روی login و ping فرستاده نمی‌شود', () async {
      final fake = _Fake()
        ..body = {
          'ok': true,
          'data': {'token': 't', 'expires_in_days': 90, 'user': {}}
        };
      final api = ApiClient(baseUrl: 'https://x.test', httpClient: fake.client())
        ..token = 'old.token';

      await api.login(username: 'a', password: 'b');
      expect(fake.headers.last.containsKey('Authorization'), isFalse);
    });
  });

  group('تشخیص شکل آدرس', () {
    test('اگر آدرس تمیز کار کند، همان استفاده می‌شود', () async {
      final fake = _Fake();
      final api = ApiClient(baseUrl: 'https://x.test', httpClient: fake.client());

      await api.ping();
      expect(fake.seen.single.path, '/api/v1/ping');
    });

    test('اگر آدرس تمیز ۴۰۴ـِ HTML بدهد، خودکار به ?p= می‌افتد', () async {
      // این دقیقاً همان حالتی است که روی سرور واقعی پیش آمد: قاعده‌ی
      // rewrite در nginx نبود و /api/v1/ping صفحه‌ی ۴۰۴ می‌داد.
      final fake = _Fake()..cleanUrlBroken = true;
      final api = ApiClient(baseUrl: 'https://x.test', httpClient: fake.client());

      await api.ping();

      expect(fake.seen.length, 2, reason: 'اول تمیز، بعد ?p=');
      expect(fake.seen.first.path, '/api/v1/ping');
      expect(fake.seen.last.path, '/api/v1/index.php');
      expect(fake.seen.last.queryParameters['p'], 'ping');
    });

    test('شکلِ کارآمد نگه داشته می‌شود و دوباره امتحان نمی‌شود', () async {
      final fake = _Fake()..cleanUrlBroken = true;
      final api = ApiClient(baseUrl: 'https://x.test', httpClient: fake.client());

      await api.ping();
      fake.seen.clear();
      await api.ping();

      expect(fake.seen.length, 1, reason: 'بار دوم نباید تمیز را دوباره بزند');
      expect(fake.seen.single.queryParameters['p'], 'ping');
    });

    test('پارامترها کنار ?p= درست می‌آیند', () async {
      final fake = _Fake()
        ..cleanUrlBroken = true
        ..body = {
          'ok': true,
          'data': {'items': [], 'page': {}}
        };
      final api = ApiClient(baseUrl: 'https://x.test', httpClient: fake.client());

      await api.transactions(type: 'expense', page: 2, perPage: 10);

      final q = fake.seen.last.queryParameters;
      expect(q['p'], 'transactions');
      expect(q['type'], 'expense');
      expect(q['page'], '2');
      expect(q['per_page'], '10');
    });
  });

  test('باز کردن ریشه‌ی آدرس، اسلشِ اضافه را می‌اندازد', () {
    expect(ApiClient(baseUrl: 'https://x.test/').baseUrl, 'https://x.test');
    expect(ApiClient(baseUrl: 'https://x.test///').baseUrl, 'https://x.test');
  });
}
