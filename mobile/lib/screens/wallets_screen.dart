import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../models/models.dart';
import '../theme.dart';
import '../widgets/format.dart';
import 'home_screen.dart' show ErrorView;

class WalletsScreen extends StatefulWidget {
  final ApiClient api;
  final int revision;

  const WalletsScreen({super.key, required this.api, required this.revision});

  @override
  State<WalletsScreen> createState() => _WalletsScreenState();
}

class _WalletsScreenState extends State<WalletsScreen> {
  late Future<WalletList> _future = widget.api.wallets();

  @override
  void didUpdateWidget(WalletsScreen old) {
    super.didUpdateWidget(old);
    if (old.revision != widget.revision) _reload();
  }

  void _reload() => setState(() => _future = widget.api.wallets());

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () async => _reload(),
      child: FutureBuilder<WalletList>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snap.hasError) {
            return ErrorView(error: snap.error, onRetry: _reload);
          }

          final data = snap.data!;
          return ListView(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 96),
            children: [
              Card(
                color: Theme.of(context).colorScheme.primaryContainer,
                child: ListTile(
                  title: const Text('مجموع حساب‌ها'),
                  trailing: Text(
                    formatToman(data.totalBalance),
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, fontSize: 16),
                  ),
                ),
              ),
              const SizedBox(height: 12),
              ...data.items.map((w) => Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: _WalletTile(wallet: w),
                  )),
              if (data.items.isEmpty)
                const Padding(
                  padding: EdgeInsets.all(32),
                  child: Center(child: Text('حسابی ثبت نشده است.')),
                ),
            ],
          );
        },
      ),
    );
  }
}

class _WalletTile extends StatelessWidget {
  final Wallet wallet;

  const _WalletTile({required this.wallet});

  /// رنگِ حساب از سرور می‌آید (`#RRGGBB`). اگر نبود یا خراب بود، رنگِ
  /// پیش‌فرضِ تم — نه اینکه اپ بشکند.
  Color _color(BuildContext context) {
    final raw = wallet.color;
    if (raw != null && RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch(raw)) {
      return Color(int.parse('FF${raw.substring(1)}', radix: 16));
    }
    return Theme.of(context).colorScheme.primary;
  }

  @override
  Widget build(BuildContext context) {
    final color = _color(context);

    return Card(
      child: ListTile(
        leading: Container(
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            color: color.withValues(alpha: 0.15),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Icon(
            wallet.kind == 'cash'
                ? Icons.payments_outlined
                : Icons.credit_card_outlined,
            color: color,
          ),
        ),
        title: Text(wallet.name),
        subtitle: Text([
          wallet.kindText,
          if (wallet.bankName != null && wallet.bankName!.isNotEmpty)
            wallet.bankName!,
        ].join(' · ')),
        trailing: Text(
          formatMoney(wallet.balance),
          style: TextStyle(
            fontWeight: FontWeight.bold,
            // موجودی منفی باید فوراً دیده شود
            color: amountColor(context, isIncome: wallet.balance >= 0),
          ),
        ),
      ),
    );
  }
}
