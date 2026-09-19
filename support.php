<?php
/**
 * ⛔ پشتیبانی — راهنما اول، تیکت بعد.
 *
 * **خواسته‌ی مالکِ نصب:** «قبل از اینکه کاربر بتواند تیکت ثبت کند، بر
 * اساس موضوعِ انتخاب‌شده، مقالات و پاسخ‌های مرتبط نمایش داده شود…
 * دو گزینه: مشکلم حل شد / هنوز مشکل دارم. فقط با انتخابِ «هنوز مشکل
 * دارم» امکان ثبت تیکت نمایش داده شود.»
 *
 * ⛔ **آن دروازه یک `<details>` یا یک `hidden` سمتِ مرورگر نیست، یک
 *    گامِ واقعی در آدرس است** (`?v=ask` → `?v=new&c=…`). دو دلیل:
 *    با دکمه‌ی بازگشتِ مرورگر کار می‌کند، و اگر `app.js` نرسد (همان
 *    حالتی که `js-loading` برایش ساخته شد) دروازه بی‌صدا **باز**
 *    نمی‌شود — که دقیقاً خلافِ خواسته بود.
 *
 * ⛔ **هیچ اندپوینتِ `api/` تازه‌ای ندارد.** همه‌ی نوشتن‌ها فرمِ
 *    POST + CSRF + ریدایرکت‌اند، مثل `admin/errors.php`: بدونِ
 *    جاوااسکریپت هم کار می‌کند، و تازه‌سازیِ صفحه بعد از ثبت، همان
 *    تیکت را دوباره نمی‌فرستد.
 *
 * ⛔ **جداسازی کاربران:** هر خواندنِ تیکت از `Support::ticketFor()`
 *    رد می‌شود که شناسه را همیشه با `Auth::userId()` می‌سنجد. هیچ
 *    مسیری اینجا `user_id` را از ورودی نمی‌گیرد.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/support.php';
require_once __DIR__ . '/includes/notify.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/paged_list.php';

Auth::initSession();
Auth::requireLogin();

$userId = (int)Auth::userId();
$ready  = Support::available();

/**
 * ⛔ نمای جاری در آدرس است، پس ریدایرکتِ بعد از هر عملیات به همان‌جا
 *    برمی‌گردد — همان درسِ `$backTo` در `admin/users.php`. بدونِ آن،
 *    ثبتِ پاسخ روی یک تیکت کاربر را به فهرستِ راهنما پرت می‌کرد.
 */
$view   = getParam('v', 'home');
$cat    = getParam('c', '');
$tid    = (int)getParam('t', '0');
$q      = trim((string)getParam('q', ''));
$flash  = '';
$error  = '';

// ───────────────────────── نوشتن ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');

    if (!$ready) {
        $error = 'بخش پشتیبانی هنوز روی این نصب راه‌اندازی نشده است.';
    } elseif ($action === 'create') {
        $res = Support::createTicket($userId, (string)postParam('category'),
            (string)postParam('subject'), (string)postParam('body'));
        if ($res['ok']) {
            $fileErr = '';
            if (!empty($_FILES['file']['name'])) {
                $fileErr = Support::attach((int)($res['message_id'] ?? 0), $userId, $_FILES['file']);
            }
            header('Location: ' . APP_BASE_PATH . '/support.php?v=ticket&t=' . $res['id']
                . '&done=' . urlencode($fileErr === '' ? 'created' : 'created_nofile'));
            exit;
        }
        $error = $res['error'];
        $view  = 'new';
        $cat   = (string)postParam('category');
    } elseif ($action === 'reply') {
        $ticket = Support::ticketFor($tid, $userId);
        if (!$ticket) {
            $error = 'این درخواست پیدا نشد.';
        } elseif (Support::userMessageCount($tid) >= Support::MAX_USER_MESSAGES) {
            $error = 'این گفت‌وگو به سقفِ پیام رسیده است. لطفاً درخواستِ تازه‌ای ثبت کنید.';
        } else {
            $mid = Support::addMessage($tid, $userId, 'user', $userId, (string)postParam('body'));
            if ($mid > 0) {
                if (!empty($_FILES['file']['name'])) { Support::attach($mid, $userId, $_FILES['file']); }
                header('Location: ' . APP_BASE_PATH . '/support.php?v=ticket&t=' . $tid . '&done=replied');
                exit;
            }
            $error = 'پیام ثبت نشد. متن را خالی نگذارید.';
        }
        $view = 'ticket';
    } elseif ($action === 'close') {
        Support::closeByUser($tid, $userId);
        header('Location: ' . APP_BASE_PATH . '/support.php?v=ticket&t=' . $tid . '&done=closed');
        exit;
    }
}

$done = (string)getParam('done', '');
if ($done === 'created')        { $flash = 'درخواست شما ثبت شد. پاسخ را همین‌جا می‌بینید و در مرکز اعلان هم خبردار می‌شوید.'; }
elseif ($done === 'created_nofile') { $flash = 'درخواست شما ثبت شد، ولی فایلِ پیوست ذخیره نشد. می‌توانید آن را در پاسخِ بعدی بفرستید.'; }
elseif ($done === 'replied')    { $flash = 'پیام شما ثبت شد.'; }
elseif ($done === 'closed')     { $flash = 'درخواست بسته شد. اگر باز هم مشکل داشتید، درخواستِ تازه‌ای ثبت کنید.'; }

// ───────────────────────── خواندن ─────────────────────────
$ticket   = null;
$messages = [];
if ($ready && $view === 'ticket' && $tid > 0) {
    $ticket = Support::ticketFor($tid, $userId);
    if ($ticket) {
        Support::markRead($tid, $userId);
        $messages = Support::messages($tid);
    } else {
        $view  = 'home';
        $error = 'این درخواست پیدا نشد.';
    }
}

$results = ($ready && $view === 'search' && $q !== '') ? Support::searchArticles($q) : [];
$myPg    = ['rows' => [], 'pages' => 1];
$myList  = [];
if ($ready && $view === 'mine') {
    $total  = Support::userTicketCount($userId);
    $myPg   = pagedWindow($total, 'tk', Support::PAGE_SIZE);
    $myList = Support::userTickets($userId, $myPg['offset'], $myPg['all'] ? 200 : $myPg['size']);
}
$unread = $ready ? Support::userUnread($userId) : 0;

$pageTitle = 'پشتیبانی';
include __DIR__ . '/includes/header.php';
?>

<a href="<?= APP_BASE_PATH ?>/index.php" class="page-back js-page-back">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M11 6l-6 6 6 6"/></svg>
    <span>بازگشت</span>
</a>

<?php if ($flash !== ''): ?>
    <div class="sup-flash sup-flash-ok"><?= h($flash) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="sup-flash sup-flash-err"><?= h($error) ?></div>
<?php endif; ?>

<?php if (!$ready): ?>
    <div class="card sup-empty">
        <h2>پشتیبانی هنوز راه‌اندازی نشده</h2>
        <p>جدول‌های این بخش روی این نصب ساخته نشده‌اند. مدیر باید یک بار migration را اجرا کند.</p>
    </div>
    <?php include __DIR__ . '/includes/footer.php'; exit; ?>
<?php endif; ?>

<?php
/* نوارِ نماها — زیرخط‌دار مثل `due.php`، نه قرص: صافیِ داخلِ صفحه هم
   قرص است و با شکلِ مشترک، دو چیزِ هم‌وزن دیده می‌شدند. */
$tabs = ['home' => 'راهنما', 'ask' => 'ثبت درخواست', 'mine' => 'درخواست‌های من'];
$here = in_array($view, ['home', 'search'], true) ? 'home'
      : (in_array($view, ['ask', 'new'], true) ? 'ask' : 'mine');
?>
<nav class="page-tabs" aria-label="بخش‌های پشتیبانی">
    <?php foreach ($tabs as $k => $label): ?>
        <a href="<?= APP_BASE_PATH ?>/support.php?v=<?= h($k) ?>"
           class="page-tab <?= $here === $k ? 'is-active' : '' ?>"
           <?= $here === $k ? 'aria-current="page"' : '' ?>><?= h($label) ?><?php
            if ($k === 'mine' && $unread > 0): ?><span class="sup-badge ltr-num"><?= h(toPersianDigits((string)$unread)) ?></span><?php
            endif; ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($here === 'home'): ?>
    <!-- ═══════════ راهنما: جست‌وجو + دسته‌بندی ═══════════ -->
    <form method="get" action="<?= APP_BASE_PATH ?>/support.php" class="card sup-search">
        <input type="hidden" name="v" value="search">
        <label for="supQ" class="sup-search-label">جست‌وجو در راهنما</label>
        <div class="sup-search-row">
            <input type="search" id="supQ" name="q" value="<?= h($q) ?>"
                   placeholder="مثلاً: موجودی حساب، پشتیبان، چک برگشتی" autocomplete="off">
            <button type="submit" class="btn btn-primary">جست‌وجو</button>
        </div>
    </form>

    <?php if ($view === 'search'): ?>
        <?php if ($q === ''): ?>
            <div class="card sup-empty"><p>چیزی برای جست‌وجو ننوشتید.</p></div>
        <?php elseif (!$results): ?>
            <div class="card sup-empty">
                <h2>چیزی پیدا نشد</h2>
                <p>برای «<?= h($q) ?>» راهنمایی نداریم. می‌توانید همین‌جا درخواست ثبت کنید.</p>
                <a class="btn btn-primary" href="<?= APP_BASE_PATH ?>/support.php?v=ask">ثبت درخواست</a>
            </div>
        <?php else: ?>
            <p class="sup-note"><?= h(toPersianDigits((string)count($results))) ?> پاسخ برای «<?= h($q) ?>»</p>
            <div class="card">
                <?php foreach ($results as $a): ?>
                    <details class="sup-article">
                        <summary><?= h($a['title']) ?><span class="sup-cat-tag"><?= h(Support::categoryLabel($a['category'])) ?></span></summary>
                        <div class="sup-article-body"><?= nl2br(h($a['body'])) ?></div>
                    </details>
                <?php endforeach; ?>
            </div>
            <div class="card sup-still">
                <p>جوابتان را نگرفتید؟</p>
                <a class="btn btn-primary" href="<?= APP_BASE_PATH ?>/support.php?v=ask">ثبت درخواست</a>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php $all = Support::articles('', 200); $byCat = []; ?>
        <?php foreach ($all as $a) { $byCat[$a['category']][] = $a; } ?>
        <?php if (!$all): ?>
            <div class="card sup-empty">
                <h2>هنوز راهنمایی نوشته نشده</h2>
                <p>می‌توانید مستقیم درخواست ثبت کنید.</p>
                <a class="btn btn-primary" href="<?= APP_BASE_PATH ?>/support.php?v=ask">ثبت درخواست</a>
            </div>
        <?php else: ?>
            <?php foreach (Support::CATEGORIES as $key => $label): ?>
                <?php if (empty($byCat[$key])) { continue; } ?>
                <div class="card sup-cat-card">
                    <h2 class="sup-cat-title"><?= h($label) ?></h2>
                    <?php foreach ($byCat[$key] as $a): ?>
                        <details class="sup-article">
                            <summary><?= h($a['title']) ?></summary>
                            <div class="sup-article-body"><?= nl2br(h($a['body'])) ?></div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

<?php elseif ($view === 'ask'): ?>
    <!-- ═══════════ دروازه: اول راهنمای همان موضوع ═══════════ -->
    <?php if ($cat === '' || !isset(Support::CATEGORIES[$cat])): ?>
        <div class="card">
            <h2 class="sup-cat-title">موضوع درخواستتان چیست؟</h2>
            <p class="sup-note">با انتخابِ موضوع، اول راهکارهای مرتبط را نشانتان می‌دهیم.</p>
            <div class="sup-pick">
                <?php foreach (Support::CATEGORIES as $key => $label): ?>
                    <a class="sup-pick-item" href="<?= APP_BASE_PATH ?>/support.php?v=ask&amp;c=<?= h($key) ?>"><?= h($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php else: ?>
        <?php $related = Support::articles($cat, 20); ?>
        <div class="card">
            <h2 class="sup-cat-title"><?= h(Support::categoryLabel($cat)) ?></h2>
            <?php if ($related): ?>
                <p class="sup-note">پیش از ثبتِ درخواست، ببینید جوابتان این‌جا نیست:</p>
                <?php foreach ($related as $a): ?>
                    <details class="sup-article">
                        <summary><?= h($a['title']) ?></summary>
                        <div class="sup-article-body"><?= nl2br(h($a['body'])) ?></div>
                    </details>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="sup-note">برای این موضوع هنوز راهنمایی ننوشته‌ایم.</p>
            <?php endif; ?>
        </div>
        <?php /* ⛔ دو راه، و فقط دومی فرم را باز می‌کند — همان دروازه‌ای
                 که خواسته شده. هر دو **لینک**اند، نه دکمه‌ی جاوااسکریپتی. */ ?>
        <div class="card sup-gate">
            <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/support.php?v=home">مشکلم حل شد</a>
            <a class="btn btn-primary" href="<?= APP_BASE_PATH ?>/support.php?v=new&amp;c=<?= h($cat) ?>">هنوز مشکل دارم</a>
        </div>
    <?php endif; ?>

<?php elseif ($view === 'new'): ?>
    <!-- ═══════════ فرمِ کوتاهِ ثبت ═══════════ -->
    <?php if (!isset(Support::CATEGORIES[$cat])) { $cat = 'other'; } ?>
    <div class="card">
        <h2 class="sup-cat-title">ثبت درخواست</h2>
        <form method="post" enctype="multipart/form-data" action="<?= APP_BASE_PATH ?>/support.php?v=new&amp;c=<?= h($cat) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label for="supCat">دسته‌بندی</label>
                <select id="supCat" name="category">
                    <?php foreach (Support::CATEGORIES as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= $key === $cat ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="supSubject">موضوع</label>
                <input type="text" id="supSubject" name="subject" maxlength="200" required
                       value="<?= h((string)postParam('subject')) ?>" placeholder="در یک جمله">
            </div>
            <div class="form-group">
                <label for="supBody">شرح مشکل</label>
                <textarea id="supBody" name="body" rows="6" required
                          placeholder="چه کاری کردید، چه انتظاری داشتید، و چه دیدید؟"><?= h((string)postParam('body')) ?></textarea>
            </div>
            <div class="form-group">
                <label for="supFile">تصویر یا فایل (اختیاری)</label>
                <input type="file" id="supFile" name="file" accept="image/jpeg,image/png,image/webp,application/pdf">
                <p class="hint">JPG، PNG، WEBP یا PDF — حداکثر ۳ مگابایت.</p>
            </div>
            <?php /* ⚠ نسخه و مرورگر را سیستم خودش به تیکت اضافه می‌کند و
                     از کاربر پرسیده نمی‌شود — خواسته‌ی صریحِ مالکِ نصب.
                     همین‌جا نوشته می‌شود تا پنهان نباشد. */ ?>
            <p class="hint sup-meta-note">نسخه‌ی برنامه و اطلاعات فنیِ لازم خودکار به درخواست اضافه می‌شود؛ چیزی از شما پرسیده نمی‌شود.</p>
            <div class="modal-actions">
                <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/support.php?v=ask&amp;c=<?= h($cat) ?>">بازگشت</a>
                <button type="submit" class="btn btn-primary">ثبت درخواست</button>
            </div>
        </form>
    </div>

<?php elseif ($view === 'ticket' && $ticket): ?>
    <!-- ═══════════ یک تیکت ═══════════ -->
    <?php $userMsgs = Support::userMessageCount($tid); ?>
    <div class="card sup-ticket-head">
        <div class="sup-ticket-top">
            <span class="sup-no ltr-num"><?= h(Support::ticketNo((int)$ticket['id'])) ?></span>
            <span class="sup-status sup-status-<?= h($ticket['status']) ?>"><?= h(Support::statusLabel($ticket['status'])) ?></span>
        </div>
        <h2 class="sup-subject"><?= h($ticket['subject']) ?></h2>
        <p class="sup-meta">
            <span><?= h(Support::categoryLabel($ticket['category'])) ?></span>
            <span>ثبت: <?= h(toJalali(substr((string)$ticket['created_at'], 0, 10))) ?></span>
            <span>آخرین فعالیت: <?= h(toJalali(substr((string)$ticket['last_activity_at'], 0, 10))) ?></span>
        </p>
    </div>

    <div class="card sup-thread">
        <?php foreach ($messages as $m): ?>
            <div class="sup-msg sup-msg-<?= h($m['sender']) ?>">
                <div class="sup-msg-who">
                    <?= $m['sender'] === 'admin' ? 'پشتیبانی' : 'شما' ?>
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

    <?php if ($ticket['status'] === 'closed'): ?>
        <div class="card sup-still">
            <p>این درخواست بسته شده است.</p>
            <a class="btn btn-primary" href="<?= APP_BASE_PATH ?>/support.php?v=ask">درخواست تازه</a>
        </div>
    <?php elseif ($userMsgs >= Support::MAX_USER_MESSAGES): ?>
        <?php /* ⛔ سقفِ پیام — همان «به چت بی‌نهایت تبدیل نکن». */ ?>
        <div class="card sup-still">
            <p>این گفت‌وگو به سقفِ پیام رسیده است. اگر موضوع تازه‌ای هست، لطفاً درخواستِ جدا ثبت کنید.</p>
            <a class="btn btn-primary" href="<?= APP_BASE_PATH ?>/support.php?v=ask">درخواست تازه</a>
        </div>
    <?php else: ?>
        <div class="card">
            <form method="post" enctype="multipart/form-data" action="<?= APP_BASE_PATH ?>/support.php?v=ticket&amp;t=<?= (int)$tid ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="reply">
                <div class="form-group">
                    <label for="supReply">پاسخ شما</label>
                    <textarea id="supReply" name="body" rows="4" required placeholder="اگر نکته‌ای مانده بنویسید"></textarea>
                </div>
                <div class="form-group">
                    <label for="supReplyFile">تصویر یا فایل (اختیاری)</label>
                    <input type="file" id="supReplyFile" name="file" accept="image/jpeg,image/png,image/webp,application/pdf">
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">ارسال</button>
                </div>
            </form>
        </div>
        <form method="post" class="sup-close-form" action="<?= APP_BASE_PATH ?>/support.php?v=ticket&amp;t=<?= (int)$tid ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="close">
            <button type="submit" class="btn btn-secondary">مشکلم حل شد، این درخواست را ببند</button>
        </form>
    <?php endif; ?>

<?php else: ?>
    <!-- ═══════════ درخواست‌های من ═══════════ -->
    <?php if (!$myList): ?>
        <div class="card sup-empty">
            <h2>هنوز درخواستی ثبت نکرده‌اید</h2>
            <p>اگر جوابتان را در راهنما پیدا نکردید، درخواست ثبت کنید.</p>
            <a class="btn btn-primary" href="<?= APP_BASE_PATH ?>/support.php?v=ask">ثبت درخواست</a>
        </div>
    <?php else: ?>
        <div class="card">
            <?php foreach ($myList as $t): ?>
                <a class="sup-row" href="<?= APP_BASE_PATH ?>/support.php?v=ticket&amp;t=<?= (int)$t['id'] ?>">
                    <span class="sup-row-main">
                        <span class="sup-row-title"><?= h($t['subject']) ?></span>
                        <span class="sup-row-sub">
                            <span class="ltr-num"><?= h(Support::ticketNo((int)$t['id'])) ?></span>
                            · <?= h(Support::categoryLabel($t['category'])) ?>
                            · <?= h(toJalali(substr((string)$t['last_activity_at'], 0, 10))) ?>
                        </span>
                    </span>
                    <span class="sup-row-side">
                        <?php if ((int)$t['user_unread'] === 1): ?><span class="sup-dot" title="پاسخ تازه"></span><?php endif; ?>
                        <span class="sup-status sup-status-<?= h($t['status']) ?>"><?= h(Support::statusLabel($t['status'])) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php pagedNav($myPg); ?>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
