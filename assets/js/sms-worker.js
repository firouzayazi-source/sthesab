/**
 * ⛔ کارگرِ پس‌زمینه‌ی اپ اندروید — پیامکِ بانک بی‌باز کردنِ اپ به دفتر می‌رسد.
 *
 * `SmsSync.java` این صفحه (`assets/sms-worker.html`) را در یک WebViewِ
 * نامرئی باز می‌کند و `smsRun()` را با پیامک‌های تازه‌ی صندوق صدا می‌زند؛
 * جواب را با `HesabSms.done(json)` پس می‌گیرد.
 *
 * ⛔ تصمیم فقط با `smsWorker()` (`sms-core.js`) — همان سه تابعِ مسیرِ وب.
 * ⛔ متنِ پیامک **هرگز** در درخواست نیست؛ فقط فیلدهای `post` و اثرِ انگشت
 *    (قاعده ۱۹). کلید (`token`) محدود به `api/v1/sms/*` است.
 *
 * نتیجه‌ی هر پیامک (`status`) — اپ بر اساسش نشانه را جلو می‌برد:
 *   posted  ثبت شد            dup     پیش‌تر ثبت شده بود
 *   review  تراکنش است ولی خودکار نه (اعلانِ «بزن تا ثبت شود»)
 *   skip    تراکنش نیست (رمزِ پویا، آگهی، مانده)
 *   error   شبکه/سرور — همین و بعدی‌ها دوباره تلاش می‌شوند
 */
(function () {
    'use strict';

    // ⚠ پایه از خودِ آدرسِ صفحه، تا نصب در زیرپوشه هم کار کند.
    var BASE = String(window.location.href).replace(/\/assets\/sms-worker\.html.*$/, '');

    function call(path, token, body) {
        var opt = { method: body ? 'POST' : 'GET', headers: { 'Accept': 'application/json' }, cache: 'no-store' };
        if (token) { opt.headers.Authorization = 'Bearer ' + token; }
        if (body) {
            opt.headers['Content-Type'] = 'application/json';
            opt.body = JSON.stringify(body);
        }
        // ⚠ `?p=` همیشه کار می‌کند، حتی بی‌قاعده‌ی rewriteِ nginx (`api/v1/index.php`).
        return fetch(BASE + '/api/v1/index.php?p=' + path, opt).then(function (res) {
            return res.json().then(function (j) { return { status: res.status, j: j || {} }; },
                                   function () { return { status: res.status, j: {} }; });
        });
    }

    /** روزِ محلیِ رسیدنِ پیامک (YYYY-MM-DD) — برای پیامکِ بی‌تاریخ. */
    function isoDay(ms) {
        var d = new Date(+ms || Date.now());
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    }

    /**
     * @param {{token:string, nonce:string, items:Array<{t:string, at:number}>}} cfg
     */
    window.smsRun = function (cfg) {
        cfg = cfg || {};
        var out = { token: '', username: '', unauth: false, results: [] };
        var token = String(cfg.token || '');
        var items = Array.isArray(cfg.items) ? cfg.items : [];
        var done = function () {
            try { window.HesabSms.done(JSON.stringify(out)); } catch (e) { /* بیرون از اپ */ }
        };

        var seq = Promise.resolve();
        if (!token && cfg.nonce) {
            seq = call('sms/claim', '', { nonce: String(cfg.nonce) }).then(function (r) {
                if (r.status === 200 && r.j.data && r.j.data.token) {
                    token = out.token = String(r.j.data.token);
                    out.username = String(r.j.data.username || '');
                }
            });
        }

        seq.then(function () {
            if (!token) { return null; }
            return call('sms/wallets', token, null);
        }).then(function (w) {
            if (!w) { return; }
            if (w.status === 401 || w.status === 403) { out.unauth = true; return; }
            if (w.status !== 200 || !w.j.data) { return; }   // بی‌نتیجه → اپ دوباره تلاش می‌کند
            var wallets = w.j.data.items || [];
            var chain = Promise.resolve(true);
            items.forEach(function (it) {
                chain = chain.then(function (go) {
                    if (!go) { return false; }
                    var v = window.smsWorker(String(it.t || ''), wallets, isoDay(it.at));
                    var res = { at: it.at, status: '', label: v.label, why: v.why };
                    out.results.push(res);
                    if (!v.post) { res.status = v.label ? 'review' : 'skip'; return true; }
                    var body = {};
                    for (var k in v.post) { if (Object.prototype.hasOwnProperty.call(v.post, k)) { body[k] = v.post[k]; } }
                    body.fp = v.fp;
                    body.sms_at = it.at;
                    return call('sms/tx', token, body).then(function (r) {
                        if (r.status === 201 || r.status === 200) {
                            res.status = r.j.data && r.j.data.duplicate ? 'dup' : 'posted';
                            return true;
                        }
                        if (r.status === 401 || r.status === 403) { out.unauth = true; res.status = 'error'; return false; }
                        if (r.status >= 400 && r.status < 500) { res.status = 'review'; return true; }
                        res.status = 'error';
                        return false;
                    }, function () { res.status = 'error'; return false; });
                });
            });
            return chain;
        }).then(done, function () { done(); });
    };
})();
