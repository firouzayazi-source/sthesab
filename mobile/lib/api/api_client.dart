/// کلاینت api/v1.
///
/// همه‌ی ارتباط با سرور از همین‌جا می‌گذرد. هیچ صفحه‌ای مستقیم http
/// نمی‌زند — وگرنه قواعدِ پاکت پاسخ و خطا و توکن در ده جا تکرار می‌شوند و
/// دیر یا زود یکی جا می‌افتد.
library;

import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../models/models.dart';

/// خطای API — همیشه یک `code` ماشین‌خوان دارد.
///
/// ⚠ اپ باید روی `code` تصمیم بگیرد، نه روی `message`. متن پیام فارسیِ
/// قابل نمایش است و ممکن است سرور اصلاحش کند؛ اگر منطق به متن گره بخورد،
/// اصلاح یک غلط املایی اپ را می‌شکند.
class ApiException implements Exception {
  final String code;
  final String message;
  final int? status;

  const ApiException(this.code, this.message, {this.status});

  /// توکن نامعتبر یا منقضی — اپ باید کاربر را به صفحه‌ی ورود ببرد.
  bool get isUnauthenticated => code == 'unauthenticated';

  /// شبکه اصلاً در دسترس نبود (نه اینکه سرور خطا داده باشد).
  bool get isOffline => code == 'offline';

  @override
  String toString() => message;
}

/// شکلِ آدرسی که با آن حرف می‌زنیم.
enum _UrlStyle {
  /// `/api/v1/transactions` — به قاعده‌ی rewrite در nginx نیاز دارد
  clean,

  /// `/api/v1/index.php?p=transactions` — همیشه کار می‌کند
  query,
}

class ApiClient {
  /// ریشه‌ی سایت، بدون `/` انتهایی. مثال: `https://hesab.stland.ir`
  final String baseUrl;
  final http.Client _http;

  /// توکن جاری. `AuthStore` آن را می‌گذارد و برمی‌دارد.
  String? token;

  /// وقتی توکن رد شد صدا زده می‌شود — اپ با آن به صفحه‌ی ورود می‌رود.
  void Function()? onUnauthenticated;

  /// شکل آدرسی که ثابت شده کار می‌کند. تا وقتی معلوم نشده `null` است.
  _UrlStyle? _style;

  ApiClient({required String baseUrl, http.Client? httpClient})
      : baseUrl = baseUrl.replaceAll(RegExp(r'/+$'), ''),
        _http = httpClient ?? http.Client();

  void close() => _http.close();

  // ---------------------------------------------------------------
  // اندپوینت‌ها
  // ---------------------------------------------------------------

  /// بدون توکن — برای سنجش دسترسی و نسخه‌ی سرور.
  Future<Map<String, dynamic>> ping() async =>
      await _send('GET', 'ping', auth: false);

  Future<AuthResult> login({
    required String username,
    required String password,
    String? deviceName,
    String? platform,
  }) async {
    final data = await _send('POST', 'auth/login', auth: false, body: {
      'username': username,
      'password': password,
      'device_name': ?deviceName,
      'platform': ?platform,
    });
    return AuthResult.fromJson(data);
  }

  /// فقط توکنِ همین دستگاه را باطل می‌کند.
  Future<void> logout() async => await _send('POST', 'auth/logout');

  Future<User> me() async => User.fromJson(await _send('GET', 'me'));

  Future<Dashboard> dashboard({String? from, String? to}) async =>
      Dashboard.fromJson(await _send('GET', 'dashboard', query: {
        'from': ?from,
        'to': ?to,
      }));

  Future<TransactionPage> transactions({
    String? from,
    String? to,
    String? type,
    int? categoryId,
    int? walletId,
    int page = 1,
    int perPage = 50,
  }) async =>
      TransactionPage.fromJson(await _send('GET', 'transactions', query: {
        'from': ?from,
        'to': ?to,
        'type': ?type,
        if (categoryId != null) 'category_id': '$categoryId',
        if (walletId != null) 'wallet_id': '$walletId',
        'page': '$page',
        'per_page': '$perPage',
      }));

  Future<Transaction> transaction(int id) async =>
      Transaction.fromJson(await _send('GET', 'transactions/$id'));

  /// شناسه‌ی تراکنشِ تازه را برمی‌گرداند.
  Future<int> createTransaction({
    required String type,
    required int amount,
    required String title,
    required String dateIso,
    String? note,
    int? categoryId,
    int? walletId,
  }) async {
    final data = await _send('POST', 'transactions', body: {
      'type': type,
      'amount': amount,
      'title': title,
      'transaction_date': dateIso,
      if (note != null && note.isNotEmpty) 'note': note,
      'category_id': ?categoryId,
      'wallet_id': ?walletId,
    });
    return (data['id'] as num?)?.toInt() ?? 0;
  }

  /// ⚠ حساب را عوض نمی‌کند — سرور عمداً `wallet_id` را در ویرایش
  /// نمی‌پذیرد، دقیقاً مثل فرم ویرایشِ سایت.
  Future<void> updateTransaction({
    required int id,
    required String type,
    required int amount,
    required String title,
    required String dateIso,
    String? note,
    int? categoryId,
  }) async {
    await _send('PATCH', 'transactions/$id', body: {
      'type': type,
      'amount': amount,
      'title': title,
      'transaction_date': dateIso,
      'note': ?note,
      'category_id': ?categoryId,
    });
  }

  Future<void> deleteTransaction(int id) async =>
      await _send('DELETE', 'transactions/$id');

  Future<WalletList> wallets() async =>
      WalletList.fromJson(await _send('GET', 'wallets'));

  Future<List<Category>> categories({String? type}) async {
    final data = await _send('GET', 'categories',
        query: {'type': ?type});
    return (data['items'] as List? ?? [])
        .whereType<Map>()
        .map((e) => Category.fromJson(e.cast<String, dynamic>()))
        .toList();
  }

  // ---------------------------------------------------------------
  // لایه‌ی انتقال
  // ---------------------------------------------------------------

  Uri _uri(_UrlStyle style, String path, Map<String, String> query) {
    return switch (style) {
      _UrlStyle.clean => Uri.parse('$baseUrl/api/v1/$path')
          .replace(queryParameters: query.isEmpty ? null : query),
      // `p` نامِ رزروشده‌ی مسیر است؛ بقیه‌ی پارامترها کنارش می‌آیند.
      _UrlStyle.query => Uri.parse('$baseUrl/api/v1/index.php')
          .replace(queryParameters: {'p': path, ...query}),
    };
  }

  /// یک درخواست، با تشخیصِ خودکارِ شکل آدرس.
  ///
  /// **چرا این پیچیدگی هست:** آدرس تمیز (`/api/v1/ping`) به یک قاعده‌ی
  /// rewrite در nginx نیاز دارد. آن قاعده ممکن است اصلاً اعمال نشده باشد،
  /// یا روزی با بازنویسیِ فایل سایت (مثلاً توسط certbot) از دست برود.
  /// اپی که روی گوشی کاربر نصب شده را نمی‌شود مجبور به به‌روزرسانی کرد،
  /// پس نباید به یک خط پیکربندیِ سرور وابسته باشد.
  ///
  /// بار اول هر دو امتحان می‌شوند و نتیجه نگه داشته می‌شود، تا درخواست‌های
  /// بعدی یک رفت‌وبرگشتِ اضافه ندهند.
  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, String> query = const {},
    Map<String, Object?>? body,
    bool auth = true,
  }) async {
    final styles = _style != null
        ? [_style!]
        : [_UrlStyle.clean, _UrlStyle.query];

    ApiException? lastRoutingError;

    for (final style in styles) {
      final http.Response res;
      try {
        res = await _perform(method, _uri(style, path, query), body, auth);
      } on ApiException {
        rethrow;
      }

      final decoded = _decode(res);

      // پاسخی که JSON نیست یعنی درخواست اصلاً به API نرسیده — تقریباً
      // همیشه صفحه‌ی ۴۰۴ خودِ nginx است، یعنی این شکلِ آدرس کار نمی‌کند.
      if (decoded == null) {
        lastRoutingError = ApiException(
          'bad_gateway_shape',
          'پاسخ سرور JSON نبود (کد ${res.statusCode}).',
          status: res.statusCode,
        );
        continue; // شکل بعدی را امتحان کن
      }

      // این شکل جواب داد — از این به بعد همین را استفاده کن
      _style = style;
      return _unwrap(decoded, res.statusCode);
    }

    throw lastRoutingError ??
        const ApiException('unreachable', 'سرور در دسترس نیست.');
  }

  Future<http.Response> _perform(
    String method,
    Uri uri,
    Map<String, Object?>? body,
    bool auth,
  ) async {
    final headers = <String, String>{
      'Accept': 'application/json',
      if (body != null) 'Content-Type': 'application/json; charset=utf-8',
      if (auth && token != null) 'Authorization': 'Bearer $token',
    };
    final payload = body == null ? null : jsonEncode(body);

    try {
      final request = http.Request(method, uri)
        ..headers.addAll(headers)
        ..followRedirects = true;
      if (payload != null) request.body = payload;

      final streamed = await _http.send(request).timeout(
            const Duration(seconds: 20),
          );
      return await http.Response.fromStream(streamed);
    } on TimeoutException {
      throw const ApiException('offline', 'سرور پاسخ نداد. اینترنت را بررسی کنید.');
    } catch (_) {
      throw const ApiException('offline', 'اتصال به سرور برقرار نشد.');
    }
  }

  Map<String, dynamic>? _decode(http.Response res) {
    if (res.bodyBytes.isEmpty) return null;
    try {
      final decoded = jsonDecode(utf8.decode(res.bodyBytes));
      return decoded is Map ? decoded.cast<String, dynamic>() : null;
    } catch (_) {
      return null;
    }
  }

  /// پاکتِ `{ok, data}` یا `{ok:false, error:{code,message}}` را باز می‌کند.
  Map<String, dynamic> _unwrap(Map<String, dynamic> json, int status) {
    if (json['ok'] == true) {
      final data = json['data'];
      if (data is Map) return data.cast<String, dynamic>();
      return <String, dynamic>{};
    }

    final error = (json['error'] as Map? ?? const {}).cast<String, dynamic>();
    final ex = ApiException(
      error['code'] as String? ?? 'unknown',
      error['message'] as String? ?? 'خطای ناشناخته از سرور.',
      status: status,
    );

    if (ex.isUnauthenticated) onUnauthenticated?.call();
    throw ex;
  }
}
