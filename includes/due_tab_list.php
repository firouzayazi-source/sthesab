<?php
/**
 * زبانه‌ی «سررسیدها» — فهرستِ اقدام‌پذیر.
 *
 * ⛔ هیچ پولی اینجا ثبت نمی‌شود: «تسویه» فقط وضعیتِ خودِ سررسید را
 *    می‌بندد و لینکِ ردیف به صفحه‌ی همان بخش می‌رود که منطقِ پولش آنجاست.
 *    با ثبتِ پول از اینجا، یک پرداخت دو جا نوشته می‌شد.
 *
 * ⚠ قراردادِ ورودی **صریح** است، نه «هرچه در scopeِ صداکننده بود».
 *   همان الگوی `header.php` با `$pageTitle`: هر متغیری که از بیرون
 *   می‌آید اینجا نام برده می‌شود و اگر نیامده باشد مقدارِ امنِ خودش را
 *   می‌گیرد. بدونِ این، وابستگیِ پنهان به یک فایلِ دیگر می‌ماند که
 *   شکستنش هیچ خطایی نمی‌دهد — فقط یک صفحه‌ی نصفه.
 */
if (!defined('APP_BASE_PATH')) { http_response_code(404); exit; }

$userId = $userId ?? (int)Auth::userId();
$today  = $today  ?? today();
$ready  = $ready  ?? Schedule::available();
$filter = $filter ?? 'week';
$rows   = $rows   ?? [];
$counts = $counts ?? array_fill_keys(array_keys(DUE_FILTERS), 0);

/** رنگ و برچسب و مقصدِ هر نوع — تنها جای این نگاشت. */
function dueMeta(string $type): array
{
    return match ($type) {
        'cheque'       => ['چک', 'cheques.php', 'due-cheque'],
        'debt'         => ['طلب و بدهی', 'debts.php', 'due-debt'],
        'recurring_tx' => ['تراکنش دوره‌ای', 'recurring.php', 'due-recurring'],
        default        => ['یادآور', 'due.php?t=reminders', 'due-custom'],
    };
}

/* ⛔ «پول قابل خرج» عمداً افقِ **ثابتِ ۳۰ روزه** دارد و به صافیِ فهرست
     وابسته نیست. اگر با هر صافی عوض می‌شد، عددِ بالای صفحه با زدنِ
     «امروز» یا «همه» می‌پرید و کاربر نمی‌فهمید کدامش «موجودیِ واقعیِ
     قابل خرج» است. این کارت یک سؤالِ دیگر را جواب می‌دهد، نه سؤالِ
     فهرست.

   ⛔ و تنها جای این عدد `safeToSpend()` است. جمعِ دریافتی/پرداختیِ جدا
     — که نسخه‌ی قبلیِ این صفحه داشت — نسخه‌ی دومی از همان حقیقت بود و
     دیر یا زود با این یکی اختلاف پیدا می‌کرد. */
$sts = safeToSpend($userId, 30);
?>

<?php if ($sts['available'] !== null): ?>
<div class="balance-ribbon">
    <div class="balance-label">پول قابل خرج</div>
    <div class="balance-value">
        <span class="bv-num"><?= $sts['available'] < 0 ? '−' : '' ?><?= formatMoney(abs($sts['available'])) ?></span>
        <span class="bv-unit">تومان</span>
    </div>
    <div class="balance-split">
        <div>
            <div class="bs-label">موجودی کل</div>
            <div class="bs-value"><?= formatMoney($sts['balance']) ?></div>
        </div>
        <div class="bs-out-row">
            <div class="bs-label">تعهدات ۳۰ روز آینده</div>
            <div class="bs-value bs-out"><?= formatMoney($sts['commitments']) ?></div>
        </div>
    </div>
    <p class="hint" style="color:#8d939e; margin-top:11px;">
        این عدد موجودی بانکی شما نیست؛ موجودی منهای پرداخت‌های قطعی پیش‌رو است.
        <?php if (!empty($sts['overdue'])): ?>
            <?php /* بدون این خط، کاربر می‌دید عدد از موجودی کمتر است ولی
                     نمی‌فهمید چرا — تعهدهای سررسیدگذشته در فهرستِ
                     «۳۰ روز آینده» پیدا نمی‌شوند. */ ?>
            <br>از این مبلغ، <strong><?= formatMoney($sts['overdue']) ?></strong> تومان
            سررسیدش گذشته است.
        <?php endif; ?>
    </p>
</div>
<?php endif; ?>

<?php if (!$ready): ?>

    <?php /* ⛔ نصبی که هنوز migration نخورده نباید صفحه‌ی خالی ببیند.
             پیش از یکی شدنِ صفحه‌ها، «آینده مالی» بدونِ هیچ جدولِ
             سررسیدی کار می‌کرد؛ اگر اینجا فقط می‌نوشتیم «migration را
             اجرا کنید»، یک صفحه‌ی سالم را **از کار انداخته** بودیم.
             پس همان نمای قدیمی از `financialEvents()` رندر می‌شود —
             بدونِ دکمه‌های اقدام، که آن‌ها به جدولِ occurrence بند
             هستند. */ ?>
    <?php
    $fallback = financialEvents(
        $userId,
        date('Y-m-d', strtotime('-90 days')),
        date('Y-m-d', strtotime('+30 days'))
    );
    $fbOver = array_values(array_filter($fallback, fn($e) => $e['is_overdue']));
    $fbNext = array_values(array_filter($fallback, fn($e) => !$e['is_overdue']));
    ?>

    <?php if ($fbOver): ?>
    <div class="card" style="border:1px solid var(--out);">
        <h2 class="card-title" style="color:var(--out);">سررسید گذشته</h2>
        <?php foreach ($fbOver as $e): ?>
            <a href="<?= h($e['url']) ?>" class="event-row">
                <span class="event-when event-overdue"><?= humanDaysUntil($e['date']) ?></span>
                <span class="event-body">
                    <span class="event-title"><?= h($e['title']) ?></span>
                    <span class="event-meta"><?= eventKindLabel($e['kind']) ?> · <?= toJalali($e['date']) ?></span>
                </span>
                <span class="event-amount <?= $e['direction'] === 'in' ? 'amount-income' : 'amount-expense' ?>">
                    <?= $e['direction'] === 'in' ? '+' : '−' ?><?= formatMoney($e['amount']) ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2 class="card-title">رویدادهای پیش‌رو</h2>
        <?php if (!$fbNext): ?>
            <p class="empty-row">در ۳۰ روز آینده رویداد مالی ثبت‌شده‌ای ندارید.</p>
        <?php else: ?>
            <?php $lastDate = null; foreach ($fbNext as $e): ?>
                <?php if ($lastDate !== $e['date']): $lastDate = $e['date']; ?>
                    <div class="event-daybreak"><?= humanDaysUntil($e['date']) ?> — <?= toJalali($e['date']) ?></div>
                <?php endif; ?>
                <a href="<?= h($e['url']) ?>" class="event-row">
                    <span class="event-dot event-dot-<?= $e['direction'] ?>"></span>
                    <span class="event-body">
                        <span class="event-title"><?= h($e['title']) ?></span>
                        <span class="event-meta"><?= eventKindLabel($e['kind']) ?></span>
                    </span>
                    <span class="event-amount <?= $e['direction'] === 'in' ? 'amount-income' : 'amount-expense' ?>">
                        <?= $e['direction'] === 'in' ? '+' : '−' ?><?= formatMoney($e['amount']) ?>
                    </span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php else: ?>

<nav class="due-filters">
    <?php foreach (DUE_FILTERS as $key => $label): ?>
        <a href="?t=list&f=<?= h($key) ?>" class="due-filter <?= $filter === $key ? 'is-active' : '' ?>">
            <?= h($label) ?>
            <?php if ($counts[$key] > 0): ?>
                <span class="due-count"><?= toPersianDigits((string)$counts[$key]) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<div class="card">
    <?php if (!$rows): ?>
        <p class="hint">در این بازه سررسیدی نیست.</p>
    <?php else: ?>
        <ul class="due-list">
            <?php foreach ($rows as $o): ?>
                <?php
                    [$typeLabel, $url, $cls] = dueMeta((string)$o['source_type']);
                    $late = $o['due_date'] < $today;
                    $days = (int)floor((strtotime($o['due_date']) - strtotime($today)) / 86400);
                ?>
                <li class="due-row">
                    <span class="due-dot <?= h($cls) ?>" title="<?= h($typeLabel) ?>"></span>

                    <a class="due-main" href="<?= APP_BASE_PATH ?>/<?= h($url) ?>">
                        <div class="due-title"><?= h($o['title']) ?></div>
                        <div class="due-meta">
                            <span class="due-type"><?= h($typeLabel) ?></span>
                            <span class="<?= $late ? 'is-late' : ($days === 0 ? 'is-today' : '') ?>">
                                <?= toPersianDigits(toJalali($o['due_date'])) ?>
                                ·
                                <?php if ($late): ?>
                                    <?= toPersianDigits((string)abs($days)) ?> روز گذشته
                                <?php elseif ($days === 0): ?>
                                    امروز
                                <?php else: ?>
                                    <?= toPersianDigits((string)$days) ?> روز مانده
                                <?php endif; ?>
                            </span>
                            <?php if (!empty($o['amount'])): ?>
                                <span class="due-amount ltr-num"><?= formatMoney((int)$o['amount']) ?></span>
                            <?php endif; ?>
                        </div>
                    </a>

                    <?php /* سه اقدامِ ثابت برای همه‌ی انواع. «تسویه» فقط
                             وضعیت را می‌بندد و پول را همان بخش ثبت
                             می‌کند — لینکِ بالا به آنجا می‌رود. */ ?>
                    <div class="due-actions">
                        <form method="POST" action="<?= APP_BASE_PATH ?>/api/due_action.php" class="js-due-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                            <input type="hidden" name="action" value="done">
                            <button type="submit" class="due-btn due-btn-ok" title="تسویه شد">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                            </button>
                        </form>
                        <form method="POST" action="<?= APP_BASE_PATH ?>/api/due_action.php" class="js-due-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                            <input type="hidden" name="action" value="snooze">
                            <input type="hidden" name="days" value="7">
                            <button type="submit" class="due-btn" title="تعویق ۷ روز">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            </button>
                        </form>
                        <form method="POST" action="<?= APP_BASE_PATH ?>/api/due_action.php" class="js-due-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                            <input type="hidden" name="action" value="skip">
                            <button type="submit" class="due-btn due-btn-mute" title="رد کردن">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
                            </button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php endif; ?>
