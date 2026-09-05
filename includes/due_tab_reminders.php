<?php
/**
 * زبانه‌ی «یادآورهای من» — تاریخ‌هایی که کاربر خودش تعیین می‌کند.
 *
 * ⛔ چرا جدا از «تراکنش دوره‌ای»: تراکنش دوره‌ای **پول جابه‌جا می‌کند**
 *    و در گزارش می‌نشیند. یادآور هیچ پولی جابه‌جا نمی‌کند — فقط می‌گوید
 *    «سرِ این تاریخ حواست باشد». بیمه‌ی آتش‌سوزی، تمدید گواهینامه،
 *    عوارض خودرو: مبلغش را از قبل نمی‌دانی و تراکنشِ خودکارش غلط است.
 *
 * ⚠ قراردادِ ورودی صریح است — همان الگوی `header.php` با `$pageTitle`.
 */
if (!defined('APP_BASE_PATH')) { http_response_code(404); exit; }

$userId = $userId ?? (int)Auth::userId();
$today  = $today  ?? today();
$pdo    = $pdo    ?? Database::getConnection();

$ready = Notify::remindersAvailable();

$rows = [];
if ($ready) {
    // ⚠ ترتیب: انجام‌نشده‌ها اول و نزدیک‌ترین بالا؛ انجام‌شده‌ها ته فهرست.
    // ⛔ فقط یادآورهای **دلخواهِ خودِ کاربر**، نه قانون‌های چک و بدهی.
    //
    //    `syncScheduleRules()` برای هر چک و طلب و بدهی و تراکنشِ دوره‌ای
    //    یک ردیف در همین جدول می‌سازد (`source_type` مقدارش را می‌گوید).
    //    این کوئری قبل از آمدنِ آن ستون نوشته شده بود و همه را می‌آورد،
    //    پس این صفحه پر شد از چیزهایی که **بخشِ خودشان** از قبل خبرشان
    //    را می‌دهد — و کاربر همان یادآورِ چک را دو جا می‌دید.
    //
    // ⚠ `source_type IS NULL` هم می‌آید: ردیف‌هایی که پیش از migration
    //   ساخته شده‌اند مالِ خودِ کاربرند و نباید ناپدید شوند.
    $stmt = $pdo->prepare('
        SELECT * FROM reminders
        WHERE user_id = :u
          AND (source_type = \'custom\' OR source_type IS NULL)
        ORDER BY is_done ASC, remind_date ASC, id ASC
    ');
    $stmt->execute(['u' => $userId]);
    $rows = $stmt->fetchAll();
}
?>

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

        <?php /* ⛔ چیپ‌های آماده، چون بیشترِ یادآورهای واقعیِ یک خانوار
                 همین چهار تا هستند و تایپ کردنِ «بیمه‌ی شخص ثالث» روی
                 کیبوردِ گوشی همان اصطکاکی است که باعث می‌شود کاربر اصلاً
                 ثبت نکند. هرکدام دوره‌ی متعارفِ خودش را هم می‌گذارد —
                 ولی همه چیز بعدش قابل تغییر است، چون این پیشنهاد است نه
                 تصمیم. «سایر» فرم را خالی می‌کند تا حالتِ دستی هم یک
                 انتخابِ صریح باشد، نه «هیچ‌کدام را نزن».

                 ⚠ فقط UI است و به سرور نمی‌رود؛ چیزی که ذخیره می‌شود
                   همان عنوان و دوره است. پس فهرستِ دومی در برابرِ
                   `Schedule::RECUR_PRESETS` نمی‌سازد. */ ?>
        <div class="form-group">
            <label>چه چیزی؟</label>
            <div class="stay-chips rm-chips" id="rm_presets">
                <?php
                $titlePresets = [
                    ['t' => 'بیمه تأمین اجتماعی', 'r' => 'm3'],
                    ['t' => 'بیمه خودرو',          'r' => 'y1'],
                    ['t' => 'بیمه شخص ثالث',       'r' => 'y1'],
                    ['t' => 'اقساط بانک',          'r' => 'm1'],
                    ['t' => '',                     'r' => 'once', 'l' => 'سایر'],
                ];
                foreach ($titlePresets as $p): ?>
                    <button type="button" class="stay-chip" data-title="<?= h($p['t']) ?>"
                            data-repeat="<?= h($p['r']) ?>"><?= h($p['l'] ?? $p['t']) ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group">
            <label for="rm_title">عنوان <span class="req">*</span></label>
            <input type="text" id="rm_title" name="title" maxlength="200" required
                   placeholder="مثلاً بیمه آتش‌سوزی خانه">
        </div>

        <div class="form-group">
            <?php /* ⚠ «تاریخ» به‌تنهایی نمی‌گفت تاریخِ چه چیزی — کاربر
                     نمی‌دانست روزِ سررسید را بنویسد یا روزی که می‌خواهد
                     خبردار شود (آن یکی پایین‌تر و جداست). */ ?>
            <label>تاریخ سررسید <span class="req">*</span></label>
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

        <?php /* ⛔ «هر ماه / هر سال» برای بیمه‌ی تأمین اجتماعی (هر ۴ ماه) و
                 خیلی از تعهدهای واقعی کافی نبود، ولی منوی کشویی هم
                 جوابش نبود: کاربر باید منو را باز می‌کرد، گزینه‌ی «هر چند
                 ماه» را می‌دید، انتخاب می‌کرد، و **بعد** یک فیلدِ عددی
                 ظاهر می‌شد — سه حرکت برای چیزی که یک تپ است.

                 حالا چیپ‌های آماده از هفتگی تا سالانه در یک نگاه دیده
                 می‌شوند و «دلخواه» همان N + واحد را برای بقیه‌ی حالت‌ها
                 نگه می‌دارد (تأمین اجتماعیِ هر ۴ ماه). فهرست از
                 `Schedule::RECUR_PRESETS` می‌آید، نه از اینجا. */ ?>
        <div class="form-group">
            <label>تکرار</label>
            <div class="stay-chips rm-chips" id="rm_repeat_chips">
                <?php foreach (Schedule::RECUR_PRESETS as $key => $p): ?>
                    <button type="button" class="stay-chip<?= $key === 'once' ? ' active' : '' ?>"
                            data-key="<?= h($key) ?>" data-type="<?= h($p['type']) ?>"
                            data-n="<?= (int)$p['n'] ?>"><?= h($p['label']) ?></button>
                <?php endforeach; ?>
                <button type="button" class="stay-chip" data-key="custom"
                        data-type="every_n_months" data-n="4">دلخواه</button>
            </div>
            <input type="hidden" name="recurrence_type" id="rm_rtype" value="once">
            <input type="hidden" name="recurrence_n" id="rm_rn" value="1">

            <div class="rm-custom" id="rm_custom" hidden>
                <label for="rm_n" class="stay-choices-label">هر چند بار یک بار؟</label>
                <div class="rm-custom-row">
                    <input type="number" id="rm_n" min="1" max="60" value="4"
                           inputmode="numeric" class="amount-input-sm">
                    <select id="rm_unit">
                        <option value="every_n_months">ماه</option>
                        <option value="every_n_days">روز</option>
                    </select>
                </div>
            </div>
        </div>

        <?php /* ⛔ «۱۲ قسط» با یک بار ثبت، نه دوازده بار.
                 هیچ ردیفِ قسطی ذخیره نمی‌شود — فقط «چندمین از چندتا» روی
                 خودِ قانون می‌نشیند؛ همان مدلی که `debtInstallments()`
                 دارد و دلیلش در `migration_reminder_plan.sql` نوشته شده. */ ?>
        <div class="form-group" id="rm_count_wrap" hidden>
            <label for="rm_count">چند بار تکرار شود؟</label>
            <input type="number" id="rm_count" name="total_count" min="0" max="600" value="0"
                   inputmode="numeric" class="amount-input-sm">
            <p class="hint"><span class="ltr-num">۰</span> یعنی بی‌پایان (تا وقتی خودتان حذفش کنید).</p>
        </div>

        <div class="form-group">
            <label for="rm_amount">مبلغ (اختیاری)</label>
            <input type="text" id="rm_amount" name="amount" inputmode="numeric"
                   class="amount-input-sm" placeholder="اگر می‌دانید چقدر است">
            <?php /* ⛔ بدونِ این انتخاب، «۱۲ قسط، ۱۲ میلیون» دو معنی دارد
                     و هیچ‌کدام از دیگری واضح‌تر نیست: ۱۲ میلیون در هر
                     قسط، یا ۱۲ میلیونِ کل. حدس زدنش یعنی عددِ کارت
                     ده‌برابرِ واقعیت شود، بی‌هیچ خطایی. */ ?>
            <div class="stay-chips rm-chips" id="rm_amount_mode" hidden>
                <button type="button" class="stay-chip active" data-mode="each">مبلغ هر قسط</button>
                <button type="button" class="stay-chip" data-mode="total">جمع کل</button>
            </div>
            <input type="hidden" name="amount_mode" id="rm_amode" value="each">
            <p class="hint" id="rm_plan_note" hidden></p>
        </div>

        <?php /* ⛔ چند بازه با هم، نه یکی. کسی که می‌خواهد یک هفته قبل
                 خبردار شود اغلب می‌خواهد یک روز قبل هم یادش بیفتد. ستون
                 `notify_days_before` از اول آرایه‌ی JSON بود؛ فقط فرمش
                 نبود.

                 ⚠ و فرمِ اولش غلط بود: `.switch` یک ردیفِ تمام‌عرض است
                   (`display:flex` با `.switch-text{flex:1}`)، پس چهار
                   کلید زیرِ هم می‌افتادند و دستگیره‌ی سفید کنارِ متن
                   شبیهِ یک تکه‌ی جامانده دیده می‌شد. کلید برای «روشن یا
                   خاموش» است؛ اینجا انتخابِ چندتایی از یک مجموعه است و
                   زبانِ طراحیِ خودِ پروژه برایش از قبل `.stay-chip` را
                   داشت (پروفایل و سرنوشتِ چک). */ ?>
        <div class="form-group">
            <label>چند روز قبل خبر بدهد؟</label>
            <div class="stay-chips rm-chips" id="rm_notify_days">
                <?php
                // ⚠ برچسب‌ها اینجا هستند ولی **فهرستِ مقادیر** از
                //   `Notify::REMINDER_STEPS` می‌آید: اگر بازه‌ای اضافه شود
                //   و اینجا جا بماند، چیپش اصلاً رندر نمی‌شود — که دیدنی
                //   است. برعکسش (چیپی که سرور قبولش نکند) بی‌صداست.
                $dayLabels = [1 => 'یک روز', 3 => 'سه روز', 7 => 'یک هفته', 30 => 'یک ماه'];
                foreach (Notify::REMINDER_STEPS as $d): ?>
                    <button type="button" class="stay-chip<?= $d === 1 ? ' active' : '' ?>"
                            data-day="<?= (int)$d ?>"><?= h($dayLabels[$d] ?? toPersianDigits((string)$d) . ' روز') ?> قبل</button>
                <?php endforeach; ?>
            </div>
            <?php /* ⚠ مقدارِ واقعی در یک فیلدِ پنهان است نه در خودِ چیپ‌ها:
                     `<button>` چیزی به `FormData` اضافه نمی‌کند. */ ?>
            <input type="hidden" name="notify_days" id="rm_days" value="[1]">
            <p class="hint">هرکدام را که خواستید بزنید — هر سه هم می‌شود.</p>
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

                    // ⚠ ستون‌های چنددوره‌ای ممکن است هنوز با migration
                    //   نیامده باشند — همان قاعده‌ی `walletBalances()`:
                    //   نبودنشان نباید صفحه را بشکند.
                    $tCount = (int)($r['total_count'] ?? 0);
                    $dCount = (int)($r['done_count'] ?? 0);
                    $shown  = Schedule::installmentAmount([
                        'total_count'  => $tCount,
                        'total_amount' => (int)($r['total_amount'] ?? 0),
                        'done_count'   => $dCount,
                        'amount'       => (int)($r['amount'] ?? 0),
                    ]);
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
                            <?php if ($shown > 0): ?>
                                <span class="reminder-amount ltr-num"><?= formatMoney($shown) ?></span>
                            <?php endif; ?>
                            <?php
                                $rt = (string)($r['recurrence_type'] ?? 'once');
                                $rn = max(1, (int)($r['recurrence_n'] ?? 1));
                                // ⚠ برچسب از همان فهرستِ چیپ‌ها می‌آید تا آنچه
                                //   کاربر هنگام ثبت زده با آنچه بعداً می‌خواند
                                //   یکی باشد؛ فقط حالتِ «دلخواه» ساخته می‌شود.
                                $pKey     = Schedule::presetKey($rt, $rn);
                                $repLabel = $pKey !== 'custom'
                                    ? (Schedule::RECUR_PRESETS[$pKey]['label'] ?? '')
                                    : 'هر ' . toPersianDigits((string)$rn)
                                      . ($rt === 'every_n_days' ? ' روز' : ' ماه');
                                if ($rt === 'once') { $repLabel = ''; }
                            ?>
                            <?php if ($repLabel !== ''): ?>
                                <span class="asset-tag"><?= h($repLabel) ?></span>
                            <?php endif; ?>
                            <?php if ($tCount > 1): ?>
                                <?php /* «قسط ۳ از ۱۲» — همان خطی که کارتِ بدهیِ
                                         قسطی دارد، به همان دلیل: بدونش کاربر
                                         نمی‌داند چقدر از تعهدش مانده. */ ?>
                                <span class="asset-tag">قسط <?= toPersianDigits((string)min($dCount + 1, $tCount)) ?>
                                    از <?= toPersianDigits((string)$tCount) ?></span>
                            <?php endif; ?>
                            <?php if ($tCount > 1 && (int)($r['total_amount'] ?? 0) > 0): ?>
                                <span class="asset-tag">جمع کل <span class="ltr-num"><?= formatMoney((int)$r['total_amount']) ?></span></span>
                            <?php endif; ?>
                            <?php
                                $days = json_decode((string)($r['notify_days_before'] ?? '[1]'), true);
                                $days = is_array($days) ? array_values(array_filter(array_map('intval', $days))) : [];
                                rsort($days);
                            ?>
                            <?php if ($days): ?>
                                <span class="asset-tag">اعلان: <?= toPersianDigits(implode('، ', $days)) ?> روز قبل</span>
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
                                data-rtype="<?= h((string)($r['recurrence_type'] ?? 'once')) ?>"
                                data-rn="<?= (int)($r['recurrence_n'] ?? 1) ?>"
                                data-rkey="<?= h($pKey) ?>"
                                data-count="<?= $tCount ?>"
                                data-total="<?= (int)($r['total_amount'] ?? 0) ?>"
                                data-days="<?= h((string)($r['notify_days_before'] ?? '[1]')) ?>"
                                data-note="<?= h((string)($r['note'] ?? '')) ?>"
                                aria-label="ویرایش">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/></svg>
                        </button>
                        <?php /* ⛔ «به تأخیر» جدا از «انجام شد» است و جدا از «حذف».
                                 بدونِ آن، کاربری که قبضش را هنوز نداده تنها
                                 راهش «انجام شد» زدن بود — یعنی دروغ گفتن به
                                 دفترِ خودش — یا حذف کردن، که یادآور را برای
                                 همیشه می‌برد. */ ?>
                        <?php if (!$done): ?>
                        <form method="POST" action="<?= APP_BASE_PATH ?>/api/save_reminder.php"
                              class="js-reminder-done" style="display:inline;">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="snooze_days" value="7">
                            <button type="submit" class="btn btn-secondary btn-sm"
                                    aria-label="یک هفته به تأخیر بینداز" title="یک هفته به تأخیر">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            </button>
                        </form>
                        <?php endif; ?>
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

