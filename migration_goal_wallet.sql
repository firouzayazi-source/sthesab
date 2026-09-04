-- «این پس‌انداز در کدام حساب انجام می‌شود؟»
--
-- ⛔ این پیوند **ارجاعی است، نه انتقالِ پول** — و این قابل مذاکره نیست.
--    `savings_entries` هیچ‌وقت در `walletBalances()` نیامده و نباید بیاید:
--    اگر امروز بیاید، موجودیِ همه‌ی کاربرانِ فعلی **بی‌صدا** عوض می‌شود
--    (هر کسی که تا امروز پس‌انداز ثبت کرده، فردا موجودیِ حسابش کمتر
--    می‌شود بی‌آنکه چیزی خرج کرده باشد). پس‌انداز یک «پاکتِ کنارگذاشته»
--    است روی پولی که از قبل در حساب هست، نه یک خرج.
--
--    فایده‌ی واقعیِ این ستون هم همین است: کاربر می‌پرسد «آن ۵ میلیونی
--    که برای سفر کنار گذاشته‌ام، *کجاست*؟» — و تا امروز هیچ جوابی
--    نداشت.
--
-- ⚠ `ON DELETE SET NULL`: حذفِ یک حساب نباید هدفِ پس‌اندازِ کاربر را
--   با خودش ببرد. هدف می‌ماند و فقط بی‌حساب می‌شود.
ALTER TABLE `savings_goals`
    ADD COLUMN IF NOT EXISTS `wallet_id` INT UNSIGNED NULL AFTER `user_id`;

-- ⚠ کلیدِ خارجی جدا اضافه می‌شود چون `ADD CONSTRAINT IF NOT EXISTS` در
--   MariaDB وجود ندارد؛ با شمردنِ خودِ کلید ایدمپوتنت می‌شود.
SET @fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'savings_goals'
      AND CONSTRAINT_NAME = 'fk_goal_wallet'
);
SET @sql := IF(@fk = 0,
    'ALTER TABLE `savings_goals`
        ADD CONSTRAINT `fk_goal_wallet` FOREIGN KEY (`wallet_id`)
        REFERENCES `wallets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
