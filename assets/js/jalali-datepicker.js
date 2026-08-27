/**
 * انتخابگر تاریخ شمسی — بدون وابستگی به کتابخانه خارجی
 * الگوریتم تبدیل دقیقاً منطبق با توابع PHP پروژه (includes/functions.php)
 */
(function () {
    'use strict';

    var PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    var MONTH_NAMES = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    var WEEKDAY_LABELS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

    function toFa(n) {
        return String(n).replace(/[0-9]/g, function (d) { return PERSIAN_DIGITS[d]; });
    }

    function pad2(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function intdiv(a, b) { return Math.trunc(a / b); }

    function gregorianToJalali(gy, gm, gd) {
        var g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        var gy2 = (gm > 2) ? (gy + 1) : gy;
        var days = 355666 + (365 * gy) + intdiv(gy2 + 3, 4) - intdiv(gy2 + 99, 100)
            + intdiv(gy2 + 399, 400) + gd + g_d_m[gm - 1];
        var jy = -1595 + (33 * intdiv(days, 12053));
        days %= 12053;
        jy += 4 * intdiv(days, 1461);
        days %= 1461;
        if (days > 365) { jy += intdiv(days - 1, 365); days = (days - 1) % 365; }
        var jm, jd;
        if (days < 186) { jm = 1 + intdiv(days, 31); jd = 1 + (days % 31); }
        else { jm = 7 + intdiv(days - 186, 30); jd = 1 + ((days - 186) % 30); }
        return [jy, jm, jd];
    }

    function jalaliToGregorian(jy0, jm, jd) {
        var jy = jy0 + 1595;
        var days = -355668 + (365 * jy) + (intdiv(jy, 33) * 8) + intdiv((jy % 33) + 3, 4)
            + jd + ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
        var gy = 400 * intdiv(days, 146097);
        days %= 146097;
        if (days > 36524) {
            gy += 100 * intdiv(--days, 36524);
            days %= 36524;
            if (days >= 365) days++;
        }
        gy += 4 * intdiv(days, 1461);
        days %= 1461;
        if (days > 365) { gy += intdiv(days - 1, 365); days = (days - 1) % 365; }
        var gd = days + 1;
        var sal_a = [0, 31, (((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        var gm = 0;
        for (var i = 1; i <= 12; i++) {
            if (gd <= sal_a[i]) { gm = i; break; }
            gd -= sal_a[i];
        }
        return [gy, gm, gd];
    }

    function daysInJalaliMonth(jy, jm) {
        var startG = jalaliToGregorian(jy, jm, 1);
        var nextJy = jm === 12 ? jy + 1 : jy;
        var nextJm = jm === 12 ? 1 : jm + 1;
        var endG = jalaliToGregorian(nextJy, nextJm, 1);
        var d1 = Date.UTC(startG[0], startG[1] - 1, startG[2]);
        var d2 = Date.UTC(endG[0], endG[1] - 1, endG[2]);
        return Math.round((d2 - d1) / 86400000);
    }

    function firstWeekdayIndex(jy, jm) {
        var g = jalaliToGregorian(jy, jm, 1);
        var d = new Date(Date.UTC(g[0], g[1] - 1, g[2]));
        return (d.getUTCDay() + 1) % 7; // 0 = شنبه
    }

    function todayJalali() {
        var now = new Date();
        return gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
    }

    function gregorianStrToJalali(gStr) {
        var parts = gStr.split('-').map(Number);
        return gregorianToJalali(parts[0], parts[1], parts[2]);
    }

    function jalaliToGregorianStr(jy, jm, jd) {
        var g = jalaliToGregorian(jy, jm, jd);
        return g[0] + '-' + pad2(g[1]) + '-' + pad2(g[2]);
    }

    function formatJalaliDisplay(jy, jm, jd) {
        return toFa(jy) + '/' + toFa(pad2(jm)) + '/' + toFa(pad2(jd));
    }

    var activePopup = null;

    function closePopup() {
        if (activePopup) {
            activePopup.remove();
            activePopup = null;
        }
    }

    function buildPopup(field, viewJy, viewJm, selectedJy, selectedJm, selectedJd) {
        closePopup();

        var overlay = document.createElement('div');
        overlay.className = 'jdp-overlay';

        var box = document.createElement('div');
        box.className = 'jdp-box';

        var header = document.createElement('div');
        header.className = 'jdp-header';

        var prevBtn = document.createElement('button');
        prevBtn.type = 'button';
        prevBtn.className = 'jdp-nav';
        prevBtn.textContent = '›';
        prevBtn.setAttribute('aria-label', 'ماه قبل');

        var nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.className = 'jdp-nav';
        nextBtn.textContent = '‹';
        nextBtn.setAttribute('aria-label', 'ماه بعد');

        var title = document.createElement('div');
        title.className = 'jdp-title';
        title.textContent = MONTH_NAMES[viewJm - 1] + ' ' + toFa(viewJy);

        header.appendChild(nextBtn);
        header.appendChild(title);
        header.appendChild(prevBtn);

        var weekRow = document.createElement('div');
        weekRow.className = 'jdp-weekdays';
        WEEKDAY_LABELS.forEach(function (w) {
            var cell = document.createElement('span');
            cell.textContent = w;
            weekRow.appendChild(cell);
        });

        var grid = document.createElement('div');
        grid.className = 'jdp-grid';

        var startIdx = firstWeekdayIndex(viewJy, viewJm);
        var totalDays = daysInJalaliMonth(viewJy, viewJm);

        for (var i = 0; i < startIdx; i++) {
            var blank = document.createElement('span');
            blank.className = 'jdp-day jdp-day-empty';
            grid.appendChild(blank);
        }

        var todayJ = todayJalali();

        for (var day = 1; day <= totalDays; day++) {
            var cell2 = document.createElement('button');
            cell2.type = 'button';
            cell2.className = 'jdp-day';
            cell2.textContent = toFa(day);

            if (viewJy === todayJ[0] && viewJm === todayJ[1] && day === todayJ[2]) {
                cell2.classList.add('jdp-today');
            }
            if (viewJy === selectedJy && viewJm === selectedJm && day === selectedJd) {
                cell2.classList.add('jdp-selected');
            }

            (function (d) {
                cell2.addEventListener('click', function () {
                    var display = field.querySelector('.jdp-display');
                    var hidden = field.querySelector('.jdp-hidden');
                    display.value = formatJalaliDisplay(viewJy, viewJm, d);
                    hidden.value = jalaliToGregorianStr(viewJy, viewJm, d);
                    hidden.dispatchEvent(new Event('change', { bubbles: true }));
                    closePopup();
                });
            })(day);

            grid.appendChild(cell2);
        }

        var footer = document.createElement('div');
        footer.className = 'jdp-footer';
        var todayBtn = document.createElement('button');
        todayBtn.type = 'button';
        todayBtn.className = 'jdp-today-btn';
        todayBtn.textContent = 'امروز';
        todayBtn.addEventListener('click', function () {
            var display = field.querySelector('.jdp-display');
            var hidden = field.querySelector('.jdp-hidden');
            display.value = formatJalaliDisplay(todayJ[0], todayJ[1], todayJ[2]);
            hidden.value = jalaliToGregorianStr(todayJ[0], todayJ[1], todayJ[2]);
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
            closePopup();
        });
        footer.appendChild(todayBtn);

        prevBtn.addEventListener('click', function () {
            var m = viewJm - 1, y = viewJy;
            if (m < 1) { m = 12; y -= 1; }
            buildPopup(field, y, m, selectedJy, selectedJm, selectedJd);
        });
        nextBtn.addEventListener('click', function () {
            var m = viewJm + 1, y = viewJy;
            if (m > 12) { m = 1; y += 1; }
            buildPopup(field, y, m, selectedJy, selectedJm, selectedJd);
        });

        box.appendChild(header);
        box.appendChild(weekRow);
        box.appendChild(grid);
        box.appendChild(footer);
        overlay.appendChild(box);

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closePopup();
        });

        document.body.appendChild(overlay);
        activePopup = overlay;
    }

    function openPickerFor(field) {
        var hidden = field.querySelector('.jdp-hidden');
        var current;
        if (hidden.value) {
            current = gregorianStrToJalali(hidden.value);
        } else {
            current = todayJalali();
        }
        buildPopup(field, current[0], current[1], current[0], current[1], current[2]);
    }

    function initField(field) {
        var display = field.querySelector('.jdp-display');
        if (!display) return;

        if (!field.querySelector('.jdp-icon')) {
            var icon = document.createElement('span');
            icon.className = 'jdp-icon';
            icon.textContent = '📅';
            field.appendChild(icon);
        }

        field.addEventListener('click', function () {
            openPickerFor(field);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.jdp-field').forEach(initField);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePopup();
    });

    window.JalaliDatePicker = {
        gregorianToJalali: gregorianToJalali,
        jalaliToGregorian: jalaliToGregorian,
        toFa: toFa
    };
})();
