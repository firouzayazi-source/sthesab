/// انواع پایه‌ای که در همه‌ی پاسخ‌های api/v1 تکرار می‌شوند.
///
/// ⚠ دو قاعده‌ی سرور که اینجا هم رعایت می‌شوند و نباید شکسته شوند:
///
///   • تاریخ را **اپ حساب نمی‌کند.** سرور هر تاریخ را هم میلادی و هم شمسیِ
///     آماده می‌فرستد. منطق تبدیل شمسی الان دو بار در پروژه‌ی وب هست
///     (PHP و jalali-datepicker.js) و یک تست هم‌خوانی‌شان را می‌سنجد؛
///     پیاده‌سازی سوم در Dart بیرون از پوشش آن تست می‌ماند و دیر یا زود
///     یک روز اختلاف پیدا می‌کند.
///
///   • مبلغ همیشه `int` است، هرگز `double`. ستون‌های سرور BIGINT اند و
///     واحد تومان؛ با double مقدارهای بزرگ دقتشان را از دست می‌دهند و
///     جمع‌ها نمی‌خوانند.
library;

/// تاریخ، همان‌طور که سرور می‌فرستد: هم میلادی هم شمسیِ آماده.
class ApiDate {
  /// `2026-08-31` — برای مرتب‌سازی و برای پس‌فرستادن به سرور
  final String iso;

  /// `۱۴۰۵/۰۶/۰۹` — فقط برای نمایش
  final String jalali;

  const ApiDate({required this.iso, required this.jalali});

  static ApiDate? fromJson(Object? json) {
    if (json is! Map) return null;
    final iso = json['iso'];
    if (iso is! String) return null;
    return ApiDate(iso: iso, jalali: json['jalali'] as String? ?? iso);
  }

  @override
  String toString() => jalali;

  @override
  bool operator ==(Object other) => other is ApiDate && other.iso == iso;

  @override
  int get hashCode => iso.hashCode;
}

/// مبلغ را همیشه به `int` تبدیل می‌کند.
///
/// JSON ممکن است عدد یا رشته بدهد (بسته به درایور دیتابیس)، پس هر دو
/// پذیرفته می‌شوند — ولی خروجی همیشه `int` است.
int asMoney(Object? value) {
  if (value is int) return value;
  if (value is double) return value.round();
  if (value is String) return int.tryParse(value) ?? 0;
  return 0;
}

int? asIntOrNull(Object? value) {
  if (value is int) return value;
  if (value is String) return int.tryParse(value);
  return null;
}
