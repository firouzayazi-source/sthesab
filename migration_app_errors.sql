-- migration_app_errors.sql — سطحِ دیدنِ خطاهای PHP
--
-- ⛔ چرا این جدول لازم است: ۶۱ فایل `error_log()` صدا می‌زنند و هیچ صفحه
--    و هیچ خلاصه‌ای آن‌ها را نشان نمی‌داد. یعنی هر ۵۰۰ بی‌صدا در لاگِ
--    FPM می‌ماند و مالکِ نصب فقط وقتی می‌فهمد که کاربری شکایت کند — و
--    بیشترِ کاربرها شکایت نمی‌کنند، فقط اپ را می‌بندند.
--
-- ⛔ `fingerprint` کلیدِ یکتاست، نه یک ردیف به‌ازای هر رخداد. همان
--    استدلالِ `dedup_key` در `notifications`: یک خطا که در حلقه هزار بار
--    می‌افتد، جدول را غیرقابل خواندن و دیسک را پر می‌کند — یعنی قابلیت
--    خودش را می‌کشد. اینجا فقط `hits` بالا می‌رود.
--
-- ⛔ و عمداً هیچ ستونی برای `user_id`، آدرس، یا بدنه‌ی درخواست ندارد.
--    این جدول درباره‌ی **کد** است نه رفتارِ کاربر؛ با یک ستونِ `user_id`
--    همان «ردِ رفتاریِ تازه» ساخته می‌شد که `admin/insights.php` صریحاً
--    از آن پرهیز کرده و `privacy.php` رویش ادعا نوشته.

CREATE TABLE IF NOT EXISTS `app_errors` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `fingerprint` CHAR(40)        NOT NULL,
    `level`       VARCHAR(20)     NOT NULL,
    `message`     VARCHAR(300)    NOT NULL,
    `file`        VARCHAR(255)    NOT NULL,
    `line`        INT UNSIGNED    NOT NULL DEFAULT 0,
    `hits`        INT UNSIGNED    NOT NULL DEFAULT 1,
    `first_seen`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_fingerprint` (`fingerprint`),
    KEY `idx_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
