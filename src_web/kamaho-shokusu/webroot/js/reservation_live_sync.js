/* eslint-env browser */
/**
 * 予約データの更新を拾って画面に反映する。
 *
 * 他の人が予約を変更しても、画面を開いたままでは気づけなかった。
 * サーバーが持つ「版数」を短い間隔で問い合わせ、変わっていたときだけ
 * 本体のデータを取りに行く。
 *
 * 方式について:
 *   WebSocket や SSE ではなく短いポーリングにしている。本番の Apache は
 *   prefork（1接続につき1プロセス）のため、張りっぱなしの接続は
 *   利用者が増えるとワーカーを食い潰してしまう。
 *   版数の問い合わせは DB を引かずキャッシュを読むだけなので、
 *   この間隔なら負荷は実質かからない。
 *
 * 使い方:
 *   ReservationLiveSync.start({
 *       onChange: function () { ... 画面を更新する ... },
 *       hasUnsavedChanges: function () { return 未保存があるか; }
 *   });
 */
(function () {
    'use strict';

    /*
     * 既定の間隔。
     *
     * 本番の Apache は prefork + KeepAlive(5秒) のため、5秒より短い間隔で
     * 問い合わせると接続が切れる前に次が来て、画面を開いている人数ぶんの
     * ワーカーを常時占有してしまう。prefork は1ワーカーが数十MBのメモリを
     * 持つので、メモリの小さいサーバーではここが先に限界に達する。
     * KeepAliveTimeout より十分長い値にすること。
     */
    var DEFAULT_INTERVAL_MS = 20000;

    /** これより短い間隔は、上記の理由により受け付けない */
    var MIN_INTERVAL_MS = 6000;

    /** 画面に触れないまま この時間を過ぎたら問い合わせを止める */
    var IDLE_STOP_MS = 30 * 60 * 1000;

    /** 連続して失敗したときに間隔を伸ばす上限 */
    var MAX_BACKOFF_MULTIPLIER = 8;

    // document.currentScript は実行直後にしか取れないため、ここで控える
    var selfScript = document.currentScript;

    var state = {
        timerId: null,
        knownVersion: null,
        options: null,
        inFlight: false,
        failureCount: 0,
        skipTicks: 0,
        lastActivityAt: Date.now(),
    };

    /**
     * 問い合わせ先の URL を組み立てる。
     *
     * __BASE_PATH はカレンダー画面でしか定義されていないため、
     * 無い場合は読み込んだ自分の script の src から導く。
     * ベースパス（/kamaho-shokusu など）を取り違えると 404 になり、
     * 更新に永久に気づけなくなる。
     */
    function endpointUrl() {
        var base = (typeof window.__BASE_PATH === 'string' && window.__BASE_PATH)
            ? window.__BASE_PATH
            : basePathFromScriptSrc();
        return base.replace(/\/$/, '') + '/TReservationInfo/sync-version';
    }

    function basePathFromScriptSrc() {
        var el = selfScript
            || document.querySelector('script[src*="reservation_live_sync"]');
        if (!el || !el.src) return '';
        try {
            // 例: https://host/kamaho-shokusu/js/reservation_live_sync.js -> /kamaho-shokusu
            var path = new URL(el.src, window.location.origin).pathname;
            var idx = path.indexOf('/js/');
            return idx > 0 ? path.slice(0, idx) : '';
        } catch (e) {
            return '';
        }
    }

    /** 版数を取得する。取れなければ null を返す（失敗しても画面は壊さない）。 */
    function fetchVersion() {
        return fetch(endpointUrl(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store',
        })
            .then(function (res) {
                if (!res.ok) return null;
                return res.json();
            })
            .then(function (json) {
                var v = json && (json.version != null ? json.version : (json.data && json.data.version));
                return (typeof v === 'number') ? v : null;
            })
            .catch(function () {
                // 通信に失敗しても何もしない。次回の問い合わせで拾い直す。
                return null;
            });
    }

    function check() {
        if (state.inFlight) return;
        // タブが裏にある間は問い合わせない（無駄なリクエストを減らす）
        if (document.hidden) return;

        // 開きっぱなしで放置された画面が、夜通し問い合わせ続けるのを防ぐ。
        // 操作を再開すれば自動で復帰する。
        if (Date.now() - state.lastActivityAt > IDLE_STOP_MS) return;

        // 失敗が続いている間は間隔を空ける（サーバーが苦しいときに追い打ちしない）
        if (state.skipTicks > 0) {
            state.skipTicks--;
            return;
        }

        state.inFlight = true;
        fetchVersion().then(function (version) {
            state.inFlight = false;

            if (version === null) {
                // 失敗するほど間隔を伸ばす（最大でも既定間隔の MAX 倍まで）
                state.failureCount++;
                state.skipTicks = Math.min(state.failureCount, MAX_BACKOFF_MULTIPLIER);
                return;
            }
            state.failureCount = 0;
            state.skipTicks = 0;

            if (state.knownVersion === null) {
                state.knownVersion = version;
                return;
            }
            if (version === state.knownVersion) return;

            state.knownVersion = version;
            try {
                state.options.onChange();
            } catch (e) {
                console.warn('ReservationLiveSync onChange error:', e);
            }
        });
    }

    /** 保存直後など、こちらの操作で版数が進んだときに基準を取り直す。 */
    function resync() {
        fetchVersion().then(function (version) {
            if (version !== null) state.knownVersion = version;
        });
    }

    function resolveInterval() {
        var requested = (typeof window.__RESERVATION_SYNC_INTERVAL_MS === 'number'
            && window.__RESERVATION_SYNC_INTERVAL_MS > 0)
            ? window.__RESERVATION_SYNC_INTERVAL_MS
            : DEFAULT_INTERVAL_MS;

        if (window.__RESERVATION_SYNC_ALLOW_FAST === true) return requested;

        return Math.max(requested, MIN_INTERVAL_MS);
    }

    /** 利用者が触っていることを記録する（放置判定の基準） */
    function markActivity() {
        state.lastActivityAt = Date.now();
    }

    function start(options) {
        if (state.timerId) return;
        state.options = Object.assign({
            // テストや検証で間隔を詰めたいときに上書きできるようにする。
            // ただし MIN_INTERVAL_MS を下回る指定は、prefork のワーカー占有を
            // 招くため受け付けない（テスト時のみ __RESERVATION_SYNC_ALLOW_FAST で解除）。
            intervalMs: resolveInterval(),
            onChange: function () {},
            hasUnsavedChanges: function () { return false; },
        }, options || {});

        // 初回に現在の版数を控える（この時点では画面を更新しない）
        resync();

        state.timerId = window.setInterval(check, state.options.intervalMs);

        // 裏に回っている間の変更を、戻ってきた時点ですぐ拾う
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { markActivity(); check(); }
        });

        // 操作があったら「放置」の判定をリセットする
        ['click', 'keydown', 'scroll', 'touchstart'].forEach(function (type) {
            document.addEventListener(type, markActivity, { passive: true });
        });
    }

    function stop() {
        if (state.timerId) {
            window.clearInterval(state.timerId);
            state.timerId = null;
        }
    }

    /**
     * 「他の人が更新しました」のお知らせを画面上部に出す。
     *
     * 入力途中の内容を勝手に消さないため、未保存の変更があるときは
     * 自動では更新せず、再読み込みするかどうかを利用者に委ねる。
     */
    function showReloadNotice(message) {
        if (document.getElementById('reservation-live-notice')) return;

        var notice = document.createElement('div');
        notice.id = 'reservation-live-notice';
        notice.className = 'alert alert-info d-flex align-items-center gap-2 mb-3';
        notice.setAttribute('role', 'status');
        notice.setAttribute('aria-live', 'polite');
        notice.innerHTML =
            '<i class="bi bi-arrow-clockwise" aria-hidden="true"></i>' +
            '<span>' + String(message || '他の方が予約を更新しました。').replace(/[<>&"]/g, '') + '</span>' +
            '<button type="button" class="btn btn-sm btn-primary ms-auto" id="reservation-live-reload">最新を表示する</button>';

        var host = document.querySelector('#ce-root, #mcg-root, .dash-main, .container, main') || document.body;
        host.insertBefore(notice, host.firstChild);

        var btn = document.getElementById('reservation-live-reload');
        if (btn) btn.addEventListener('click', function () { window.location.reload(); });
    }

    window.ReservationLiveSync = {
        start: start,
        stop: stop,
        resync: resync,
        showReloadNotice: showReloadNotice,
    };
})();
