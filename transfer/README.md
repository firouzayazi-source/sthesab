# پوشه‌ی انتقال — موقتی

`sthesab-app.bundle` بسته‌ی گیتِ **اپ فلاتر** است، با هر چهار کامیتش.

## چرا اینجاست

اپ فلاتر در نشستی ساخته شد که به مخزن `sthesab-app` دسترسی نوشتن نداشت
(فهرست مخزن‌های مجاز در لحظه‌ی شروع نشست ثابت می‌شود و بعد عوض نمی‌شود).
تنها مخزنی که آن نشست می‌توانست در آن بنویسد همین `sthesab` بود، پس بسته
از این راه منتقل شد.

## چطور از آن استفاده کنیم

```
git clone transfer/sthesab-app.bundle /tmp/app
cd /tmp/app
git remote add origin https://github.com/firouzayazi-source/sthesab-app.git
git push -u origin main
```

بعد از رسیدنِ کد به `sthesab-app`، **این پوشه را پاک کنید**. کارش تمام است
و کد فلاتر جایش اینجا نیست.
