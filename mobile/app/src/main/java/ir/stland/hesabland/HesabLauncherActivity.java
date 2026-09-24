package ir.stland.hesabland;

import android.Manifest;
import android.content.Context;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.os.Build;
import android.os.Bundle;

import androidx.annotation.Nullable;

import com.google.androidbrowserhelper.trusted.LauncherActivity;

import java.util.ArrayList;

/**
 * درِ ورودیِ اپ — همان `LauncherActivity`ِ کتابخانه، با **یک** کارِ اضافه:
 * پرسیدنِ مجوزِ پیامک و اعلان در همان اجرای اول.
 *
 * ⛔ **گزارشِ مالکِ نصب:** «اپ اندروید هنگام راه‌اندازی تأییدِ دسترسی
 *    نمی‌گیره، پیامِ برداشت یا واریز هم گوشی نمی‌گیره.» هر دو یک علت
 *    داشتند: مجوز فقط از صفحه‌ی تنظیم (لینکِ `intent://` در پروفایل)
 *    خواسته می‌شد و کلید هم پیش‌فرض خاموش بود — یعنی کسی که فقط اپ را
 *    نصب و باز می‌کرد **هرگز** پیامکی نمی‌گرفت، بی‌هیچ خطایی.
 *
 * ⛔ **و این همان «پنجره‌ی اضافه»ای نیست که یک بار پس گرفته شد.** آن
 *    نسخه `LAUNCHER` را به `SmsSetupActivity` برده بود: هر بار باز کردنِ
 *    اپ یک اکتیویتیِ دیگر پیش از کروم می‌ساخت و کاربر «رفرش و صفحه‌ی
 *    سفید» می‌دید. اینجا هیچ اکتیویتیِ تازه‌ای در کار نیست — همان یک
 *    اکتیویتیِ کتابخانه است که فقط **تا جوابِ دیالوگ** صبر می‌کند
 *    (`shouldLaunchImmediately()` که خودِ کتابخانه برای همین کار گذاشته)
 *    و بعد همان `launchTwa()` را صدا می‌زند. وقتی مجوزها داده شده‌اند —
 *    یعنی از اجرای دوم به بعد — رفتار بایت‌به‌بایت همان کتابخانه است.
 *
 * ⛔ **دو بار می‌پرسد، نه هر بار** (`MAX_ASKS`). پرسیدن در هر اجرا همان
 *    مزاحمتی است که آدم را وادار می‌کند اپ را پاک کند؛ و اندروید ۱۱ به
 *    بعد خودش بعد از دو «نه» دیگر دیالوگ نشان نمی‌دهد. راهِ بعدی همان
 *    صفحه‌ی تنظیم است که حالتِ «مجوز نیست» را با دکمه‌اش نشان می‌دهد.
 *
 * ⛔ **رد کردن هیچ دری را نمی‌بندد**: چه «اجازه» چه «نه»، اپ همان لحظه
 *    باز می‌شود. و هر خطایی در این مسیر به `launchTwa()` می‌رسد نه به
 *    کرش — اپی که به‌خاطرِ یک دیالوگِ کمکی باز نشود، بدترین خرابیِ ممکن
 *    است.
 *
 * ⚠ اینجا هیچ پیامکی خوانده نمی‌شود و صندوق هم نه. فقط **مجوز** گرفته
 *   می‌شود؛ خواندن کارِ `BankSmsReceiver` است و بررسیِ صندوق فقط با تپِ
 *   کاربر در `SmsSetupActivity`. قاعده ۱۲ همین را می‌سنجد.
 */
public class HesabLauncherActivity extends LauncherActivity {

    private static final int    REQ_START  = 4031;
    private static final String KEY_ASKING = "hesabland.asking";

    /** چند بار در عمرِ نصب پرسیده شده — نه بیشتر از `MAX_ASKS`. */
    static final String PREF_ASKS = "perm_asks";
    static final int    MAX_ASKS  = 2;

    /**
     * ⚠ دیالوگ باز است. اگر اکتیویتی زیرِ آن از نو ساخته شود (چرخشِ
     *   صفحه)، دوباره نمی‌پرسیم و فقط منتظرِ همان جواب می‌مانیم؛ وگرنه
     *   دو دیالوگِ روی هم بالا می‌آمد.
     */
    private boolean asking;

    @Override
    protected void onCreate(@Nullable Bundle saved) {
        asking = saved != null && saved.getBoolean(KEY_ASKING, false);
        super.onCreate(saved);
    }

    @Override
    protected boolean shouldLaunchImmediately() {
        if (asking) { return false; }
        try {
            String[] need = missingPermissions();
            if (need.length == 0) { return true; }

            SharedPreferences sp = getSharedPreferences(BankSmsReceiver.PREFS, Context.MODE_PRIVATE);
            int n = sp.getInt(PREF_ASKS, 0);
            if (n >= MAX_ASKS) { return true; }
            sp.edit().putInt(PREF_ASKS, n + 1).apply();

            requestPermissions(need, REQ_START);
            asking = true;
            return false;
        } catch (Throwable t) {
            asking = false;
            return true;   // ⛔ هر خطایی یعنی «همین حالا باز کن»، نه کرش
        }
    }

    /**
     * مجوزهایی که هنوز داده نشده‌اند.
     *
     * ⚠ مجوزِ پیامک فقط وقتی پرسیده می‌شود که کاربر ثبتِ خودکار را **عمداً**
     *   خاموش نکرده باشد. پیش‌فرض روشن است (`PREF_ON` نبوده = روشن)؛ ولی
     *   کسی که در صفحه‌ی تنظیم «خاموش کردن» را زده نباید با هر نصب و
     *   اجرا دوباره درباره‌ی پیامک پرسیده شود.
     */
    private String[] missingPermissions() {
        ArrayList<String> out = new ArrayList<>();
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) { return new String[0]; }

        boolean captureOn = getSharedPreferences(BankSmsReceiver.PREFS, Context.MODE_PRIVATE)
                .getBoolean(BankSmsReceiver.PREF_ON, BankSmsReceiver.DEFAULT_ON);
        if (captureOn) {
            if (!has(Manifest.permission.RECEIVE_SMS)) { out.add(Manifest.permission.RECEIVE_SMS); }
            if (!has(Manifest.permission.READ_SMS))    { out.add(Manifest.permission.READ_SMS); }
        }
        // ⚠ اعلان فقط روی اندروید ۱۳ به بالا مجوزِ زمانِ اجرا دارد. هم اعلانِ
        //   پیامکِ بانک از آن رد می‌شود هم اعلانِ خودِ سایت (Web Push)، که
        //   با نشستنِ این مجوز همان لحظه «granted» دیده می‌شود.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU
                && !has(Manifest.permission.POST_NOTIFICATIONS)) {
            out.add(Manifest.permission.POST_NOTIFICATIONS);
        }
        return out.toArray(new String[0]);
    }

    private boolean has(String perm) {
        return checkSelfPermission(perm) == PackageManager.PERMISSION_GRANTED;
    }

    @Override
    public void onRequestPermissionsResult(int req, String[] perms, int[] results) {
        super.onRequestPermissionsResult(req, perms, results);
        if (req != REQ_START) { return; }
        asking = false;
        // ⛔ جواب هر چه باشد، اپ باز می‌شود.
        launchTwa();
    }

    @Override
    protected void onSaveInstanceState(Bundle out) {
        super.onSaveInstanceState(out);
        out.putBoolean(KEY_ASKING, asking);
    }
}
