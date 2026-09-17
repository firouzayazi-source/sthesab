<?php
/**
 * لغوِ حذف — «حذف شد، لغو؟» به‌جای «مطمئنید؟».
 *
 * ⛔ چرا: مودالِ تأیید هزینه‌اش را از **همه** می‌گیرد تا اشتباهِ گاه‌به‌گاهِ
 *    یک نفر را بگیرد. کاربری که صد بار «بله» زده، بارِ صد و یکم هم
 *    بی‌خواندن می‌زند — یعنی مودال دقیقاً وقتی که لازم است کار نمی‌کند.
 *    راهِ درست این است که حذف **انجام شود** و راهِ برگشت باز بماند.
 *
 * ⛔ و برگشت واقعی است، نه «حذفِ نرم». `deleted_at` روی هر جدول یعنی
 *    هر کوئریِ موجود باید صافیِ تازه بگیرد، و اولین کوئری‌ای که یادش
 *    برود ردیف‌های حذف‌شده را برمی‌گرداند — بی‌هیچ خطایی. اینجا ردیف
 *    واقعاً حذف می‌شود و **عکسش** پیش از حذف برداشته می‌شود؛ «لغو»
 *    همان عکس را دوباره می‌نشاند.
 *
 * ⛔ فهرستِ فرزندها از **خودِ دیتابیس** کشف می‌شود، نه یک آرایه‌ی دستی
 *    (همان قاعده‌ی `userDataTables()`). حذفِ یک بدهی، `debt_payments`
 *    را هم با CASCADE می‌برد؛ اگر عکس فقط ردیفِ پدر را داشت، «لغو»
 *    بدهی را برمی‌گرداند و پرداخت‌هایش را **بی‌صدا** جا می‌گذاشت —
 *    یعنی عددِ باقیمانده عوض می‌شد بی‌آنکه کسی بفهمد چرا.
 *
 * ⛔ اگر عکس گرفتن ممکن نشد، توکن `null` برمی‌گردد و صفحه به همان
 *    `confirm()` قدیمی برمی‌گردد. «لغوِ ناموجود» بدتر از تأیید است:
 *    کاربر روی وعده‌ای حساب می‌کند که وجود ندارد.
 */

require_once __DIR__ . '/db.php';

final class Undo
{
    private const SESSION_KEY = 'undo_snapshots';

    /** بیش از این تعداد ردیف عکس گرفته نمی‌شود (نشستِ کاربر جای انبار نیست). */
    private const MAX_ROWS = 500;

    /** فقط چند عکسِ آخر می‌مانند. */
    private const MAX_KEPT = 5;

    /** عمرِ عکس. نوارِ «لغو» خیلی زودتر می‌رود؛ این فقط سقفِ بالاست. */
    private const TTL = 600;

    /** نگاشتِ فرزندانِ CASCADE، یک بار در هر درخواست. */
    private static ?array $cascade = null;

    /**
     * عکسِ یک ردیف و همه‌ی فرزندانِ CASCADE اش، **پیش از** حذف.
     *
     * @return string|null توکن، یا `null` اگر عکس گرفتن ممکن نبود.
     */
    public static function capture(string $table, int $id, int $userId): ?string
    {
        if ($id <= 0 || $userId <= 0 || !self::safeName($table)) { return null; }

        try {
            $groups = [];
            $count  = 0;
            self::collect($table, [$id], $userId, $groups, $count, 0);
            if (!$groups || $count === 0) { return null; }
        } catch (Throwable $e) {
            Log::error('undo.capture_failed', $e);
            return null;   // ⚠ هرگز جلوی خودِ حذف را نمی‌گیرد
        }

        $token = bin2hex(random_bytes(16));
        $box   = $_SESSION[self::SESSION_KEY] ?? [];
        $box[$token] = ['at' => time(), 'uid' => $userId, 'groups' => $groups];

        // کهنه‌ها و اضافه‌ها بیرون
        $now = time();
        $box = array_filter($box, fn($s) => ($now - (int)$s['at']) < self::TTL);
        if (count($box) > self::MAX_KEPT) {
            $box = array_slice($box, -self::MAX_KEPT, null, true);
        }
        $_SESSION[self::SESSION_KEY] = $box;

        return $token;
    }

    /**
     * برگرداندنِ عکس.
     *
     * @return array{ok: bool, message: string}
     */
    public static function restore(string $token, int $userId): array
    {
        $box = $_SESSION[self::SESSION_KEY] ?? [];
        $snap = $box[$token] ?? null;

        if (!$snap || (int)$snap['uid'] !== $userId) {
            return ['ok' => false, 'message' => 'چیزی برای برگرداندن پیدا نشد.'];
        }
        if ((time() - (int)$snap['at']) >= self::TTL) {
            unset($_SESSION[self::SESSION_KEY][$token]);
            return ['ok' => false, 'message' => 'مهلتِ برگرداندن گذشته است.'];
        }

        $pdo = Database::getConnection();
        try {
            $pdo->beginTransaction();
            // ترتیب مهم است: پدر پیش از فرزند، وگرنه کلیدِ خارجی رد می‌کند.
            foreach ($snap['groups'] as $g) {
                foreach ($g['rows'] as $row) {
                    self::insertRow($pdo, $g['table'], $row, $userId);
                }
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            Log::error('undo.restore_failed', $e);
            // پرتکرارترین حالت: کاربر دو بار «لغو» زده و ردیف از قبل هست.
            return ['ok' => false, 'message' => 'برگرداندن انجام نشد — شاید از قبل برگردانده شده باشد.'];
        }

        unset($_SESSION[self::SESSION_KEY][$token]);
        return ['ok' => true, 'message' => 'برگردانده شد.'];
    }

    // ---------------------------------------------------------------

    /**
     * ردیف‌های یک جدول را برمی‌دارد و بعد سراغِ فرزندانِ CASCADE می‌رود.
     *
     * ⚠ عمقِ محدود: یک حلقه در کلیدهای خارجی (که نباید باشد ولی ممکن
     *   است) وگرنه اینجا برای همیشه می‌چرخید.
     */
    private static function collect(string $table, array $ids, int $userId,
                                    array &$groups, int &$count, int $depth): void
    {
        if ($depth > 4 || !$ids || !self::safeName($table)) { return; }

        $pdo  = Database::getConnection();
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $sql  = "SELECT * FROM `{$table}` WHERE id IN ({$in})";

        // ⛔ جداسازی کاربران: جدولِ پدر حتماً باید مالِ همین کاربر باشد.
        //    جدول‌های فرزند ممکن است ستونِ `user_id` نداشته باشند (مثل
        //    `trade_sales`)، و آن‌ها با کلیدِ خارجیِ پدرشان محافظت
        //    می‌شوند — پدری که خودش سنجیده شده.
        $params = $ids;
        if (tableHasColumn($table, 'user_id')) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { return; }

        $count += count($rows);
        if ($count > self::MAX_ROWS) { throw new RuntimeException('عکس بیش از حد بزرگ است'); }

        $groups[] = ['table' => $table, 'rows' => $rows];

        $gotIds = array_map(fn($r) => (int)$r['id'], $rows);
        foreach (self::cascadeChildren($table) as [$childTable, $childCol]) {
            $cin = implode(',', array_fill(0, count($gotIds), '?'));
            $cs  = $pdo->prepare("SELECT id FROM `{$childTable}` WHERE `{$childCol}` IN ({$cin})");
            $cs->execute($gotIds);
            $childIds = array_map('intval', $cs->fetchAll(PDO::FETCH_COLUMN));
            if ($childIds) {
                self::collect($childTable, $childIds, $userId, $groups, $count, $depth + 1);
            }
        }
    }

    /** جدول‌هایی که با حذفِ این جدول، ردیفشان هم می‌رود. */
    private static function cascadeChildren(string $table): array
    {
        if (self::$cascade === null) {
            self::$cascade = [];
            $q = Database::getConnection()->query("
                SELECT k.TABLE_NAME AS child, k.COLUMN_NAME AS col,
                       k.REFERENCED_TABLE_NAME AS parent
                  FROM information_schema.KEY_COLUMN_USAGE k
                  JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                    ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                   AND rc.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
                 WHERE k.CONSTRAINT_SCHEMA = DATABASE()
                   AND rc.DELETE_RULE = 'CASCADE'
            ");
            foreach ($q as $r) {
                if (self::safeName($r['child']) && self::safeName($r['col'])) {
                    self::$cascade[$r['parent']][] = [$r['child'], $r['col']];
                }
            }
        }
        return self::$cascade[$table] ?? [];
    }

    /** درج دوباره‌ی یک ردیف، با همان شناسه تا پیوندها سالم بمانند. */
    private static function insertRow(PDO $pdo, string $table, array $row, int $userId): void
    {
        $cols = [];
        $vals = [];
        foreach ($row as $col => $v) {
            if (!self::safeName($col)) { continue; }
            // ⛔ `user_id` همیشه از نشست، هرگز از عکس — حتی عکسی که
            //    خودمان گرفته‌ایم. یک عکسِ دست‌کاری‌شده در نشست نباید
            //    بتواند ردیفی به نامِ کاربرِ دیگری بسازد.
            $cols[] = "`{$col}`";
            $vals[] = ($col === 'user_id') ? $userId : $v;
        }
        if (!$cols) { return; }

        $ph = implode(',', array_fill(0, count($cols), '?'));
        $pdo->prepare("INSERT INTO `{$table}` (" . implode(',', $cols) . ") VALUES ({$ph})")
            ->execute($vals);
    }

    /** نامِ جدول و ستون فقط از دیتابیس می‌آید، ولی باز هم سنجیده می‌شود. */
    private static function safeName(string $n): bool
    {
        return $n !== '' && preg_match('/^[a-z_][a-z0-9_]*$/i', $n) === 1;
    }
}
