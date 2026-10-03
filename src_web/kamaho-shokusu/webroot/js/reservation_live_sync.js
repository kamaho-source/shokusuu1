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

    /** 自分の書き込みが連続したとき、基準の取り直しをまとめる待ち時間 */
    var RESYNC_DEBOUNCE_MS = 400;

    /**
     * ログインが切れたことを表す印。
     *
     * 通信失敗（null）と区別する必要がある。通信失敗は黙って間隔を伸ばして
     * 待てばよいが、ログイン切れは待っても直らない。区別せずに扱うと
     * 「自動更新されているつもりで古い画面を見続ける」ことになる。
     */
    var AUTH_LOST = 'auth-lost';

    // document.currentScript は実行直後にしか取れないため、ここで控える
    var selfScript = document.currentScript;

    var state = {
        timerId: null,
        knownVersion: null,
        options: null,
        inFlight: false,
        failureCount: 0,
        skipTicks: 0,
        resyncTimerId: null,
        /** 自分の保存を他の人の変更と誤認しないための抑止フラグ */
        selfWritePending: false,
        lastActivityAt: Date.now(),
        /** ログイン切れを知らせたか（何度も出さない） */
        authLost: false,
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

    /**
     * 版数を取得する。
     *
     * @returns {Promise<number|string|null>}
     *   数値 = 版数 / AUTH_LOST = ログイン切れ / null = 取得できず（通信失敗など）
     *
     * redirect: 'manual' が要る。既定の 'follow' だとログイン画面へ 302 で
     * 飛ばされた結果の HTML が 200 で返り、JSON 解析の失敗として
     * 「ただの通信失敗」に埋もれてしまう。
     */
    function fetchVersion() {
        return fetch(endpointUrl(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store',
            redirect: 'manual',
        })
            .then(function (res) {
                // ログイン画面へのリダイレクト（opaqueredirect）と権限切れ
                if (res.type === 'opaqueredirect' || res.status === 401 || res.status === 403) {
                    return AUTH_LOST;
                }
                if (!res.ok) return null;
                return res.json();
            })
            .then(function (json) {
                if (json === AUTH_LOST) return AUTH_LOST;
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
        if (Date.now() - state.lastActivityAt > resolveIdleStop()) return;

        // 失敗が続いている間は間隔を空ける（サーバーが苦しいときに追い打ちしない）
        if (state.skipTicks > 0) {
            state.skipTicks--;
            return;
        }

        state.inFlight = true;
        fetchVersion().then(function (version) {
            state.inFlight = false;

            if (version === AUTH_LOST) {
                handleAuthLost();
                return;
            }
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

            // 自分が保存した直後の版上がりを他の人の変更として通知してはいけない
            if (state.selfWritePending) return;

            try {
                state.options.onChange();
            } catch (e) {
                console.warn('ReservationLiveSync onChange error:', e);
            }
        });
    }

    /**
     * 保存直後など、こちらの操作で版数が進んだときに基準を取り直す。
     *
     * 取り直さないと、次の確認で自分の保存を他の人の変更と判定してしまい、
     * 画面の再読み込みや不要な「他の方の変更を反映しました」が起きる。
     *
     * 1回の操作で複数件を書き込む画面（食数を4つまとめて登録するなど）から
     * 連続で呼ばれるため、短い間はまとめて1回にする。
     */
    function resync() {
        // 抑止はこの瞬間から効かせる。基準の取り直しだけを debounce でまとめる。
        // 遅らせると、取り直しが終わる前のポーリングが自分の保存を他の人の変更と誤認する。
        state.selfWritePending = true;
        if (state.resyncTimerId) window.clearTimeout(state.resyncTimerId);
        state.resyncTimerId = window.setTimeout(function () {
            state.resyncTimerId = null;
            captureBaseline().then(function () {
                state.selfWritePending = false;
            });
        }, RESYNC_DEBOUNCE_MS);
    }

    /**
     * 現在の版数を基準として控える（待たずにすぐ取りに行く）。
     *
     * 開始直後はここを遅らせてはいけない。基準が決まる前に他の人が書き込むと、
     * その変更を基準に取り込んでしまい、以後ずっと気づけなくなる。
     */
    function captureBaseline() {
        return fetchVersion().then(function (version) {
            if (version === AUTH_LOST) {
                handleAuthLost();
                return null;
            }
            if (version !== null) state.knownVersion = version;
            return version;
        });
    }

    /**
     * ログインが切れたときの扱い。
     *
     * 待っても直らないので問い合わせを止め、利用者に伝える。
     * 黙って止めると「自動更新されているつもりで古い画面を見続ける」ことになり、
     * 食数を取り違えたまま発注まで進んでしまう。
     */
    function handleAuthLost() {
        if (state.authLost) return;
        state.authLost = true;
        stop();
        if (state.options && typeof state.options.onAuthLost === 'function') {
            try {
                state.options.onAuthLost();
                return;
            } catch (e) {
                console.warn('ReservationLiveSync onAuthLost error:', e);
            }
        }
        showReloadNotice(
            'ログインの有効期限が切れたため、自動更新を停止しました。再読み込みしてログインし直してください。',
            'warning'
        );
    }

    function resolveInterval() {
        var requested = (typeof window.__RESERVATION_SYNC_INTERVAL_MS === 'number'
            && window.__RESERVATION_SYNC_INTERVAL_MS > 0)
            ? window.__RESERVATION_SYNC_INTERVAL_MS
            : DEFAULT_INTERVAL_MS;

        if (window.__RESERVATION_SYNC_ALLOW_FAST === true) return requested;

        return Math.max(requested, MIN_INTERVAL_MS);
    }

    /**
     * 放置とみなすまでの時間を返す。
     *
     * 30分を待つ検証は現実的でないため、間隔と同じく上書きできるようにする。
     * 本番で誤って短くされないよう、__RESERVATION_SYNC_ALLOW_FAST を
     * 立てていない限り既定値を下回る指定は受け付けない。
     *
     * @returns {number} ミリ秒
     */
    function resolveIdleStop() {
        var requested = (typeof window.__RESERVATION_SYNC_IDLE_MS === 'number'
            && window.__RESERVATION_SYNC_IDLE_MS > 0)
            ? window.__RESERVATION_SYNC_IDLE_MS
            : IDLE_STOP_MS;

        if (window.__RESERVATION_SYNC_ALLOW_FAST === true) return requested;

        return Math.max(requested, IDLE_STOP_MS);
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
            // ログイン切れ時の扱い。未指定なら画面上部にお知らせを出す。
            onAuthLost: null,
        }, options || {});

        // 初回の基準はすぐ控える（遅らせると、その間の変更を取りこぼす）
        captureBaseline();

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
        if (state.resyncTimerId) {
            window.clearTimeout(state.resyncTimerId);
            state.resyncTimerId = null;
        }
        state.selfWritePending = false;
    }

    /**
     * 「他の人が更新しました」のお知らせを画面上部に出す。
     *
     * 入力途中の内容を勝手に消さないため、未保存の変更があるときは
     * 自動では更新せず、再読み込みするかどうかを利用者に委ねる。
     */
    function showReloadNotice(message, variant) {
        if (document.getElementById('reservation-live-notice')) return;

        var notice = document.createElement('div');
        notice.id = 'reservation-live-notice';
        notice.className = 'alert alert-' + (variant === 'warning' ? 'warning' : 'info')
            + ' d-flex align-items-center gap-2 mb-3';
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
        /**
         * 基準の版数を明示的に置き換える。
         *
         * データを取り直した画面が「その データが どの版数のものか」を
         * 知らせるために使う。resync() で取り直すと、データ取得後に
         * 入った変更まで取り込んでしまい、その分を見落とす。
         *
         * @param {number} version
         */
        setBaseline: function (version) {
            if (typeof version === 'number') state.knownVersion = version;
        },
        /** 基準の版数が決まったか（検証用。決まる前の変更は検知できない） */
        hasBaseline: function () { return state.knownVersion !== null; },
        /** 問い合わせが止まっているか（検証用） */
        isStopped: function () { return state.timerId === null; },
    };
})();
