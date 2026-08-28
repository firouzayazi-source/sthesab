<?php
function h(?string $string): string
{
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function formatMoney($amount): string
{
    // جداکننده‌ی هزارگان فارسی (٬) — کامای لاتین در متن فارسی بیگانه به نظر می‌رسد
    return toPersianDigits(number_format((float)$amount, 0, '.', '٬'));
}

function toPersianDigits($input): string
{
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $latin   = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($latin, $persian, (string)$input);
}

function toLatinDigits($input): string
{
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $latin   = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $step1 = str_replace($persian, $latin, (string)$input);
    return str_replace($arabic, $latin, $step1);
}

function sanitizeAmount($input): int
{
    $clean = toLatinDigits($input);
    $clean = preg_replace('/[^0-9]/', '', $clean);
    return (int)$clean;
}

function isValidDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function postParam(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function getParam(string $key, string $default = ''): string
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function typeLabel(string $type): string
{
    return $type === 'income' ? 'درآمد' : 'هزینه';
}

function toJalali(string $gregorianDate): string
{
    [$gy, $gm, $gd] = array_map('intval', explode('-', $gregorianDate));
    [$jy, $jm, $jd] = gregorianToJalali($gy, $gm, $gd);

    return toPersianDigits($jy) . '/' . toPersianDigits(sprintf('%02d', $jm)) . '/' . toPersianDigits(sprintf('%02d', $jd));
}

function gregorianToJalali(int $gy, int $gm, int $gd): array
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
        + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];

    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;

    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }

    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }

    return [$jy, $jm, $jd];
}

function today(): string
{
    return date('Y-m-d');
}

function startOfWeek(): string
{
    $dayOfWeek = (int)date('w');
    $diff = ($dayOfWeek == 6) ? 0 : ($dayOfWeek + 1);
    return date('Y-m-d', strtotime("-{$diff} days"));
}

function startOfJalaliMonth(): string
{
    $gy = (int)date('Y');
    $gm = (int)date('m');
    $gd = (int)date('d');
    [$jy, $jm, ] = gregorianToJalali($gy, $gm, $gd);
    [$gy2, $gm2, $gd2] = jalaliToGregorian($jy, $jm, 1);
    return sprintf('%04d-%02d-%02d', $gy2, $gm2, $gd2);
}

function startOfJalaliYear(): string
{
    $gy = (int)date('Y');
    $gm = (int)date('m');
    $gd = (int)date('d');
    [$jy, , ] = gregorianToJalali($gy, $gm, $gd);
    [$gy2, $gm2, $gd2] = jalaliToGregorian($jy, 1, 1);
    return sprintf('%04d-%02d-%02d', $gy2, $gm2, $gd2);
}

function jalaliToGregorian(int $jy, int $jm, int $jd): array
{
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4)
        + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;

    if ($days > 36524) {
        $gy += 100 * intdiv(--$days, 36524);
        $days %= 36524;
        if ($days >= 365) {
            $days++;
        }
    }

    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;

    if ($days > 365) {
        $gy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }

    $gd = $days + 1;
    $sal_a = [0, 31, ((($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28),
        31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    $gm = 0;
    for ($i = 1; $i <= 12; $i++) {
        if ($gd <= $sal_a[$i]) {
            $gm = $i;
            break;
        }
        $gd -= $sal_a[$i];
    }

    return [$gy, $gm, $gd];
}

function redirectWithMessage(string $url, string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $url);
    exit;
}

function getFlash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function getSetting(string $key, string $default = ''): string
{
    try {
        $stmt = Database::getConnection()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

function setSetting(string $key, string $value): void
{
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare('
        INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
        ON DUPLICATE KEY UPDATE setting_value = :value2
    ');
    $stmt->execute(['key' => $key, 'value' => $value, 'value2' => $value]);
}

/**
 * نگاشت کد آیکن (که در دیتابیس ذخیره می‌شود) به ایموجی.
 * ایموجی عمداً در دیتابیس ذخیره نمی‌شود تا مشکل کاراکترست پیش نیاید.
 */
/* ============================================================
   کیف پول / حساب مالی
   ============================================================ */

function walletKindLabel(string $kind): string
{
    return [
        'cash'  => 'نقدی',
        'bank'  => 'حساب بانکی',
        'card'  => 'کارت بانکی',
        'other' => 'سایر',
    ][$kind] ?? 'سایر';
}

/**
 * موجودی هر کیف پول را برمی‌گرداند.
 *
 * موجودی = موجودی اولیه
 *        + درآمدهای ثبت‌شده روی آن حساب
 *        − هزینه‌های ثبت‌شده روی آن حساب
 *        + انتقال‌های واردشده
 *        − انتقال‌های خارج‌شده (به‌علاوه کارمزد)
 *
 * همه‌چیز در یک کوئری محاسبه می‌شود تا گزارش و داشبورد
 * هیچ‌وقت دو عدد متفاوت نشان ندهند.
 */
function walletBalances(int $userId): array
{
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare('
        SELECT
            w.id, w.name, w.kind, w.bank_name, w.card_last4,
            w.color, w.is_active, w.initial_balance,
            w.initial_balance
              + COALESCE(tx.income, 0)
              - COALESCE(tx.expense, 0)
              + COALESCE(tin.total, 0)
              - COALESCE(tout.total, 0) AS balance
        FROM wallets w
        LEFT JOIN (
            SELECT wallet_id,
                   SUM(CASE WHEN type = "income"  THEN amount ELSE 0 END) AS income,
                   SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END) AS expense
            FROM transactions WHERE user_id = :u1 GROUP BY wallet_id
        ) tx  ON tx.wallet_id = w.id
        LEFT JOIN (
            SELECT to_wallet_id AS wid, SUM(amount) AS total
            FROM transfers WHERE user_id = :u2 GROUP BY to_wallet_id
        ) tin ON tin.wid = w.id
        LEFT JOIN (
            SELECT from_wallet_id AS wid, SUM(amount + fee) AS total
            FROM transfers WHERE user_id = :u3 GROUP BY from_wallet_id
        ) tout ON tout.wid = w.id
        WHERE w.user_id = :u4
        ORDER BY w.is_active DESC, w.sort_order, w.name
    ');
    $stmt->execute(['u1' => $userId, 'u2' => $userId, 'u3' => $userId, 'u4' => $userId]);

    return $stmt->fetchAll();
}

function totalBalance(int $userId): int
{
    $sum = 0;
    foreach (walletBalances($userId) as $w) {
        if ((int)$w['is_active'] === 1) {
            $sum += (int)$w['balance'];
        }
    }
    return $sum;
}

/**
 * آیا این کیف پول رکورد وابسته دارد؟ (برای جلوگیری از حذفِ داده‌دار)
 */
function walletUsageCount(int $walletId, int $userId): int
{
    $pdo = Database::getConnection();

    $t = $pdo->prepare('SELECT COUNT(*) AS c FROM transactions WHERE wallet_id = :w AND user_id = :u');
    $t->execute(['w' => $walletId, 'u' => $userId]);
    $count = (int)$t->fetch()['c'];

    $f = $pdo->prepare('SELECT COUNT(*) AS c FROM transfers WHERE (from_wallet_id = :w1 OR to_wallet_id = :w2) AND user_id = :u');
    $f->execute(['w1' => $walletId, 'w2' => $walletId, 'u' => $userId]);

    return $count + (int)$f->fetch()['c'];
}

/* ============================================================
   بودجه‌بندی
   ============================================================ */

/**
 * بازه‌ی زمانی جاری یک بودجه را برمی‌گرداند (شروع، پایان، برچسب).
 * برای weekly/monthly/yearly همیشه بازه‌ی «جاری» را حساب می‌کند —
 * یعنی بودجه یک قانون تکرارشونده است، نه یک عدد یک‌بارمصرف.
 */
function budgetPeriodRange(string $periodType, ?string $customStart, ?string $customEnd): array
{
    $today = today();
    switch ($periodType) {
        case 'weekly':
            return [startOfWeek(), $today];
        case 'yearly':
            return [startOfJalaliYear(), $today];
        case 'custom':
            $from = $customStart && isValidDate($customStart) ? $customStart : $today;
            $to   = $customEnd && isValidDate($customEnd) ? min($customEnd, $today) : $today;
            return [$from, $to];
        case 'monthly':
        default:
            return [startOfJalaliMonth(), $today];
    }
}

/**
 * وضعیت همه‌ی بودجه‌های فعال کاربر را با مصرف واقعی محاسبه می‌کند.
 * چیزی ذخیره نمی‌شود — همیشه از روی تراکنش‌های واقعی است.
 */
function budgetStatuses(int $userId): array
{
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare('
        SELECT b.*, c.name AS category_name, c.icon AS cat_icon, c.color AS cat_color
        FROM budgets b
        JOIN categories c ON c.id = b.category_id
        WHERE b.user_id = :u AND b.is_active = 1
        ORDER BY c.name
    ');
    $stmt->execute(['u' => $userId]);
    $budgets = $stmt->fetchAll();

    $result = [];
    foreach ($budgets as $b) {
        [$from, $to] = budgetPeriodRange($b['period_type'], $b['start_date'], $b['end_date']);

        $spentStmt = $pdo->prepare('
            SELECT COALESCE(SUM(amount), 0) AS spent
            FROM transactions
            WHERE user_id = :u AND category_id = :c AND type = "expense"
              AND transaction_date BETWEEN :f AND :t
        ');
        $spentStmt->execute(['u' => $userId, 'c' => $b['category_id'], 'f' => $from, 't' => $to]);
        $spent = (int)$spentStmt->fetch()['spent'];

        $amount = (int)$b['amount'];
        $pct = $amount > 0 ? round(($spent / $amount) * 100) : 0;
        $status = $pct >= 100 ? 'over' : ($pct >= 80 ? 'warn' : 'good');

        $b['spent'] = $spent;
        $b['remaining'] = $amount - $spent;
        $b['percent'] = min($pct, 999);
        $b['status'] = $status;
        $b['range_from'] = $from;
        $b['range_to'] = $to;
        $result[] = $b;
    }
    return $result;
}

function budgetPeriodLabel(string $periodType): string
{
    return [
        'weekly'  => 'هفتگی',
        'monthly' => 'ماهانه',
        'yearly'  => 'سالانه',
        'custom'  => 'بازه دلخواه',
    ][$periodType] ?? 'ماهانه';
}

/* ============================================================
   اهداف پس‌انداز
   ============================================================ */

function savingsGoalsWithProgress(int $userId, bool $includeArchived = false): array
{
    $pdo = Database::getConnection();
    $sql = '
        SELECT g.*, COALESCE(SUM(e.amount), 0) AS current_amount
        FROM savings_goals g
        LEFT JOIN savings_entries e ON e.goal_id = g.id
        WHERE g.user_id = :u' . ($includeArchived ? '' : ' AND g.is_archived = 0') . '
        GROUP BY g.id
        ORDER BY g.is_archived, g.created_at DESC
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['u' => $userId]);
    $goals = $stmt->fetchAll();

    foreach ($goals as &$g) {
        $current = (int)$g['current_amount'];
        $target = (int)$g['target_amount'];
        $g['current_amount'] = $current;
        $g['remaining'] = max(0, $target - $current);
        $g['percent'] = $target > 0 ? min(100, round(($current / $target) * 100)) : 0;
        $g['is_complete'] = $current >= $target && $target > 0;
    }
    return $goals;
}

/* ============================================================
   طلب/بدهی — پرداخت جزئی
   ============================================================ */

function debtRemaining(array $debt): int
{
    return max(0, (int)$debt['amount'] - (int)$debt['paid_amount']);
}

/* ============================================================
   تراکنش دوره‌ای — بدون نیاز به Cron
   ============================================================ */

function jalaliMonthLength(int $jy, int $jm): int
{
    $startG = jalaliToGregorian($jy, $jm, 1);
    $nextJy = $jm === 12 ? $jy + 1 : $jy;
    $nextJm = $jm === 12 ? 1 : $jm + 1;
    $endG = jalaliToGregorian($nextJy, $nextJm, 1);

    $start = new DateTime(sprintf('%04d-%02d-%02d', $startG[0], $startG[1], $startG[2]));
    $end = new DateTime(sprintf('%04d-%02d-%02d', $endG[0], $endG[1], $endG[2]));

    return (int)$start->diff($end)->days;
}

/**
 * تاریخ سررسید بعدی را بر اساس تقویم شمسی محاسبه می‌کند (نه میلادی)،
 * چون کاربر «هر ماه روز فلان» را شمسی می‌فهمد، نه میلادی.
 * برای ماه/سال، اگر روز هدف در ماه مقصد وجود نداشت (مثلاً ۳۱ در مهر)
 * به آخرین روز معتبر همان ماه محدود می‌شود.
 */
function advanceRecurringDate(string $gregorianDate, string $frequency, int $intervalCount): string
{
    if ($frequency === 'daily') {
        return date('Y-m-d', strtotime($gregorianDate . " +{$intervalCount} days"));
    }
    if ($frequency === 'weekly') {
        $days = $intervalCount * 7;
        return date('Y-m-d', strtotime($gregorianDate . " +{$days} days"));
    }

    [$gy, $gm, $gd] = array_map('intval', explode('-', $gregorianDate));
    [$jy, $jm, $jd] = gregorianToJalali($gy, $gm, $gd);

    if ($frequency === 'monthly') {
        $total = ($jm - 1) + $intervalCount;
        $newJy = $jy + intdiv($total, 12);
        $newJm = $total % 12;
        if ($newJm < 0) { $newJm += 12; $newJy--; }
        $newJm += 1;
    } else { // yearly
        $newJy = $jy + $intervalCount;
        $newJm = $jm;
    }

    $newJd = min($jd, jalaliMonthLength($newJy, $newJm));
    $g = jalaliToGregorian($newJy, $newJm, $newJd);
    return sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
}

/**
 * تراکنش‌های دوره‌ای سررسیدشده را پردازش می‌کند.
 * - mode=auto: تراکنش را خودش می‌سازد و سررسید را جلو می‌برد (حتی چند دوره‌ی عقب‌افتاده را جبران می‌کند)
 * - mode=remind/confirm: چیزی نمی‌سازد؛ فقط برای نمایش در داشبورد برگردانده می‌شود
 *
 * چون بدون Cron کار می‌کند، این تابع در بازدیدهای صفحه اصلی/داشبورد صدا زده می‌شود.
 */
function processRecurringTransactions(int $userId, bool $force = false): array
{
    // در هر نشست فقط یک‌بار در روز بررسی می‌شود.
    // بدون این محافظ، هر بار باز کردن خانه یا داشبورد یک کوئری اضافه می‌زد.
    $stamp = 'recur_checked_' . $userId;
    $todayKey = today();
    if (!$force && isset($_SESSION[$stamp]) && $_SESSION[$stamp] === $todayKey) {
        return $_SESSION['recur_pending_' . $userId] ?? [];
    }

    $pdo = Database::getConnection();
    $today = today();

    $stmt = $pdo->prepare('
        SELECT * FROM recurring_transactions
        WHERE user_id = :u AND is_active = 1 AND next_due_date <= :today
    ');
    $stmt->execute(['u' => $userId, 'today' => $today]);
    $due = $stmt->fetchAll();

    $needsAttention = [];

    foreach ($due as $r) {
        if ($r['mode'] !== 'auto') {
            $needsAttention[] = $r;
            continue;
        }

        $nextDue = $r['next_due_date'];
        $guard = 0;
        while ($nextDue <= $today && $guard < 60) {
            if ($r['end_date'] !== null && $nextDue > $r['end_date']) {
                break;
            }

            try {
                $pdo->beginTransaction();

                $ins = $pdo->prepare('
                    INSERT INTO transactions (user_id, category_id, wallet_id, recurring_id, type, amount, title, note, transaction_date)
                    VALUES (:u, :cat, :wallet, :rid, :type, :amount, :title, :note, :date)
                ');
                $ins->execute([
                    'u' => $userId, 'cat' => $r['category_id'], 'wallet' => $r['wallet_id'],
                    'rid' => $r['id'], 'type' => $r['type'], 'amount' => $r['amount'],
                    'title' => $r['title'], 'note' => $r['note'], 'date' => $nextDue,
                ]);

                $nextDue = advanceRecurringDate($nextDue, $r['frequency'], (int)$r['interval_count']);

                $upd = $pdo->prepare('UPDATE recurring_transactions SET next_due_date = :n WHERE id = :id');
                $upd->execute(['n' => $nextDue, 'id' => $r['id']]);

                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('Recurring auto-generate failed for #' . $r['id'] . ': ' . $e->getMessage());
                break;
            }

            $guard++;
        }

        // اگر تاریخ پایان رسیده، دیگر تکرار نکن
        if ($r['end_date'] !== null && $nextDue > $r['end_date']) {
            $deact = $pdo->prepare('UPDATE recurring_transactions SET is_active = 0 WHERE id = :id');
            $deact->execute(['id' => $r['id']]);
        }
    }

    // نتیجه تا پایان روز در نشست نگه داشته می‌شود
    $_SESSION[$stamp] = $todayKey;
    $_SESSION['recur_pending_' . $userId] = $needsAttention;

    return $needsAttention;
}

/**
 * کش بررسی تراکنش‌های دوره‌ای را باطل می‌کند.
 * بعد از هر تغییر (تأیید، رد، ساخت، حذف) صدا زده می‌شود تا
 * فهرست «در انتظار» بلافاصله به‌روز شود.
 */
function invalidateRecurringCache(int $userId): void
{
    unset($_SESSION['recur_checked_' . $userId], $_SESSION['recur_pending_' . $userId]);
}

function recurringFrequencyLabel(string $freq, int $interval): string
{
    $base = ['daily' => 'روز', 'weekly' => 'هفته', 'monthly' => 'ماه', 'yearly' => 'سال'][$freq] ?? 'ماه';
    return $interval > 1 ? 'هر ' . toPersianDigits($interval) . ' ' . $base . ' یک‌بار' : 'هر ' . $base;
}

function recurringModeLabel(string $mode): string
{
    return ['auto' => 'ثبت خودکار', 'remind' => 'فقط یادآوری', 'confirm' => 'نیازمند تأیید'][$mode] ?? 'یادآوری';
}

/* ============================================================
   آینده مالی — یک منبع داده واحد برای «تقویم» و «رویدادهای پیش‌رو»
   ============================================================ */

/**
 * همه‌ی رویدادهای مالی یک بازه را از منابع مختلف جمع می‌کند:
 * طلب، بدهی، چک دریافتی/صادره، و تراکنش‌های دوره‌ای.
 *
 * چون هر دو صفحه‌ی «تقویم» و «پیش‌رو» از همین تابع تغذیه می‌شوند،
 * هیچ‌وقت دو صفحه عدد متفاوت نشان نمی‌دهند.
 *
 * خروجی: آرایه‌ای از رویدادها، هرکدام با کلیدهای:
 *   date, kind, direction, title, amount, url, is_overdue
 */
function financialEvents(int $userId, string $fromDate, string $toDate): array
{
    $pdo = Database::getConnection();
    $today = today();
    $events = [];

    // ---------- طلب و بدهی ----------
    try {
        $stmt = $pdo->prepare('
            SELECT id, direction, counterparty_name, amount, paid_amount, due_date
            FROM debts
            WHERE user_id = :u AND is_settled = 0 AND due_date BETWEEN :f AND :t
        ');
        $stmt->execute(['u' => $userId, 'f' => $fromDate, 't' => $toDate]);
        foreach ($stmt->fetchAll() as $d) {
            $remaining = (int)$d['amount'] - (int)($d['paid_amount'] ?? 0);
            if ($remaining <= 0) { continue; }
            $events[] = [
                'date' => $d['due_date'],
                'kind' => 'debt',
                'direction' => $d['direction'] === 'receivable' ? 'in' : 'out',
                'title' => ($d['direction'] === 'receivable' ? 'طلب از ' : 'بدهی به ') . $d['counterparty_name'],
                'amount' => $remaining,
                'url' => 'debts.php',
                'is_overdue' => $d['due_date'] < $today,
            ];
        }
    } catch (PDOException $e) { /* جدول موجود نیست */ }

    // ---------- چک‌ها ----------
    try {
        $stmt = $pdo->prepare('
            SELECT id, direction, counterparty_name, amount, due_date
            FROM cheques
            WHERE user_id = :u AND is_settled = 0 AND due_date IS NOT NULL AND due_date BETWEEN :f AND :t
        ');
        $stmt->execute(['u' => $userId, 'f' => $fromDate, 't' => $toDate]);
        foreach ($stmt->fetchAll() as $c) {
            $events[] = [
                'date' => $c['due_date'],
                'kind' => 'cheque',
                'direction' => $c['direction'] === 'received' ? 'in' : 'out',
                'title' => 'چک ' . ($c['direction'] === 'received' ? 'دریافتی از ' : 'صادره به ') . $c['counterparty_name'],
                'amount' => (int)$c['amount'],
                'url' => 'cheques.php',
                'is_overdue' => $c['due_date'] < $today,
            ];
        }
    } catch (PDOException $e) { /* جدول موجود نیست */ }

    // ---------- تراکنش‌های دوره‌ای ----------
    // سررسیدهای آینده را تا انتهای بازه شبیه‌سازی می‌کنیم (بدون ثبت چیزی)
    try {
        $stmt = $pdo->prepare('
            SELECT id, type, title, amount, frequency, interval_count, next_due_date, end_date
            FROM recurring_transactions
            WHERE user_id = :u AND is_active = 1 AND next_due_date <= :t
        ');
        $stmt->execute(['u' => $userId, 't' => $toDate]);
        foreach ($stmt->fetchAll() as $r) {
            $due = $r['next_due_date'];
            $guard = 0;
            while ($due <= $toDate && $guard < 40) {
                if ($r['end_date'] !== null && $due > $r['end_date']) { break; }
                if ($due >= $fromDate) {
                    $events[] = [
                        'date' => $due,
                        'kind' => 'recurring',
                        'direction' => $r['type'] === 'income' ? 'in' : 'out',
                        'title' => $r['title'],
                        'amount' => (int)$r['amount'],
                        'url' => 'recurring.php',
                        'is_overdue' => $due < $today,
                    ];
                }
                $due = advanceRecurringDate($due, $r['frequency'], (int)$r['interval_count']);
                $guard++;
            }
        }
    } catch (PDOException $e) { /* جدول موجود نیست */ }

    usort($events, fn($a, $b) => $a['date'] <=> $b['date']);
    return $events;
}

/**
 * «پول قابل خرج» = موجودی فعلی − تعهدات قطعی نزدیک
 * این عدد نباید با موجودی بانکی اشتباه گرفته شود.
 */
function safeToSpend(int $userId, int $daysAhead = 30): array
{
    $balance = 0;
    try {
        $balance = totalBalance($userId);
    } catch (PDOException $e) {
        return ['available' => null, 'balance' => 0, 'commitments' => 0];
    }

    $to = date('Y-m-d', strtotime("+{$daysAhead} days"));
    $events = financialEvents($userId, today(), $to);

    $commitments = 0;
    foreach ($events as $e) {
        if ($e['direction'] === 'out') {
            $commitments += $e['amount'];
        }
    }

    return [
        'available' => $balance - $commitments,
        'balance' => $balance,
        'commitments' => $commitments,
    ];
}

function eventKindLabel(string $kind): string
{
    return ['debt' => 'طلب/بدهی', 'cheque' => 'چک', 'recurring' => 'دوره‌ای'][$kind] ?? '';
}

/**
 * فاصله‌ی روز تا یک تاریخ را به زبان آدمیزاد برمی‌گرداند.
 */
function humanDaysUntil(string $date): string
{
    $today = new DateTime(today());
    $target = new DateTime($date);
    $diff = (int)$today->diff($target)->format('%r%a');

    if ($diff < 0)  { return toPersianDigits(abs($diff)) . ' روز گذشته'; }
    if ($diff === 0) { return 'امروز'; }
    if ($diff === 1) { return 'فردا'; }
    return toPersianDigits($diff) . ' روز دیگر';
}

/* ============================================================
   گزارش‌های مقایسه‌ای
   ============================================================ */

/**
 * مقایسه‌ی ماه جاری با ماه قبل (شمسی) — درآمد، هزینه، و درصد تغییر.
 */
function monthComparison(int $userId): array
{
    $pdo = Database::getConnection();
    $today = today();

    [$jy, $jm, ] = gregorianToJalali((int)date('Y'), (int)date('m'), (int)date('d'));

    $curStartG = jalaliToGregorian($jy, $jm, 1);
    $curStart = sprintf('%04d-%02d-%02d', $curStartG[0], $curStartG[1], $curStartG[2]);

    $pJy = $jy; $pJm = $jm - 1;
    if ($pJm < 1) { $pJm = 12; $pJy--; }
    $prevStartG = jalaliToGregorian($pJy, $pJm, 1);
    $prevStart = sprintf('%04d-%02d-%02d', $prevStartG[0], $prevStartG[1], $prevStartG[2]);
    $prevEnd = date('Y-m-d', strtotime($curStart . ' -1 day'));

    $q = $pdo->prepare('
        SELECT
            COALESCE(SUM(CASE WHEN type = "income"  THEN amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END), 0) AS expense
        FROM transactions
        WHERE user_id = :u AND transaction_date BETWEEN :f AND :t
    ');

    $q->execute(['u' => $userId, 'f' => $curStart, 't' => $today]);
    $cur = $q->fetch();

    $q->execute(['u' => $userId, 'f' => $prevStart, 't' => $prevEnd]);
    $prev = $q->fetch();

    $pct = function ($now, $before) {
        $now = (int)$now; $before = (int)$before;
        if ($before === 0) { return $now > 0 ? 100 : 0; }
        return (int)round((($now - $before) / $before) * 100);
    };

    $monthNames = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
                   'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    return [
        'current_label' => $monthNames[$jm],
        'prev_label' => $monthNames[$pJm],
        'current_income' => (int)$cur['income'],
        'current_expense' => (int)$cur['expense'],
        'prev_income' => (int)$prev['income'],
        'prev_expense' => (int)$prev['expense'],
        'income_change' => $pct($cur['income'], $prev['income']),
        'expense_change' => $pct($cur['expense'], $prev['expense']),
    ];
}

/**
 * بیشترین هزینه‌ها و میانگین روزانه در یک بازه.
 */
function spendingInsights(int $userId, string $fromDate, string $toDate): array
{
    $pdo = Database::getConnection();

    $topStmt = $pdo->prepare('
        SELECT t.title, t.amount, t.transaction_date, c.name AS category_name
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.user_id = :u AND t.type = "expense" AND t.transaction_date BETWEEN :f AND :t
        ORDER BY t.amount DESC LIMIT 5
    ');
    $topStmt->execute(['u' => $userId, 'f' => $fromDate, 't' => $toDate]);
    $topExpenses = $topStmt->fetchAll();

    $sumStmt = $pdo->prepare('
        SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt
        FROM transactions
        WHERE user_id = :u AND type = "expense" AND transaction_date BETWEEN :f AND :t
    ');
    $sumStmt->execute(['u' => $userId, 'f' => $fromDate, 't' => $toDate]);
    $sum = $sumStmt->fetch();

    $days = max(1, (int)((strtotime($toDate) - strtotime($fromDate)) / 86400) + 1);

    return [
        'top_expenses' => $topExpenses,
        'total' => (int)$sum['total'],
        'count' => (int)$sum['cnt'],
        'daily_average' => (int)round((int)$sum['total'] / $days),
        'days' => $days,
    ];
}

/**
 * شماره‌ی نسخه‌ی فایل‌های ثابت برای کش مرورگر.
 *
 * نکته‌ی مهم: هرگز از time() استفاده نمی‌کند. اگر از time() استفاده شود،
 * آدرس فایل در هر ثانیه عوض می‌شود و مرورگر مجبور است CSS و JS را
 * در «هر بار بارگذاری صفحه» دوباره دانلود کند — که کندی شدید ایجاد می‌کند.
 * اگر خواندن زمان فایل ممکن نبود، یک مقدار ثابت برمی‌گرداند.
 */
/**
 * دسته‌بندی‌ها در طول یک درخواست فقط یک‌بار از دیتابیس خوانده می‌شوند.
 * چند بخش صفحه (فرم ثبت، مودال ویرایش، …) به همین فهرست نیاز دارند.
 */
function cachedCategories(): array
{
    static $cats = null;
    if ($cats === null) {
        try {
            $cats = Database::getConnection()
                ->query('SELECT id, name, type FROM categories WHERE is_active = 1 ORDER BY type, name')
                ->fetchAll();
        } catch (PDOException $e) {
            $cats = [];
        }
    }
    return $cats;
}

function assetVersion(array $relativePaths): string
{
    static $cache = [];
    $key = implode('|', $relativePaths);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $base = __DIR__ . '/../assets/';
    $latest = 0;
    foreach ($relativePaths as $rel) {
        $full = $base . $rel;
        $mtime = @filemtime($full);
        if ($mtime !== false && $mtime > $latest) {
            $latest = (int)$mtime;
        }
    }

    // اگر هیچ زمانی خوانده نشد، ثابت بماند تا کش مرورگر نشکند
    $cache[$key] = $latest > 0 ? (string)$latest : 'v1';
    return $cache[$key];
}

function categoryIconMap(): array
{
    // آیکن‌های خطی یکدست (stroke) — نه ایموجی، تا ظاهر حرفه‌ای و یکنواخت بماند
    return [
        'food'      => ['label' => 'غذا و خوراک',     'path' => '<path d="M4 3v8a3 3 0 003 3v7M7 3v6M10 3v6M17 3c-1.5 2-2 4-2 6 0 2 .7 3 2 3h2V3h-2z"/><path d="M19 12v9"/>'],
        'transport' => ['label' => 'حمل و نقل',        'path' => '<path d="M5 17h14M6 17V9l1.6-4h8.8L18 9v8"/><circle cx="8" cy="17.5" r="1.6"/><circle cx="16" cy="17.5" r="1.6"/><path d="M6 12h12"/>'],
        'shopping'  => ['label' => 'خرید',              'path' => '<path d="M4 7h16l-1.3 12.2A2 2 0 0116.7 21H7.3a2 2 0 01-2-1.8L4 7z"/><path d="M9 10V6a3 3 0 016 0v4"/>'],
        'home'      => ['label' => 'خانه و اجاره',      'path' => '<path d="M3 10.5L12 3l9 7.5"/><path d="M5.5 9.5V20h13V9.5"/><path d="M10 20v-6h4v6"/>'],
        'health'    => ['label' => 'سلامت',             'path' => '<path d="M12 5.5C10.5 3 7 3 5.4 5A4.6 4.6 0 005 10.5L12 19l7-8.5a4.6 4.6 0 00-.4-5.5C17 3 13.5 3 12 5.5z"/>'],
        'bill'      => ['label' => 'قبوض',              'path' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M9.5 8h5M9.5 12h5"/>'],
        'phone'     => ['label' => 'موبایل و اینترنت',  'path' => '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M10.5 18.5h3"/>'],
        'education' => ['label' => 'آموزش',             'path' => '<path d="M12 4L2.5 9 12 14l9.5-5L12 4z"/><path d="M6.5 11.3V16c0 1.4 2.5 2.6 5.5 2.6s5.5-1.2 5.5-2.6v-4.7"/>'],
        'fun'       => ['label' => 'تفریح و سفر',       'path' => '<path d="M2.5 12.5l19-7-7 19-2.6-7.4-9.4-4.6z"/>'],
        'clothes'   => ['label' => 'پوشاک',             'path' => '<path d="M9 3l3 2 3-2 5 3-2.2 3.4-1.8-1V21H8V8.4l-1.8 1L4 6l5-3z"/>'],
        'gift'      => ['label' => 'هدیه',              'path' => '<rect x="3.5" y="9" width="17" height="4"/><path d="M5 13v8h14v-8"/><path d="M12 9v12"/><path d="M12 9S10 3 7.5 4.5 12 9 12 9zM12 9s2-6 4.5-4.5S12 9 12 9z"/>'],
        'repair'    => ['label' => 'تعمیر و خدمات',     'path' => '<path d="M14.5 6.5a3.8 3.8 0 005 5l-8 8a2.5 2.5 0 01-3.5-3.5l8-8z"/><path d="M14.5 6.5L11 3a3.8 3.8 0 00-5 5l3.5 3.5"/>'],
        'car'       => ['label' => 'خودرو',             'path' => '<path d="M5 17h14M6 17V9l1.6-4h8.8L18 9v8"/><circle cx="8" cy="17.5" r="1.6"/><circle cx="16" cy="17.5" r="1.6"/>'],
        'pet'       => ['label' => 'حیوان خانگی',       'path' => '<ellipse cx="12" cy="16" rx="4" ry="3.2"/><circle cx="6.5" cy="10" r="2"/><circle cx="17.5" cy="10" r="2"/><circle cx="9.5" cy="6" r="1.9"/><circle cx="14.5" cy="6" r="1.9"/>'],
        'sport'     => ['label' => 'ورزش',              'path' => '<circle cx="12" cy="12" r="9"/><path d="M12 3v18M3 12h18" opacity=".55"/>'],
        'coffee'    => ['label' => 'کافه',              'path' => '<path d="M4 8h13v6a4 4 0 01-4 4H8a4 4 0 01-4-4V8z"/><path d="M17 9.5h1.8a2.2 2.2 0 010 4.4H17"/><path d="M4 21h13"/>'],
        'salary'    => ['label' => 'حقوق',              'path' => '<rect x="2.5" y="6.5" width="19" height="12" rx="2"/><circle cx="12" cy="12.5" r="2.6"/><path d="M6 10v5M18 10v5"/>'],
        'profit'    => ['label' => 'سود و سرمایه',      'path' => '<path d="M3.5 16.5l5-5 3.5 3.5 7-7.5"/><path d="M15 7.5h4.5V12"/>'],
        'sale'      => ['label' => 'فروش',              'path' => '<path d="M11.5 2.5H20a1.5 1.5 0 011.5 1.5v8.5L12 21.5 2.5 12 11.5 2.5z"/><circle cx="17" cy="7" r="1.4"/>'],
        'money'     => ['label' => 'درآمد عمومی',       'path' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M14.8 9.4c0-1.2-1.2-1.9-2.8-1.9s-2.8.7-2.8 2 1 1.7 2.8 2.2 3 .9 3 2.3-1.4 2-3 2-3-.7-3-2"/>'],
        'default'   => ['label' => 'سایر',              'path' => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="2.6"/>'],
    ];
}

/**
 * آیکن دسته‌بندی را به‌صورت SVG خطی برمی‌گرداند.
 */
function categoryIconSvg(?string $key, int $size = 20): string
{
    $map = categoryIconMap();
    $item = $map[$key ?? 'default'] ?? $map['default'];

    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
         . 'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" '
         . 'aria-hidden="true">' . $item['path'] . '</svg>';
}

function renderTransactionRow(array $tx): void
{
    $icon  = categoryIconSvg($tx['cat_icon'] ?? null);
    $color = !empty($tx['cat_color']) ? $tx['cat_color'] : '#64748b';
    ?>
    <div class="tx-row" data-id="<?= (int)$tx['id'] ?>">
        <div class="tx-row-summary">
            <span class="tx-row-icon-wrap">
                <span class="cat-icon" style="background: <?= h($color) ?>22; color: <?= h($color) ?>;"><?= $icon ?></span>
                <span class="tx-row-texts">
                    <span class="tx-row-title"><?= h($tx['title']) ?></span>
                    <span class="tx-row-cat"><?= $tx['category_name'] ? h($tx['category_name']) : 'بدون دسته‌بندی' ?></span>
                </span>
            </span>
            <span class="tx-row-amount amount-<?= h($tx['type']) ?>"><?= ($tx['type'] === 'expense' ? '−' : '+') ?><?= formatMoney($tx['amount']) ?></span>
            <span class="tx-row-chevron">▾</span>
        </div>
        <div class="tx-row-details">
            <div class="tx-row-details-line"><span>نوع</span><span class="type-tag type-tag-<?= h($tx['type']) ?>"><?= typeLabel($tx['type']) ?></span></div>
            <div class="tx-row-details-line"><span>تاریخ</span><span><?= toJalali($tx['transaction_date']) ?></span></div>
            <?php if (isset($tx['created_at'])): ?>
                <div class="tx-row-details-line"><span>زمان ثبت</span><span><?= toPersianDigits(date('H:i', strtotime($tx['created_at']))) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($tx['note'])): ?>
                <div class="tx-row-details-line"><span>توضیح</span><span><?= h($tx['note']) ?></span></div>
            <?php endif; ?>
            <div class="tx-row-actions">
                <button type="button" class="btn btn-secondary btn-sm js-load-attachments" data-tx-id="<?= (int)$tx['id'] ?>">پیوست</button>
                <button type="button" class="btn btn-secondary btn-sm js-edit-tx"
                    data-id="<?= (int)$tx['id'] ?>"
                    data-type="<?= h($tx['type']) ?>"
                    data-amount="<?= (int)$tx['amount'] ?>"
                    data-title="<?= h($tx['title']) ?>"
                    data-note="<?= h($tx['note'] ?? '') ?>"
                    data-date="<?= h($tx['transaction_date']) ?>"
                    data-category-id="<?= (int)($tx['category_id'] ?? 0) ?>">ویرایش</button>
                <button class="delete-btn js-delete-tx" data-id="<?= (int)$tx['id'] ?>">حذف</button>
            </div>

            <?php /* باکس پیوست هنگام کلیک با جاوااسکریپت ساخته می‌شود —
                     ساختن آن برای تک‌تک ردیف‌ها، حجم صفحه را بی‌دلیل چند برابر می‌کرد */ ?>
        </div>
    </div>
    <?php
}

/**
 * لیست تراکنش‌ها را بر اساس روز گروه‌بندی و رندر می‌کند،
 * با نمایش تاریخ شمسی و جمع خالص همان روز در سربرگ هر گروه.
 */
function renderTransactionsGrouped(array $transactions): void
{
    if (empty($transactions)) {
        return;
    }

    $groups = [];
    foreach ($transactions as $tx) {
        $groups[$tx['transaction_date']][] = $tx;
    }

    foreach ($groups as $date => $rows) {
        $dayNet = 0;
        foreach ($rows as $r) {
            $dayNet += ($r['type'] === 'income' ? (int)$r['amount'] : -(int)$r['amount']);
        }
        ?>
        <div class="tx-day-group">
            <div class="tx-day-header">
                <span class="tx-day-date"><?= jalaliWithWeekday($date) ?></span>
                <span class="tx-day-total <?= $dayNet >= 0 ? 'amount-income' : 'amount-expense' ?>">
                    <?= $dayNet >= 0 ? '+' : '−' ?><?= formatMoney(abs($dayNet)) ?>
                </span>
            </div>
            <?php foreach ($rows as $tx) { renderTransactionRow($tx); } ?>
        </div>
        <?php
    }
}

function jalaliWithWeekday(string $gregorianDate): string
{
    $weekdays = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];
    $ts = strtotime($gregorianDate);
    $idx = ((int)date('w', $ts) + 1) % 7; // تبدیل یکشنبه‌محور به شنبه‌محور
    return $weekdays[$idx] . ' ' . toJalali($gregorianDate);
}
/**
 * بانک‌های پیش‌فرض ایران — فقط برای لیست «بانک طرف مقابل» (چک‌های دریافتی)
 * لیست «بانک‌های خودم» عمداً خالی می‌ماند تا شلوغ نشود؛ کاربر خودش اضافه می‌کند.
 */
function defaultExternalBanks(): array
{
    return ['ملی', 'ملت', 'صادرات', 'تجارت', 'سپه', 'کشاورزی', 'مسکن', 'رفاه',
            'پارسیان', 'پاسارگاد', 'سامان', 'اقتصاد نوین', 'سینا', 'شهر', 'دی',
            'آینده', 'گردشگری', 'قرض‌الحسنه مهر ایران', 'پست بانک', 'بانک صنعت و معدن'];
}

function defaultAssetTypes(): array
{
    return [
        ['name' => 'دلار',          'unit' => 'دلار'],
        ['name' => 'طلا (۱۸ عیار)', 'unit' => 'گرم'],
        ['name' => 'نقره',          'unit' => 'گرم'],
        ['name' => 'سکه تمام',      'unit' => 'عدد'],
    ];
}

/**
 * اولین بار که کاربر وارد بخش چک یا دارایی می‌شود، مقادیر پیش‌فرض ساخته می‌شوند.
 * از app_settings برای علامت‌گذاری استفاده می‌کنیم تا اگر کاربر همه را پاک کرد،
 * دوباره برنگردند.
 */
function seedUserDefaults(int $userId): void
{
    $pdo = Database::getConnection();

    $flagKey = 'seeded_user_' . $userId;
    if (getSetting($flagKey, '0') === '1') {
        return;
    }

    try {
        $bankStmt = $pdo->prepare('INSERT IGNORE INTO banks (user_id, scope, name) VALUES (:user_id, "external", :name)');
        foreach (defaultExternalBanks() as $bankName) {
            $bankStmt->execute(['user_id' => $userId, 'name' => $bankName]);
        }

        $assetStmt = $pdo->prepare('INSERT IGNORE INTO asset_types (user_id, name, unit) VALUES (:user_id, :name, :unit)');
        foreach (defaultAssetTypes() as $at) {
            $assetStmt->execute(['user_id' => $userId, 'name' => $at['name'], 'unit' => $at['unit']]);
        }

        setSetting($flagKey, '1');
    } catch (PDOException $e) {
        error_log('Seeding defaults failed for user ' . $userId . ': ' . $e->getMessage());
    }
}

/**
 * بررسی می‌کند آیا می‌توان یک ردیف مرجع (بانک، نوع دارایی، دسته‌بندی) را حذف کرد.
 * فقط وقتی هیچ رکوردی به آن وابسته نباشد قابل حذف است.
 */
function referenceInUseCount(string $table, string $column, int $id, int $userId): int
{
    $allowed = [
        'cheques'      => 'bank_id',
        'assets'       => 'asset_type_id',
        'transactions' => 'category_id',
    ];

    if (!isset($allowed[$table]) || $allowed[$table] !== $column) {
        return 1; // ناشناخته → محافظه‌کارانه، اجازه حذف نده
    }

    $stmt = Database::getConnection()->prepare(
        "SELECT COUNT(*) AS cnt FROM `$table` WHERE `$column` = :id AND user_id = :user_id"
    );
    $stmt->execute(['id' => $id, 'user_id' => $userId]);
    return (int)$stmt->fetch()['cnt'];
}

function formatQuantity($qty): string
{
    $qty = (float)$qty;
    $formatted = ($qty == (int)$qty)
        ? number_format($qty, 0, '.', ',')
        : rtrim(rtrim(number_format($qty, 4, '.', ','), '0'), '.');
    return toPersianDigits($formatted);
}

/**
 * آدرس پایه‌ی مطلق اپ — برای ساختن لینک‌هایی که در ایمیل می‌روند.
 *
 * ⚠️ چرا APP_URL بر Host مرورگر اولویت دارد:
 * سرآیند Host را خود درخواست‌کننده تعیین می‌کند. در بازیابی رمز این یک
 * حمله‌ی شناخته‌شده است: مهاجم برای حساب قربانی درخواست بازیابی می‌دهد
 * ولی Host را evil.com می‌گذارد؛ ایمیل با لینکِ evil.com به قربانی
 * می‌رسد و اگر رویش کلیک کند، توکن به دست مهاجم می‌افتد.
 *
 * پس اگر APP_URL در config تعریف شده باشد، همان ملاک است و Host
 * درخواست اصلاً خوانده نمی‌شود.
 */
function appBaseUrl(): string
{
    if (defined('APP_URL') && APP_URL !== '') {
        return rtrim(APP_URL, '/');
    }

    // برگشت به Host درخواست — فقط وقتی APP_URL تنظیم نشده باشد
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (defined('APP_FORCE_HTTPS') && APP_FORCE_HTTPS)
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

    // حداقل پاکسازی: فقط کاراکترهای مجاز یک نام میزبان
    $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $host);

    return ($https ? 'https://' : 'http://') . $host
        . (defined('APP_BASE_PATH') ? rtrim(APP_BASE_PATH, '/') : '');
}

/**
 * آیا ستون email روی جدول users هست؟
 *
 * با migration_password_reset اضافه شده. نصب‌هایی که هنوز migration را
 * اجرا نکرده‌اند باید بدون خطا کار کنند، پس همه جا قبل از دست زدن به
 * ایمیل این را می‌پرسیم. نتیجه در همان درخواست کش می‌شود چون کوئری
 * information_schema ارزان نیست و چند بار پرسیده می‌شود.
 */
function usersHaveEmailColumn(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) { return $cached; }
    try {
        $cached = (bool)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'email'"
        )->fetchColumn();
    } catch (PDOException $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * تنها مسیر نوشتن ایمیل کاربر. برمی‌گرداند: '' یعنی موفق، وگرنه متن خطا.
 *
 * ⚠️ رشته‌ی خالی یعنی «دست نزن»، نه «پاک کن».
 *
 * چرا این‌قدر مهم است: ایمیل حالا هم راه ورود است هم تنها راه بازیابی
 * رمز. یک بار پاک شدنش یعنی کاربر بی‌سروصدا از بازیابی محروم می‌شود و
 * تا وقتی رمزش را فراموش نکرده، هیچ‌کس نمی‌فهمد. فرم ویرایش کاربر در
 * پنل مدیر مقدار فعلی را با جاوااسکریپت پر می‌کند؛ اگر آن اسکریپت اجرا
 * نشود فیلد خالی می‌ماند و ذخیره‌ی ساده، ایمیل ثبت‌شده را پاک می‌کرد.
 * دقیقاً همین اتفاق افتاد. پاک کردن حالا باید صریح خواسته شود.
 *
 * این تابع سه قاعده را یک‌جا نگه می‌دارد تا در سه فایل تکرار نشوند:
 * وجود ستون، معتبر بودن آدرس، و یکتا بودنش بین کاربران.
 */
function saveUserEmail(PDO $pdo, int $userId, string $email, bool $allowClear = false): string
{
    if (!usersHaveEmailColumn($pdo)) {
        return 'ستون ایمیل هنوز ساخته نشده — migration را اجرا کنید (bash deploy/migrate.sh --apply).';
    }

    $email = trim($email);

    if ($email === '') {
        if (!$allowClear) { return ''; }   // دست‌نخورده می‌ماند
        $pdo->prepare('UPDATE users SET email = NULL WHERE id = :id')->execute(['id' => $userId]);
        return '';
    }

    if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'ایمیل معتبر نیست.';
    }

    $dup = $pdo->prepare('SELECT username FROM users WHERE email = :e AND id <> :id');
    $dup->execute(['e' => $email, 'id' => $userId]);
    if ($other = $dup->fetchColumn()) {
        return 'این ایمیل برای کاربر «' . $other . '» ثبت شده است.';
    }

    $pdo->prepare('UPDATE users SET email = :e WHERE id = :id')
        ->execute(['e' => $email, 'id' => $userId]);
    return '';
}
