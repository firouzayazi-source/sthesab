package ir.stland.hesabland;

import android.Manifest;
import android.app.Activity;
import android.app.NotificationManager;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.Settings;
import android.view.Gravity;
import android.view.View;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

/**
 * تنها صفحه‌ی «بومی» این اپ — و عمداً همین یکی.
 *
 * ⛔ چرا اصلاً وجود دارد: `RECEIVE_SMS` یک مجوزِ **خطرناک** است و از
 *    اندروید ۶ به بعد فقط با یک درخواستِ زنده از داخلِ یک اکتیویتیِ
 *    **خودمان** داده می‌شود. TWA یک پوسته است و `LauncherActivity` مالِ
 *    کتابخانه؛ پس بدونِ این صفحه هیچ راهی برای گرفتنِ مجوز نبود — و
 *    گرفتنِ مجوز از داخلِ صفحه‌ی وب اصلاً ممکن نیست (وب به SMS دسترسی
 *    ندارد؛ همان محدودیتی که در `CLAUDE.md` توضیح داده شده).
 *
 * ⛔ و از حالا **درِ ورودیِ اپ** هم هست (`MAIN`/`LAUNCHER` در manifest).
 *    دلیلش تنها خواسته‌ی صریح است: مجوز باید در **اولین اجرا** پرسیده
 *    شود، و اولین اجرا یعنی همان لحظه‌ای که کاربر آیکون را می‌زند.
 *    - اجرای اول  → همین صفحه با توضیح، و دو راه: روشن کردن، یا رد شدن.
 *    - اجراهای بعد → هیچ چیزی ساخته نمی‌شود؛ همان اول `LauncherActivity`
 *      باز می‌شود و این بسته. کاربر تفاوتی نمی‌بیند.
 *
 * ⛔ و **پیش‌فرض همچنان خاموش است.** خواندنِ پیامک‌های ورودی چیزی نیست
 *    که با یک به‌روزرسانی و بی‌خبر روشن شود. اینجا هم فقط با تپِ خودِ
 *    کاربر روشن می‌شود؛ رد کردن (یا ندادنِ مجوز) هیچ دری را نمی‌بندد و
 *    اپ کاملاً باز می‌شود. همان قاعده‌ی «ثبت‌نام خودسرویس» و «ورود
 *    پیامکی».
 *
 * ⛔ سه حالتِ خرابی جدا نشان داده می‌شوند، چون هر سه بی‌صدا هستند و
 *    راهِ حلشان فرق می‌کند: مجوزِ پیامک نیست؛ اعلان‌ها بسته‌اند (پیامک
 *    خوانده می‌شود ولی هیچ چیزی دیده نمی‌شود)؛ یا خودِ کلید خاموش است.
 *
 * ⚠ صفحه عمداً در کد ساخته می‌شود نه با فایل layout: یک کارتِ متن و چند
 *   دکمه است و یک فایلِ XML تازه فقط چیزی می‌شد که باید هم‌زمان با این
 *   نگه داشته شود.
 */
public class SmsSetupActivity extends Activity {

    private static final int REQ_SMS   = 4021;
    private static final int REQ_NOTIF = 4022;

    /**
     * ⛔ نشانه‌ی «یک بار پرسیده‌ایم». بدونِ آن، هر بار باز کردنِ اپ همان
     *    صفحه را نشان می‌داد — یعنی یک دیوارِ همیشگی سرِ راهِ کسی که
     *    جوابش را داده. و «هشدارِ همیشگی بدتر از نبودنش است».
     *
     * ⚠ جدا از `PREF_ON` است و باید بماند: کسی که «فعلاً نه» زده هم
     *   پاسخ داده، هرچند کلید خاموش مانده.
     */
    private static final String PREF_ONBOARDED = "sms_onboarded";

    private boolean  firstRun;   // از آیکونِ اپ آمده و هنوز پاسخ نداده
    private TextView state;
    private Button   toggle;
    private Button   fixNotif;

    @Override
    protected void onCreate(Bundle saved) {
        super.onCreate(saved);

        boolean fromLauncher = Intent.ACTION_MAIN.equals(getIntent().getAction());
        firstRun = fromLauncher && !prefs().getBoolean(PREF_ONBOARDED, false);

        // ⛔ مسیرِ عبور: هیچ view ای ساخته نمی‌شود و هیچ فریمی کشیده
        //    نمی‌شود. زمینه‌ی تم تیره است، پس همان چیزی دیده می‌شود که
        //    پیش از این هم دیده می‌شد — تا آمدنِ اولین فریمِ کروم.
        if (fromLauncher && !firstRun) {
            openSite();
            return;
        }

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(48, 64, 48, 48);
        root.setBackgroundColor(Color.parseColor("#15171C"));

        TextView title = new TextView(this);
        title.setText(R.string.sms_setup_title);
        title.setTextColor(Color.WHITE);
        title.setTextSize(20);
        title.setGravity(Gravity.END);
        root.addView(title);

        TextView body = new TextView(this);
        body.setText(R.string.sms_setup_body);
        body.setTextColor(Color.parseColor("#B6BCC6"));
        body.setTextSize(14);
        body.setGravity(Gravity.END);
        body.setPadding(0, 32, 0, 32);
        root.addView(body);

        // ⚠ در اولین اجرا صریح گفته می‌شود که رد کردنش چیزی را نمی‌بندد.
        //   بدونِ این خط، صفحه‌ی اولِ یک اپِ تازه‌نصب که مجوزِ پیامک
        //   می‌خواهد شبیهِ یک شرطِ اجباری خوانده می‌شود.
        if (firstRun) {
            TextView lead = new TextView(this);
            lead.setText(R.string.sms_setup_first_run);
            lead.setTextColor(Color.parseColor("#8D93A0"));
            lead.setTextSize(13);
            lead.setGravity(Gravity.END);
            lead.setPadding(0, 0, 0, 32);
            root.addView(lead);
        }

        state = new TextView(this);
        state.setTextColor(Color.parseColor("#E8B54D"));
        state.setTextSize(14);
        state.setGravity(Gravity.END);
        state.setPadding(0, 0, 0, 32);
        root.addView(state);

        toggle = new Button(this);
        toggle.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) { onToggle(); }
        });
        root.addView(toggle);

        fixNotif = new Button(this);
        fixNotif.setText(R.string.sms_notif_fix);
        fixNotif.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) { askNotifOrOpenSettings(); }
        });
        root.addView(fixNotif);

        Button bottom = new Button(this);
        bottom.setText(firstRun ? R.string.sms_setup_skip : R.string.sms_setup_back);
        bottom.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                if (firstRun) { markOnboarded(); openSite(); } else { finish(); }
            }
        });
        root.addView(bottom);

        setContentView(root);
        render();
    }

    /** رفتن به خودِ برنامه — همان اکتیویتیِ کتابخانه که آدرس را باز می‌کند. */
    private void openSite() {
        try {
            startActivity(new Intent(this,
                    com.google.androidbrowserhelper.trusted.LauncherActivity.class));
        } catch (Exception e) {
            // ⚠ عمداً بلعیده می‌شود: این صفحه هیچ‌وقت نباید مانعِ باز
            //   شدنِ اپ شود. حتی اگر اینجا شکست بخورد، `finish()` زیر
            //   کاربر را به صفحه‌ی خانه‌ی گوشی برمی‌گرداند، نه به یک
            //   پیامِ خطای بی‌معنا.
        }
        finish();
    }

    private void markOnboarded() {
        prefs().edit().putBoolean(PREF_ONBOARDED, true).apply();
    }

    private SharedPreferences prefs() {
        return getSharedPreferences(BankSmsReceiver.PREFS, Context.MODE_PRIVATE);
    }

    private boolean enabled() {
        return prefs().getBoolean(BankSmsReceiver.PREF_ON, false);
    }

    private boolean granted() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) { return true; }
        return checkSelfPermission(Manifest.permission.RECEIVE_SMS)
                == PackageManager.PERMISSION_GRANTED;
    }

    /**
     * ⛔ «اعلان دیده می‌شود؟» — نه «مجوزش داده شده؟».
     *
     *    `areNotificationsEnabled()` هر دو را با هم جواب می‌دهد: روی
     *    اندروید ۱۳ به بالا ندادنِ `POST_NOTIFICATIONS` را، و روی هر
     *    نسخه‌ای بستنِ اعلان‌های اپ از تنظیماتِ گوشی را. سنجیدنِ فقط
     *    مجوز، حالتِ دومی را نمی‌دید — و آن هم دقیقاً همان خرابیِ
     *    بی‌صداست: پیامک خوانده می‌شود و هیچ چیزی بالا نمی‌آید.
     */
    private boolean notifVisible() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            NotificationManager nm =
                    (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
            if (nm != null) { return nm.areNotificationsEnabled(); }
        }
        return true;
    }

    private void render() {
        // ⛔ «روشن» یعنی هر دو: کلیدِ خودمان **و** مجوزِ اندروید. اگر فقط
        //    کلید سنجیده می‌شد، کاربری که مجوز را از تنظیماتِ گوشی پس
        //    گرفته صفحه‌ی «روشن است» می‌دید و هیچ اعلانی نمی‌گرفت — یعنی
        //    دقیقاً همان خرابیِ بی‌صدایی که این پروژه دنبالش است.
        boolean on = enabled() && granted();

        int msg;
        if (on && !notifVisible())      { msg = R.string.sms_state_no_notif; }
        else if (on)                    { msg = R.string.sms_state_on; }
        else if (enabled())             { msg = R.string.sms_state_no_perm; }
        else                            { msg = R.string.sms_state_off; }
        state.setText(msg);

        toggle.setText(on ? R.string.sms_setup_disable : R.string.sms_setup_enable);

        // ⚠ دکمه‌ی رفعِ اعلان فقط در همان یک حالت دیده می‌شود. دکمه‌ای که
        //   همیشه باشد و بیشترِ وقت‌ها کاری نکند، همان «دکمه‌ی بی‌کار از
        //   نبودنش بدتر است».
        fixNotif.setVisibility(on && !notifVisible() ? View.VISIBLE : View.GONE);
    }

    private void onToggle() {
        if (enabled() && granted()) {
            prefs().edit().putBoolean(BankSmsReceiver.PREF_ON, false).apply();
            markOnboarded();
            render();
            return;
        }
        if (!granted()) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                requestPermissions(new String[]{ Manifest.permission.RECEIVE_SMS }, REQ_SMS);
            } else {
                // اندروید ۵: مجوز هنگام نصب داده شده و درخواستی در کار نیست.
                turnOn();
            }
            return;
        }
        turnOn();
    }

    /**
     * روشن کردنِ کلید — و بلافاصله پرسیدنِ مجوزِ اعلان.
     *
     * ⛔ این دو با هم می‌آیند و جدا کردنشان همان باگِ بی‌صداست: مجوزِ
     *    اعلان **تنها** وقتی لازم است که این قابلیت روشن باشد (تنها
     *    چیزی که این اپ اعلان می‌کند همین است)، و اگر خواسته نشود،
     *    روی اندروید ۱۳ به بالا پیامک خوانده می‌شود و هیچ اعلانی
     *    دیده نمی‌شود.
     */
    private void turnOn() {
        prefs().edit().putBoolean(BankSmsReceiver.PREF_ON, true).apply();
        markOnboarded();
        if (askNotifPermission()) { return; }   // جواب در callback می‌رسد
        render();
        if (firstRun) { openSite(); }
    }

    /** @return آیا دیالوگی بالا آمد (یعنی جواب بعداً می‌رسد) */
    private boolean askNotifPermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU
                && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS)
                   != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{ Manifest.permission.POST_NOTIFICATIONS }, REQ_NOTIF);
            return true;
        }
        return false;
    }

    /**
     * اگر هنوز می‌شود پرسید، بپرس؛ وگرنه تنظیماتِ خودِ اپ را باز کن.
     *
     * ⚠ حالتِ «اعلان‌ها از تنظیمات بسته شده‌اند» با درخواستِ مجوز درست
     *   نمی‌شود و آنجا دیالوگ اصلاً بالا نمی‌آید — پس بدونِ شاخه‌ی دوم،
     *   کاربر دکمه را می‌زد و هیچ اتفاقی نمی‌افتاد.
     */
    private void askNotifOrOpenSettings() {
        if (askNotifPermission()) { return; }
        openAppSettings();
    }

    private void openAppSettings() {
        try {
            Intent i = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                                  Uri.parse("package:" + getPackageName()));
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            startActivity(i);
        } catch (Exception e) {
            // ⚠ روی بعضی پوسته‌ها این صفحه نیست. بی‌صدا رد می‌شود؛
            //   کرش کردنِ اپ سرِ یک دکمه‌ی کمکی بدترین جواب است.
            Toast.makeText(this, R.string.sms_perm_denied, Toast.LENGTH_LONG).show();
        }
    }

    @Override
    public void onRequestPermissionsResult(int req, String[] perms, int[] results) {
        boolean ok = results.length > 0 && results[0] == PackageManager.PERMISSION_GRANTED;

        if (req == REQ_SMS && ok) {
            // `turnOn()` خودش مجوزِ اعلان را می‌پرسد و در اولین اجرا
            // خودش هم اپ را باز می‌کند.
            turnOn();
            return;
        }

        if (req == REQ_SMS) {
            // ⛔ رد کردن هیچ چیزی را نمی‌شکند: کلید خاموش می‌ماند، پیامی
            //    داده می‌شود، و اپ عادی جلو می‌رود.
            prefs().edit().putBoolean(BankSmsReceiver.PREF_ON, false).apply();
            markOnboarded();
            Toast.makeText(this, R.string.sms_perm_denied, Toast.LENGTH_LONG).show();

            // ⚠ «هرگز نپرس» یعنی درخواستِ بعدی بی‌صدا رد می‌شود؛ پس کاربر
            //   به تنظیماتِ خودِ اپ فرستاده می‌شود، وگرنه دکمه را می‌زند و
            //   هیچ اتفاقی نمی‌افتد. **ولی نه در اولین اجرا**: پرت کردنِ
            //   کسی که همین حالا «نه» گفته به صفحه‌ی تنظیماتِ سیستم،
            //   جوابش را نادیده گرفتن است.
            if (!firstRun && Build.VERSION.SDK_INT >= Build.VERSION_CODES.M
                    && !shouldShowRequestPermissionRationale(Manifest.permission.RECEIVE_SMS)) {
                openAppSettings();
            }
        }

        // مجوزِ اعلان: چه بدهد چه ندهد، قابلیت روشن می‌ماند و `render()`
        // حالتِ «اعلان‌ها بسته‌اند» را نشان می‌دهد.
        render();

        // در اولین اجرا، پس از پاسخ به دیالوگ، خودِ برنامه باز می‌شود —
        // کاربر برای رسیدن به اپ نباید دکمه‌ی دیگری بزند.
        if (firstRun) { openSite(); }
    }

    @Override
    protected void onResume() {
        super.onResume();
        // برگشت از تنظیماتِ گوشی: وضعیت ممکن است عوض شده باشد.
        if (state != null) { render(); }
    }
}
