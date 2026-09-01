/// زبان بصریِ اپ — هم‌خانواده با نسخه‌ی وب.
///
/// رنگِ درآمد و هزینه در کل اپ معنا دارد و تزئین نیست: سبز برای پولی که
/// می‌آید، قرمز برای پولی که می‌رود. همان قراردادی که کاربر در سایت
/// دیده، تا جابه‌جا شدن بین وب و اپ گیجش نکند.
library;

import 'package:flutter/material.dart';

class AppColors {
  /// درآمد / مثبت
  static const income = Color(0xFF16794F);
  static const incomeDark = Color(0xFF34D399);

  /// هزینه / منفی
  static const expense = Color(0xFFC0392B);
  static const expenseDark = Color(0xFFF87171);

  static const brand = Color(0xFF1E293B);
  static const accent = Color(0xFF3B82F6);
}

/// رنگِ یک مبلغ بر اساس جهتش.
Color amountColor(BuildContext context, {required bool isIncome}) {
  final dark = Theme.of(context).brightness == Brightness.dark;
  if (isIncome) return dark ? AppColors.incomeDark : AppColors.income;
  return dark ? AppColors.expenseDark : AppColors.expense;
}

ThemeData buildTheme(Brightness brightness) {
  final scheme = ColorScheme.fromSeed(
    seedColor: AppColors.accent,
    brightness: brightness,
  );

  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    // فونت را از دارایی‌های اپ می‌گیریم تا روی هر گوشی یک شکل باشد؛
    // فونت پیش‌فرضِ اندروید برای فارسی جاهایی نیم‌فاصله را می‌شکند.
    fontFamily: 'Vazirmatn',
    scaffoldBackgroundColor:
        brightness == Brightness.dark ? const Color(0xFF0F1115) : const Color(0xFFF4F6FA),
    appBarTheme: AppBarTheme(
      centerTitle: false,
      elevation: 0,
      backgroundColor: scheme.surface,
      foregroundColor: scheme.onSurface,
    ),
    cardTheme: CardThemeData(
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      color: scheme.surfaceContainerLowest,
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: BorderSide.none,
      ),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        minimumSize: const Size.fromHeight(50),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
        ),
      ),
    ),
  );
}
