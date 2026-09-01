/// مدل‌های api/v1.
///
/// هر مدل فقط از کلیدهایی می‌خواند که در docs/API.md تضمین شده‌اند.
/// **کلید ناشناخته نادیده گرفته می‌شود، نه اینکه خطا بدهد** — قاعده‌ی
/// نسخه‌ی سرور می‌گوید افزودنِ کلید تازه مجاز است، پس اپی که با کلید
/// تازه می‌شکند، با اولین به‌روزرسانیِ سرور از کار می‌افتد.
library;

import 'api_types.dart';

export 'api_types.dart';

class User {
  final int id;
  final String username;
  final String fullName;
  final String role;

  const User({
    required this.id,
    required this.username,
    required this.fullName,
    required this.role,
  });

  factory User.fromJson(Map<String, dynamic> j) => User(
        id: asIntOrNull(j['id']) ?? 0,
        username: j['username'] as String? ?? '',
        fullName: j['full_name'] as String? ?? '',
        role: j['role'] as String? ?? 'user',
      );

  bool get isAdmin => role == 'admin';
}

class Wallet {
  final int id;
  final String name;
  final String kind;
  final String? kindLabel;
  final String? bankName;
  final String? color;
  final int balance;
  final bool isActive;

  const Wallet({
    required this.id,
    required this.name,
    required this.kind,
    required this.balance,
    required this.isActive,
    this.kindLabel,
    this.bankName,
    this.color,
  });

  factory Wallet.fromJson(Map<String, dynamic> j) => Wallet(
        id: asIntOrNull(j['id']) ?? 0,
        name: j['name'] as String? ?? '',
        kind: j['kind'] as String? ?? 'other',
        kindLabel: j['kind_label'] as String?,
        bankName: j['bank_name'] as String?,
        color: j['color'] as String?,
        balance: asMoney(j['balance']),
        isActive: j['is_active'] == true,
      );

  /// برچسبی که به کاربر نشان داده می‌شود.
  String get kindText => switch (kind) {
        'cash' => 'نقدی',
        'bank' => 'حساب بانکی',
        'card' => 'کارت بانکی',
        _ => kindLabel?.isNotEmpty == true ? kindLabel! : 'سایر',
      };
}

class Category {
  final int id;
  final String name;
  final String type;
  final String? icon;
  final String? color;

  /// دسته‌ی پیش‌فرضِ برنامه است (کاربر نمی‌تواند حذفش کند)؟
  final bool isDefault;

  const Category({
    required this.id,
    required this.name,
    required this.type,
    required this.isDefault,
    this.icon,
    this.color,
  });

  factory Category.fromJson(Map<String, dynamic> j) => Category(
        id: asIntOrNull(j['id']) ?? 0,
        name: j['name'] as String? ?? '',
        type: j['type'] as String? ?? 'expense',
        icon: j['icon'] as String?,
        color: j['color'] as String?,
        isDefault: j['is_default'] == true,
      );

  bool get isIncome => type == 'income';
}

/// ارجاعِ کوتاه به دسته یا حساب، همان‌طور که داخل یک تراکنش می‌آید.
class Ref {
  final int id;
  final String name;
  final String? icon;
  final String? color;

  const Ref({required this.id, required this.name, this.icon, this.color});

  static Ref? fromJson(Object? json) {
    if (json is! Map) return null;
    final id = asIntOrNull(json['id']);
    if (id == null) return null;
    return Ref(
      id: id,
      name: json['name'] as String? ?? '',
      icon: json['icon'] as String?,
      color: json['color'] as String?,
    );
  }
}

class Transaction {
  final int id;

  /// `income` یا `expense`
  final String type;
  final int amount;
  final String title;
  final String? note;
  final ApiDate date;
  final Ref? category;
  final Ref? wallet;

  const Transaction({
    required this.id,
    required this.type,
    required this.amount,
    required this.title,
    required this.date,
    this.note,
    this.category,
    this.wallet,
  });

  factory Transaction.fromJson(Map<String, dynamic> j) => Transaction(
        id: asIntOrNull(j['id']) ?? 0,
        type: j['type'] as String? ?? 'expense',
        amount: asMoney(j['amount']),
        title: j['title'] as String? ?? '',
        note: j['note'] as String?,
        date: ApiDate.fromJson(j['date']) ??
            const ApiDate(iso: '', jalali: ''),
        category: Ref.fromJson(j['category']),
        wallet: Ref.fromJson(j['wallet']),
      );

  bool get isIncome => type == 'income';

  /// مبلغ با علامت — برای جمع زدن و رنگ دادن
  int get signedAmount => isIncome ? amount : -amount;
}

/// اطلاعات صفحه‌بندیِ فهرست‌ها.
class PageInfo {
  final int number;
  final int perPage;
  final int total;
  final int pages;

  const PageInfo({
    required this.number,
    required this.perPage,
    required this.total,
    required this.pages,
  });

  factory PageInfo.fromJson(Map<String, dynamic> j) => PageInfo(
        number: asIntOrNull(j['number']) ?? 1,
        perPage: asIntOrNull(j['per_page']) ?? 50,
        total: asIntOrNull(j['total']) ?? 0,
        pages: asIntOrNull(j['pages']) ?? 1,
      );

  bool get hasMore => number < pages;
}

/// یک صفحه از فهرست تراکنش‌ها.
class TransactionPage {
  final List<Transaction> items;
  final PageInfo page;

  const TransactionPage({required this.items, required this.page});

  factory TransactionPage.fromJson(Map<String, dynamic> j) => TransactionPage(
        items: (j['items'] as List? ?? [])
            .whereType<Map>()
            .map((e) => Transaction.fromJson(e.cast<String, dynamic>()))
            .toList(),
        page: PageInfo.fromJson(
            (j['page'] as Map? ?? {}).cast<String, dynamic>()),
      );
}

class WalletList {
  final List<Wallet> items;
  final int totalBalance;

  const WalletList({required this.items, required this.totalBalance});

  factory WalletList.fromJson(Map<String, dynamic> j) => WalletList(
        items: (j['items'] as List? ?? [])
            .whereType<Map>()
            .map((e) => Wallet.fromJson(e.cast<String, dynamic>()))
            .toList(),
        totalBalance: asMoney(j['total_balance']),
      );
}

class Dashboard {
  final ApiDate? from;
  final ApiDate? to;
  final int income;
  final int expense;
  final int net;
  final int transactionCount;
  final int totalBalance;

  const Dashboard({
    required this.income,
    required this.expense,
    required this.net,
    required this.transactionCount,
    required this.totalBalance,
    this.from,
    this.to,
  });

  factory Dashboard.fromJson(Map<String, dynamic> j) {
    final range = (j['range'] as Map? ?? {}).cast<String, dynamic>();
    return Dashboard(
      from: ApiDate.fromJson(range['from']),
      to: ApiDate.fromJson(range['to']),
      income: asMoney(j['income']),
      expense: asMoney(j['expense']),
      net: asMoney(j['net']),
      transactionCount: asIntOrNull(j['transaction_count']) ?? 0,
      totalBalance: asMoney(j['total_balance']),
    );
  }
}

/// نتیجه‌ی ورود موفق.
class AuthResult {
  final String token;
  final int expiresInDays;
  final User user;

  const AuthResult({
    required this.token,
    required this.expiresInDays,
    required this.user,
  });

  factory AuthResult.fromJson(Map<String, dynamic> j) => AuthResult(
        token: j['token'] as String? ?? '',
        expiresInDays: asIntOrNull(j['expires_in_days']) ?? 0,
        user: User.fromJson((j['user'] as Map? ?? {}).cast<String, dynamic>()),
      );
}
