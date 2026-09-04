package ir.stland.daftar;

import android.Manifest;
import android.app.Activity;
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
 *    اندروید ۶ به بعد فقط با یک درخواستِ زنده از داخلِ اپ داده می‌شود.
 *    TWA یک پوسته است و هیچ اکتیویتیِ خودمان ندارد، پس بدونِ این صفحه
 *    هیچ راهی برای گرفتنِ مجوز نبود — و گرفتنِ مجوز از داخلِ صفحه‌ی وب
 *    اصلاً ممکن نیست (وب به SMS دسترسی ندارد؛ همان محدودیتی که در
 *    `CLAUDE.md` توضیح داده شده).
 *
 * ⛔ و **پیش‌فرض خاموش است.** خواندنِ پیامک‌های ورودی چیزی نیست که با یک
 *    به‌روزرسانی و بی‌خبر روشن شود؛ کاربر باید هم کلید را بزند هم مجوز
 *    را بدهد. همان قاعده‌ی «ثبت‌نام خودسرویس» و «ورود پیامکی».
 *
 * ⚠ صفحه عمداً در کد ساخته می‌شود نه با فایل layout: یک کارتِ متن و دو
 *   دکمه است و یک فایلِ XML تازه فقط چیزی می‌شد که باید هم‌زمان با این
 *   نگه داشته شود.
 */
public class SmsSetupActivity extends Activity {

    private static final int REQ = 4021;

    private TextView state;
    private Button   toggle;

    @Override
    protected void onCreate(Bundle saved) {
        super.onCreate(saved);

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

        Button back = new Button(this);
        back.setText(R.string.sms_setup_back);
        back.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) { finish(); }
        });
        root.addView(back);

        setContentView(root);
        render();
    }

    private boolean granted() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) { return true; }
        return checkSelfPermission(Manifest.permission.RECEIVE_SMS)
                == PackageManager.PERMISSION_GRANTED;
    }

    private SharedPreferences prefs() {
        return getSharedPreferences(BankSmsReceiver.PREFS, Context.MODE_PRIVATE);
    }

    private boolean enabled() {
        return prefs().getBoolean(BankSmsReceiver.PREF_ON, false);
    }

    private void render() {
        // ⛔ «روشن» یعنی هر دو: کلیدِ خودمان **و** مجوزِ اندروید. اگر فقط
        //    کلید سنجیده می‌شد، کاربری که مجوز را از تنظیماتِ گوشی پس
        //    گرفته صفحه‌ی «روشن است» می‌دید و هیچ اعلانی نمی‌گرفت — یعنی
        //    دقیقاً همان خرابیِ بی‌صدایی که این پروژه دنبالش است.
        boolean on = enabled() && granted();
        state.setText(on ? R.string.sms_state_on
                         : (enabled() ? R.string.sms_state_no_perm : R.string.sms_state_off));
        toggle.setText(on ? R.string.sms_setup_disable : R.string.sms_setup_enable);
    }

    private void onToggle() {
        if (enabled() && granted()) {
            prefs().edit().putBoolean(BankSmsReceiver.PREF_ON, false).apply();
            render();
            return;
        }
        if (!granted()) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                requestPermissions(new String[]{ Manifest.permission.RECEIVE_SMS }, REQ);
            }
            return;
        }
        prefs().edit().putBoolean(BankSmsReceiver.PREF_ON, true).apply();
        render();
    }

    @Override
    public void onRequestPermissionsResult(int req, String[] perms, int[] results) {
        if (req != REQ) { return; }
        if (results.length > 0 && results[0] == PackageManager.PERMISSION_GRANTED) {
            prefs().edit().putBoolean(BankSmsReceiver.PREF_ON, true).apply();
        } else {
            // ⚠ «هرگز نپرس» یعنی درخواستِ بعدی بی‌صدا رد می‌شود؛ پس
            //   کاربر به تنظیماتِ خودِ اپ فرستاده می‌شود، وگرنه دکمه را
            //   می‌زند و هیچ اتفاقی نمی‌افتد.
            Toast.makeText(this, R.string.sms_perm_denied, Toast.LENGTH_LONG).show();
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M
                    && !shouldShowRequestPermissionRationale(Manifest.permission.RECEIVE_SMS)) {
                Intent i = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                                      Uri.parse("package:" + getPackageName()));
                i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                startActivity(i);
            }
        }
        render();
    }

    @Override
    protected void onResume() {
        super.onResume();
        // برگشت از تنظیماتِ گوشی: وضعیت ممکن است عوض شده باشد.
        render();
    }
}
