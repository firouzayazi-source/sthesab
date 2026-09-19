<?php
/**
 * ⛔ پشتیبانی — میزِ کارِ مدیر.
 *
 * **خواسته‌ی مالکِ نصب:** فهرستِ تیکت‌های جدید / منتظر پاسخ ادمین / در
 * حال بررسی / پاسخ داده‌شده / بسته‌شده، با جست‌وجو، صافیِ وضعیت و
 * دسته‌بندی و تاریخ، جست‌وجوی کاربر و شماره‌ی تیکت؛ و داخلِ هر تیکت
 * پاسخ دادن، تغییر وضعیت و اولویت، بستن و باز کردن، و انتخابِ پاسخِ
 * آماده.
 *
 * ⛔ **پیش‌فرضِ صفحه «منتظر پاسخ من» است، نه «همه».** کارِ روزمره‌ی مدیر
 *    دقیقاً همان فهرست است؛ با پیش‌فرضِ «همه»، تیکتِ تازه لای صدها
 *    تیکتِ بسته گم می‌شود — همان چیزی که `admin/errors.php` با
 *    پیش‌فرضِ «باز» حلش کرد.
 *
 * ⛔ **صفحه‌بندی با `LIMIT` در SQL است، نه `array_slice`** — این فهرست
 *    تنها مصرف‌کننده‌ی کوئریِ خودش است (مرزش بالای `pagedWindow()`
 *    نوشته شده). با هزاران تیکت، خواندنِ همه دقیقاً همان چهار
 *    مگابایتِ `admin/users.php` را برمی‌گرداند.
 *
 * ⛔ **هر نوشتنی CSRF دارد و بعدش ریدایرکت می‌شود، نه رندرِ مستقیم**
 *    (قاعده ۳ + درسِ `admin/errors.php`): با رندر، تازه‌سازیِ صفحه همان
 *    پاسخ را دوباره می‌فرستاد.
 *
 * ⛔ و ریدایرکت **نمای جاری را نگه می‌دارد** (`$backTo`) — همان درسِ
 *    `admin/users.php`: بدونِ آن، پاسخ دادن به تیکتِ صفحه‌ی ۱۲ مدیر را
 *    به صفحه‌ی ۱ برمی‌گرداند و باید دوازده بار ورق بزند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/support.php';
require_once __DIR__ . '/../includes/paged_list.php';

Auth::initSession();
Auth::requireCap('support');

const SUPPORT_ADMIN_PAGE_SIZE = 15;

$ready = Support::available();

$filter = (string)getParam('f', 'waiting');
if (!isset(Support::ADMIN_FILTERS[$filter])) { $filter = 'waiting'; }
$q    = trim((string)getParam('q', ''));
$cat  = (string)getParam('c', '');
$from = (string)getParam('from', '');
$to   = (string)getParam('to', '');
$tid  = (int)getParam('t', '0');

/** نمای جاری، برای ریدایرکتِ بعد از هر عملیات. */
$backTo = (static function (): string {
    $keep = [];
    foreach (['f', 'q', 'c', 'from', 'to', 't', 'pg_tk', 'all'] as $k) {
        $v = $_GET[$k] ?? null;
        if (is_string($v) && $v !== '') { $keep[$k] = $v; }
    }
    return 'support.php' . ($keep ? '?' . http_build_query($keep) : '');
})();

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');
    $id     = (int)postParam('id', '0');
    // ⛔ مدیر است، پس `ticketFor(…, 0)` — و همین مسیر پشتِ
    //    `Auth::requireAdmin()` بالای فایل است.
    $t = $id > 0 ? Support::ticketFor($id, 0) : null;

    if ($t && $action === 'reply') {
        $body = (string)postParam('body');
        $mid  = Support::addMessage($id, (int)$t['user_id'], 'admin', (int)Auth::userId(), $body);
        if ($mid > 0) {
            if (!empty($_FILES['file']['name'])) {
                Support::attach($mid, (int)$t['user_id'], $_FILES['file']);
            }
            Support::notifyReply($t, $mid, $body);
            $flash = 'replied';
        } else {
            $flash = 'empty';
        }
    } elseif ($t && $action === 'status') {
        Support::setStatus($id, (string)postParam('status'));
        $flash = 'status';
    } elseif ($t && $action === 'priority') {
        Support::setPriority($id, (string)postParam('priority'));
        $flash = 'priority';
    }

    header('Location: ' . APP_BASE_PATH . '/admin/' . $backTo
        . (strpos($backTo, '?') === false ? '?' : '&') . 'done=' . urlencode($flash));
    exit;
}

$done = (string)getParam('done', '');
$msg  = '';
if ($done === 'replied')       { $msg = 'پاسخ ثبت شد و به کاربر خبر داده شد.'; }
elseif ($done === 'empty')     { $msg = 'متنِ پاسخ خالی بود؛ چیزی ثبت نشد.'; }
elseif ($done === 'status')    { $msg = 'وضعیت تغییر کرد.'; }
elseif ($done === 'priority')  { $msg = 'اولویت تغییر کرد.'; }

$ticket   = ($ready && $tid > 0) ? Support::ticketFor($tid, 0) : null;
$messages = $ticket ? Support::messages($tid) : [];
$canned   = $ticket ? Support::cannedList(true) : [];

$list = ['rows' => [], 'total' => 0];
$pg   = ['page' => 1, 'pages' => 1, 'total' => 0, 'all' => false, 'key' => 'tk',
         'size' => SUPPORT_ADMIN_PAGE_SIZE, 'offset' => 0, 'rows' => []];
if ($ready && !$ticket) {
    // ⚠ دو مرحله: اول تعداد را برای صفحه‌بندی لازم داریم و بعد برش.
    //   `adminTickets()` هر دو را با هم می‌دهد، پس یک بار برای شمردن
    //   صدا زده می‌شود و یک بار برای همان صفحه — نه سه کوئری.
    $probe = Support::adminTickets($filter, $q, $cat, $from, $to, 0, 1);
    $pg    = pagedWindow((int)$probe['total'], 'tk', SUPPORT_ADMIN_PAGE_SIZE);
    $list  = Support::adminTickets($filter, $q, $cat, $from, $to,
        $pg['offset'], $pg['all'] ? 500 : $pg['size']);
}

// ⛔ جدول پنج‌ستونه است و با عرضِ خواندنِ ۷۲۰ پیکسل ستونِ عملیات له
//    می‌شد (قاعده ۳۱).
$pageWide  = true;
$pageTitle = 'پشتیبانی';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/_nav.php';
?>

<?php if ($msg !== ''): ?>
    <div class="sup-flash sup-flash-ok"><?= h($msg) ?></div>
<?php endif; ?>

<?php if (!$ready): ?>
    <div class="card sup-empty">
        <h2>جدول‌های پشتیبانی ساخته نشده‌اند</h2>
        <p>یک بار <span class="ltr-num">bash deploy/migrate.sh --apply</span> را روی سرور اجرا کنید.</p>
    </div>
<?php elseif ($ticket): ?>
    <!-- ═══════════ یک تیکت ═══════════ -->
    <a href="<?= APP_BASE_PATH ?>/admin/support.php?f=<?= h($filter) ?>" class="page-back">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M11 6l-6 6 6 6"/></svg>
        <span>فهرست تیکت‌ها</span>
    </a>

    <div class="card sup-ticket-head">
        <div class="sup-ticket-top">
            <span class="sup-no ltr-num"><?= h(Support::ticketNo((int)$ticket['id'])) ?></span>
            <span class="sup-status sup-status-<?= h($ticket['status']) ?>"><?= h(Support::statusLabel($ticket['status'])) ?></span>
            <span class="sup-prio sup-prio-<?= h($ticket['priority']) ?>"><?= h(Support::priorityLabel($ticket['priority'])) ?></span>
        </div>
        <h2 class="sup-subject"><?= h($ticket['subject']) ?></h2>
        <p class="sup-meta">
            <span><?= h($ticket['full_name'] !== '' ? $ticket['full_name'] : $ticket['username']) ?>
                (<span class="ltr-num"><?= h($ticket['username']) ?></span>)</span>
            <span><?= h(Support::categoryLabel($ticket['category'])) ?></span>
            <span>ثبت: <?= h(toJalali(substr((string)$ticket['created_at'], 0, 10))) ?></span>
            <span>آخرین فعالیت: <?= h(toJalali(substr((string)$ticket['last_activity_at'], 0, 10))) ?></span>
        </p>
        <?php if ((string)$ticket['meta'] !== ''): ?>
            <p class="sup-tech ltr-num"><?= h($ticket['meta']) ?></p>
        <?php endif; ?>
    </div>

    <div class="card sup-thread">
        <?php foreach ($messages as $m): ?>
            <div class="sup-msg sup-msg-<?= h($m['sender']) ?>">
                <div class="sup-msg-who">
                    <?= $m['sender'] === 'admin'
                        ? h(($m['sender_name'] ?? '') !== '' ? 'پشتیبانی — ' . $m['sender_name'] : 'پشتیبانی')
                        : 'کاربر' ?>
                    <span class="sup-msg-time ltr-num"><?= h(toJalali(substr((string)$m['created_at'], 0, 10))) ?></span>
                </div>
                <div class="sup-msg-body"><?= nl2br(h($m['body'])) ?></div>
                <?php foreach ($m['files'] as $f): ?>
                    <a class="sup-file" target="_blank" rel="noopener"
                       href="<?= APP_BASE_PATH ?>/api/view_support_file.php?id=<?= (int)$f['id'] ?>">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>
                        <span><?= h($f['original_name'] !== '' ? $f['original_name'] : 'پیوست') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <form method="post" enctype="multipart/form-data"
              action="<?= APP_BASE_PATH ?>/admin/<?= h($backTo) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
            <?php if ($canned): ?>
                <?php /* ⚠ انتخابگرِ پاسخِ آماده فقط متن را داخلِ همان
                         `<textarea>` می‌ریزد و چیزی ارسال نمی‌کند؛ مدیر
                         می‌تواند قبلِ فرستادن ویرایشش کند. متن‌ها با
                         `JSON_HEX_TAG` می‌روند چون مدیر خودش نوشته‌شان
                         و داخلِ `<script>` می‌نشینند (درسِ قاعده ۳۸). */ ?>
                <div class="form-group">
                    <label for="supCanned">پاسخ آماده</label>
                    <select id="supCanned">
                        <option value="">— انتخاب کنید —</option>
                        <?php foreach ($canned as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= h($c['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <script>
                    window.SUPPORT_CANNED = <?= json_encode(
                        array_reduce($canned, static function (array $acc, array $c): array {
                            $acc[(string)$c['id']] = (string)$c['body'];
                            return $acc;
                        }, []),
                        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
                    ) ?>;
                </script>
            <?php endif; ?>
            <div class="form-group">
                <label for="supAdminReply">پاسخ</label>
                <textarea id="supAdminReply" name="body" rows="6" required></textarea>
            </div>
            <div class="form-group">
                <label for="supAdminFile">فایل (اختیاری)</label>
                <input type="file" id="supAdminFile" name="file" accept="image/jpeg,image/png,image/webp,application/pdf">
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn btn-primary">ارسال پاسخ</button>
            </div>
        </form>
    </div>

    <div class="card sup-admin-ops">
        <form method="post" action="<?= APP_BASE_PATH ?>/admin/<?= h($backTo) ?>" class="sup-op-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
            <label for="supStatus">وضعیت</label>
            <select id="supStatus" name="status">
                <?php foreach (Support::STATUSES as $k => $label): ?>
                    <option value="<?= h($k) ?>" <?= $ticket['status'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary btn-sm">ثبت</button>
        </form>
        <form method="post" action="<?= APP_BASE_PATH ?>/admin/<?= h($backTo) ?>" class="sup-op-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="priority">
            <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
            <label for="supPrio">اولویت</label>
            <select id="supPrio" name="priority">
                <?php foreach (Support::PRIORITIES as $k => $label): ?>
                    <option value="<?= h($k) ?>" <?= $ticket['priority'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary btn-sm">ثبت</button>
        </form>
    </div>

<?php else: ?>
    <!-- ═══════════ فهرست ═══════════ -->
    <nav class="page-tabs" aria-label="صافی تیکت‌ها">
        <?php foreach (Support::ADMIN_FILTERS as $k => $label): ?>
            <a href="<?= APP_BASE_PATH ?>/admin/support.php?f=<?= h($k) ?>"
               class="page-tab <?= $filter === $k ? 'is-active' : '' ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <form method="get" action="<?= APP_BASE_PATH ?>/admin/support.php" class="card user-filters">
        <input type="hidden" name="f" value="<?= h($filter) ?>">
        <input type="search" name="q" value="<?= h($q) ?>" class="user-filter-q"
               placeholder="موضوع، نام کاربر، یا شماره تیکت" aria-label="جست‌وجو">
        <select name="c" aria-label="دسته‌بندی">
            <option value="">همه‌ی دسته‌ها</option>
            <?php foreach (Support::CATEGORIES as $k => $label): ?>
                <option value="<?= h($k) ?>" <?= $cat === $k ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= h($from) ?>" aria-label="از تاریخ">
        <input type="date" name="to" value="<?= h($to) ?>" aria-label="تا تاریخ">
        <button type="submit" class="btn btn-primary btn-sm">جست‌وجو</button>
        <a class="btn btn-secondary btn-sm" href="<?= APP_BASE_PATH ?>/admin/support.php?f=<?= h($filter) ?>">پاک کردن</a>
    </form>

    <?php if (!$list['rows']): ?>
        <div class="card sup-empty">
            <h2>تیکتی در این نما نیست</h2>
            <p>صافی را عوض کنید یا جست‌وجو را پاک کنید.</p>
        </div>
    <?php else: ?>
        <p class="user-filter-note"><?= h(toPersianDigits((string)$pg['total'])) ?> تیکت در این نما</p>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>شماره</th>
                        <th>موضوع</th>
                        <th>کاربر</th>
                        <th>دسته</th>
                        <th>وضعیت</th>
                        <th>آخرین فعالیت</th>
                        <th class="actions-cell">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($list['rows'] as $t): ?>
                    <tr>
                        <td class="ltr-num"><?= h(Support::ticketNo((int)$t['id'])) ?></td>
                        <td><?= h($t['subject']) ?></td>
                        <td><?= h($t['full_name'] !== '' ? $t['full_name'] : $t['username']) ?></td>
                        <td><?= h(Support::categoryLabel($t['category'])) ?></td>
                        <td>
                            <span class="sup-status sup-status-<?= h($t['status']) ?>"><?= h(Support::statusLabel($t['status'])) ?></span>
                            <?php if ($t['last_sender'] === 'user' && $t['status'] !== 'closed'): ?>
                                <span class="sup-wait" title="منتظر پاسخ شما">•</span>
                            <?php endif; ?>
                        </td>
                        <td class="ltr-num"><?= h(toJalali(substr((string)$t['last_activity_at'], 0, 10))) ?></td>
                        <td class="actions-cell">
                            <div class="table-actions">
                                <a class="btn btn-secondary btn-sm"
                                   href="<?= APP_BASE_PATH ?>/admin/support.php?f=<?= h($filter) ?>&amp;t=<?= (int)$t['id'] ?>">باز کردن</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php pagedNav($pg); ?>
    <?php endif; ?>

    <div class="card sup-still">
        <p>متنِ راهنما و پاسخ‌های آماده در صفحه‌ی جدا مدیریت می‌شوند.</p>
        <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/admin/support-content.php">راهنما و پاسخ‌های آماده</a>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
