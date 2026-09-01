import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../api/api_client.dart';
import '../models/models.dart';
import '../widgets/format.dart';

/// ثبت و ویرایش تراکنش.
///
/// ⚠ در حالت **ویرایش**، حساب نشان داده می‌شود ولی قابل تغییر نیست —
/// چون `PATCH /transactions/{id}` عمداً `wallet_id` نمی‌پذیرد، دقیقاً
/// مثل فرم ویرایشِ سایت. اگر اینجا فیلدِ قابل‌تغییر می‌گذاشتیم، کاربر
/// عوضش می‌کرد و هیچ اتفاقی نمی‌افتاد.
class TransactionForm extends StatefulWidget {
  final ApiClient api;
  final Transaction? existing;

  const TransactionForm({super.key, required this.api, this.existing});

  @override
  State<TransactionForm> createState() => _TransactionFormState();
}

class _TransactionFormState extends State<TransactionForm> {
  final _form = GlobalKey<FormState>();
  late final _title = TextEditingController(text: widget.existing?.title ?? '');
  late final _amount = TextEditingController(
      text: widget.existing == null ? '' : formatMoney(widget.existing!.amount));
  late final _note = TextEditingController(text: widget.existing?.note ?? '');

  late String _type = widget.existing?.type ?? 'expense';
  late String _dateIso = widget.existing?.date.iso ?? '';
  late String _dateLabel = widget.existing?.date.jalali ?? '';

  int? _categoryId;
  int? _walletId;

  List<Category> _categories = [];
  List<Wallet> _wallets = [];

  bool _busy = false;
  bool _loadingRefs = true;
  String? _error;

  bool get _isEdit => widget.existing != null;

  @override
  void initState() {
    super.initState();
    _categoryId = widget.existing?.category?.id;
    _walletId = widget.existing?.wallet?.id;
    _loadRefs();
  }

  @override
  void dispose() {
    _title.dispose();
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  /// دسته‌ها، حساب‌ها، و — برای تراکنش تازه — تاریخ امروز.
  ///
  /// تاریخ امروز از خودِ سرور می‌آید (`ping`)، نه از ساعت گوشی: اپ حق
  /// ندارد تاریخ شمسی حساب کند، و ساعتِ گوشی هم ممکن است اشتباه باشد.
  Future<void> _loadRefs() async {
    try {
      final results = await Future.wait([
        widget.api.categories(),
        widget.api.wallets(),
        if (!_isEdit) widget.api.ping(),
      ]);

      if (!mounted) return;
      setState(() {
        _categories = results[0] as List<Category>;
        _wallets = (results[1] as WalletList).items;
        if (!_isEdit) {
          final today = ApiDate.fromJson(
              (results[2] as Map<String, dynamic>)['today']);
          if (today != null) {
            _dateIso = today.iso;
            _dateLabel = today.jalali;
          }
          _walletId ??= _wallets.isNotEmpty ? _wallets.first.id : null;
        }
        _loadingRefs = false;
      });
    } catch (e) {
      if (mounted) {
        setState(() {
          _loadingRefs = false;
          _error = e is ApiException ? e.message : 'خواندن اطلاعات ناموفق بود.';
        });
      }
    }
  }

  List<Category> get _visibleCategories =>
      _categories.where((c) => c.type == _type).toList();

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    if (_dateIso.isEmpty) {
      setState(() => _error = 'تاریخ مشخص نیست.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final amount = parseAmount(_amount.text);
      if (_isEdit) {
        await widget.api.updateTransaction(
          id: widget.existing!.id,
          type: _type,
          amount: amount,
          title: _title.text.trim(),
          dateIso: _dateIso,
          note: _note.text.trim(),
          categoryId: _categoryId,
        );
      } else {
        await widget.api.createTransaction(
          type: _type,
          amount: amount,
          title: _title.text.trim(),
          dateIso: _dateIso,
          note: _note.text.trim(),
          categoryId: _categoryId,
          walletId: _walletId,
        );
      }
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_isEdit ? 'ویرایش تراکنش' : 'ثبت تراکنش'),
      ),
      body: _loadingRefs
          ? const Center(child: CircularProgressIndicator())
          : Form(
              key: _form,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  SegmentedButton<String>(
                    segments: const [
                      ButtonSegment(value: 'expense', label: Text('هزینه')),
                      ButtonSegment(value: 'income', label: Text('درآمد')),
                    ],
                    selected: {_type},
                    onSelectionChanged: (s) => setState(() {
                      _type = s.first;
                      // دسته‌ی انتخاب‌شده ممکن است با نوع تازه نخواند
                      if (!_visibleCategories.any((c) => c.id == _categoryId)) {
                        _categoryId = null;
                      }
                    }),
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _amount,
                    keyboardType: TextInputType.number,
                    // ارقام فارسی هم پذیرفته می‌شوند؛ parseAmount پاکشان می‌کند
                    inputFormatters: [
                      FilteringTextInputFormatter.allow(
                          RegExp(r'[0-9۰-۹٠-٩,٬\s]')),
                    ],
                    decoration: const InputDecoration(
                      labelText: 'مبلغ (تومان)',
                      prefixIcon: Icon(Icons.payments_outlined),
                    ),
                    validator: (v) => parseAmount(v ?? '') <= 0
                        ? 'مبلغ باید بزرگ‌تر از صفر باشد'
                        : null,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _title,
                    decoration: const InputDecoration(
                      labelText: 'عنوان',
                      prefixIcon: Icon(Icons.title),
                    ),
                    validator: (v) => (v == null || v.trim().isEmpty)
                        ? 'عنوان را وارد کنید'
                        : null,
                  ),
                  const SizedBox(height: 12),
                  DropdownButtonFormField<int?>(
                    initialValue: _categoryId,
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'دسته‌بندی',
                      prefixIcon: Icon(Icons.category_outlined),
                    ),
                    items: [
                      const DropdownMenuItem(value: null, child: Text('بدون دسته')),
                      ..._visibleCategories.map((c) => DropdownMenuItem(
                            value: c.id,
                            child: Text('${c.icon ?? ''} ${c.name}'.trim()),
                          )),
                    ],
                    onChanged: (v) => setState(() => _categoryId = v),
                  ),
                  const SizedBox(height: 12),
                  if (_isEdit)
                    // ویرایش حساب را عوض نمی‌کند — پس فقط نشانش می‌دهیم
                    ListTile(
                      leading: const Icon(Icons.account_balance_wallet_outlined),
                      title: const Text('حساب'),
                      subtitle: Text(widget.existing?.wallet?.name ?? 'بدون حساب'),
                      trailing: const Tooltip(
                        message: 'تغییر حساب از این فرم ممکن نیست',
                        child: Icon(Icons.lock_outline, size: 18),
                      ),
                    )
                  else
                    DropdownButtonFormField<int?>(
                      initialValue: _walletId,
                      isExpanded: true,
                      decoration: const InputDecoration(
                        labelText: 'حساب',
                        prefixIcon: Icon(Icons.account_balance_wallet_outlined),
                      ),
                      items: _wallets
                          .map((w) => DropdownMenuItem(
                                value: w.id,
                                child: Text(w.name),
                              ))
                          .toList(),
                      onChanged: (v) => setState(() => _walletId = v),
                    ),
                  const SizedBox(height: 12),
                  ListTile(
                    leading: const Icon(Icons.event_outlined),
                    title: const Text('تاریخ'),
                    subtitle: Text(_dateLabel.isEmpty ? '—' : _dateLabel),
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _note,
                    maxLines: 3,
                    decoration: const InputDecoration(
                      labelText: 'توضیحات (اختیاری)',
                      alignLabelWithHint: true,
                    ),
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 16),
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: Theme.of(context).colorScheme.errorContainer,
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Text(_error!),
                    ),
                  ],
                  const SizedBox(height: 24),
                  FilledButton(
                    onPressed: _busy ? null : _submit,
                    child: _busy
                        ? const SizedBox(
                            height: 20,
                            width: 20,
                            child: CircularProgressIndicator(strokeWidth: 2))
                        : Text(_isEdit ? 'ذخیره' : 'ثبت'),
                  ),
                ],
              ),
            ),
    );
  }
}
