-- ============================================================
-- migration_debt_optional_due.sql — سررسیدِ طلب/بدهی اختیاری شد
--
-- `debts.due_date` از روزِ اول `NOT NULL` بود، پس کاربری که «به علی
-- ۲۰ میلیون قرض دادم، هر وقت داشت پس می‌دهد» ناچار بود یک تاریخِ
-- **ساختگی** بزند — و آن تاریخِ ساختگی بعد در «آینده مالی»، «پول قابل
-- خرج» و یادآوریِ روزانه به‌عنوان سررسیدِ واقعی ظاهر می‌شد و عددِ
-- قابل خرج را بی‌دلیل کم می‌کرد.
--
-- ⛔ `NULL` یعنی «سررسید ندارد»، نه «امروز». هر مصرف‌کننده‌ای که
--    سررسید می‌خواهد (`financialEvents()`، `syncScheduleRules()`، نشانِ
--    نوارِ کناری، یادآوری‌های داشبورد) با `BETWEEN`/`<=` می‌سنجد و
--    `NULL` در هیچ‌کدام صادق نیست — پس بدهیِ بی‌سررسید هیچ‌جا به‌عنوان
--    «سررسید شده» نمی‌آید، که همان رفتارِ درست است.
--
-- ⚠ ردیف‌های موجود دست نمی‌خورند: فقط اجازه‌ی NULL داده می‌شود.
--
-- ایمن و قابل اجرای دوباره.
-- ============================================================

SET @due_notnull = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'debts'
      AND COLUMN_NAME = 'due_date' AND IS_NULLABLE = 'NO'
);
SET @sql = IF(@due_notnull = 1,
    'ALTER TABLE `debts` MODIFY COLUMN `due_date` DATE NULL DEFAULT NULL COMMENT ''NULL = سررسید ندارد''',
    'SELECT ''debts.due_date از قبل NULL می‌پذیرد'' AS skipped'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT 'migration_debt_optional_due با موفقیت اجرا شد.' AS result;
