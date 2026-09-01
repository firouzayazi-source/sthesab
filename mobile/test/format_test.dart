import 'package:daftar/widgets/format.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('نمایش مبلغ', () {
    test('جداکننده‌ی هزارگان و ارقام فارسی', () {
      expect(formatMoney(1234567), '۱٬۲۳۴٬۵۶۷');
      expect(formatMoney(0), '۰');
      expect(formatMoney(999), '۹۹۹');
      expect(formatMoney(1000), '۱٬۰۰۰');
    });

    test('منفی با خط تفریق می‌آید، نه خط تیره', () {
      // در متن راست‌به‌چپ، خط تیره‌ی معمولی جای عجیبی می‌افتد
      expect(formatMoney(-2500000), '−۲٬۵۰۰٬۰۰۰');
      expect(formatMoney(-1).startsWith('−'), isTrue);
    });
  });

  group('خواندن مبلغِ ورودیِ کاربر', () {
    test('ارقام فارسی و عربی', () {
      expect(parseAmount('۱۲۵۰۰'), 12500);
      expect(parseAmount('١٢٥٠٠'), 12500);
    });

    test('جداکننده و فاصله و واحد', () {
      expect(parseAmount('۱۲٬۵۰۰'), 12500);
      expect(parseAmount('12,500'), 12500);
      expect(parseAmount(' ۱۲٬۵۰۰ تومان '), 12500);
    });

    test('ورودی بی‌معنی صفر می‌شود تا فرم خطا بدهد', () {
      expect(parseAmount(''), 0);
      expect(parseAmount('abc'), 0);
      expect(parseAmount('تومان'), 0);
    });

    test('رفت و برگشت', () {
      for (final n in [0, 5, 999, 1000, 1234567, 987654321]) {
        expect(parseAmount(formatMoney(n)), n, reason: 'روی $n');
      }
    });
  });

  group('تبدیل ارقام', () {
    test('فارسی به لاتین و برعکس', () {
      expect(toPersianDigits('2026-08-31'), '۲۰۲۶-۰۸-۳۱');
      expect(toLatinDigits('۱۴۰۵/۰۶/۰۹'), '1405/06/09');
      // متن غیرعددی دست نمی‌خورد
      expect(toPersianDigits('تومان'), 'تومان');
    });
  });
}
