/// تنظیمات اپ.
///
/// عمداً فقط چیزهایی اینجاست که **مالِ همین دستگاه** است. هر تنظیمی که
/// به حساب کاربر مربوط باشد (ایمیل، رمز، بخش معاملات) سمت سرور است و
/// جایش نسخه‌ی وب است — وگرنه دو جا برای یک چیز می‌شود و دیر یا زود از
/// هم دور می‌افتند.
library;

import 'package:flutter/material.dart';

import '../api/auth_store.dart';
import '../security/app_lock.dart';

class SettingsScreen extends StatelessWidget {
  final AuthStore auth;
  final AppLock lock;

  const SettingsScreen({super.key, required this.auth, required this.lock});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('تنظیمات')),
      body: ListenableBuilder(
        listenable: lock,
        builder: (context, _) => ListView(
          children: [
            if (auth.user != null)
              ListTile(
                leading: const Icon(Icons.person_outline),
                title: Text(auth.user!.fullName),
                subtitle: Text(auth.user!.username),
              ),
            const Divider(),
            _LockTile(lock: lock),
            const Padding(
              padding: EdgeInsets.fromLTRB(16, 4, 16, 16),
              child: Text(
                'قفل فقط جلوی این اپ روی همین گوشی را می‌گیرد. خارج شدن از '
                'حساب نیست و اطلاعاتی پاک نمی‌کند.',
                style: TextStyle(fontSize: 12.5, height: 1.6),
              ),
            ),
            const Divider(),
            ListTile(
              leading: const Icon(Icons.logout),
              title: const Text('خروج از حساب'),
              subtitle: const Text('فقط از این دستگاه'),
              onTap: () async {
                final ok = await showDialog<bool>(
                  context: context,
                  builder: (ctx) => AlertDialog(
                    title: const Text('خروج از حساب'),
                    content: const Text(
                        'از این دستگاه خارج می‌شوید. دستگاه‌های دیگر '
                        'دست‌نخورده می‌مانند.'),
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
                if (ok != true) return;
                await auth.logout();
                lock.reset();
              },
            ),
          ],
        ),
      ),
    );
  }
}

class _LockTile extends StatelessWidget {
  final AppLock lock;

  const _LockTile({required this.lock});

  @override
  Widget build(BuildContext context) {
    // گوشیِ بدون حسگر و بدون رمز، نمی‌تواند قفل داشته باشد. کلیدِ
    // خاکستری بدون توضیح، کاربر را سردرگم می‌کند — پس دلیلش نوشته می‌شود.
    if (!lock.supported) {
      return const ListTile(
        leading: Icon(Icons.fingerprint),
        title: Text('قفل اپ'),
        subtitle: Text(
            'برای این گوشی در دسترس نیست — اول در تنظیمات گوشی رمز یا '
            'اثر انگشت بگذارید.'),
        enabled: false,
      );
    }

    return SwitchListTile(
      secondary: const Icon(Icons.fingerprint),
      title: const Text('قفل اپ'),
      subtitle: const Text('هنگام باز کردن، اثر انگشت یا رمز گوشی بپرسد'),
      value: lock.enabled,
      onChanged: lock.busy
          ? null
          : (want) async {
              if (!want) {
                await lock.disable();
                return;
              }
              final outcome = await lock.enable();
              if (!context.mounted) return;
              final message = switch (outcome) {
                UnlockOutcome.success => 'قفل روشن شد.',
                UnlockOutcome.canceled => null,
                UnlockOutcome.unavailable =>
                  'تأیید هویت روی این گوشی در دسترس نیست.',
                UnlockOutcome.failed => 'تأیید نشد؛ قفل روشن نشد.',
              };
              if (message != null) {
                ScaffoldMessenger.of(context)
                    .showSnackBar(SnackBar(content: Text(message)));
              }
            },
    );
  }
}
