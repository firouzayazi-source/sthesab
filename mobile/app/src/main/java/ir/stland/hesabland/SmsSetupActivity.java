package ir.stland.hesabland;

import android.Manifest;
import android.app.NotificationManager;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.PowerManager;
import android.provider.Settings;
import android.view.Gravity;
import android.view.View;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

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
 * ⛔ **و درِ ورودیِ اپ نیست — یک بار شد و پس گرفته شد.**
 *    `MAIN`/`LAUNCHER` برای «پرسیدنِ مجوز در اولین اجرا» به اینجا منتقل
 *    شده بود. کار می‌کرد، ولی هزینه‌اش را **هر** بار باز کردنِ اپ
 *    می‌داد: یک اکتیویتیِ اضافه پیش از کروم، و کاربر «چند بار رفرش شدن
 *    و صفحه‌ی سفید» می‌دید. مجوز یک بار پرسیده می‌شود و اپ هزار بار باز
 *    می‌شود؛ گذاشتنِ هزینه روی مسیرِ پرتکرار برای سودِ مسیرِ یک‌باره،
 *    معامله‌ی بدی است.
 *    و بدتر از کندی: اولین چیزی که کاربرِ تازه از یک دفترِ مالی می‌دید
 *    یک صفحه‌ی مجوزِ **پیامک** بود.
 *    راهش همان لینکِ `intent://` از پروفایل است — تنها راهی که از اولش
 *    هم بود.
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
 *
 * ⛔ **`AppCompatActivity` است نه `android.app.Activity`ِ خام، و این
 *    اجباری است.** تمِ این اکتیویتی `Theme.AppCompat.NoActionBar` است و
 *    همه‌ی `Button` و `TextView` ها در کد ساخته می‌شوند، پس سبکشان را از
 *    همان تم می‌گیرند. یک اکتیویتیِ فریم‌ورکیِ خام با تمِ AppCompat یک
 *    جفتِ ناهمخوان است؛ تا وقتی این صفحه فقط از لینکِ `intent://` باز
 *    می‌شد کسی به آن نمی‌رسید، ولی از لحظه‌ای که **درِ ورودیِ اپ** شد،
 *    هر خطایی اینجا یعنی اپ اصلاً بالا نمی‌آید.
 */
public class SmsSetupActivity extends AppCompatActivity {

    private static final int REQ_SMS   = 4021;
    private static final int REQ_NOTIF = 4022;

    private TextView state;
    private TextView diag;
    private Button   toggle;
    private Button   fixNotif;
    private Button   fixBattery;

    @Override
    protected void onCreate(Bundle saved) {
        super.onCreate(saved);

        // ⛔ گاردِ `Throwable` سرِ جایش می‌ماند، هرچند این صفحه دیگر درِ
        //    ورودیِ اپ نیست.
        //
        //    همه‌ی ویجت‌ها اینجا در کد ساخته می‌شوند و هر ناهمخوانیِ
        //    تم/اکتیویتی یک استثنا می‌دهد. بدونِ گارد، کاربری که از
        //    پروفایل «تنظیم در اپ اندروید» را می‌زند یک اپِ بسته‌شده
        //    می‌بیند — و هیچ راهی برای فهمیدنِ علتش ندارد.
        //
        // ⚠ و متنِ خطا **نشان داده می‌شود**، برخلافِ قاعده‌ی همیشگی:
        //   جایگزینش سکوتِ کامل است، همان چیزی که این پروژه همه‌جا
        //   «خرابیِ بی‌صدا» می‌نامدش.
        try {
            build();
        } catch (Throwable t) {
            try {
                Toast.makeText(this,
                        t.getClass().getSimpleName() + ": " + t.getMessage(),
                        Toast.LENGTH_LONG).show();
            } catch (Throwable ignored) { }
            finish();
        }
    }

    private void build() {
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

        // ⛔ تنها راهِ رفعِ **علتِ اولِ** «هیچ اعلانی نیامد» — و تا امروز
        //    هیچ‌جای اپ نداشتش: خطِ تشخیص می‌گفت «پیامک اصلاً نرسید» و
        //    بالای همین فایل هم نوشته بود علتش «محدودیتِ پس‌زمینه‌ی رام»
        //    است، ولی کاربر هیچ دکمه‌ای برای برداشتنِ آن محدودیت نداشت.
        //    تشخیصی که راهِ حل ندارد، نصفِ کار است.
        fixBattery = new Button(this);
        fixBattery.setText(R.string.sms_battery_fix);
        fixBattery.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) { openBatterySettings(); }
        });
        root.addView(fixBattery);

        // ⛔ خطِ تشخیص — کم‌رنگ‌تر از خطِ وضعیت، چون جوابِ سؤالِ دوم است
        //    نه اول: «روشن است یا نه» را بالا می‌گوید، این می‌گوید
        //    «آخرین پیامک چه شد».
        diag = new TextView(this);
        diag.setTextColor(Color.parseColor("#8C93A0"));
        diag.setTextSize(13);
        diag.setGravity(Gravity.END);
        diag.setPadding(0, 32, 0, 16);
        root.addView(diag);

        Button test = new Button(this);
        test.setText(R.string.sms_test);
        test.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) { sendTestNotification(); }
        });
        root.addView(test);

        Button bottom = new Button(this);
        bottom.setText(R.string.sms_setup_back);
        bottom.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) { finish(); }
        });
        root.addView(bottom);

        setContentView(root);
        render();
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

    /**
     * ⛔ «اندروید این برنامه را در پس‌زمینه محدود کرده؟» — یک **واقعیتِ
     *    سنجیدنی**، نه یک حدس.
     *
     *    این تنها علتِ «پیامک اصلاً به گیرنده نرسید» است که از داخلِ اپ
     *    هم دیدنی است و هم قابلِ رفع. روی شیائومی و سامسونگ و هواوی
     *    حالتِ **پیش‌فرض** همین است، و خرابی‌اش کاملاً بی‌صداست: مجوز
     *    داده شده، کلید روشن است، اعلان‌ها باز — و هیچ پیامکی خوانده
     *    نمی‌شود.
     *
     * ⚠ زیرِ اندروید ۶ اصلاً چنین چیزی نیست، پس آنجا `true` — وگرنه
     *   دکمه‌ای می‌ساختیم که به صفحه‌ای می‌رود که وجود ندارد.
     */
    private boolean batteryOk() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) { return true; }
        PowerManager pm = (PowerManager) getSystemService(Context.POWER_SERVICE);
        if (pm == null) { return true; }
        return pm.isIgnoringBatteryOptimizations(getPackageName());
    }

    /**
     * ⛔ `ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS` است، نه
     *    `ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS`.
     *
     *    دومی یک دیالوگِ یک‌تپی می‌دهد ولی مجوزِ
     *    `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` می‌خواهد و آن از
     *    مجوزهای **محدودشده‌ی گوگل‌پلی** است — یعنی برای یک دکمه‌ی
     *    کمکی، ریسکِ ردِ کلِ اپ. اولی هیچ مجوزی نمی‌خواهد و همان فهرستِ
     *    سیستم را باز می‌کند؛ یک تپ بیشتر است و هیچ هزینه‌ای ندارد.
     *
     * ⚠ و بعضی رام‌ها این اکشن را ندارند. بدونِ `try`، تپِ کاربر اپ را
     *   می‌بست — همان کرشِ بی‌توضیحی که گاردِ `onCreate` برایش نوشته شد.
     *   اینجا به‌جای بستن، صریح می‌گوید مسیرِ دستی کجاست.
     */
    private void openBatterySettings() {
        try {
            startActivity(new Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS));
        } catch (Throwable t) {
            Toast.makeText(this, R.string.sms_battery_none, Toast.LENGTH_LONG).show();
        }
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

        // ⚠ همان قاعده: فقط وقتی دیده می‌شود که سیستم واقعاً بگوید این
        //   برنامه محدود است. روی گوشیِ معافْ دکمه هیچ کاری نمی‌کرد و
        //   فقط صفحه را شلوغ می‌کرد — «دکمه‌ی بی‌کار از نبودنش بدتر
        //   است». و با روشن شدنِ معافیت، خودش ناپدید می‌شود.
        fixBattery.setVisibility(on && !batteryOk() ? View.VISIBLE : View.GONE);

        diag.setText(lastEventLine());
    }

    /**
     * ⛔ «هیچ اعلانی نیامد» سه علت دارد و از بیرون یک شکل‌اند. این خط
     *    آن‌ها را از هم جدا می‌کند:
     *
     *    - «هنوز هیچ پیامکی نرسیده» → پیامک اصلاً به گیرنده نمی‌رسد
     *      (محدودیتِ پس‌زمینه‌ی رام، یا اپ هرگز باز نشده).
     *    - «رد شد چون …» → رسید ولی از صافی نگذشت؛ متنِ آن بانک با
     *      فهرستِ کلمات نمی‌خواند.
     *    - «شناخته شد و اعلان ساخته شد» → مسیر کامل درست کار کرده و
     *      اعلان‌ها بسته‌اند.
     *
     *    بدونِ این، تشخیص فقط با چند نوبت ساختِ APK و آزمایشِ حدسی
     *    ممکن بود.
     */
    private String lastEventLine() {
        long at = prefs().getLong(BankSmsReceiver.PREF_LAST_AT, 0L);
        if (at <= 0L) {
            // ⛔ «هیچ پیامکی نرسیده» فقط وقتی حرفِ درستی است که قابلیت
            //    واقعاً روشن باشد. با کلیدِ خاموش (یا مجوزِ نداده) گیرنده
            //    پیش از هر ثبتی برمی‌گردد، پس این خط «نرسید» می‌گفت در
            //    حالی که اصلاً قرار نبود چیزی خوانده شود — و کاربر دنبالِ
            //    محدودیتِ رام می‌گشت. همان تشخیصِ غلطی که این خط برای
            //    نفیِ آن نوشته شد.
            //
            // ⚠ و این حالت **بعد از نصبِ دوباره طبیعی است**: پاک شدنِ اپ
            //   تنظیماتش را هم می‌برد، پس کلید به پیش‌فرضِ خاموش برمی‌گردد.
            if (!(enabled() && granted())) { return getString(R.string.sms_diag_off); }

            // ⛔ ادعای علت فقط جایی که اثباتش دستِ خودِ سیستم است. روی
            //    گوشیِ معاف این جمله یک هشدارِ الکی بود و کاربر را دنبالِ
            //    چیزی می‌فرستاد که از قبل درست است — و هشدارِ الکی از
            //    نبودِ تشخیص بدتر است، چون آدم را عادت می‌دهد نادیده‌شان
            //    بگیرد.
            return getString(R.string.sms_diag_none)
                 + (batteryOk() ? "" : getString(R.string.sms_diag_battery));
        }

        String from = prefs().getString(BankSmsReceiver.PREF_LAST_FROM, "");
        String why  = prefs().getString(BankSmsReceiver.PREF_LAST_WHY, "");
        if (from == null || from.isEmpty()) { from = "—"; }

        int msg;
        if (BankSmsReceiver.WHY_OK.equals(why))              { msg = R.string.sms_diag_ok; }
        else if (BankSmsReceiver.WHY_NO_HINT.equals(why))    { msg = R.string.sms_diag_no_hint; }
        else if (BankSmsReceiver.WHY_NO_AMOUNT.equals(why))  { msg = R.string.sms_diag_no_amount; }
        else                                                 { msg = R.string.sms_diag_short; }

        return getString(msg, ago(at), from);
    }

    /**
     * فاصله‌ی زمانی، نه تاریخ.
     *
     * ⛔ عمداً هیچ تقویمی در کار نیست: تبدیلِ شمسی فقط در توابعِ خودِ
     *    پروژه انجام می‌شود و پیاده‌سازیِ دومش در جاوا همان چیزی است که
     *    قاعده‌ی پروژه ممنوع کرده. برای تشخیص هم «۳ دقیقه پیش» از یک
     *    تاریخِ کامل گویاتر است.
     */
    private String ago(long at) {
        long min = (System.currentTimeMillis() - at) / 60000L;
        if (min < 1)    { return getString(R.string.sms_diag_now); }
        if (min < 60)   { return getString(R.string.sms_diag_min, min); }
        if (min < 1440) { return getString(R.string.sms_diag_hour, min / 60); }
        return getString(R.string.sms_diag_day, min / 1440);
    }

    /**
     * ⛔ از **همان** مسیرِ اعلانِ واقعی می‌رود (`postNotification`)، نه یک
     *    نسخه‌ی دوم. با نسخه‌ی دوم، آزمایش می‌توانست سبز شود در حالی که
     *    مسیرِ واقعی خراب است — همان «سنجشی که روی خرابی سبز می‌شود».
     *
     * ⚠ متنِ نمونه در `strings.xml` است نه اینجا: منطقِ دامنه (مبلغ و
     *   واحدِ پول) داخلِ فایلِ بومی نمی‌آید.
     */
    private void sendTestNotification() {
        BankSmsReceiver.postNotification(
                this,
                getString(R.string.sms_test_from),
                getString(R.string.sms_test_body));
        Toast.makeText(this, R.string.sms_test_sent, Toast.LENGTH_LONG).show();
    }

    private void onToggle() {
        if (enabled() && granted()) {
            prefs().edit().putBoolean(BankSmsReceiver.PREF_ON, false).apply();
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
        if (askNotifPermission()) { return; }   // جواب در callback می‌رسد
        render();
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
        // ⚠ `AppCompatActivity` این را برای فرگمنت‌ها لازم دارد؛ نبودنش
        //   امروز چیزی نمی‌شکند ولی یک بدهیِ خاموش است.
        super.onRequestPermissionsResult(req, perms, results);

        boolean ok = results.length > 0 && results[0] == PackageManager.PERMISSION_GRANTED;

        if (req == REQ_SMS && ok) {
            turnOn();   // خودش مجوزِ اعلان را هم می‌پرسد
            return;
        }

        if (req == REQ_SMS) {
            // ⛔ رد کردن هیچ چیزی را نمی‌شکند: کلید خاموش می‌ماند، پیامی
            //    داده می‌شود، و اپ عادی جلو می‌رود.
            prefs().edit().putBoolean(BankSmsReceiver.PREF_ON, false).apply();
            Toast.makeText(this, R.string.sms_perm_denied, Toast.LENGTH_LONG).show();

            // ⚠ «هرگز نپرس» یعنی درخواستِ بعدی بی‌صدا رد می‌شود؛ پس کاربر
            //   به تنظیماتِ خودِ اپ فرستاده می‌شود، وگرنه دکمه را می‌زند و
            //   هیچ اتفاقی نمی‌افتد.
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M
                    && !shouldShowRequestPermissionRationale(Manifest.permission.RECEIVE_SMS)) {
                openAppSettings();
            }
        }

        // مجوزِ اعلان: چه بدهد چه ندهد، قابلیت روشن می‌ماند و `render()`
        // حالتِ «اعلان‌ها بسته‌اند» را نشان می‌دهد.
        render();
    }

    @Override
    protected void onResume() {
        super.onResume();
        // برگشت از تنظیماتِ گوشی: وضعیت ممکن است عوض شده باشد.
        if (state != null) { render(); }
    }
}
