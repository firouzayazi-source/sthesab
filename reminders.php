<?php
/**
 * یادآورهای من — تاریخ‌هایی که کاربر خودش تعیین می‌کند.
 *
 * ⛔ چرا جدا از «تراکنش دوره‌ای»: تراکنش دوره‌ای **پول جابه‌جا می‌کند**
 *    و در گزارش می‌نشیند. یادآور هیچ پولی جابه‌جا نمی‌کند — فقط می‌گوید
 *    «سرِ این تاریخ حواست باشد». بیمه‌ی آتش‌سوزی، تمدید گواهینامه،
 *    عوارض خودرو: مبلغش را از قبل نمی‌دانی و تراکنشِ خودکارش غلط است.
 *    اگر یکی می‌بودند، یا یادآور در گزارش هزینه می‌آمد (دروغ) یا
 *    تراکنش دوره‌ای باید مبلغِ اختیاری می‌گرفت (که یعنی تراکنشِ صفر).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notify.php';

Auth::initSession();
Auth::requireLogin();

$userId = Auth::userId();
$pdo    = Database::getConnection();
$ready  = Notify::remindersAvailable();

$rows = [];
if ($ready) {
    // ⚠ ترتیب: انجام‌نشده‌ها اول و نزدیک‌ترین بالا؛ انجام‌شده‌ها ته فهرست.
    $stmt = $pdo->prepare('
        SELECT * FROM reminders
        WHERE user_id = :u
        ORDER BY is_done ASC, remind_date ASC, id ASC
    ');
    $stmt->execute(['u' => $userId]);
    $rows = $stmt->fetchAll();
}

$today = today();

$pageTitle = 'یادآورها';
include __DIR__ . '/includes/header.php';
?>

<a href="<?= APP_BASE_PATH ?>/index.php" class="page-back js-page-back">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M11 6l-6 6 6 6"/></svg>
    <span>بازگشت</span>
</a>

<?php if (!$ready): ?>
    <div class="card">
        <p class="hint">
            جدولِ یادآورها هنوز ساخته نشده است. ابتدا migration ها را اعمال کنید
            (<code>deploy/migrate.sh --apply</code>).
        </p>
    </div>
<?php else: ?>

<div class="card">
    <h2 class="card-title">یادآور تازه</h2>
    <p class="hint" style="margin-bottom:12px;">
        سرِ تاریخی که تعیین می‌کنید، یادآور در «اعلان‌ها» ظاهر می‌شود و
        اگر ایمیلِ یادآوری روشن باشد در ایمیلِ روزانه هم می‌آید.
    </p>
    <form id="reminderForm" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" id="rm_id" value="">

        <div class="form-group">
            <label for="rm_title">برای چه چیزی؟ <span class="req">*</span></label>
            <input type="text" id="rm_title" name="title" maxlength="200" required
                   placeholder="مثلاً بیمه آتش‌سوزی خانه">
        </div>

        <div class="form-group">
            <label>تاریخ <span class="req">*</span></label>
            <?php /* همان الگوی تقویمِ بقیه‌ی اپ: نمایشِ شمسی + مقدارِ
                     میلادیِ پنهان. تبدیل فقط با همان یک پیاده‌سازی
                     انجام می‌شود، نه یک تقویمِ تازه. */ ?>
            <div class="jdp-field">
                <input type="text" class="jdp-display" id="rm_date_display" readonly
                       value="<?= toJalali($today) ?>">
                <input type="hidden" class="jdp-hidden" id="rm_date" name="remind_date"
                       value="<?= h($today) ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="rm_amount">مبلغ (اختیاری)</label>
            <input type="text" id="rm_amount" name="amount" inputmode="numeric"
                   class="amount-input-sm" placeholder="اگر می‌دانید چقدر است">
        </div>

        <div class="form-group">
            <label for="rm_repeat">تکرار</label>
            <select id="rm_repeat" name="repeat_every">
                <option value="none">یک بار</option>
                <option value="monthly">هر ماه</option>
                <option value="yearly">هر سال</option>
            </select>
            <p class="hint">
                بعد از اینکه «انجام شد» بزنید، یادآورِ تکرارشونده خودش به
                دوره‌ی بعد می‌رود.
            </p>
        </div>

        <div class="form-group">
            <label for="rm_note">توضیح (اختیاری)</label>
            <input type="text" id="rm_note" name="note" maxlength="500"
                   placeholder="مثلاً شعبه‌ی مرکزی، کد پیگیری ۱۲۳">
        </div>

        <button type="submit" class="btn btn-primary btn-block" data-busy="در حال ذخیره…">ذخیره یادآور</button>
        <button type="button" class="btn btn-secondary btn-block js-reminder-reset"
                style="margin-top:8px;" hidden>انصراف از ویرایش</button>
    </form>
</div>

<div class="card">
    <h2 class="card-title">یادآورهای من</h2>
    <?php if (!$rows): ?>
        <p class="hint">هنوز یادآوری ثبت نکرده‌اید.</p>
    <?php else: ?>
        <ul class="reminder-list">
            <?php foreach ($rows as $r): ?>
                <?php
                    $done = (int)$r['is_done'] === 1;
                    $late = !$done && $r['remind_date'] < $today;
                    $soon = !$done && $r['remind_date'] === $today;
                ?>
                <li class="reminder-row <?= $done ? 'is-done' : '' ?>">
                    <form method="POST" action="<?= APP_BASE_PATH ?>/api/save_reminder.php"
                          class="js-reminder-done">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="toggle_done" value="1">
                        <button type="submit" class="debt-check <?= $done ? 'is-on' : '' ?>"
                                aria-label="<?= $done ? 'برگرداندن' : 'انجام شد' ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                        </button>
                    </form>

                    <div class="reminder-main">
                        <div class="reminder-title"><?= h($r['title']) ?></div>
                        <div class="reminder-meta">
                            <span class="<?= $late ? 'is-late' : ($soon ? 'is-today' : '') ?>">
                                <?= $late ? 'گذشته · ' : ($soon ? 'امروز · ' : '') ?><?= toPersianDigits(toJalali($r['remind_date'])) ?>
                            </span>
                            <?php if (!empty($r['amount'])): ?>
                                <span class="reminder-amount ltr-num"><?= formatMoney((int)$r['amount']) ?></span>
                            <?php endif; ?>
                            <?php if ($r['repeat_every'] !== 'none'): ?>
                                <span class="asset-tag"><?= $r['repeat_every'] === 'monthly' ? 'ماهانه' : 'سالانه' ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($r['note'])): ?>
                            <div class="reminder-note"><?= h($r['note']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="reminder-actions">
                        <button type="button" class="btn btn-secondary btn-sm js-reminder-edit"
                                data-id="<?= (int)$r['id'] ?>"
                                data-title="<?= h($r['title']) ?>"
                                data-date="<?= h(toJalali($r['remind_date'])) ?>"
                                data-amount="<?= (int)($r['amount'] ?? 0) ?>"
                                data-repeat="<?= h($r['repeat_every']) ?>"
                                data-note="<?= h((string)($r['note'] ?? '')) ?>"
                                aria-label="ویرایش">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/></svg>
                        </button>
                        <form method="POST" action="<?= APP_BASE_PATH ?>/api/delete_reminder.php"
                              class="js-reminder-delete" style="display:inline;">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm" aria-label="حذف">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>
                            </button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
