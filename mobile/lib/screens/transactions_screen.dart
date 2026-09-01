import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../models/models.dart';
import '../theme.dart';
import '../widgets/format.dart';
import 'home_screen.dart' show ErrorView;
import 'transaction_form.dart';

class TransactionsScreen extends StatefulWidget {
  final ApiClient api;
  final int revision;
  final VoidCallback onChanged;

  const TransactionsScreen({
    super.key,
    required this.api,
    required this.revision,
    required this.onChanged,
  });

  @override
  State<TransactionsScreen> createState() => _TransactionsScreenState();
}

class _TransactionsScreenState extends State<TransactionsScreen> {
  final _scroll = ScrollController();
  final _items = <Transaction>[];

  /// `null` یعنی هر دو نوع
  String? _type;

  int _page = 1;
  bool _loading = false;
  bool _hasMore = true;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
    _load(reset: true);
  }

  @override
  void didUpdateWidget(TransactionsScreen old) {
    super.didUpdateWidget(old);
    if (old.revision != widget.revision) _load(reset: true);
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  void _onScroll() {
    // کمی زودتر از ته فهرست شروع به گرفتن صفحه‌ی بعد می‌کنیم تا کاربر
    // منتظر نماند.
    if (_scroll.position.pixels >= _scroll.position.maxScrollExtent - 300) {
      _load();
    }
  }

  Future<void> _load({bool reset = false}) async {
    if (_loading) return;
    if (!reset && !_hasMore) return;

    setState(() {
      _loading = true;
      if (reset) _error = null;
    });

    try {
      final page = reset ? 1 : _page;
      final result = await widget.api.transactions(
        type: _type,
        page: page,
        perPage: 30,
      );

      if (!mounted) return;
      setState(() {
        if (reset) _items.clear();
        _items.addAll(result.items);
        _page = result.page.number + 1;
        _hasMore = result.page.hasMore;
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _openForm([Transaction? tx]) async {
    final saved = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => TransactionForm(api: widget.api, existing: tx),
      ),
    );
    if (saved == true) widget.onChanged();
  }

  Future<void> _delete(Transaction tx) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('حذف تراکنش'),
        content: Text('«${tx.title}» حذف شود؟'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('انصراف')),
          FilledButton(
            style: FilledButton.styleFrom(
                backgroundColor: Theme.of(ctx).colorScheme.error),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('حذف'),
          ),
        ],
      ),
    );
    if (ok != true) return;

    try {
      await widget.api.deleteTransaction(tx.id);
      widget.onChanged();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _items.isEmpty) {
      return ErrorView(error: _error, onRetry: () => _load(reset: true));
    }

    return Column(
      children: [
        _FilterBar(
          type: _type,
          onChanged: (t) {
            setState(() => _type = t);
            _load(reset: true);
          },
        ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: () => _load(reset: true),
            child: _items.isEmpty && !_loading
                ? ListView(
                    children: const [
                      SizedBox(height: 120),
                      Center(child: Text('هنوز تراکنشی ثبت نشده است.')),
                    ],
                  )
                : ListView.separated(
                    controller: _scroll,
                    padding: const EdgeInsets.fromLTRB(12, 8, 12, 96),
                    itemCount: _items.length + (_hasMore ? 1 : 0),
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) {
                      if (i >= _items.length) {
                        return const Padding(
                          padding: EdgeInsets.all(16),
                          child: Center(child: CircularProgressIndicator()),
                        );
                      }
                      final tx = _items[i];
                      return _TransactionTile(
                        tx: tx,
                        onTap: () => _openForm(tx),
                        onDelete: () => _delete(tx),
                      );
                    },
                  ),
          ),
        ),
      ],
    );
  }
}

class _FilterBar extends StatelessWidget {
  final String? type;
  final ValueChanged<String?> onChanged;

  const _FilterBar({required this.type, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
      child: SegmentedButton<String?>(
        segments: const [
          ButtonSegment(value: null, label: Text('همه')),
          ButtonSegment(value: 'income', label: Text('درآمد')),
          ButtonSegment(value: 'expense', label: Text('هزینه')),
        ],
        selected: {type},
        onSelectionChanged: (s) => onChanged(s.first),
      ),
    );
  }
}

class _TransactionTile extends StatelessWidget {
  final Transaction tx;
  final VoidCallback onTap;
  final VoidCallback onDelete;

  const _TransactionTile({
    required this.tx,
    required this.onTap,
    required this.onDelete,
  });

  @override
  Widget build(BuildContext context) {
    final color = amountColor(context, isIncome: tx.isIncome);

    return Card(
      child: ListTile(
        onTap: onTap,
        onLongPress: onDelete,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        leading: CircleAvatar(
          backgroundColor: color.withValues(alpha: 0.12),
          child: Text(
            tx.category?.icon ?? (tx.isIncome ? '↑' : '↓'),
            style: TextStyle(color: color, fontSize: 18),
          ),
        ),
        title: Text(tx.title, maxLines: 1, overflow: TextOverflow.ellipsis),
        subtitle: Text(
          [
            tx.date.jalali,
            if (tx.category != null) tx.category!.name,
            if (tx.wallet != null) tx.wallet!.name,
          ].join(' · '),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
        trailing: Text(
          formatMoney(tx.isIncome ? tx.amount : -tx.amount),
          style: TextStyle(
              color: color, fontWeight: FontWeight.bold, fontSize: 15),
        ),
      ),
    );
  }
}
