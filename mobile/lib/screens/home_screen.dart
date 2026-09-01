import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../api/auth_store.dart';
import '../models/models.dart';
import '../security/app_lock.dart';
import '../theme.dart';
import '../widgets/format.dart';
import 'settings_screen.dart';
import 'transaction_form.dart';
import 'transactions_screen.dart';
import 'wallets_screen.dart';

class HomeScreen extends StatefulWidget {
  final AuthStore auth;
  final AppLock lock;

  const HomeScreen({super.key, required this.auth, required this.lock});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _tab = 0;

  /// شمارنده‌ای که با هر تغییرِ داده بالا می‌رود تا زبانه‌ها تازه شوند.
  ///
  /// بعد از ثبت یا حذف یک تراکنش، هم داشبورد و هم موجودی حساب‌ها عوض
  /// می‌شوند. اگر فقط همان صفحه تازه شود، کاربر به زبانه‌ی بعدی می‌رود و
  /// عدد قدیمی می‌بیند و فکر می‌کند ثبت نشده — همان قاعده‌ای که در وب هم
  /// رعایت شده.
  int _revision = 0;

  void _refreshAll() => setState(() => _revision++);

  ApiClient get _api => widget.auth.api;

  @override
  Widget build(BuildContext context) {
    final pages = [
      DashboardTab(api: _api, revision: _revision),
      TransactionsScreen(api: _api, revision: _revision, onChanged: _refreshAll),
      WalletsScreen(api: _api, revision: _revision),
    ];

    return Scaffold(
      appBar: AppBar(
        title: Text(_tab == 0
            ? 'سلام${widget.auth.user != null ? '، ${widget.auth.user!.fullName}' : ''}'
            : const ['', 'تراکنش‌ها', 'حساب‌ها'][_tab]),
        actions: [
          IconButton(
            tooltip: 'تنظیمات',
            icon: const Icon(Icons.settings_outlined),
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) =>
                    SettingsScreen(auth: widget.auth, lock: widget.lock),
              ),
            ),
          ),
        ],
      ),
      body: IndexedStack(index: _tab, children: pages),
      floatingActionButton: FloatingActionButton(
        onPressed: () async {
          final saved = await Navigator.of(context).push<bool>(
            MaterialPageRoute(builder: (_) => TransactionForm(api: _api)),
          );
          if (saved == true) _refreshAll();
        },
        child: const Icon(Icons.add),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (i) => setState(() => _tab = i),
        destinations: const [
          NavigationDestination(
              icon: Icon(Icons.home_outlined),
              selectedIcon: Icon(Icons.home),
              label: 'خانه'),
          NavigationDestination(
              icon: Icon(Icons.receipt_long_outlined),
              selectedIcon: Icon(Icons.receipt_long),
              label: 'تراکنش‌ها'),
          NavigationDestination(
              icon: Icon(Icons.account_balance_wallet_outlined),
              selectedIcon: Icon(Icons.account_balance_wallet),
              label: 'حساب‌ها'),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------

class DashboardTab extends StatefulWidget {
  final ApiClient api;
  final int revision;

  const DashboardTab({super.key, required this.api, required this.revision});

  @override
  State<DashboardTab> createState() => _DashboardTabState();
}

class _DashboardTabState extends State<DashboardTab> {
  late Future<Dashboard> _future = widget.api.dashboard();

  @override
  void didUpdateWidget(DashboardTab old) {
    super.didUpdateWidget(old);
    if (old.revision != widget.revision) _reload();
  }

  void _reload() => setState(() => _future = widget.api.dashboard());

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () async => _reload(),
      child: FutureBuilder<Dashboard>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snap.hasError) {
            return ErrorView(error: snap.error, onRetry: _reload);
          }

          final d = snap.data!;
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _BalanceCard(total: d.totalBalance),
              const SizedBox(height: 12),
              if (d.from != null && d.to != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8, right: 4),
                  child: Text(
                    'از ${d.from!.jalali} تا ${d.to!.jalali}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ),
              Row(
                children: [
                  Expanded(
                      child: _StatCard(
                          label: 'درآمد',
                          amount: d.income,
                          isIncome: true)),
                  const SizedBox(width: 12),
                  Expanded(
                      child: _StatCard(
                          label: 'هزینه',
                          amount: d.expense,
                          isIncome: false)),
                ],
              ),
              const SizedBox(height: 12),
              Card(
                child: ListTile(
                  title: const Text('خالص این بازه'),
                  subtitle: Text(
                      '${toPersianDigits('${d.transactionCount}')} تراکنش'),
                  trailing: Text(
                    formatMoney(d.net),
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 18,
                      color: amountColor(context, isIncome: d.net >= 0),
                    ),
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _BalanceCard extends StatelessWidget {
  final int total;

  const _BalanceCard({required this.total});

  @override
  Widget build(BuildContext context) {
    return Card(
      color: Theme.of(context).colorScheme.primaryContainer,
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('موجودی کل'),
            const SizedBox(height: 8),
            Text(
              formatToman(total),
              style: const TextStyle(
                  fontSize: 26, fontWeight: FontWeight.bold),
            ),
          ],
        ),
      ),
    );
  }
}

class _StatCard extends StatelessWidget {
  final String label;
  final int amount;
  final bool isIncome;

  const _StatCard({
    required this.label,
    required this.amount,
    required this.isIncome,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: Theme.of(context).textTheme.bodySmall),
            const SizedBox(height: 6),
            Text(
              formatMoney(amount),
              style: TextStyle(
                fontSize: 18,
                fontWeight: FontWeight.bold,
                color: amountColor(context, isIncome: isIncome),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// نمایش خطا با دکمه‌ی تلاش دوباره.
///
/// پیام از `ApiException` می‌آید که خودش متنِ فارسیِ سرور را دارد؛ برای
/// خطاهای دیگر یک متن عمومی نشان می‌دهیم تا کاربر با پیام انگلیسیِ
/// کتابخانه روبه‌رو نشود.
class ErrorView extends StatelessWidget {
  final Object? error;
  final VoidCallback onRetry;

  const ErrorView({super.key, required this.error, required this.onRetry});

  @override
  Widget build(BuildContext context) {
    final message = error is ApiException
        ? (error as ApiException).message
        : 'خطایی رخ داد. دوباره تلاش کنید.';

    return ListView(
      padding: const EdgeInsets.all(32),
      children: [
        const SizedBox(height: 40),
        Icon(Icons.cloud_off,
            size: 48, color: Theme.of(context).colorScheme.outline),
        const SizedBox(height: 16),
        Text(message, textAlign: TextAlign.center),
        const SizedBox(height: 24),
        Center(
          child: OutlinedButton.icon(
            onPressed: onRetry,
            icon: const Icon(Icons.refresh),
            label: const Text('تلاش دوباره'),
          ),
        ),
      ],
    );
  }
}
