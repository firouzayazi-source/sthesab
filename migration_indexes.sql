-- ============================================
-- ایندکس‌های ترکیبی برای سرعت بیشتر
-- این فایل را در phpMyAdmin روی دیتابیس فعلی Import کنید
-- (اجرای دوباره‌اش مشکلی ایجاد نمی‌کند؛ اگر ایندکس باشد فقط خطای تکراری می‌دهد)
-- ============================================

-- تقریباً همه کوئری‌ها: WHERE user_id = ? AND transaction_date BETWEEN ? AND ?
ALTER TABLE `transactions` ADD INDEX `idx_user_date` (`user_id`, `transaction_date`);

-- گزارش دسته‌بندی: WHERE user_id = ? AND type = ? AND transaction_date BETWEEN ? AND ?
ALTER TABLE `transactions` ADD INDEX `idx_user_type_date` (`user_id`, `type`, `transaction_date`);

-- لیست و یادآوری طلب/بدهی: WHERE user_id = ? AND is_settled = ? ORDER BY due_date
ALTER TABLE `debts` ADD INDEX `idx_user_settled_due` (`user_id`, `is_settled`, `due_date`);

-- همان برای چک‌ها
ALTER TABLE `cheques` ADD INDEX `idx_user_settled_due` (`user_id`, `is_settled`, `due_date`);
