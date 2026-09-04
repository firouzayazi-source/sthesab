package ir.stland.daftar;

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
    public  static final String PREFS      = "daftar";
    public  static final String PREF_ON    = "sms_capture_on";

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

        // ⚠ کلید خاموش باشد یعنی هیچ کاری — حتی خواندنِ متن. کاربری که
        //   این را نمی‌خواهد نباید فقط به لطفِ نبودِ اعلان در امان باشد.
        SharedPreferences sp = ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        if (!sp.getBoolean(PREF_ON, false)) { return; }

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
        if (text.length() < 12 || !looksLikeBankSms(text)) { return; }

        notifyUser(ctx, sender, text);
    }

    /**
     * ⚠ دو شرط، و هر دو لازم: یک کلمه‌ی جهت **و** یک عددِ دست‌کم
     *   چهاررقمی. بدونِ شرطِ دوم، پیامکِ «واریز حقوق شما انجام شد» هم
     *   اعلان می‌ساخت و کاربر یک فرمِ خالی می‌دید؛ بدونِ شرطِ اول، هر
     *   پیامکِ حاویِ عدد (کدِ تأیید، تبلیغ) اعلان می‌شد.
     */
    private static boolean looksLikeBankSms(String text) {
        boolean hint = false;
        for (String h : HINTS) {
            if (text.contains(h)) { hint = true; break; }
        }
        if (!hint) { return false; }

        // عددِ چهاررقمی یا بیشتر — با ارقامِ لاتین یا فارسی.
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

    private void notifyUser(Context ctx, String sender, String text) {
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
