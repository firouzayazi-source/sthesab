/// نمایش عدد و پول به فارسی.
///
/// ⚠ اینجا **فقط نمایش** است. هیچ محاسبه‌ای روی تاریخ نمی‌شود — تاریخ
/// شمسی از سرور آماده می‌آید (`ApiDate.jalali`) و اپ حق ندارد خودش
/// تبدیل کند؛ دلیلش در lib/models/api_types.dart نوشته شده.
library;

const _fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

/// ارقام لاتین را به فارسی برمی‌گرداند.
String toPersianDigits(String input) {
  final buffer = StringBuffer();
  for (final rune in input.runes) {
    if (rune >= 48 && rune <= 57) {
      buffer.write(_fa[rune - 48]);
    } else {
      buffer.writeCharCode(rune);
    }
  }
  return buffer.toString();
}

/// ارقام فارسی و عربی را به لاتین برمی‌گرداند — برای خواندنِ ورودی کاربر.
String toLatinDigits(String input) {
  final buffer = StringBuffer();
  for (final rune in input.runes) {
    if (rune >= 0x06F0 && rune <= 0x06F9) {
      buffer.write(rune - 0x06F0); // ۰-۹ فارسی
    } else if (rune >= 0x0660 && rune <= 0x0669) {
      buffer.write(rune - 0x0660); // ٠-٩ عربی
    } else {
      buffer.writeCharCode(rune);
    }
  }
  return buffer.toString();
}

/// `1234567` → `۱٬۲۳۴٬۵۶۷`
String formatMoney(int amount) {
  final negative = amount < 0;
  final digits = amount.abs().toString();

  final grouped = StringBuffer();
  for (var i = 0; i < digits.length; i++) {
    if (i > 0 && (digits.length - i) % 3 == 0) grouped.write('٬');
    grouped.write(digits[i]);
  }

  // علامت منفی با U+2212 (خط تفریق) نه خط تیره — در متن راست‌به‌چپ
  // خطِ تیره‌ی معمولی جای عجیبی می‌افتد.
  return '${negative ? '−' : ''}${toPersianDigits(grouped.toString())}';
}

/// مبلغ با واحد، برای جاهایی که فضا هست.
String formatToman(int amount) => '${formatMoney(amount)} تومان';

/// ورودیِ کاربر برای مبلغ را به عدد تبدیل می‌کند.
///
/// ارقام فارسی، جداکننده‌ی هزارگان، فاصله و «تومان» را می‌فهمد. اگر چیزی
/// نماند، صفر برمی‌گرداند تا فرم بتواند خطای «مبلغ را وارد کنید» بدهد.
int parseAmount(String input) {
  final cleaned = toLatinDigits(input).replaceAll(RegExp(r'[^0-9]'), '');
  if (cleaned.isEmpty) return 0;
  return int.tryParse(cleaned) ?? 0;
}
