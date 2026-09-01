/// صفحه‌ای که وقتی قفل روشن است جلوی اپ می‌ایستد.
///
/// عمداً هیچ داده‌ای نشان نمی‌دهد — نه موجودی، نه آخرین تراکنش. کل دلیلِ
/// وجودِ قفل همین است که کسی که گوشیِ باز را دستش گرفته چیزی نبیند.
library;

import 'package:flutter/material.dart';

import '../security/app_lock.dart';

class LockScreen extends StatefulWidget {
  final AppLock lock;

  /// راه خروجِ اضطراری. اگر احراز هویت روی این گوشی خراب شود، کاربر
  /// نباید برای همیشه گیر کند.
  final Future<void> Function() onLogout;

  const LockScreen({super.key, required this.lock, required this.onLogout});

  @override
  State<LockScreen> createState() => _LockScreenState();
}

class _LockScreenState extends State<LockScreen> {
  String? _message;

  @override
  void initState() {
    super.initState();
    // بدون این، کاربر باید یک دکمه بزند تا تازه پنجره‌ی اثر انگشت
    // بیاید — یک ضربه‌ی اضافه در پرتکرارترین مسیر اپ.
    WidgetsBinding.instance.addPostFrameCallback((_) => _tryUnlock());
  }

  Future<void> _tryUnlock() async {
    final outcome = await widget.lock.unlock();
    if (!mounted) return;
    setState(() {
      _message = switch (outcome) {
        UnlockOutcome.success => null,
        // انصراف را کاربر خودش زده؛ گفتنش به او توهین است.
        UnlockOutcome.canceled => null,
        UnlockOutcome.unavailable =>
          'قفل روی این گوشی دیگر در دسترس نیست و خاموش شد.',
        UnlockOutcome.failed => 'تأیید نشد. دوباره تلاش کنید.',
      };
    });
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(32),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(Icons.lock_outline, size: 56, color: scheme.primary),
                const SizedBox(height: 20),
                Text('دفتر مالی',
                    style: Theme.of(context).textTheme.headlineSmall),
                const SizedBox(height: 8),
                Text(
                  'برای دیدن اطلاعات، هویتتان را تأیید کنید.',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
                if (_message != null) ...[
                  const SizedBox(height: 16),
                  Text(
                    _message!,
                    textAlign: TextAlign.center,
                    style: TextStyle(color: scheme.error),
                  ),
                ],
                const SizedBox(height: 28),
                ListenableBuilder(
                  listenable: widget.lock,
                  builder: (context, _) => FilledButton.icon(
                    onPressed: widget.lock.busy ? null : _tryUnlock,
                    icon: const Icon(Icons.fingerprint),
                    label: const Text('باز کردن'),
                  ),
                ),
                const SizedBox(height: 12),
                TextButton(
                  onPressed: () async {
                    final ok = await showDialog<bool>(
                      context: context,
                      builder: (ctx) => AlertDialog(
                        title: const Text('خروج از حساب'),
                        content: const Text(
                            'اگر تأیید هویت کار نمی‌کند، می‌توانید خارج شوید و '
                            'دوباره با نام کاربری و رمز وارد شوید.'),
                        actions: [
                          TextButton(
                              onPressed: () => Navigator.pop(ctx, false),
                              child: const Text('انصراف')),
                          FilledButton(
                              onPressed: () => Navigator.pop(ctx, true),
                              child: const Text('خروج')),
                        ],
                      ),
                    );
                    if (ok == true) await widget.onLogout();
                  },
                  child: const Text('خروج از حساب'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
