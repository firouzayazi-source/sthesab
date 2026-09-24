package ir.stland.hesabland;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.net.Uri;
import android.os.Build;
import android.provider.Telephony;
import android.telephony.SmsMessage;

import java.net.URLEncoder;

/**
 * گرفتنِ خودکارِ پیامکِ بانک روی اندروید.
 *
 * ⛔ **این کلاس پیامک را «نمی‌خواند»، فقط تحویلش می‌دهد.**
 *
 *    کلِ منطقِ فهمیدنِ مبلغ و جهت و تاریخ در `window.parseBankSms()`
 *    داخل `assets/js/app.js` است و باید همان‌جا بماند. دلیلش قاعده‌ی
 *    خودِ پروژه است: آن تابع با ده جهش سنجیده شده و چهار محافظ دارد
 *    (بدون کلمه‌ی جهت تراکنش نیست، هیچ عددی پیش‌فرض مبلغ نیست، شماره‌ی
 *    کارت پاک می‌شود، «مانده/موجودی» رد می‌شود). اگر اینجا یک پارسرِ
 *    دومِ جاوا نوشته شود، دو پیاده‌سازی دیر یا زود از هم دور می‌افتند و
 *    خرابی‌اش **بی‌صداست**: فرم پر می‌شود، کاربر ثبت می‌کند، و ماه بعد
 *    گزارش غلط است.
 *
 * ⛔ **و متنِ پیامک روی سیم نمی‌رود.** آدرسی که ساخته می‌شود متن را در
 *    **فرگمنت** (`#sms=…`) می‌گذارد، نه در query string. مرورگر فرگمنت
 *    را هرگز به سرور نمی‌فرستد، پس متنِ خامِ پیامکِ بانک نه در درخواست
 *    می‌رود نه در لاگِ دسترسیِ nginx می‌نشیند — همان تضمینی که تا امروز
 *    از «کاربر خودش می‌چسباند» می‌آمد.
 *
 * ⚠ فیلترِ اینجا عمداً **درشت و سخاوتمند** است، نه دقیق: اگر شک داشت
 *   می‌فرستد و تصمیمِ واقعی را جاوااسکریپت می‌گیرد. فیلترِ دقیق یعنی
 *   همان فهرستِ کلماتِ `parseBankSms()` دو جا نوشته شود — و آن‌وقت
 *   بانکی که فردا «پرید» را عوض کند، اینجا از قلم می‌افتد بی‌آنکه
 *   کسی بفهمد.
 */
public class BankSmsReceiver extends BroadcastReceiver {

    private static final String CHANNEL_ID = "bank_sms";
    public  static final String PREFS      = "hesabland";
    public  static final String PREF_ON    = "sms_capture_on";

    /**
     * ⛔ پیش‌فرضِ کلید — **روشن**، به خواستِ صریحِ مالکِ نصب: «پیش‌فرض نوتیف
     *    و اطلاع‌رسانی و تمام قابلیت‌ها باید فعال باشد».
     *
     *    تا دیروز خاموش بود و آن تصمیم یک خرابیِ بی‌صدا می‌ساخت: کسی که فقط
     *    اپ را نصب و باز می‌کرد هرگز پیامکی نمی‌گرفت، و هیچ‌جا هم دیده
     *    نمی‌شد چرا. حالا «کلید را دست نزده» یعنی روشن، و فقط «خاموش
     *    کردنِ» صریح در صفحه‌ی تنظیم آن را می‌بندد.
     *
     * ⛔ **تنها مرجع** است: گیرنده، صفحه‌ی تنظیم و `HesabLauncherActivity`
     *    هر سه از همین می‌خوانند. با `false`ِ سخت‌کد در یکی، صفحه‌ی تنظیم
     *    «روشن است» می‌گفت و گیرنده بی‌صدا هیچ کاری نمی‌کرد.
     *
     * ⚠ روشن بودنِ کلید به‌تنهایی چیزی نمی‌خواند: بدونِ مجوزِ `RECEIVE_SMS`
     *   اندروید اصلاً پیامکی به این گیرنده نمی‌دهد. مجوز در اولین اجرا
     *   پرسیده می‌شود (`HesabLauncherActivity`).
     */
    public  static final boolean DEFAULT_ON = true;

    /**
     * ⛔ ردِ آخرین پیامک — چون بدونش این قابلیت **سه** خرابیِ کاملاً
     *    متفاوت دارد که از بیرون دقیقاً یک شکل‌اند: «هیچ اعلانی نیامد».
     *
     *    ۱. پیامک اصلاً به گیرنده نرسید (محدودیتِ پس‌زمینه‌ی رام، یا اپ
     *       در حالتِ stopped).
     *    ۲. رسید ولی از صافی رد نشد (کلمه‌ی جهت نداشت یا عددِ درشت).
     *    ۳. رسید و اعلان ساخته شد، ولی اعلان‌ها بسته‌اند.
     *
     *    راهِ حلِ هر سه فرق می‌کند و حدس زدن بینشان یعنی چند نوبت ساختِ
     *    APK و آزمایشِ روی گوشی. با این سه کلید، صفحه‌ی تنظیم همان‌جا
     *    می‌گوید کدام‌یک بوده.
     *
     * ⚠ **متنِ پیامک ذخیره نمی‌شود** — فقط زمان، فرستنده، و نتیجه.
     *   همان قاعده‌ای که متن را در فرگمنت نگه می‌دارد: یک نسخه‌ی دائمیِ
     *   تازه از پیامکِ بانک روی دیسک نمی‌سازیم، آن هم برای چیزی که
     *   تشخیصش به متن نیازی ندارد.
     */
    public static final String PREF_LAST_AT   = "sms_last_at";
    public static final String PREF_LAST_FROM = "sms_last_from";
    public static final String PREF_LAST_WHY  = "sms_last_why";

    /**
     * ⛔ «تا اینجا اعلان ساخته شده» — نشانه‌ی بررسیِ دستیِ صندوقِ پیامک.
     *
     *    `SmsSetupActivity` می‌تواند به خواستِ کاربر صندوق را بگردد و
     *    پیامک‌هایی را که این گیرنده هرگز ندید (پیش از نصب، یا وقتی رام
     *    جلویش را گرفته بود) پیدا کند. بدونِ این نشانه، همان بررسی برای
     *    پیامک‌هایی که **همین حالا** از این مسیر اعلان گرفته‌اند دوباره
     *    اعلان می‌ساخت — یعنی کاربر یک تراکنش را دو بار ثبت می‌کرد،
     *    بی‌هیچ خطایی.
     *
     * ⛔ و فقط بعد از ساختنِ **واقعیِ** اعلان جلو می‌رود، نه در هر
     *    دریافت. اگر با هر پیامکی جلو می‌رفت، پیامکی که به‌خاطرِ بسته
     *    بودنِ اعلان‌ها دیده نشده بود هم «رسیدگی‌شده» علامت می‌خورد و
     *    بررسیِ دستی دیگر پیدایش نمی‌کرد — همان چیزی که این بررسی برای
     *    نجاتش ساخته شده.
     */
    public static final String PREF_SCAN_AT = "sms_scan_at";

    /** نتیجه‌ی صافی — همان دو شرطی که از قبل بود، فقط حالا نام دارند. */
    public static final String WHY_OK        = "ok";
    public static final String WHY_NO_HINT   = "no_hint";
    public static final String WHY_NO_AMOUNT = "no_amount";
    public static final String WHY_SHORT     = "short";

    /**
     * فهرستِ درشتِ کلماتِ جهت — **زیرمجموعه‌ی سخت‌گیرانه‌ای از آن چیزی که
     * `parseBankSms()` می‌شناسد نیست، بلکه ابرمجموعه‌ی ساده‌ی آن است.**
     *
     * ⚠ «پرید» و «نشست» ادبیاتِ بلوبانک‌اند و بودنشان اینجا لازم است،
     *   وگرنه کاربرِ آن بانک اصلاً هیچ اعلانی نمی‌گرفت.
     */
    private static final String[] HINTS = {
        "برداشت", "واریز", "پرداخت", "خرید", "کسر", "انتقال",
        "بدهکار", "بستانکار", "پرید", "نشست", "دریافت"
    };

    @Override
    public void onReceive(Context ctx, Intent intent) {
        if (!Telephony.Sms.Intents.SMS_RECEIVED_ACTION.equals(intent.getAction())) { return; }

        // ⚠ کلیدِ **عمداً** خاموش یعنی هیچ کاری — حتی خواندنِ متن. کاربری
        //   که این را نمی‌خواهد نباید فقط به لطفِ نبودِ اعلان در امان باشد.
        //   (کلیدِ دست‌نخورده روشن است — `DEFAULT_ON`.)
        SharedPreferences sp = ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        if (!sp.getBoolean(PREF_ON, DEFAULT_ON)) { return; }

        SmsMessage[] parts = Telephony.Sms.Intents.getMessagesFromIntent(intent);
        if (parts == null || parts.length == 0) { return; }

        // ⚠ پیامکِ بلند چند تکه می‌آید و مبلغ ممکن است وسطِ مرزِ دو تکه
        //   باشد؛ پس اول کامل می‌شود، بعد سنجیده.
        StringBuilder body = new StringBuilder();
        String sender = "";
        for (SmsMessage m : parts) {
            if (m == null) { continue; }
            if (sender.isEmpty() && m.getOriginatingAddress() != null) {
                sender = m.getOriginatingAddress();
            }
            String b = m.getMessageBody();
            if (b != null) { body.append(b); }
        }

        String text = body.toString();
        String why  = classify(text);

        // ⛔ **پیش از** هر خروجِ زودهنگام ثبت می‌شود، نه فقط در مسیرِ
        //    موفق. کلِ ارزشِ این ردْ همان حالتی است که اعلانی ساخته
        //    نمی‌شود؛ اگر فقط مسیرِ موفق ثبت می‌شد، دقیقاً در خرابی
        //    ساکت می‌ماند.
        sp.edit()
          .putLong(PREF_LAST_AT, System.currentTimeMillis())
          .putString(PREF_LAST_FROM, sender)
          .putString(PREF_LAST_WHY, why)
          .apply();

        if (!WHY_OK.equals(why)) { return; }

        postNotification(ctx, sender, text);

        // ⚠ بعد از ساختِ اعلان، نه پیش از آن (دلیلش بالای PREF_SCAN_AT).
        //   `currentTimeMillis()` عمداً کمی **جلوتر** از تاریخِ خودِ ردیفِ
        //   صندوق است، پس بررسیِ دستی این پیامک را رد می‌کند — خطا به
        //   سمتِ «دوباره اعلان نساز» می‌رود، نه «دوباره بساز».
        sp.edit().putLong(PREF_SCAN_AT, System.currentTimeMillis()).apply();
    }

    /**
     * ⚠ دو شرط، و هر دو لازم: یک کلمه‌ی جهت **و** یک عددِ دست‌کم
     *   چهاررقمی. بدونِ شرطِ دوم، پیامکِ «واریز حقوق شما انجام شد» هم
     *   اعلان می‌ساخت و کاربر یک فرمِ خالی می‌دید؛ بدونِ شرطِ اول، هر
     *   پیامکِ حاویِ عدد (کدِ تأیید، تبلیغ) اعلان می‌شد.
     *
     * ⚠ **همان دو شرطِ قبلی است، نه یک صافیِ تازه** — فقط به‌جای
     *   `true/false` می‌گوید کدام‌یک رد کرد. منطقِ تصمیم عوض نشده؛ اگر
     *   عوض می‌شد، صافیِ اینجا از `parseBankSms()` دور می‌افتاد و همان
     *   خرابیِ بی‌صدایی می‌شد که بالای این فایل نوشته شده.
     */
    static String classify(String text) {
        if (text.length() < 12) { return WHY_SHORT; }

        boolean hint = false;
        for (String h : HINTS) {
            if (text.contains(h)) { hint = true; break; }
        }
        if (!hint) { return WHY_NO_HINT; }

        return hasBigNumber(text) ? WHY_OK : WHY_NO_AMOUNT;
    }

    /** عددِ چهاررقمی یا بیشتر — با ارقامِ لاتین یا فارسی. */
    private static boolean hasBigNumber(String text) {
        int run = 0;
        for (int i = 0; i < text.length(); i++) {
            char c = text.charAt(i);
            boolean digit = (c >= '0' && c <= '9') || (c >= '۰' && c <= '۹');
            if (digit) {
                if (++run >= 4) { return true; }
            } else if (c != ',' && c != '٬' && c != '.' && c != '/') {
                run = 0;
            }
        }
        return false;
    }

    /**
     * ⛔ `static` و package-visible است تا **صفحه‌ی تنظیم هم از همین
     *    مسیر** اعلانِ آزمایشی بسازد. با یک نسخه‌ی دومِ ساختِ اعلان،
     *    آزمایش می‌توانست موفق شود در حالی که مسیرِ واقعی خراب است —
     *    یعنی بدترین نوعِ سنجش: سبز روی خرابی.
     */
    static void postNotification(Context ctx, String sender, String text) {
        String url;
        try {
            // ⛔ فرگمنت، نه query: سرور هرگز این متن را نمی‌بیند.
            url = ctx.getString(R.string.launch_url) + "#sms=" + URLEncoder.encode(text, "UTF-8");
        } catch (Exception e) {
            return;   // ⚠ سکوت بهتر از اعلانِ خراب است
        }

        Intent open = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
        // ⚠ مقصد صریح است، وگرنه اندروید «با چه چیزی باز شود؟» می‌پرسد و
        //   کاربر ممکن است مرورگرِ معمولی را بزند — آنجا نشستِ اپ نیست.
        open.setPackage(ctx.getPackageName());
        open.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);

        int flags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            flags |= PendingIntent.FLAG_IMMUTABLE;
        }
        // ⚠ شناسه‌ی یکتا از خودِ متن، وگرنه دو پیامکِ پشت سر هم یک
        //   PendingIntent را بازنویسی می‌کردند و اعلانِ دوم متنِ اولی را
        //   باز می‌کرد.
        PendingIntent pi = PendingIntent.getActivity(
                ctx, Math.abs(text.hashCode()), open, flags);

        NotificationManager nm =
                (NotificationManager) ctx.getSystemService(Context.NOTIFICATION_SERVICE);
        if (nm == null) { return; }

        // ⚠ روی اندروید ۱۳ به بالا، بدونِ `POST_NOTIFICATIONS` خطِ
        //   `nm.notify()` زیر **بی‌هیچ خطایی** اجرا می‌شود و هیچ چیزی
        //   دیده نمی‌شود. آن مجوز را `SmsSetupActivity` کنارِ همان مجوزِ
        //   پیامک می‌گیرد و اگر داده نشده باشد همان‌جا صریح می‌گوید —
        //   اینجا کاری از دستِ گیرنده برنمی‌آید (از یک BroadcastReceiver
        //   نمی‌شود مجوز خواست).

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel ch = new NotificationChannel(
                    CHANNEL_ID,
                    ctx.getString(R.string.sms_channel_name),
                    NotificationManager.IMPORTANCE_DEFAULT);
            ch.setDescription(ctx.getString(R.string.sms_channel_desc));
            nm.createNotificationChannel(ch);
        }

        // ⚠ خلاصه‌ی خودِ پیامک در متنِ اعلان می‌آید تا کاربر **پیش از**
        //   باز کردن بداند دارد چه چیزی را ثبت می‌کند. اعلانی که فقط
        //   بگوید «یک پیامک بانکی» یک تپِ اضافه‌ی بی‌فایده است.
        String preview = text.length() > 90 ? text.substring(0, 90) + "…" : text;

        Notification.Builder b = (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O)
                ? new Notification.Builder(ctx, CHANNEL_ID)
                : new Notification.Builder(ctx);

        b.setSmallIcon(android.R.drawable.stat_sys_download_done)
         .setContentTitle(ctx.getString(R.string.sms_notif_title))
         .setContentText(preview)
         .setStyle(new Notification.BigTextStyle().bigText(preview))
         .setSubText(sender)
         .setAutoCancel(true)
         .setContentIntent(pi);

        nm.notify(Math.abs(text.hashCode()), b.build());
    }
}
