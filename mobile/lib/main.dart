/// دفتر مالی — اپ موبایل.
///
/// روی `api/v1` می‌نشیند و هیچ منطق مالی‌ای در خودش ندارد: جمع‌ها،
/// موجودی، و تبدیل تاریخ همه سمت سرور انجام می‌شوند. دلیلش این است که
/// وب و اپ نباید دو حساب متفاوت بدهند.
library;

import 'dart:io' show Platform;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';

import 'api/api_client.dart';
import 'api/auth_store.dart';
import 'screens/home_screen.dart';
import 'screens/lock_screen.dart';
import 'screens/login_screen.dart';
import 'security/app_lock.dart';
import 'theme.dart';

/// آدرس سرور. با `--dart-define=BASE_URL=...` هنگام build عوض می‌شود،
/// تا برای آزمودن روی سرور محلی لازم نباشد کد را دست بزنیم.
const kBaseUrl = String.fromEnvironment(
  'BASE_URL',
  defaultValue: 'https://hesab.stland.ir',
);

void main() {
  final api = ApiClient(baseUrl: kBaseUrl);
  runApp(DaftarApp(auth: AuthStore(api: api), lock: AppLock()));
}

class DaftarApp extends StatefulWidget {
  final AuthStore auth;
  final AppLock lock;

  const DaftarApp({super.key, required this.auth, required this.lock});

  @override
  State<DaftarApp> createState() => _DaftarAppState();
}

class _DaftarAppState extends State<DaftarApp> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    widget.auth.addListener(_onAuthChanged);
    widget.lock.addListener(_onAuthChanged);
    widget.auth.restore();
    widget.lock.restore();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    widget.auth.removeListener(_onAuthChanged);
    widget.lock.removeListener(_onAuthChanged);
    super.dispose();
  }

  /// ⚠ `inactive` هم شمرده می‌شود، نه فقط `paused`.
  ///
  /// روی iOS وقتی کاربر اپ را به بالا می‌کشد تا در فهرست اپ‌های باز
  /// ببیندش، حالت `inactive` است نه `paused` — و همان‌جا سیستم از صفحه
  /// یک عکس می‌گیرد که در همان فهرست دیده می‌شود. اگر فقط `paused` را
  /// می‌شمردیم، موجودی کاربر در آن عکسِ کوچک می‌ماند.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    switch (state) {
      case AppLifecycleState.inactive:
      case AppLifecycleState.paused:
      case AppLifecycleState.hidden:
      case AppLifecycleState.detached:
        widget.lock.onPaused();
      case AppLifecycleState.resumed:
        widget.lock.onResumed();
    }
  }

  void _onAuthChanged() => setState(() {});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'دفتر مالی',
      debugShowCheckedModeBanner: false,
      theme: buildTheme(Brightness.light),
      darkTheme: buildTheme(Brightness.dark),
      // کل اپ راست‌به‌چپ است — نه فقط متن‌ها، بلکه چیدمان هم.
      locale: const Locale('fa', 'IR'),
      supportedLocales: const [Locale('fa', 'IR')],
      builder: (context, child) => Directionality(
        textDirection: TextDirection.rtl,
        child: child ?? const SizedBox.shrink(),
      ),
      home: AuthGate(auth: widget.auth, lock: widget.lock),
    );
  }
}

/// تصمیم می‌گیرد کاربر صفحه‌ی ورود را ببیند، صفحه‌ی قفل را، یا خودِ اپ را.
///
/// ترتیب مهم است و عمدی: **ورود مقدم بر قفل است.** قفل چیزی است که
/// کاربرِ واردشده برای خودش گذاشته؛ نشان دادنش به کسی که اصلاً وارد نشده
/// بی‌معنا است و فقط او را از صفحه‌ی ورود دور می‌کند.
class AuthGate extends StatelessWidget {
  final AuthStore auth;
  final AppLock lock;

  const AuthGate({super.key, required this.auth, required this.lock});

  @override
  Widget build(BuildContext context) {
    // هر دو، نه فقط auth: تا وقتی وضعیتِ قفل معلوم نشده نباید هیچ
    // داده‌ای رندر شود.
    if (auth.loading || lock.loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (!auth.isLoggedIn) return LoginScreen(auth: auth);
    if (lock.locked) {
      return LockScreen(
        lock: lock,
        onLogout: () async {
          await auth.logout();
          lock.reset();
        },
      );
    }
    return HomeScreen(auth: auth, lock: lock);
  }
}

/// نامِ سکو، برای اینکه کاربر در فهرست دستگاه‌ها بفهمد کدام است.
String currentPlatform() {
  if (kIsWeb) return 'web';
  if (Platform.isAndroid) return 'android';
  if (Platform.isIOS) return 'ios';
  return 'desktop';
}
