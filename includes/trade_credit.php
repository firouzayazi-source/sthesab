<?php
/**
 * خرید امانی/نسیه و فروش نسیه در «معاملات» — پیوند به «طلب و بدهی».
 *
 * «از علی گوشی گرفتم و پولش را نداده‌ام» یعنی **بدهی** به علی؛ «به محمد
 * فروختم و پولش را بعداً می‌دهد» یعنی **طلب** از محمد. این فایل تنها
 * جایی است که این دو ردیف ساخته، هم‌گام، و پس گرفته می‌شوند — مثلِ
 * `debts.cheque_id` برای چکِ برگشتی، و به همان دلیل: با دو نسخه از این
 * منطق، ویرایشِ معامله یک مبلغ می‌گفت و طلب و بدهی مبلغِ دیگری.
 *
 * ⛔ هیچ پولی جابه‌جا نمی‌شود. پای نسیه هیچ حسابی ندارد
 *    (`buy_wallet_id`/`wallet_id` = NULL)، پس `walletBalances()` دست
 *    نمی‌خورد؛ پول وقتی جابه‌جا می‌شود که کاربر در «طلب و بدهی» پرداخت
 *    ثبت کند (`debt_payments.wallet_id`) — همان مسیرِ همیشگی.
 *
 * ⛔ ردیفی که رویش پرداخت ثبت شده هرگز خودبه‌خود پاک نمی‌شود؛ فقط
 *    پیوندش برداشته می‌شود. پاک کردنش یعنی از بین بردنِ کارِ خودِ کاربر
 *    و پولی که واقعاً جابه‌جا شده (همان قاعده‌ی چکِ برگشتی).
 *
 * ⛔ هر کوئری `user_id = :u` دارد و `user_id` از فراخواننده (نشست) می‌آید.
 */

require_once __DIR__ . '/functions.php';

/** دو سرِ ممکن: خرید → بدهی به فروشنده، فروش → طلب از خریدار. */
const TRADE_CREDIT_KINDS = [
    'buy'  => ['col' => 'trade_id',      'direction' => 'payable'],
    'sale' => ['col' => 'trade_sale_id', 'direction' => 'receivable'],
];

/** ستون‌ها با `migration_trade_credit.sql` می‌آیند؛ بدونشان گزینه رندر نمی‌شود. */
function tradeCreditAvailable(): bool
{
    return tableHasColumn('debts', 'trade_id')
        && tableHasColumn('debts', 'trade_sale_id')
        && tableHasColumn('trades', 'on_credit')
        && tableHasColumn('trade_sales', 'on_credit');
}

/** طلب/بدهیِ پیوندیِ یک خرید یا فروش، یا null. */
function tradeCreditLinked(PDO $pdo, int $userId, string $kind, int $linkId): ?array
{
    $k = TRADE_CREDIT_KINDS[$kind] ?? null;
    if ($k === null || $linkId <= 0) { return null; }
    $st = $pdo->prepare(
        'SELECT id, amount, paid_amount, is_settled FROM debts
         WHERE user_id = :u AND ' . $k['col'] . ' = :l ORDER BY id LIMIT 1'
    );
    $st->execute(['u' => $userId, 'l' => $linkId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * طلب/بدهیِ پیوندی را می‌سازد یا با معامله هم‌گام می‌کند.
 *
 * ⛔ مبلغِ تازه نمی‌تواند کمتر از آنچه تاکنون پرداخت شده باشد: وگرنه
 *    باقیمانده منفی می‌شد و «خالص طلب و بدهی» بی‌صدا غلط.
 *
 * @return array{ok:bool, error?:string, debt_id?:int, created?:bool}
 */
function tradeCreditUpsert(PDO $pdo, int $userId, string $kind, int $linkId,
                           string $name, int $amount, string $date, string $note): array
{
    $k = TRADE_CREDIT_KINDS[$kind] ?? null;
    if ($k === null || $linkId <= 0) { return ['ok' => false, 'error' => 'پیوند نامعتبر است.']; }
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'error' => $kind === 'buy'
            ? 'برای خرید امانی/نسیه، نام فروشنده لازم است.'
            : 'برای فروش نسیه، نام خریدار لازم است.'];
    }
    if (mb_strlen($name) > 150) { return ['ok' => false, 'error' => 'نام طرف مقابل بیش از حد بلند است.']; }

    $existing = tradeCreditLinked($pdo, $userId, $kind, $linkId);
    if ($existing) {
        $paid = (int)$existing['paid_amount'];
        if ($amount < $paid) {
            return ['ok' => false, 'error' => 'مبلغ نمی‌تواند کمتر از مبلغِ پرداخت‌شده‌ی '
                . ($kind === 'buy' ? 'بدهی' : 'طلب') . ' (' . formatMoney($paid) . ' ' . APP_CURRENCY . ') باشد.'];
        }
        $settled = $paid >= $amount;
        $pdo->prepare(
            'UPDATE debts SET counterparty_name = :n, amount = :a, entry_date = :e, note = :note,
                    is_settled = :s,
                    settled_at = CASE WHEN :s2 = 1 THEN COALESCE(settled_at, NOW()) ELSE NULL END
             WHERE id = :id AND user_id = :u'
        )->execute([
            'n' => $name, 'a' => $amount, 'e' => $date, 'note' => $note,
            's' => $settled ? 1 : 0, 's2' => $settled ? 1 : 0,
            'id' => (int)$existing['id'], 'u' => $userId,
        ]);
        return ['ok' => true, 'debt_id' => (int)$existing['id'], 'created' => false];
    }

    // «هر وقت داشت پس می‌دهد» — سررسیدِ ساختگی در «آینده مالی» تعهدِ
    // واقعی می‌نشست. روی نصبِ عقب‌مانده ستون NOT NULL است، پس همان تاریخ.
    $due = debtDueOptional() ? null : $date;
    $pdo->prepare(
        'INSERT INTO debts (user_id, ' . $k['col'] . ', direction, counterparty_name, amount,
                            paid_amount, entry_date, due_date, note, is_settled)
         VALUES (:u, :l, :dir, :n, :a, 0, :e, :d, :note, 0)'
    )->execute([
        'u' => $userId, 'l' => $linkId, 'dir' => $k['direction'],
        'n' => $name, 'a' => $amount, 'e' => $date, 'd' => $due, 'note' => $note,
    ]);
    return ['ok' => true, 'debt_id' => (int)$pdo->lastInsertId(), 'created' => true];
}

/**
 * پای نسیه برداشته شد (پرداختِ نقدی شد، یا خرید/فروش حذف شد).
 *
 * @return string 'none' | 'deleted' | 'kept' — «kept» یعنی پرداخت داشت و
 *                فقط پیوندش برداشته شد.
 */
function tradeCreditRelease(PDO $pdo, int $userId, string $kind, int $linkId): string
{
    $k = TRADE_CREDIT_KINDS[$kind] ?? null;
    if ($k === null) { return 'none'; }
    $existing = tradeCreditLinked($pdo, $userId, $kind, $linkId);
    if (!$existing) { return 'none'; }

    $paid = $pdo->prepare('SELECT COUNT(*) FROM debt_payments WHERE debt_id = :d AND user_id = :u');
    $paid->execute(['d' => (int)$existing['id'], 'u' => $userId]);
    if ((int)$paid->fetchColumn() > 0 || (int)$existing['paid_amount'] > 0) {
        $pdo->prepare('UPDATE debts SET ' . $k['col'] . ' = NULL WHERE id = :id AND user_id = :u')
            ->execute(['id' => (int)$existing['id'], 'u' => $userId]);
        return 'kept';
    }
    $pdo->prepare('DELETE FROM debts WHERE id = :id AND user_id = :u')
        ->execute(['id' => (int)$existing['id'], 'u' => $userId]);
    return 'deleted';
}

/** پیش از حذفِ یک معامله: بدهیِ خرید و طلبِ همه‌ی فروش‌هایش. */
function tradeCreditReleaseTrade(PDO $pdo, int $userId, int $tradeId): int
{
    $kept = tradeCreditRelease($pdo, $userId, 'buy', $tradeId) === 'kept' ? 1 : 0;
    $st = $pdo->prepare('SELECT id FROM trade_sales WHERE trade_id = :t AND user_id = :u');
    $st->execute(['t' => $tradeId, 'u' => $userId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $saleId) {
        if (tradeCreditRelease($pdo, $userId, 'sale', (int)$saleId) === 'kept') { $kept++; }
    }
    return $kept;
}

/** پیامِ کوتاه برای وقتی ردیفی پرداخت داشت و سرِ جایش ماند. */
function tradeCreditKeptNote(int $kept): string
{
    return $kept > 0
        ? ' طلب/بدهیِ مرتبط چون پرداخت داشت در «طلب و بدهی» ماند.'
        : '';
}
