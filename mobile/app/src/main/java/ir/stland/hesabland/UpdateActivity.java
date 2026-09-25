package ir.stland.hesabland;

import android.app.DownloadManager;
import android.content.Intent;
import android.database.Cursor;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import android.view.Gravity;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

import java.io.File;

/**
 * به‌روزرسانیِ اپ با یک تپ — دریافتِ APKِ تازه و باز کردنِ پنجره‌ی نصب.
 *
 * ⛔ **گزارشِ مالکِ نصب:** «باید وقتی آپدیت جدید برای اندروید آمده روش بزنم
 *    و بلافاصله آپدیت بشه؛ الان می‌زنم دانلود می‌شه و باید دستی نصب کنم.»
 *    پیش از این، دکمه‌ی «به‌روزرسانی» فایل را با دانلودِ مرورگر می‌گرفت و
 *    کاربر باید خودش اعلانِ دانلود را پیدا و باز می‌کرد.
 *    حالا: تپ → این صفحه فایل را می‌گیرد (با درصد) → پنجره‌ی نصبِ خودِ
 *    اندروید **همان‌جا** باز می‌شود → یک تپ روی «به‌روزرسانی».
 *
 * ⛔ **نصبِ کاملاً بی‌صدا ممکن نیست و این محدودیتِ اندروید است، نه
 *    انتخاب.** هیچ اپی جز فروشگاهِ سیستمی (یا اپِ مدیرِ دستگاه) بدونِ
 *    تأییدِ کاربر نصب نمی‌کند. آن یک تپِ آخر حذف‌شدنی نیست.
 *
 * ⛔ آدرسِ فایل از **خودِ اپ** ساخته می‌شود (`R.string.launch_url`)، نه از
 *    ورودیِ لینک: این صفحه `exported` است و هر صفحه‌ی وبی می‌تواند با یک
 *    `intent://` بازش کند. اگر آدرس از بیرون می‌آمد، یک سایتِ دیگر
 *    می‌توانست فایلِ دلخواهش را جلوی پنجره‌ی نصب بگذارد. از ورودی فقط
 *    عددِ `v` (فقط رقم) خوانده می‌شود، و فقط برای شکستنِ کشِ CDN.
 *
 * ⚠ خودِ اندروید هم سدِ دوم است: نسخه‌ی تازه فقط وقتی «روی» اپ نصب
 *   می‌شود که با **همان کلید** امضا شده باشد.
 *
 * ⚠ و اولین بار اندروید ۸ به بالا یک اجازه‌ی «نصب از این منبع» برای
 *   **خودِ این اپ** می‌خواهد (`REQUEST_INSTALL_PACKAGES` در manifest).
 *   اگر نبود، صفحه‌ی تنظیمش باز می‌شود و با برگشتن ادامه پیدا می‌کند.
 */
public class UpdateActivity extends AppCompatActivity {

    static final String APK_PATH = "/download/hesabland.apk";
    static final String APK_MIME = "application/vnd.android.package-archive";
    static final String FILE_NAME = "hesabland-update.apk";

    private final Handler handler = new Handler(Looper.getMainLooper());
    private DownloadManager dm;
    private long downloadId = -1;
    private boolean waitingPermission = false;
    private TextView status;
    private Button action;

    @Override
    protected void onCreate(Bundle saved) {
        super.onCreate(saved);
        // ⛔ همان گاردِ `SmsSetupActivity`: همه‌ی ویجت‌ها در کد ساخته
        //    می‌شوند و هر ناهمخوانیِ تم یک استثنا می‌دهد؛ بی‌گارد، کاربر
        //    فقط یک اپِ بسته‌شده می‌دید. راهِ فرار، دانلود در مرورگر است.
        try {
            build();
            start();
        } catch (Throwable t) {
            try {
                Toast.makeText(this, t.getClass().getSimpleName() + ": " + t.getMessage(),
                        Toast.LENGTH_LONG).show();
            } catch (Throwable ignored) { }
            openInBrowser();
            finish();
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        // برگشت از صفحه‌ی «نصب از این منبع»
        if (waitingPermission && canInstall()) {
            waitingPermission = false;
            install();
        }
    }

    @Override
    protected void onDestroy() {
        handler.removeCallbacksAndMessages(null);
        super.onDestroy();
    }

    private void build() {
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setGravity(Gravity.CENTER);
        root.setPadding(48, 64, 48, 48);
        root.setBackgroundColor(Color.parseColor("#15171C"));

        TextView title = new TextView(this);
        title.setText(getString(R.string.update_title));
        title.setTextColor(Color.WHITE);
        title.setTextSize(20);
        title.setGravity(Gravity.CENTER);
        root.addView(title);

        status = new TextView(this);
        status.setText(getString(R.string.update_starting));
        status.setTextColor(Color.parseColor("#B8BCC6"));
        status.setTextSize(15);
        status.setGravity(Gravity.CENTER);
        status.setPadding(0, 32, 0, 32);
        root.addView(status);

        action = new Button(this);
        action.setText(getString(R.string.update_cancel));
        action.setOnClickListener(v -> cancel());
        root.addView(action);

        setContentView(root);
    }

    /** آدرسِ فایل — فقط از دامنه‌ی خودِ اپ. */
    private Uri apkUri() {
        Uri base = Uri.parse(getString(R.string.launch_url));
        String v = null;
        Uri data = getIntent() != null ? getIntent().getData() : null;
        if (data != null) {
            String q = data.getQueryParameter("v");
            if (q != null && q.matches("[0-9]{1,9}")) { v = q; }
        }
        Uri.Builder b = new Uri.Builder()
                .scheme("https")
                .encodedAuthority(base.getEncodedAuthority())
                .encodedPath(APK_PATH);
        if (v != null) { b.appendQueryParameter("v", v); }
        return b.build();
    }

    private File target() {
        File dir = getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS);
        return new File(dir, FILE_NAME);
    }

    private void start() {
        dm = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
        if (dm == null) { failed(); return; }

        File f = target();
        if (f.exists()) { f.delete(); }   // نسخه‌ی قبلیِ دریافت‌شده، نه چیزِ دیگری

        DownloadManager.Request req = new DownloadManager.Request(apkUri());
        req.setMimeType(APK_MIME);
        req.setTitle(getString(R.string.update_title));
        req.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE);
        req.setDestinationInExternalFilesDir(this, Environment.DIRECTORY_DOWNLOADS, FILE_NAME);
        downloadId = dm.enqueue(req);
        handler.post(poll);
    }

    private final Runnable poll = new Runnable() {
        @Override public void run() {
            if (dm == null || downloadId < 0) { return; }
            Cursor c = null;
            try {
                c = dm.query(new DownloadManager.Query().setFilterById(downloadId));
                if (c == null || !c.moveToFirst()) { failed(); return; }
                int st = c.getInt(c.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS));
                long done = c.getLong(c.getColumnIndexOrThrow(DownloadManager.COLUMN_BYTES_DOWNLOADED_SO_FAR));
                long total = c.getLong(c.getColumnIndexOrThrow(DownloadManager.COLUMN_TOTAL_SIZE_BYTES));
                if (st == DownloadManager.STATUS_SUCCESSFUL) { install(); return; }
                if (st == DownloadManager.STATUS_FAILED) { failed(); return; }
                if (total > 0) {
                    status.setText(getString(R.string.update_progress, (int) (done * 100 / total)));
                }
            } catch (Throwable t) {
                failed();
                return;
            } finally {
                if (c != null) { c.close(); }
            }
            handler.postDelayed(this, 400);
        }
    };

    private boolean canInstall() {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.O
                || getPackageManager().canRequestPackageInstalls();
    }

    private void install() {
        if (!canInstall()) {
            waitingPermission = true;
            status.setText(getString(R.string.update_need_source));
            try {
                startActivity(new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                        Uri.parse("package:" + getPackageName())));
            } catch (Throwable t) {
                failed();
            }
            return;
        }
        try {
            Uri file;
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
                // ⚠ از ۷ به بعد آدرسِ `file://` به اپِ دیگر داده نمی‌شود؛
                //   `content://`ِ خودِ DownloadManager با اجازه‌ی خواندن.
                file = dm.getUriForDownloadedFile(downloadId);
            } else {
                file = Uri.fromFile(target());
            }
            if (file == null) { failed(); return; }
            Intent i = new Intent(Intent.ACTION_VIEW);
            i.setDataAndType(file, APK_MIME);
            i.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
            startActivity(i);
            finish();
        } catch (Throwable t) {
            failed();
        }
    }

    /** شکست هرگز بی‌راه نمی‌ماند: همان دانلودِ مرورگر. */
    private void failed() {
        handler.removeCallbacksAndMessages(null);
        status.setText(getString(R.string.update_failed));
        action.setText(getString(R.string.update_in_browser));
        action.setOnClickListener(v -> { openInBrowser(); finish(); });
    }

    private void cancel() {
        handler.removeCallbacksAndMessages(null);
        if (dm != null && downloadId >= 0) {
            try { dm.remove(downloadId); } catch (Throwable ignored) { }
        }
        finish();
    }

    private void openInBrowser() {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, apkUri())
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
        } catch (Throwable ignored) { }
    }
}
