-- ---------------------------------------------------------------
-- migration_store_settlement.sql — تسویه‌ی نقدیِ سهامدار
--
-- **خواسته‌ی مالکِ نصب:** «می‌خوام وقتی گوشی فروش رفت، با تاییدِ تسویه
-- با سهامدار در حسابداری، پول بیاد و در کیف پولِ سهامدار در حسابلند
-- بشینه — یعنی دارایی یا کالاست، یا پولِ تسویه‌نشده، یا پولِ
-- تسویه‌شده.»
--
-- ─── ⛔ چرا **انتقال** است و نه درآمد ───
--
-- سهمِ سود همان لحظه که در فروشگاه سند می‌خورد، اینجا به‌عنوان
-- **درآمد** ثبت می‌شود (`transactions.store_share_ref`). پولِ تسویه
-- همان سود است که حالا به دستِ او می‌رسد؛ اگر آن هم درآمد ثبت شود،
-- گزارشِ درآمدِ همان ماه به اندازه‌ی کلِ پرداخت باد می‌کند — و
-- **بی‌هیچ خطایی**، چون هر دو عدد جداگانه درست‌اند.
--
-- پس این جدول **هفتمین منبعِ پول** در `walletBalances()` است، دقیقاً
-- مثل چکِ پاس‌شده (`cheques.settle_wallet_id`) و پرداختِ طلب و بدهی
-- (`debt_payments.wallet_id`): موجودیِ حساب را بالا می‌برد و هیچ ردیفِ
-- `transactions` نمی‌سازد.
--
-- ⛔ و خالص دارایی دست‌نخورده می‌ماند، که همان چیزِ درست است: با هر
--    تسویه، «مانده»ی دفترِ فروشگاه (`balance = capital + earned −
--    paid`) دقیقاً به همان اندازه کم می‌شود و قلمِ «دارایی من در
--    فروشگاه» با آن پایین می‌آید. یعنی پول از یک سطل به سطلِ دیگر
--    می‌رود، نه اینکه از هوا ساخته شود.
--
-- ─── ⛔ چرا جدولِ جدا و نه `transfers` ───
--
-- انتقال دو سرِ **حساب** دارد (`from_wallet_id` / `to_wallet_id`) و
-- اینجا سرِ اول اصلاً حساب نیست؛ فروشگاه است. با nullable کردنِ آن
-- ستون، هر کوئریِ موجودِ `transfers` باید صافیِ تازه می‌گرفت — همان
-- استدلالِ «جدولِ `attachments` بازاستفاده نشد».
--
-- ایدمپوتنت است.
-- ---------------------------------------------------------------

-- ─────────── حسابی که پولِ تسویه در آن می‌نشیند ───────────
--
-- ⛔ `NULL` یعنی «حسابِ پیش‌فرضِ همان کاربر» (`resolveWalletId()`)، نه
--    «هیچ‌جا». پس هر نصبی که همین حالا پیوند دارد، بدونِ هیچ کارِ
--    دستی درست کار می‌کند — و پول در هیچ‌کجا گم نمی‌شود، همان قاعده‌ی
--    «هیچ پولی بی‌حساب نمی‌ماند».
--
-- ⚠ `INT UNSIGNED` چون `wallets.id` همان است. با `INT`ِ علامت‌دار،
--   MySQL کلیدِ خارجی را با «errno 150» رد می‌کند و migration وسطِ کار
--   می‌ایستد — روی همین دیتابیس یک بار سرِ `store_shareholders.user_id`
--   دیده شد، نه در بازبینی.
ALTER TABLE `store_shareholders`
    ADD COLUMN IF NOT EXISTS `wallet_id` INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'حسابی که پولِ تسویه در آن می‌نشیند؛ NULL یعنی حسابِ پیش‌فرض';

-- ⛔ `ON DELETE SET NULL` است نه CASCADE: حذفِ یک حساب نباید پیوندِ
--    سهامدار را با خودش ببرد — همان قاعده‌ی `savings_goals.wallet_id`.
--    با `SET NULL` تسویه‌های بعدی به حسابِ پیش‌فرض می‌روند.
SET @fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'store_shareholders'
      AND CONSTRAINT_NAME = 'fk_store_sh_wallet'
);
SET @sql := IF(@fk = 0,
    'ALTER TABLE `store_shareholders`
        ADD CONSTRAINT `fk_store_sh_wallet` FOREIGN KEY (`wallet_id`)
        REFERENCES `wallets` (`id`) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ─────────── آینه‌ی تسویه‌ها ───────────
--
-- ⛔ `ref` یکتاست روی خودِ ستون، نه `(user_id, ref)` — همان استدلالِ
--    `transactions.store_share_ref`: شناسه‌ی آن‌طرف سراسری است و
--    `store_contact_id` هم یکتاست، پس یک تسویه به بیش از یک کاربر
--    نمی‌رسد. بدونِ آن، هر دور همگام‌سازی همان پول را دوباره در کیف
--    پول می‌نشاند و موجودی هر دقیقه باد می‌شد، **بی‌هیچ خطایی**.
CREATE TABLE IF NOT EXISTS `store_settlements` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NOT NULL,
    `wallet_id`   INT UNSIGNED NULL,
    `ref`         VARCHAR(64) NOT NULL,
    `amount`      BIGINT NOT NULL,
    `settled_on`  DATE NOT NULL,
    `description` VARCHAR(255) NOT NULL DEFAULT '',
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_store_settlement_ref` (`ref`),
    KEY `idx_user_wallet` (`user_id`, `wallet_id`),
    CONSTRAINT `fk_store_settle_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_store_settle_wallet` FOREIGN KEY (`wallet_id`)
        REFERENCES `wallets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ⚠ نشانه‌ی پایان: شاهدِ `--verify` روی همین می‌نشیند، نه روی «جدول
--   هست». فایلی که وسطِ ساختِ کلیدِ خارجی مرده باشد هم جدول را دارد و
--   آن‌وقت `--verify` همان دروغی را می‌گفت که برای گرفتنش ساخته شده.
INSERT INTO `app_settings` (`setting_key`, `setting_value`)
VALUES ('store_settlement_seeded', '1')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

SELECT 'migration_store_settlement با موفقیت اجرا شد.' AS result;
