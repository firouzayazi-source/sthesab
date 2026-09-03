<?php
/**
 * فهرست‌های من — یک جای واحد برای همه‌ی فهرست‌های کوچکی که بقیه‌ی اپ از
 * آن‌ها انتخاب می‌کند: بانک‌ها، انواع دارایی، انواع حساب.
 *
 * پیش از این هر کدام ته صفحه‌ی مربوط به خودش قایم شده بود (بانک‌ها ته
 * صفحه‌ی چک‌ها، انواع دارایی ته صفحه‌ی دارایی‌ها) و کاربر نمی‌دانست کجا
 * باید دنبالشان بگردد. حالا همه یک‌جا هستند و هر کدام می‌گوید کجای اپ
 * به کارش می‌آید.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();

seedUserDefaults($userId);

$people = peopleList($userId);

$myBanks = [];
$externalBanks = [];
try {
    $st = $pdo->prepare('SELECT id, name, scope FROM banks WHERE user_id = :u ORDER BY scope, name');
    $st->execute(['u' => $userId]);
    foreach ($st->fetchAll() as $b) {
        if ($b['scope'] === 'mine') { $myBanks[] = $b; } else { $externalBanks[] = $b; }
    }
} catch (PDOException $e) {
    $myBanks = $externalBanks = [];
}

$assetTypes = [];
try {
    $st = $pdo->prepare('SELECT id, name, unit FROM asset_types WHERE user_id = :u ORDER BY name');
    $st->execute(['u' => $userId]);
    $assetTypes = $st->fetchAll();
} catch (PDOException $e) {
    $assetTypes = [];
}

$kinds = walletKinds($userId);

// دسته‌بندی‌ها: پیش‌فرض‌های برنامه (user_id NULL) به‌علاوه‌ی شخصی‌های
// خودِ کاربر. فقط شخصی‌ها دکمه‌ی حذف می‌گیرند.
$myCats = ['income' => [], 'expense' => []];
$defaultCats = ['income' => [], 'expense' => []];
$catsReady = tableHasColumn('categories', 'user_id');
if ($catsReady) {
    try {
        $st = $pdo->prepare(
            'SELECT id, name, type, user_id FROM categories
             WHERE is_active = 1 AND ' . categoryScopeSql() . '
             ORDER BY type, name'
        );
        $st->execute(categoryScopeParams($userId));
        foreach ($st->fetchAll() as $c) {
            if ($c['user_id'] === null) { $defaultCats[$c['type']][] = $c; }
            else                        { $myCats[$c['type']][] = $c; }
        }
    } catch (PDOException $e) {
        $catsReady = false;
    }
}

// چند تا از دسته‌های پیشنهادیِ خانوار را هنوز ندارد؟ اگر صفر باشد،
// ردیفِ پیشنهاد اصلاً رندر نمی‌شود — تنظیمی که کارش تمام شده نباید
// تا ابد روی صفحه بماند.
$missingSuggested = 0;
if ($catsReady) {
    $have = [];
    foreach (['income', 'expense'] as $t) {
        foreach (array_merge($defaultCats[$t], $myCats[$t]) as $c) {
            $have[$t . '|' . $c['name']] = true;
        }
    }
    foreach (suggestedHouseholdCategories() as $s) {
        if (!isset($have[$s['type'] . '|' . $s['name']])) { $missingSuggested++; }
    }
}

$pageTitle = 'فهرست‌های من';
include __DIR__ . '/includes/header.php';
?>

<a href="<?= APP_BASE_PATH ?>/index.php" class="page-back js-page-back">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M11 6l-6 6 6 6"/></svg>
    <span>بازگشت</span>
</a>

<p class="ref-page-intro">
    این‌ها فهرست‌هایی هستند که بقیه‌ی برنامه از آن‌ها انتخاب می‌کند.
    هر چیزی که اضافه کنید بلافاصله در فرم‌های مربوطه می‌آید، و هر چیزی
    که جایی استفاده شده باشد قابل حذف نیست.
</p>

<!-- ---------- بانک‌های من ---------- -->
<!-- ---------- اشخاص ----------
     اول می‌آید چون پرکاربردترین فهرست است: در چک، طلب و بدهی، و معامله
     استفاده می‌شود. ورودی آزاد در آن فرم‌ها سر جایش می‌ماند؛ این فهرست
     فقط تایپ دوباره و غلط تایپی را کم می‌کند. -->
<?php if (tableExists('people')): ?>
<div class="card ref-card">
    <div class="ref-card-head">
        <span class="ref-card-icon" style="--rc1:#e11d48; --rc2:#be123c;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                <path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
        </span>
        <div>
            <h2 class="ref-card-title">اشخاص</h2>
            <p class="ref-card-sub">همکار، مشتری، یا هر کس دیگر — در ثبت چک، طلب و بدهی، و معامله پیشنهاد می‌شوند.</p>
        </div>
    </div>

    <div class="ref-add-row">
        <input type="text" id="newPerson" placeholder="نام (مثلاً: علی رضایی)" maxlength="150">
        <input type="text" id="newPersonRole" placeholder="سمت" maxlength="60" class="ref-unit-input"
               list="personRoleSuggestions">
        <datalist id="personRoleSuggestions">
            <option value="همکار"></option><option value="مشتری"></option>
            <option value="تأمین‌کننده"></option><option value="سایر"></option>
        </datalist>
        <button type="button" class="btn btn-secondary btn-sm js-ref-add" data-kind="person" data-input="newPerson" data-role-input="newPersonRole">افزودن</button>
    </div>
    <div class="ref-chip-list" id="personList">
        <?php if (empty($people)): ?>
            <span class="ref-empty">هنوز شخصی اضافه نکرده‌اید.</span>
        <?php else: ?>
            <?php foreach ($people as $p): ?>
                <span class="ref-chip">
                    <a href="<?= APP_BASE_PATH ?>/person.php?name=<?= urlencode($p['name']) ?>"
                       style="color:inherit;text-decoration:none;" title="گردش حساب این شخص"><?= h($p['name']) ?></a>
                    <?php if (trim((string)$p['role']) !== ''): ?>
                        <small style="opacity:.6;">(<?= h($p['role']) ?>)</small>
                    <?php endif; ?>
                    <button type="button" class="ref-chip-x js-ref-delete" data-kind="person" data-id="<?= (int)$p['id'] ?>" aria-label="حذف">&times;</button>
                </span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <p class="hint" style="margin-top:9px;">
        روی نام هر شخص بزنید تا <b>گردش حسابش</b> را ببینید.
        حذف یک شخص از این فهرست، چک‌ها و طلب‌های ثبت‌شده‌اش را دست نمی‌زند —
        نام در خودِ آن رکوردها ذخیره شده.
    </p>
</div>
<?php endif; ?>

<div class="card ref-card">
    <div class="ref-card-head">
        <span class="ref-card-icon" style="--rc1:#8b5cf6; --rc2:#6366f1;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4" stroke-linecap="round"/></svg>
        </span>
        <div>
            <h2 class="ref-card-title">بانک‌های من</h2>
            <p class="ref-card-sub">بانک‌هایی که خودم دسته‌چک دارم — در ثبت چک‌های صادره انتخاب می‌شوند.</p>
        </div>
    </div>

    <div class="ref-add-row">
        <input type="text" id="newMyBank" placeholder="مثلاً: ملت" maxlength="100">
        <button type="button" class="btn btn-secondary btn-sm js-ref-add" data-kind="bank_mine" data-input="newMyBank">افزودن</button>
    </div>
    <div class="ref-chip-list" id="myBankList">
        <?php if (empty($myBanks)): ?>
            <span class="ref-empty">هنوز بانکی اضافه نکرده‌اید.</span>
        <?php else: ?>
            <?php foreach ($myBanks as $b): ?>
                <span class="ref-chip"><?= h($b['name']) ?><button type="button" class="ref-chip-x js-ref-delete" data-kind="bank_mine" data-id="<?= (int)$b['id'] ?>" aria-label="حذف">&times;</button></span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ---------- بانک‌های طرف مقابل ---------- -->
<div class="card ref-card">
    <div class="ref-card-head">
        <span class="ref-card-icon" style="--rc1:#0891b2; --rc2:#0e7490;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M3 9.5L12 4l9 5.5"/><path d="M5 10v8M9.7 10v8M14.3 10v8M19 10v8"/><path d="M3 20h18"/></svg>
        </span>
        <div>
            <h2 class="ref-card-title">بانک‌های طرف مقابل</h2>
            <p class="ref-card-sub">بانک کسی که چک را به من داده — در ثبت چک‌های دریافتی انتخاب می‌شوند.</p>
        </div>
    </div>

    <div class="ref-add-row">
        <input type="text" id="newExtBank" placeholder="مثلاً: سامان" maxlength="100">
        <button type="button" class="btn btn-secondary btn-sm js-ref-add" data-kind="bank_external" data-input="newExtBank">افزودن</button>
    </div>
    <div class="ref-chip-list" id="extBankList">
        <?php if (empty($externalBanks)): ?>
            <span class="ref-empty">هنوز بانکی اضافه نکرده‌اید.</span>
        <?php else: ?>
            <?php foreach ($externalBanks as $b): ?>
                <span class="ref-chip"><?= h($b['name']) ?><button type="button" class="ref-chip-x js-ref-delete" data-kind="bank_external" data-id="<?= (int)$b['id'] ?>" aria-label="حذف">&times;</button></span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ---------- انواع دارایی ---------- -->
<div class="card ref-card">
    <div class="ref-card-head">
        <span class="ref-card-icon" style="--rc1:#f59e0b; --rc2:#d97706;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>
        </span>
        <div>
            <h2 class="ref-card-title">انواع دارایی</h2>
            <p class="ref-card-sub">طلا، سکه، ملک، ارز… — در ثبت دارایی انتخاب می‌شوند. واحدشان هم همین‌جا تعیین می‌شود.</p>
        </div>
    </div>

    <div class="ref-add-row">
        <input type="text" id="newAssetType" placeholder="نام (مثلاً: سکه نیم)" maxlength="100">
        <input type="text" id="newAssetUnit" placeholder="واحد" maxlength="30" class="ref-unit-input">
        <button type="button" class="btn btn-secondary btn-sm js-ref-add" data-kind="asset_type" data-input="newAssetType" data-unit-input="newAssetUnit">افزودن</button>
    </div>
    <div class="ref-chip-list" id="assetTypeList">
        <?php if (empty($assetTypes)): ?>
            <span class="ref-empty">هنوز نوعی اضافه نکرده‌اید.</span>
        <?php else: ?>
            <?php foreach ($assetTypes as $at): ?>
                <span class="ref-chip"><?= h($at['name']) ?> <small style="opacity:.6;">(<?= h($at['unit']) ?>)</small><button type="button" class="ref-chip-x js-ref-delete" data-kind="asset_type" data-id="<?= (int)$at['id'] ?>" aria-label="حذف">&times;</button></span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ---------- انواع حساب ---------- -->
<?php if (tableExists('wallet_kinds')): ?>
<div class="card ref-card">
    <div class="ref-card-head">
        <span class="ref-card-icon" style="--rc1:#16794f; --rc2:#0f766e;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7.5h15a2.5 2.5 0 012.5 2.5v7a2.5 2.5 0 01-2.5 2.5H5.5A2.5 2.5 0 013 17V7.5z"/><path d="M3 7.5l12-3v3"/><circle cx="17" cy="13.5" r="1.3" fill="currentColor"/></svg>
        </span>
        <div>
            <h2 class="ref-card-title">انواع حساب</h2>
            <p class="ref-card-sub">وقتی نوع حساب را «سایر» می‌گذارید، از این فهرست انتخاب می‌کنید — مثلاً حساب ارزی یا صندوق قرض‌الحسنه.</p>
        </div>
    </div>

    <div class="ref-add-row">
        <input type="text" id="newWalletKind" placeholder="مثلاً: حساب ارزی" maxlength="60">
        <button type="button" class="btn btn-secondary btn-sm js-ref-add" data-kind="wallet_kind" data-input="newWalletKind">افزودن</button>
    </div>
    <div class="ref-chip-list" id="walletKindList">
        <?php if (empty($kinds)): ?>
            <span class="ref-empty">هنوز نوعی اضافه نکرده‌اید.</span>
        <?php else: ?>
            <?php foreach ($kinds as $k): ?>
                <span class="ref-chip"><?= h($k['name']) ?><button type="button" class="ref-chip-x js-ref-delete" data-kind="wallet_kind" data-id="<?= (int)$k['id'] ?>" aria-label="حذف">&times;</button></span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ---------- دسته‌بندی درآمد و هزینه ----------
     پیش‌فرض‌ها مال برنامه‌اند و فقط مدیر عوضشان می‌کند؛ کاربر عادی
     می‌تواند هر چقدر دسته‌ی خودش اضافه کند و همان‌ها را پاک کند. هر
     دسته‌ای که اینجا ساخته شود بلافاصله در فرم ثبت تراکنش، بودجه،
     تراکنش دوره‌ای و گزارش دسته‌بندی می‌آید. -->
<?php if ($catsReady && $missingSuggested > 0): ?>
<div class="card ref-suggest" id="suggestCard">
    <p class="ref-suggest-text">
        <strong><?= toPersianDigits((string)$missingSuggested) ?></strong> دسته‌ی رایجِ خانوار
        (خوراک، قبوض، درمان، قسط، حقوق و …) هنوز در فهرست شما نیست.
    </p>
    <button type="button" class="btn btn-secondary btn-sm" id="addSuggestedCats">افزودن یک‌جا</button>
</div>
<?php endif; ?>

<?php if ($catsReady): ?>
<?php foreach ([
    'expense' => ['title' => 'دسته‌بندی هزینه‌ها', 'c1' => '#dc2626', 'c2' => '#b91c1c',
                  'sub' => 'هر خرجی که ثبت می‌کنید زیر یکی از این‌ها می‌نشیند و گزارش دسته‌بندی از رویشان ساخته می‌شود.',
                  'ph'  => 'مثلاً: شهریه مدرسه'],
    'income'  => ['title' => 'دسته‌بندی درآمدها', 'c1' => '#16a34a', 'c2' => '#0f766e',
                  'sub' => 'منبع‌های درآمدتان — حقوق، اجاره، فروش و هر چیز دیگری که خودتان لازم دارید.',
                  'ph'  => 'مثلاً: اجاره مغازه'],
] as $ctype => $meta): ?>
<div class="card ref-card">
    <div class="ref-card-head">
        <span class="ref-card-icon" style="--rc1:<?= h($meta['c1']) ?>; --rc2:<?= h($meta['c2']) ?>;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
        </span>
        <div>
            <h2 class="ref-card-title"><?= h($meta['title']) ?></h2>
            <p class="ref-card-sub"><?= h($meta['sub']) ?></p>
        </div>
    </div>

    <div class="ref-add-row">
        <input type="text" id="newCat_<?= $ctype ?>" placeholder="<?= h($meta['ph']) ?>" maxlength="100">
        <button type="button" class="btn btn-secondary btn-sm js-ref-add"
                data-kind="category" data-type="<?= $ctype ?>" data-input="newCat_<?= $ctype ?>">افزودن</button>
    </div>

    <div class="ref-chip-list">
        <?php if (empty($myCats[$ctype])): ?>
            <span class="ref-empty">هنوز دسته‌ی شخصی‌ای اضافه نکرده‌اید.</span>
        <?php else: ?>
            <?php foreach ($myCats[$ctype] as $c): ?>
                <span class="ref-chip"><?= h($c['name']) ?><button type="button" class="ref-chip-x js-ref-delete" data-kind="category" data-id="<?= (int)$c['id'] ?>" aria-label="حذف">&times;</button></span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($defaultCats[$ctype])): ?>
        <p class="ref-locked-title">دسته‌های پیش‌فرض برنامه — همیشه در دسترس‌اند و حذف نمی‌شوند:</p>
        <div class="ref-chip-list">
            <?php foreach ($defaultCats[$ctype] as $c): ?>
                <span class="ref-chip ref-chip-locked"><?= h($c['name']) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php include __DIR__ . '/includes/footer.php'; ?>
