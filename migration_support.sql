-- ============================================================
-- migration_support.sql — مرکز راهنما و سیستم تیکت
-- ============================================================
--
-- **خواسته‌ی مالکِ نصب:** «پشتیبانی نباید به شکل چت مستقیم و آزاد با
-- ادمین باشد. هدف، ساخت یک Help Center + Ticket System حرفه‌ای، ساده و
-- قابل مدیریت است تا حجم پیام‌های غیرضروری کم شود… این بخش را به چت
-- بی‌نهایت تبدیل نکن.»
--
-- ─── پنج جدول، و هر کدام یک کارِ متفاوت ───
--
--   support_articles     محتوای راهنما. **مالِ نصب است، نه کاربر** —
--                        پس `user_id` ندارد، مثل دسته‌ی پیش‌فرضِ برنامه.
--   support_canned       پاسخ‌های آماده‌ی مدیر. باز هم مالِ نصب.
--   support_tickets      خودِ درخواست: موضوع، دسته، وضعیت، اولویت.
--   support_messages     متنِ هر پیام (اولین پیام = شرحِ مشکل).
--   support_attachments  فایلِ پیوستِ هر پیام.
--
-- ⛔ **چرا `support_messages` هم `user_id` دارد با اینکه `ticket_id`
--    کافی بود:** دو قاعده‌ی بسته‌ی این پروژه روی همان ستون کار می‌کنند
--    — `userDataTables()` (خروجیِ کامل و حذفِ حساب) و
--    `NON_ACTIVITY_TABLES` (آمار استفاده). بدونِ آن ستون، پیام‌ها در
--    خروجیِ داده‌ی کاربر **نمی‌آمدند** و هیچ‌کس هم خبردار نمی‌شد.
--    مقدارش همیشه **صاحبِ تیکت** است، حتی وقتی مدیر نوشته باشد؛ چه
--    کسی نوشته در `sender` است.
--
-- ⛔ **`last_sender` تنها راهِ فهمیدنِ «منتظر پاسخ ادمین» است** و فقط
--    `Support::addMessage()` می‌نویسدش. با شمردنِ `support_messages`
--    در هر بارگذاری، صفحه‌ی مدیر یک زیرکوئریِ همبسته به‌ازای هر تیکت
--    می‌خورد — همان N+1 که این پروژه بارها گرفته.
--
-- ⛔ **`status` و `priority` رشته‌اند نه `ENUM`.** با `ENUM`، افزودنِ
--    یک وضعیت یعنی `ALTER TABLE` روی جدولی که ممکن است صدهزار ردیف
--    داشته باشد؛ و فهرستِ مجاز از قبل در `Support::STATUSES` بسته است
--    (همان قاعده‌ی `payments.method`).
--
-- ایدمپوتنت است.

-- ─────────────────── محتوای راهنما ───────────────────
--
-- ⛔ `user_id` ندارد و نباید داشته باشد: این محتوا بینِ همه مشترک است
--    و فقط مدیر ویرایشش می‌کند — دقیقاً مثل دسته‌ی پیش‌فرضِ برنامه
--    (`categories.user_id IS NULL`). با ستونِ `user_id`،
--    `deleteUserAccount()` مقاله‌های نصب را با حذفِ یک کاربر می‌برد.
CREATE TABLE IF NOT EXISTS `support_articles` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `category`   VARCHAR(32)  NOT NULL,
    `title`      VARCHAR(200) NOT NULL,
    `body`       TEXT         NOT NULL,
    `sort_order` INT          NOT NULL DEFAULT 100,
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_art_cat` (`is_active`, `category`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ─────────────────── پاسخ‌های آماده ───────────────────
CREATE TABLE IF NOT EXISTS `support_canned` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `title`      VARCHAR(120) NOT NULL,
    `body`       TEXT         NOT NULL,
    `sort_order` INT          NOT NULL DEFAULT 100,
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_canned` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ─────────────────── تیکت ───────────────────
--
-- ⛔ `user_id` از نوعِ `INT UNSIGNED` است چون `users.id` همان است. با
--    `INT`ِ علامت‌دار، MySQL کلیدِ خارجی را با «errno 150» رد می‌کند و
--    migration **وسطِ کار** می‌ایستد — یعنی جدول‌های بعدی هرگز ساخته
--    نمی‌شوند. روی همین پروژه یک بار واقعاً افتاد (`store_shareholders`).
--
-- ⚠ `meta` همان چیزی است که سیستم **خودش** می‌داند و از کاربر پرسیده
--   نمی‌شود: نسخه‌ی مستقرشده و مرورگر. خواسته‌ی صریحِ مالکِ نصب.
CREATE TABLE IF NOT EXISTS `support_tickets` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`          INT UNSIGNED NOT NULL,
    `category`         VARCHAR(32)  NOT NULL,
    `subject`          VARCHAR(200) NOT NULL,
    `status`           VARCHAR(16)  NOT NULL DEFAULT 'new',
    `priority`         VARCHAR(8)   NOT NULL DEFAULT 'normal',
    -- آخرین نویسنده: `user` یعنی منتظرِ پاسخِ مدیر است.
    `last_sender`      VARCHAR(8)   NOT NULL DEFAULT 'user',
    -- نشانِ «پاسخِ خوانده‌نشده» برای خودِ کاربر. صفر و یک، نه شمارش:
    -- چیزی که روی فهرست دیده می‌شود «تیکتِ پاسخ‌دار» است نه «۳ پیام».
    `user_unread`      TINYINT(1)   NOT NULL DEFAULT 0,
    `meta`             VARCHAR(255) NOT NULL DEFAULT '',
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_activity_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `closed_at`        DATETIME     NULL,
    -- فهرستِ خودِ کاربر: تازه‌ترین اول.
    KEY `idx_tk_user` (`user_id`, `id`),
    -- فهرستِ مدیر با صافیِ وضعیت و ترتیبِ «آخرین فعالیت».
    KEY `idx_tk_status` (`status`, `last_activity_at`),
    -- «منتظر پاسخ ادمین» — پرتکرارترین نمای مدیر.
    KEY `idx_tk_wait` (`last_sender`, `status`, `last_activity_at`),
    KEY `idx_tk_cat` (`category`),
    CONSTRAINT `fk_tk_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ─────────────────── پیام‌های تیکت ───────────────────
CREATE TABLE IF NOT EXISTS `support_messages` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `ticket_id`  INT          NOT NULL,
    -- ⛔ صاحبِ تیکت، نه نویسنده. توضیحش بالای فایل.
    `user_id`    INT UNSIGNED NOT NULL,
    `sender`     VARCHAR(8)   NOT NULL DEFAULT 'user',
    -- چه کسی نوشته (برای پاسخِ مدیر). `ON DELETE SET NULL` چون حذفِ
    -- حسابِ مدیر نباید تاریخچه‌ی تیکت را ببرد.
    `sender_id`  INT UNSIGNED NULL,
    `body`       TEXT         NOT NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_msg_ticket` (`ticket_id`, `id`),
    KEY `idx_msg_user` (`user_id`),
    CONSTRAINT `fk_msg_ticket` FOREIGN KEY (`ticket_id`)
        REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_msg_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_msg_sender` FOREIGN KEY (`sender_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ─────────────────── پیوستِ پیام ───────────────────
--
-- ⚠ جدولِ `attachments` بازاستفاده نشد: ستونِ `transaction_id` آن
--   `NOT NULL` است و کلیدِ خارجی به `transactions` دارد. با nullable
--   کردنش، هر کوئریِ موجودِ پیوستِ تراکنش باید صافیِ تازه می‌گرفت و
--   اولین کوئری‌ای که یادش می‌رفت پیوستِ تیکت را قاطیِ تراکنش می‌کرد.
CREATE TABLE IF NOT EXISTS `support_attachments` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `message_id`    INT          NOT NULL,
    `user_id`       INT UNSIGNED NOT NULL,
    `file_name`     VARCHAR(190) NOT NULL,
    `original_name` VARCHAR(255) NOT NULL DEFAULT '',
    `mime_type`     VARCHAR(100) NOT NULL DEFAULT '',
    `file_size`     INT          NOT NULL DEFAULT 0,
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_att_msg` (`message_id`),
    KEY `idx_att_user` (`user_id`),
    CONSTRAINT `fk_satt_msg` FOREIGN KEY (`message_id`)
        REFERENCES `support_messages` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_satt_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ═══════════════ متنِ اولیه‌ی راهنما ═══════════════
--
-- ⛔ نشانه‌ی `support_seeded` یعنی این بلوک دیگر کاری نمی‌کند — حتی
--    اگر مدیر مقاله‌ها را **عمداً حذف کرده باشد**. بدونِ نشانه، هر
--    deploy کارِ او را پس می‌زد؛ همان استدلالِ `more_categories_seeded`.
--    (شرطِ `NOT EXISTS` به‌تنهایی ایدمپوتنت بود ولی این را نمی‌داد.)
SET @support_seeded := (
    SELECT COUNT(*) FROM `app_settings` WHERE `setting_key` = 'support_seeded'
);

INSERT INTO `support_articles` (`category`, `title`, `body`, `sort_order`)
SELECT * FROM (
    SELECT 'start' AS c, 'از کجا شروع کنم؟' AS t,
'اولین کاری که باید بکنید وارد کردنِ موجودیِ واقعیِ حسابتان است. اگر این کار را نکنید، با اولین هزینه «مجموع حساب‌ها» منفی می‌شود و عددِ «پول قابل خرج» تا همیشه غلط می‌ماند.

۱. در صفحه‌ی خانه، کارتِ «موجودی فعلی‌تان چقدر است؟» را پر کنید.
۲. با دکمه‌ی + اولین تراکنش را ثبت کنید (روی کامپیوتر از نوارِ کناری).
۳. اگر چند حساب دارید، از «ابزارها ← حساب‌ها و انتقال» بقیه را بسازید.' AS b, 10 AS s
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'tx', 'چطور یک تراکنش ثبت کنم؟',
'دکمه‌ی + پایینِ صفحه (روی کامپیوتر: اولین قلمِ نوارِ کناری) شیتِ ثبت را باز می‌کند.

- پیش‌فرضِ نوع «هزینه» است؛ برای درآمد روی «درآمد» بزنید.
- «عنوان» اجباری نیست — اگر خالی بگذارید نامِ دسته‌بندی روی آن می‌نشیند.
- دسته‌بندی‌های پرکاربردتان به‌صورت چیپ بالای فرم می‌آیند. اگر می‌خواهید دسته‌ی خاصی همیشه آنجا باشد، در «فهرست‌های من» کنارِ نامش آیکونِ پین را بزنید.', 20
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'tx', 'دسته‌بندیِ خودم را چطور بسازم؟',
'در «ابزارها ← فهرست‌های من» بخشِ «دسته‌بندی درآمد» و «دسته‌بندی هزینه» را می‌بینید. هر چه آنجا بسازید فقط مالِ خودتان است و کاربرِ دیگری نمی‌بیندش.

دسته‌هایی که از اول در برنامه هستند مالِ همه‌اند و فقط مدیر می‌تواند تغییرشان بدهد — به همین دلیل دکمه‌ی حذف کنارشان نیست.', 30
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'tx', 'تراکنشم را اشتباه ثبت کردم یا اشتباه حذف کردم',
'ویرایش: روی خودِ تراکنش در فهرست بزنید.

حذف: بعد از حذف یک نوارِ «حذف شد — لغو» پایینِ صفحه می‌آید. تا وقتی آن نوار هست، «لغو» ردیف را با همه‌ی جزئیاتش برمی‌گرداند. اگر صفحه را بستید و نوار رفت، باید دستی دوباره ثبتش کنید.', 40
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'wallets', 'موجودیِ حسابم با پولِ واقعی نمی‌خواند',
'موجودیِ هر حساب محاسبه‌شده است، نه یک عددِ ذخیره‌شده: موجودیِ اولیه، به‌علاوه‌ی درآمدها، منهای هزینه‌ها، به‌علاوه‌ی انتقال‌ها و چکِ پاس‌شده و پرداختِ طلب و بدهی.

اگر با پولِ واقعی نمی‌خواند:
۱. روی کارتِ همان حساب بزنید و «تراکنش‌های این حساب» را ببینید — معمولاً یک تراکنش به حسابِ اشتباه خورده.
۲. اگر پیدا نشد، از همان کارت «تعدیل موجودی» را بزنید و گزینه‌ی «موجودی واقعی این‌قدر است» را انتخاب کنید. تعدیل نه درآمد است نه هزینه، پس در گزارش‌ها نمی‌آید.', 10
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'wallets', 'حسابی را می‌خواهم ببندم ولی تاریخچه‌اش بماند',
'در «حساب‌ها» همان حساب را ویرایش کنید و «فعال» را خاموش کنید. حساب از فهرست و از مجموع بیرون می‌رود ولی تراکنش‌هایش دست‌نخورده می‌مانند.

حذفِ کاملِ حساب تراکنش‌ها را بی‌حساب می‌کند و برگشت‌پذیر نیست، پس مگر برای حسابِ اشتباهیِ خالی سراغش نروید.', 20
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'wallets', 'کارتِ حساب را چطور روی صفحه‌ی خانه بیاورم؟',
'در «حساب‌ها» کنارِ هر حساب یک آیکونِ پین هست. هر حسابی که پین کنید به‌صورت یک اسلاید کنارِ نوارِ «مانده این ماه» روی خانه می‌آید (حداکثر هشت تا).

حسابِ غیرفعال حتی اگر پین باشد روی خانه نمی‌آید.', 30
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'due', 'چکِ دریافتی‌ام برگشت خورد، چه کار کنم؟',
'روی نشانِ وضعیتِ همان چک بزنید و «برگشت خورد» را انتخاب کنید. برنامه خودش برای آن مبلغ یک «طلب» می‌سازد تا از یادتان نرود.

اگر بعداً وضعیت را برگردانید، آن طلب هم پس گرفته می‌شود — مگر اینکه رویش پرداختی ثبت کرده باشید، که آن‌وقت کارِ خودتان دست‌نخورده می‌ماند.', 10
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'due', 'وامِ قسطی را چطور ثبت کنم؟',
'در «طلب و بدهی» هنگام ثبتِ بدهی، کلیدِ «قسطی است» را روشن کنید و تعداد قسط، فاصله‌ی اقساط و تاریخِ اولین قسط را بدهید.

برنامه هیچ ردیفی به‌ازای هر قسط نمی‌سازد؛ برنامه‌ی اقساط محاسبه می‌شود. به همین دلیل هر وقت تعداد اقساط را عوض کنید، همه چیز از نو حساب می‌شود و پرداخت‌های ثبت‌شده‌تان هم سرِ جایشان می‌مانند.

باقیمانده‌ی تقسیم به قسطِ آخر می‌رود، پس جمعِ اقساط دقیقاً برابرِ مبلغِ وام است.', 20
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'due', 'یادآوریِ سررسیدها را از کجا می‌بینم؟',
'صفحه‌ی «یادآوری‌های من» (در نوارِ کناری «سررسیدها») همه‌ی چک، طلب و بدهی، تراکنشِ دوره‌ای و یادآورهای شخصی‌تان را کنارِ هم نشان می‌دهد.

اگر ایمیلتان را در پروفایل ثبت کرده باشید، هر صبح هم یک خلاصه برایتان ایمیل می‌شود. این گزینه پیش‌فرض روشن است و در پروفایل قابلِ خاموش کردن.', 30
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'data', 'چطور از اطلاعاتم پشتیبان بگیرم؟',
'صفحه‌ی «پشتیبان» (از ابزارها ← خروجی و ورودی) یک فایل با پسوندِ ‎.sthesab‎ می‌سازد که همه‌ی دفترتان در آن است.

آن فایل را جایی بیرون از گوشی نگه دارید — تلگرامِ ذخیره‌شده‌ها، ایمیل به خودتان، یا فضای ابری. تنها خرابیِ برگشت‌ناپذیرِ این برنامه از دست رفتنِ داده است.

ماهی یک بار هم یک یادآوری در مرکزِ اعلان می‌گیرید.', 10
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'data', 'فایل پشتیبان را چطور برمی‌گردانم؟',
'در همان صفحه‌ی «پشتیبان»، بخشِ «بازگرداندن». فایلِ ‎.sthesab‎ را انتخاب کنید؛ پیش از هر کاری خلاصه‌ی فایل و فهرستِ چیزی که پاک می‌شود را می‌بینید.

⚠ بازگرداندن یعنی **جایگزینی**، نه ادغام: دفترِ فعلی‌تان پاک می‌شود و محتوای فایل جایش می‌نشیند. به همین دلیل باید عبارتِ «بازگرداندن» را دستی تایپ کنید.', 20
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'data', 'می‌خواهم همه‌ی داده‌ام را ببرم یا حسابم را پاک کنم',
'هر دو در پروفایل، بخشِ «داده‌ی من» هستند.

«خروجی کامل» یک فایلِ JSON می‌دهد که همه چیز در آن باز و خواندنی است.

«حذف حساب» برگشت‌پذیر نیست: سه سد دارد (رمز فعلی، تایپِ عبارتِ تأیید، و اینکه آخرین مدیر نمی‌تواند خودش را حذف کند).', 30
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'account', 'رمزم را فراموش کرده‌ام',
'اگر ایمیل ثبت کرده‌اید، از صفحه‌ی ورود «رمز را فراموش کرده‌اید؟» را بزنید. لینکِ بازیابی یک ساعت اعتبار دارد.

اگر ایمیل ندارید ولی شماره‌ی موبایلتان ثبت است و ورودِ پیامکی روشن است، از همان صفحه با شماره وارد شوید و بعد در پروفایل رمزِ تازه بگذارید.

اگر هیچ‌کدام، همین‌جا تیکت بزنید تا مدیر رمزتان را بازنشانی کند.', 10
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'account', 'می‌گوید «حساب موقتاً قفل است»',
'بعد از پنج رمزِ اشتباه، ورود برای همان نام کاربری تا پانزده دقیقه بسته می‌شود. این سد بین شما و کسی که رمز حدس می‌زند فرق نمی‌گذارد.

دو راه: پانزده دقیقه صبر کنید، یا از مدیر بخواهید از «مدیریت ← کاربران» قفل را باز کند.', 20
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'account', 'هر بار که برمی‌گردم دوباره رمز می‌خواهد',
'در پروفایل، «مهلت ماندن در حساب» را ببینید. پیش‌فرضش «بدون مهلت» است.

هنگام ورود هم تیکِ «این دستگاه را به خاطر بسپار» باید روشن باشد. اگر مرورگرتان کوکی‌ها را با بستن پاک می‌کند یا در حالت ناشناس باز می‌کنید، این تیک اثری ندارد.', 30
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'app', 'پیامکِ بانک خوانده نمی‌شود',
'در اپِ اندروید، از پروفایل «تنظیم در اپ اندروید» را باز کنید. همان صفحه می‌گوید دقیقاً کجای کار گیر کرده:

- «هنوز هیچ پیامکی نرسیده» → معمولاً محدودیتِ باتریِ گوشی است؛ دکمه‌ی «رفعِ محدودیتِ باتری» همان‌جا هست.
- «رد شد چون کلمه‌ی جهت نداشت» → متنِ آن بانک شناخته نشد؛ همان متن را برای ما تیکت بزنید.
- «شناخته شد و اعلان ساخته شد» → اعلان‌های اپ در تنظیماتِ گوشی بسته است.

در مرورگر (بدونِ اپ) خواندنِ خودکارِ پیامک ممکن نیست؛ آنجا متن را کپی کنید و دکمه‌ی «چسباندن و خواندن» را بزنید.', 10
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'app', 'اپِ اندروید را از کجا بگیرم؟',
'اگر با مرورگرِ گوشی وارد شده باشید، بالای صفحه یک نوارِ پیشنهادِ نصب می‌بینید. اگر بستیدش، در پروفایل کارتِ «اپ اندروید» همیشه هست.

بدونِ نصبِ اپ هم می‌توانید از منوی مرورگر «افزودن به صفحه‌ی اصلی» را بزنید؛ برنامه تمام‌صفحه باز می‌شود. تنها چیزی که با این کار به دست نمی‌آید خواندنِ خودکارِ پیامکِ بانک است.', 20
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'plan', 'اشتراک چه چیزی را باز می‌کند؟',
'ثبتِ تراکنش، حساب‌ها، دسته‌بندی، بودجه، پس‌انداز، دارایی و گزارش‌ها **همیشه رایگان‌اند و هرگز پولی نمی‌شوند**.

اشتراک برای ثبتِ **موردِ تازه** در چک، طلب و بدهی، معاملات و تراکنشِ دوره‌ای است. اگر اشتراکتان تمام شود، آنچه ثبت کرده‌اید سرِ جایش می‌ماند و می‌توانید ببینید، ویرایش کنید، پرداخت ثبت کنید و ببندید — فقط موردِ تازه اضافه نمی‌شود.', 10
) x WHERE @support_seeded = 0
UNION ALL SELECT * FROM (
    SELECT 'plan', 'پرداخت کردم ولی هنوز فعال نشده',
'پرداخت کارت‌به‌کارت است و تأییدش دستی. بعد از اعلامِ پرداخت، مدیر آن را می‌بیند و تأیید می‌کند؛ از همان لحظه اشتراک فعال می‌شود.

اگر بیش از یک روز گذشته، همین‌جا با دسته‌ی «اشتراک و پرداخت» تیکت بزنید و ساعتِ واریز را بنویسید.', 20
) x WHERE @support_seeded = 0;

-- نشانه: از این به بعد این فایل هیچ مقاله‌ای نمی‌سازد.
INSERT INTO `app_settings` (`setting_key`, `setting_value`)
VALUES ('support_seeded', '1')
ON DUPLICATE KEY UPDATE `setting_value` = '1';

SELECT 'migration_support با موفقیت اجرا شد.' AS result;
