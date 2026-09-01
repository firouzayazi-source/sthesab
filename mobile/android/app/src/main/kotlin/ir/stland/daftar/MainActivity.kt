package ir.stland.daftar

import io.flutter.embedding.android.FlutterFragmentActivity

// ⚠ FlutterFragmentActivity است، نه FlutterActivity.
//
// افزونه‌ی local_auth پنجره‌ی اثر انگشت را با BiometricPrompt نشان می‌دهد
// و آن به یک FragmentActivity نیاز دارد. با FlutterActivity ساده، اپ
// کامپایل و اجرا می‌شود و همه چیز درست به نظر می‌رسد — تا لحظه‌ای که
// کاربر قفل را روشن کند و اپ همان‌جا بیفتد. نه کامپایلر می‌گیردش نه
// flutter analyze.
class MainActivity : FlutterFragmentActivity()
