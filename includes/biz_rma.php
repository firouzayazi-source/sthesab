<?php
/**
 * ⛔ گارانتیِ پخش‌کننده — گوشی‌هایی که برای تعمیر یا تعویض به شرکت فرستاده شده (migration_biz_rma).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** گوشی‌ای که به گارانتیِ پخش می‌رود در دفترچه گم نشود: کِی رفت،
 * پیشِ کدام شرکت است، کِی برگشت، تعمیر شد یا تعویض، و هشدار وقتی دیر کرده (`OVERDUE_DAYS`).
 *
 * ⛔ فقط پیگیری: نه پول، نه حرکتِ انبار. گوشیِ انبار که تعویض شد، IMEIِ تازه این‌جا یادداشت می‌شود و
 *    جابه‌جاییِ انبار همچنان با سندِ خرید/برگشت است — دفترِ انبار فقط از سند ساخته می‌شود.
 * ⛔ شرکت فقط طرف‌حسابِ همین فروشگاه؛ قبضِ تعمیر فقط از همین فروشگاه؛ «برگشت» فقط از «ارسال‌شده».
 */

require_once __DIR__ . '/biz_repairs.php';

final class BizRma
{
    /** ⛔ وضعیت‌ها — تنها مرجع. `sent` تنها وضعیتِ باز است. */
    public const STATUSES = [
        'sent'      => 'نزدِ شرکت',
        'repaired'  => 'تعمیرشده برگشت',
        'replaced'  => 'تعویض شد',
        'rejected'  => 'رد شد (بی‌گارانتی)',
        'cancelled' => 'لغو شد',
    ];
    /** بعد از این چند روز «دیر کرده». */
    public const OVERDUE_DAYS = 20;
    public const SOURCES = ['customer' => 'گوشیِ مشتری', 'stock' => 'گوشیِ انبار'];
    public const LIMITS = ['device' => 120, 'problem' => 500, 'tracking' => 60, 'note' => 500];
    public const PAGE_SIZE = 30;

    public static function ready(): bool
    {
        return tableExists('biz_rma');
    }

    /** @return array{ok:bool, message:string, id?:int} */
    public static function create(int $userId, array $in, int $actorId): array
    {
        $t = fn(string $k): string => trim((string)($in[$k] ?? ''));
        $row = ['device' => $t('device'), 'problem' => $t('problem'), 'tracking' => toLatinDigits($t('tracking')), 'note' => $t('note'),
                'imei' => BizSerial::norm($t('imei')), 'party_id' => (int)($in['party_id'] ?? 0), 'repair_id' => (int)($in['repair_id'] ?? 0),
                'source' => isset(self::SOURCES[$t('source')]) ? $t('source') : 'customer', 'sent_on' => $t('sent_on')];
        if ($row['repair_id'] > 0) {
            $rep = BizRepairs::get($userId, $row['repair_id']);
            if (!$rep) { return ['ok' => false, 'message' => 'قبضِ تعمیر پیدا نشد.']; }
            $row['source'] = 'customer';
            if ($row['device'] === '') { $row['device'] = (string)$rep['device']; }
            if ($row['imei'] === '') { $row['imei'] = (string)($rep['imei'] ?? ''); }
            if ($row['problem'] === '') { $row['problem'] = (string)$rep['problem']; }
        }
        if ($row['device'] === '') { return ['ok' => false, 'message' => 'مدلِ دستگاه را بنویسید.']; }
        if ($row['problem'] === '') { return ['ok' => false, 'message' => 'ایرادِ دستگاه را بنویسید.']; }
        foreach (self::LIMITS as $k => $max) {
            if (mb_strlen($row[$k]) > $max) { return ['ok' => false, 'message' => 'یکی از فیلدها بلند است.']; }
        }
        if ($row['imei'] !== '' && !BizSerial::valid($row['imei'])) { return ['ok' => false, 'message' => 'IMEI باید ۱۴ تا ۱۷ رقم باشد.']; }
        if ($row['party_id'] <= 0 || !BizParties::get($userId, $row['party_id'])) { return ['ok' => false, 'message' => 'شرکتِ گارانتی (پخش‌کننده) را انتخاب کنید.']; }
        $date = $row['sent_on'] === '' ? today() : $row['sent_on'];
        if (!isValidDate($date) || $date > today()) { return ['ok' => false, 'message' => 'تاریخِ ارسال معتبر نیست.']; }
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            Biz::lockShop($pdo, $userId);
            $n = $pdo->prepare('SELECT COALESCE(MAX(number), 0) + 1 FROM biz_rma WHERE user_id = :u');
            $n->execute(['u' => $userId]);
            $no = (int)$n->fetchColumn();
            $pdo->prepare('INSERT INTO biz_rma (user_id, number, party_id, repair_id, source, device, imei, problem, tracking, sent_on, note, created_by)
                           VALUES (:u, :no, :p, :r, :s, :d, :im, :pr, :tr, :so, :n, :cb)')
                ->execute(['u' => $userId, 'no' => $no, 'p' => $row['party_id'], 'r' => $row['repair_id'] ?: null, 's' => $row['source'],
                           'd' => $row['device'], 'im' => $row['imei'] ?: null, 'pr' => $row['problem'], 'tr' => $row['tracking'] ?: null,
                           'so' => $date, 'n' => $row['note'] ?: null, 'cb' => $actorId > 0 ? $actorId : null]);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'message' => 'ارسال به گارانتی ثبت شد (شماره‌ی ' . toPersianDigits((string)$no) . ').', 'id' => $id];
    }

    /**
     * برگشت از شرکت (یا لغو). ⛔ فقط از «نزدِ شرکت»؛ تعویض بی‌IMEIِ تازه‌ی معتبر نه.
     * @return array{ok:bool, message:string}
     */
    public static function close(int $userId, int $id, string $status, array $in = []): array
    {
        if ($status === 'sent' || !isset(self::STATUSES[$status])) { return ['ok' => false, 'message' => 'وضعیت نامعتبر است.']; }
        $r = self::get($userId, $id);
        if (!$r) { return ['ok' => false, 'message' => 'پیدا نشد.']; }
        $new = BizSerial::norm(trim((string)($in['new_imei'] ?? '')));
        if ($status === 'replaced' && ($new === '' || !BizSerial::valid($new))) { return ['ok' => false, 'message' => 'برای «تعویض» IMEIِ دستگاهِ تازه را بنویسید.']; }
        $date = trim((string)($in['returned_on'] ?? '')) ?: today();
        if (!isValidDate($date) || $date > today() || $date < (string)$r['sent_on']) { return ['ok' => false, 'message' => 'تاریخِ برگشت معتبر نیست.']; }
        $note = trim((string)($in['note'] ?? ''));
        if (mb_strlen($note) > self::LIMITS['note']) { return ['ok' => false, 'message' => 'یادداشت بلند است.']; }
        // ⛔ «فقط از نزدِ شرکت» همین شرطِ UPDATE است — دو برگشتِ هم‌زمان یکی را می‌بازند
        $st = Database::getConnection()->prepare(
            "UPDATE biz_rma SET status = :s, returned_on = :d, new_imei = :ni, note = COALESCE(:n, note)
             WHERE id = :id AND user_id = :u AND status = 'sent'"
        );
        $st->execute(['s' => $status, 'd' => $status === 'cancelled' ? null : $date, 'ni' => $status === 'replaced' ? $new : null,
                      'n' => $note !== '' ? $note : null, 'id' => $id, 'u' => $userId]);
        if ($st->rowCount() === 0) { return ['ok' => false, 'message' => 'این مورد قبلاً بسته شده است.']; }
        return ['ok' => true, 'message' => 'وضعیت ثبت شد: ' . self::STATUSES[$status] . '.'];
    }

    public static function get(int $userId, int $id): ?array
    {
        $st = Database::getConnection()->prepare('SELECT r.*, p.name AS party_name FROM biz_rma r JOIN biz_parties p ON p.id = r.party_id AND p.user_id = r.user_id
                                                  WHERE r.id = :id AND r.user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->fetch() ?: null;
    }

    /** چند روز است نزدِ شرکت است (بسته‌شده: تا روزِ برگشت). */
    public static function days(array $r, ?string $today = null): int
    {
        $end = $r['returned_on'] ?? null;
        $end = $end !== null && $end !== '' ? (string)$end : ($today ?? today());
        return max(0, (int)floor((strtotime($end) - strtotime((string)$r['sent_on'])) / 86400));
    }

    public static function overdue(array $r, ?string $today = null): bool
    {
        return $r['status'] === 'sent' && self::days($r, $today) > self::OVERDUE_DAYS;
    }

    /**
     * فهرست. `$filter`: '' همه، 'open' نزدِ شرکت، 'late' دیرکرده، یا یکی از وضعیت‌ها. `$q`: IMEI (قدیم یا تازه)، شماره، دستگاه.
     * @return array{rows:list<array>, total:int, page:int, pages:int}
     */
    public static function list(int $userId, string $filter = 'open', string $q = '', int $page = 1, ?string $today = null): array
    {
        $w = ['r.user_id = :u']; $p = ['u' => $userId];
        if ($filter === 'open') { $w[] = "r.status = 'sent'"; }
        elseif ($filter === 'late') { $w[] = "r.status = 'sent'"; $w[] = 'r.sent_on < :cut'; $p['cut'] = date('Y-m-d', strtotime(($today ?? today()) . ' -' . self::OVERDUE_DAYS . ' days')); }
        elseif (isset(self::STATUSES[$filter])) { $w[] = 'r.status = :s'; $p['s'] = $filter; }
        $q = trim(toLatinDigits($q));
        if ($q !== '') {
            $digits = (string)preg_replace('/\D/', '', $q);
            if ($digits !== '' && $digits === $q) { $w[] = '(r.imei = :q1 OR r.new_imei = :q2 OR r.number = :q3)'; $p += ['q1' => $digits, 'q2' => $digits, 'q3' => (int)$digits]; }
            else { $w[] = '(r.device LIKE :q4 OR p.name LIKE :q5)'; $p += ['q4' => '%' . $q . '%', 'q5' => '%' . $q . '%']; }
        }
        $pdo = Database::getConnection();
        $join = ' FROM biz_rma r JOIN biz_parties p ON p.id = r.party_id AND p.user_id = r.user_id WHERE ' . implode(' AND ', $w);
        $st = $pdo->prepare('SELECT COUNT(*)' . $join);
        $st->execute($p);
        $n = (int)$st->fetchColumn();
        [$page, $pages, $off] = BizCommon::window($n, $page, self::PAGE_SIZE);
        $st = $pdo->prepare('SELECT r.*, p.name AS party_name' . $join . ' ORDER BY r.status = \'sent\' DESC, r.sent_on, r.id LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (int)$off);
        $st->execute($p);
        return ['rows' => $st->fetchAll(), 'total' => $n, 'page' => $page, 'pages' => $pages];
    }

    /** شمارِ باز و دیرکرده — یک کوئری. @return array{open:int, late:int} */
    public static function counts(int $userId, ?string $today = null): array
    {
        $st = Database::getConnection()->prepare("SELECT COUNT(*) AS o, COALESCE(SUM(sent_on < :cut), 0) AS l FROM biz_rma WHERE user_id = :u AND status = 'sent'");
        $st->execute(['u' => $userId, 'cut' => date('Y-m-d', strtotime(($today ?? today()) . ' -' . self::OVERDUE_DAYS . ' days'))]);
        $r = $st->fetch();
        return ['open' => (int)$r['o'], 'late' => (int)$r['l']];
    }
}
