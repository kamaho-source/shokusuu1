<?php

$this->assign('title', '食数予約');
$this->Html->script('reservation.js', ['block' => true]);
$this->Html->script('ce-change-edit.js', ['block' => true]);
$this->Html->script('add.js', ['block' => true]);
$user = $this->request->getAttribute('identity');
$isChild = ($user && (int)$user->get('i_user_level') === 1);
$isStaff = ($user && (int)$user->get('i_user_level') === 0);
$isAdmin = ($user && (int)$user->get('i_admin') === 1);
$today = date('Y-m-d');
$csrfToken = $this->request->getAttribute('csrfToken') ?? '';
$serverToday = $today;
$date = $this->request->getQuery('date', $today);

// ==== UIモード（kid/biz）トグル対応 ====
$uimodeQuery = strtolower((string)$this->request->getQuery('uimode', ''));
$forceKid = in_array($uimodeQuery, ['kid', 'child'], true);
$forceBiz = in_array($uimodeQuery, ['biz', 'adult'], true);

if ($isChild) {
    $useKidUI = true;
} elseif ($forceKid) {
    $useKidUI = true;
} elseif ($forceBiz) {
    $useKidUI = false;
} else {
    $useKidUI = $isChild;
}

// URL作成用
$here = $this->request->getPath();
$qs = $this->request->getQueryParams();
// CakePHPのベースパス（プロジェクト名）を常に先頭へ付与する
$basePath = $this->request->getAttribute('base') ?? $this->request->getAttribute('webroot') ?? '';
$mkUrl = function (array $merge) use ($here, $qs, $basePath) {
    $q = array_merge($qs, $merge);
    foreach ($q as $k => $v) if ($v === null) unset($q[$k]);
    return $basePath . $here . (empty($q) ? '' : ('?' . http_build_query($q)));
};

// --- 防御的初期化: 本番で未定義になっている可能性がある変数を必ず準備 ---
$userRoomIds = $userRoomIds ?? [];                             // 所属部屋の配列（空配列でフォールバック）
$userRoomId = $userRoomId ?? ($userRoomIds[0] ?? null);      // 所属部屋ID（未設定なら配列先頭 or null）

// GET_USERS_BY_ROOM 用テンプレート（JS側で "__RID__" を置換）
$getUsersByRoomTpl = $getUsersByRoomTpl ?? $this->Url->build(
        ['controller' => 'TReservationInfo', 'action' => 'getUsersByRoom', '__RID__'],
        ['fullBase' => false]
);

// 今日
$today = date('Y-m-d');
// 今日の予約情報（参考用）
$myReservationDates = $myReservationDates ?? [];
$myReservationDetails = $myReservationDetails ?? [];
$mealDataArray = $mealDataArray ?? [];

$todayReservation = $myReservationDetails[$today] ?? [];
$hasTodayReservation = !empty($todayReservation) && (
                ($todayReservation['breakfast'] ?? false) ||
                ($todayReservation['lunch'] ?? false) ||
                ($todayReservation['dinner'] ?? false) ||
                ($todayReservation['bento'] ?? false)
        );

// 予約コピーAPI（JSON）
$copyApi = $this->Url->build(['controller' => 'TReservationInfo', 'action' => 'copy', '_ext' => 'json'], ['fullBase' => false]);
$copyPreviewApi = $this->Url->build(['controller' => 'TReservationInfo', 'action' => 'copyPreview', '_ext' => 'json'], ['fullBase' => false]);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>食数予約</title>
    <meta name="csrfToken" content="<?= h($this->request->getAttribute('csrfToken')) ?>">
    <!-- サーバ側の値を JS に安全に出力（本番で未定義でも必ず存在するように） -->
    <script>
        window.__BASE_PATH = <?= json_encode($basePath, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.GET_USERS_BY_ROOM_TPL = <?= json_encode($getUsersByRoomTpl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.QUERY_DATE = <?= json_encode($date ?? $today, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__USER_INFO = {
            isStaff: <?= $isStaff ? 'true' : 'false' ?>,
            isChild: <?= $isChild ? 'true' : 'false' ?>,
            isAdmin: <?= $isAdmin ? 'true' : 'false' ?>,
            userLevel: <?= $user ? (int)$user->get('i_user_level') : 'null' ?>,
            roomId: <?= $userRoomId !== null ? (int)$userRoomId : 'null' ?>,
            roomIds: <?= json_encode(array_values($userRoomIds ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            roomCount: <?= count($userRoomIds ?? []) ?>
        };
    </script>

    <?php if (!$useKidUI): ?>
        <script>
            (function(){
                function pageToast(message, type = 'warning') {
                    try {
                        var wrap = document.getElementById('toastWrap');
                        if (!wrap) {
                            wrap = document.createElement('div');
                            wrap.id = 'toastWrap';
                            wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
                            document.body.appendChild(wrap);
                        }
                        var toastEl = document.createElement('div');
                        toastEl.className = 'toast align-items-center text-bg-' + (type === 'success' ? 'success' : (type === 'warning' ? 'warning' : 'danger')) + ' border-0';
                        toastEl.role = 'alert'; toastEl.ariaLive = 'assertive'; toastEl.ariaAtomic = 'true';
                        toastEl.innerHTML = '<div class="d-flex"><div class="toast-body">' + String(message) + '</div>' +
                            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
                            '</div>';
                        wrap.appendChild(toastEl);
                        var instance = window.bootstrap?.Toast.getOrCreateInstance(toastEl, { delay: 3500 });
                        instance?.show();
                        toastEl.addEventListener('hidden.bs.toast', function(){ toastEl.remove(); });
                    } catch (e) {
                        console.log('[pageToast]', message);
                    }
                }

                // 大人UIのみでネイティブ alert をトースト化
                window.alert = function(msg){
                    pageToast(msg, 'warning');
                };

                // 参照用に公開
                window.pageToast = pageToast;
            })();
        </script>
    <?php endif; ?>

    <script>
        (function(){
            if (typeof window.__BASE_PATH === 'undefined') {
                window.__BASE_PATH = <?= json_encode($basePath, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
            }
            window.GET_USERS_BY_ROOM_TPL = <?= json_encode($getUsersByRoomTpl, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
            window.__PRIMARY_ROOM_ID = <?= (int)$userRoomId ?>;
        })();
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <style>
        #calendar { max-width:130%; margin:0 auto; }
        @media (max-width: 768px){
            .fc-toolbar button{font-size:12px;}
            .fc-toolbar-title{font-size:14px;}
            #calendar{font-size:12px;}
        }
        @media (min-width:769px) and (max-width:1024px){
            .fc-toolbar button{font-size:14px;}
            .fc-toolbar-title{font-size:16px;}
            #calendar{font-size:14px;}
        }
        @media (min-width:1025px){
            #calendar{font-size:16px;}
        }

        /* 中学生向け（学習寄り＋落ち着いたトーン） */
        .kid-card .h5{font-size:1.05rem;}
        .kid-chip{font-size:.92rem;}
        .kid-head { background:#f5fbff; border:1px solid #e6f2ff; border-radius:.5rem; padding:.75rem 1rem;}
        .kid-help li{margin:.25rem 0;}
        .kid-badge-soft { font-weight:600; }

        /* ---- 4分割の小さなボタン（常に4列） ---- */
        .kid-meal-btn{
            padding:.5rem .25rem;
            display:flex;
            flex-direction:column;
            align-items:center;
            justify-content:center;
            gap:.25rem;
            min-height:64px;
            font-size:.9rem;
        }
        .kid-meal-btn .btn-emoji{ font-size:1.2rem; line-height:1; }
        .kid-meal-btn .btn-cap{ font-size:.75rem; line-height:1.1; white-space:nowrap; }

        /* 予約状態の強調表示 */
        .status-flag {
            display:inline-flex;
            align-items:center;
            gap:.4rem;
            font-weight:700;
            font-size:.9rem;
            padding:.3rem .6rem;
            border-radius:999px;
            border:1px solid transparent;
        }
        .status-flag.ok {
            color:#155724;
            background:#d4edda;
            border-color:#28a745;
        }
        .status-flag.none {
            color:#383d41;
            background:#e2e3e5;
            border-color:#6c757d;
        }

        /* 大人向け（業務システム調） */
        .biz-panel { background:#f8f9fa; border:1px solid #e9ecef; border-radius:.5rem; padding:1rem; }
        .legend-dot { display:inline-block; width:.8rem; height:.8rem; border-radius:50%; margin-right:.4rem; vertical-align:middle; }
        .legend-green { background:#28a745; }
        .legend-orange{ background:#fd7e14; }
        .legend-red   { background:#dc3545; }
        .legend-gray  { background:#6c757d; }
        .biz-note { color:#6c757d; font-size:.9rem; }

        /* 週まとめ予約の小さなリボン */
        .week-ribbon {
            font-size:.85rem;
            background:#eef6ff;
            border:1px solid #cfe5ff;
            color:#0b5ed7;
            padding:.25rem .5rem;
            border-radius:.375rem;
        }

        /* ======= 警告感のあるモーダル（共通） ======= */
        .modal-warning .modal-content {
            border:2px solid #dc3545;
            box-shadow: 0 0 0.5rem rgba(220,53,69,.5);
        }
        .modal-warning .modal-header {
            background:#dc3545;
            color:#fff;
        }
        .modal-warning .modal-title i { margin-right:.4rem; }
        .modal-warning .modal-body .alert { margin-bottom:0; }
        .modal-warning .btn-primary { background:#dc3545; border-color:#dc3545; }
        .modal-warning .btn-primary:disabled,
        .modal-warning .btn-primary.disabled { background:#dc3545; border-color:#dc3545; opacity:.65; }

        /* モード切替の見出し行（子画面） */
        .mode-bar {
            background:#fff;
            border:1px solid #e6f2ff;
            border-left:4px solid #0d6efd;
            border-radius:.5rem;
            padding:.5rem .75rem;
        }

        /* ================= 祝日＆土日強調（FullCalendar） ================= */
        .fc-daygrid-day.is-holiday {
            background: #fff0f0 !important;
            position: relative;
            border-left: 4px solid #dc3545 !important;
        }
        .fc-daygrid-day.is-holiday .fc-daygrid-day-number {
            color: #c1121f !important;
            font-weight: 700;
        }
        .fc-holiday-badge {
            position: absolute;
            top: 2px; left: 4px;
            z-index: 2;
            padding: 2px 6px;
            border-radius: 999px;
            background: #dc3545;
            color: #fff;
            font-size: 10px;
            line-height: 1;
            pointer-events: none;
            box-shadow: 0 0 0.25rem rgba(220,53,69,.35);
        }
        .fc-daygrid-day.fc-day-sun:not(.is-holiday) { background:#ffdada !important; }
        .fc-daygrid-day.fc-day-sat:not(.is-holiday) { background:#e3ecff !important; }

        /* 食数表示用スタイル */
        .fc-event.meal-count-event {
            font-size: 11px;
            border-radius: 3px;
            margin-bottom: 1px;
        }
        .fc-event.meal-count-event .fc-event-title {
            font-weight: 500;
        }


        /* ======== モーダル内スクロール等 ======== */
        #quickDayModal .modal-body {
            max-height: 70vh;
            overflow: auto;
            background:#f8f9fa;
        }

        /* モーダル内でのテーブルスクロール対応 */
        #qd-remote-wrap .table-responsive {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
        }

        #qd-remote-wrap #user-selection-table {
            min-height: 200px;
            max-height: 400px;
            overflow-y: auto;
        }

        #qd-remote-wrap table {
            margin-bottom: 0;
        }

        /* モーダル本体のスクロール調整 */
        #quickDayModal .modal-body {
            max-height: 80vh;
            overflow-y: auto;
        }

        /* 集団予約テーブルの見た目調整 */
        #qd-remote-wrap #user-checkboxes tr {
            border-bottom: 1px solid #dee2e6;
        }

        #qd-remote-wrap #user-checkboxes td {
            padding: 0.5rem;
            vertical-align: middle;
        }

        #qd-remote-wrap .container,
        #qd-remote-wrap .row { width: 100%; margin: 0; padding: 0; }
        #qd-remote-wrap .card:has(#change-edit-form),
        #qd-remote-wrap .card:has(#reservation-form) {
            margin-bottom: 0;
        }
        #qd-remote-loading { padding: 2rem; }
        /* 「Actions」サイドバーはモーダルでは非表示 */
        #qd-remote-wrap aside.col-md-3 { display:none !important; }
        /* 右側コンテンツをフル幅に（ベース） */
        #qd-remote-wrap .col-md-9 { width: 100%; flex: 0 0 100%; max-width: 100%; }

        /* ---- 右寄り対策：モーダル内の本体を中央寄せに整列 ---- */
        #qd-remote-wrap .row { justify-content: center; }
        #qd-remote-wrap .col-md-9,
        #qd-remote-wrap #ce-root,
        #qd-remote-wrap form#change-edit-form,
        #qd-remote-wrap form#reservation-form,
        #qd-remote-wrap .card:has(#change-edit-form),
        #qd-remote-wrap .card:has(#reservation-form) {
            margin-left: auto !important;
            margin-right: auto !important;
            float: none !important;
            max-width: 960px;
        }
        #qd-remote-wrap .container { padding-left: 0 !important; padding-right: 0 !important; }

        /* もしモーダルが狭い場合に拡張（XXL） */
        #quickDayModal .modal-dialog.modal-xxl { max-width: min(1280px, 95vw); }

        /* 直前期間での削除不可表示 */
        .deletion-blocked {
            color: #6c757d !important;
            font-style: italic;
        }

        .staff-last-minute-notice {
            border-left: 4px solid #0dcaf0;
        }

        .last-minute-warning {
            animation: fadeInOut 3s ease-in-out;
        }

        @keyframes fadeInOut {
            0% { opacity: 0; transform: translateY(-10px); }
            20% { opacity: 1; transform: translateY(0); }
            80% { opacity: 1; transform: translateY(0); }
            100% { opacity: 0; transform: translateY(-10px); }
        }
        
        /* 自動入力のアニメーション */
        .border-success {
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
        }

    </style>
</head>
<body>
<div class="container">
    <div class="d-flex align-items-center justify-content-between mt-2 mb-2">
        <h1 class="m-0"><?= $useKidUI ? '🍚 食数予約（中高生向け）' : '食数予約（業務）' ?></h1>
        <!-- ==== UIモード切替トグル ==== -->
        <?php if (!$useKidUI || ($useKidUI && $isStaff)): ?>
            <!-- ==== UIモード切替トグル（職員のみ子供UIでも表示） ==== -->
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted small d-none d-md-inline">表示モード:</span>
                <div class="btn-group" role="group" aria-label="UIモード切替">
                    <a class="btn btn-sm <?= $useKidUI ? 'btn-primary' : 'btn-outline-primary' ?>"
                       href="<?= h($mkUrl(['uimode'=>'kid'])) ?>">
                        子どもUI
                    </a>
                    <a class="btn btn-sm <?= !$useKidUI ? 'btn-primary' : 'btn-outline-primary' ?>"
                       href="<?= h($mkUrl(['uimode'=>'biz'])) ?>">
                        業務UI
                    </a>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
    $mealLabels = [1=>'朝食',2=>'昼食',3=>'夕食',4=>'弁当'];
    $mealKeys   = [1=>'breakfast',2=>'lunch',3=>'dinner',4=>'bento'];
    ?>

    <?php if ($useKidUI): ?>
        <?php
        // === 子供用UI: 部屋選択追加（この部屋IDで toggle を行う） ===
        $authorizedRooms = $rooms ?? [];
        $currentRoomId = $this->request->getQuery('room') ?: ($userRoomId ?? (array_key_first($authorizedRooms) ?: ''));
        ?>
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap align-items-center gap-2">
                <div class="fw-bold"><i class="bi bi-door-open"></i> 利用する部屋</div>
                <div class="ms-2">
                    <select id="kid-room-select" class="form-select form-select-sm" style="min-width: 220px;">
                        <option value="">部屋を選択してください</option>
                        <?php foreach (($authorizedRooms) as $rid => $rname): ?>
                            <option value="<?= h($rid) ?>" <?= (string)$currentRoomId === (string)$rid ? 'selected' : '' ?>>
                                <?= h($rname) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="text-muted small">
                    選んだ部屋で予約が登録されます（昼と弁当は同時予約不可）
                </div>
            </div>
        </div>
        <?php
        // 子供用: トグルURLテンプレート（__ROOM__ をJSで置換）
        $toggleBase = $this->Url->build(['controller'=>'TReservationInfo','action'=>'toggle','__ROOM__']);
        ?>

        <?php
        // 中学生向け UI 設定
        $todayDt    = new DateTimeImmutable('today');
        $day14Dt    = $todayDt->modify('+14 days'); // 当日〜14日先＝直前期間（発注済）
        $daysToShow = 31;                           // 4週間
        $todayKey   = $todayDt->format('Y-m-d');

        // （旧）固定トグルURLは使わない
        $urlHelper = $this->Url;

        $buildBulkUrl = function(string $mondayYmd) use ($urlHelper){
            return $urlHelper->build('/TReservationInfo/bulkAddForm') . '?date=' . rawurlencode($mondayYmd);
        };

        $kidMeals = [
                1 => ['text'=>'朝', 'class'=>'btn-success',           'emoji'=>'☀️'],
                2 => ['text'=>'昼', 'class'=>'btn-warning text-dark', 'emoji'=>'🌞'],
                3 => ['text'=>'夜', 'class'=>'btn-primary',           'emoji'=>'🌙'],
                4 => ['text'=>'弁', 'class'=>'btn-danger',            'emoji'=>'🍱'],
        ];
        ?>

        <!-- Flash Messages -->
        <?= $this->Flash->render() ?>

        <!-- ★ モード切替（自動 / 直前 / 通常） -->
        <div class="mode-bar d-flex align-items-center justify-content-between mb-3">
            <div class="small text-muted">
                <i class="bi bi-sliders"></i>
                モードを切り替えると、クリック時の挙動を切り替えられます（<u>画面表示のみ切替</u>）。
            </div>
            <div class="d-flex align-items-center gap-2">
                <span id="kidModeBadge" class="badge text-bg-light">モード：自動判定</span>
                <label for="kidModeSelect" class="form-label m-0 small fw-bold">モード</label>
                <select id="kidModeSelect" class="form-select form-select-sm" style="max-width: 220px;">
                    <option value="auto" selected>自動（日付に応じて判定）</option>
                    <option value="late">直前（常に同意モーダル）</option>
                    <option value="normal">通常（即時トグル）</option>
                </select>
            </div>
        </div>

        <!-- きょうの状況 -->
        <div class="reservation-status my-3 text-center">
            <?php if ($hasTodayReservation): ?>
                <div class="alert alert-success py-3">
                    <div class="fw-bold" style="font-size:1.05rem;">📆 きょう（<?= h($todayKey) ?>）：予約あり</div>
                    <div class="mt-2">
                        <span class="badge kid-chip bg-<?= ($todayReservation['breakfast']??false)?'success':'secondary' ?> mx-1">☀️ 朝：<?= ($todayReservation['breakfast']??false)?'○':'－' ?></span>
                        <span class="badge kid-chip bg-<?= ($todayReservation['lunch']??false)?'success':'secondary' ?> mx-1">🌞 昼：<?= ($todayReservation['lunch']??false)?'○':'－' ?></span>
                        <span class="badge kid-chip bg-<?= ($todayReservation['dinner']??false)?'success':'secondary' ?> mx-1">🌙 夜：<?= ($todayReservation['dinner']??false)?'○':'－' ?></span>
                        <span class="badge kid-chip bg-<?= ($todayReservation['bento']??false)?'success':'secondary' ?> mx-1">🍱 弁当：<?= ($todayReservation['bento']??false)?'○':'－' ?></span>
                    </div>
                    <div class="small mt-2 text-black">直前（きょう〜14日先）は<strong>発注済</strong>です。変更・追加の前に内容をよく確認してください。</div>
                </div>
            <?php else: ?>
                <div class="alert alert-warning py-3">
                    <div class="fw-bold" style="font-size:1.05rem;">📆 きょう（<?= h($todayKey) ?>）：予約なし</div>
                    <div class="mt-1 small">直前（きょう〜14日先）でも<strong>変更・追加OK</strong>ですが、<strong>発注済</strong>です。</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- 28日分のカード -->
        <?php
        for ($i=0; $i<$daysToShow; $i++):
            $d        = $todayDt->modify("+{$i} days");
            $dateKey  = $d->format('Y-m-d');
            $wIdx     = (int)$d->format('w');
            $w        = ['日','月','火','水','木','金','土'][$wIdx];
            $isMonday = ($wIdx === 1);
            $isLastMinute = ($d >= $todayDt && $d <= $day14Dt);
            $myDetail     = $myReservationDetails[$dateKey] ?? [];
            $hasLunchForDate = (bool)($myDetail['lunch'] ?? false);
            $hasBentoForDate = (bool)($myDetail['bento'] ?? false);

            if ($isMonday) {
                $weekStart = $d;
                $weekEnd   = $d->modify('+6 days');
                $weekLabel = $weekStart->format('n/j') . '〜' . $weekEnd->format('n/j');
                $bulkUrl   = $buildBulkUrl($dateKey);
            }
            ?>
            <div class="card mb-3 kid-card"
                 id="card-<?= h($dateKey) ?>"
                 data-date="<?= h($dateKey) ?>"
                 data-is-last-minute="<?= $isLastMinute ? '1' : '0' ?>">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div class="h5 m-0">
                            <?= h($dateKey) ?>（<?= $w ?>）
                            <?php if ($isLastMinute): ?>
                                <span class="badge bg-warning text-dark ms-2 kid-badge-soft">直前（発注済）</span>
                            <?php else: ?>
                                <span class="badge bg-success ms-2 kid-badge-soft">通常（即時トグル）</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 4分割の小ボタン -->
                    <div class="row g-2 mt-3">
                        <?php foreach ($kidMeals as $type => $info):
                            $mealKey = $mealKeys[$type];
                            $isMine  = (bool)($myDetail[$mealKey] ?? false);
                            $btnCap  = $isLastMinute ? ($isMine ? '変更(直前)' : '追加(直前)') : ($isMine ? '取消' : '追加');
                            ?>
                            <div class="col-3">
                                <a
                                        href="javascript:void(0)"
                                        class="btn kid-meal-btn w-100 <?= $isMine ? $info['class'] : 'btn-outline-secondary' ?>"
                                        data-date="<?= h($dateKey) ?>"
                                        data-meal="<?= (int)$type ?>"
                                        data-meal-key="<?= h($mealKey) ?>"
                                        data-has-lunch="<?= $hasLunchForDate ? '1' : '0' ?>"
                                        data-has-bento="<?= $hasBentoForDate ? '1' : '0' ?>"
                                        data-is-last-minute="<?= $isLastMinute ? '1' : '0' ?>"
                                        data-is-mine="<?= $isMine ? '1' : '0' ?>"
                                        data-meal-class="<?= h($info['class']) ?>"
                                        data-neutral-class="btn-outline-secondary"
                                        aria-label="<?= h($info['emoji'].' '.$info['text'].'：'.$btnCap) ?>"
                                >
                                    <span class="btn-emoji"><?= h($info['emoji']) ?></span>
                                    <span class="btn-cap"><?= h($info['text']) ?><small> <?= h($btnCap) ?></small></span>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-2">
                        <?php $selfAny = ($myDetail['breakfast']??false)||($myDetail['lunch']??false)||($myDetail['bento']??false)||($myDetail['dinner']??false); ?>
                        <span class="status-flag ok"  style="display:<?= $selfAny?'inline-flex':'none' ?>"><i class="bi bi-check-circle-fill"></i>現在：予約あり</span>
                        <span class="status-flag none" style="display:<?= $selfAny?'none':'inline-flex' ?>"><i class="bi bi-dash-circle"></i>現在：未予約</span>
                    </div>

                    <?php if ($isLastMinute): ?>
                        <div class="mt-2 small text-muted">※直前（発注済）です。変更・追加はできますが、内容をよく確認してください。</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endfor; ?>

        <!-- ルール説明モーダル -->
        <div class="modal fade" id="rule2wModal" tabindex="-1" aria-labelledby="rule2wTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="rule2wTitle">ルールの確認</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="とじる"></button>
                    </div>
                    <div class="modal-body">
                        <ul class="mb-0 ps-3">
                            <li>きょう〜14日先：<strong>発注済</strong>ですが <strong>変更・追加OK</strong>（注意モーダルが出ます）</li>
                            <li>15日目以降：<strong>クリックだけで予約↔取消</strong></li>
                            <li>昼と弁当は同時に予約しないように注意</li>
                            <li><strong>月曜日の「週まとめ予約」</strong>は15日目以降の週で利用できます</li>
                        </ul>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                    </div>
                </div></div>
        </div>

        <!-- 競合モーダル -->
        <div class="modal fade modal-warning" id="conflictModal" tabindex="-1" aria-labelledby="conflictTitle" aria-hidden="true" role="alertdialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="conflictTitle"><i class="bi bi-exclamation-octagon-fill"></i>警告：予約の競合</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="とじる"></button>
                    </div>
                    <div class="modal-body">
                        <div id="conflictBody" class="alert alert-danger mb-3"></div>
                        <div class="small text-muted">
                            下のボタンを押すと、<u>すでに登録されている予約を先に取り消し</u>、その後に<strong>目的の予約</strong>を登録します。
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a id="conflictAction" href="#" class="btn btn-primary">競合先を解除して続行</a>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">戻る</button>
                    </div>
                </div></div>
        </div>

    <?php else: ?>
        <!-- ================= 大人向け（業務システム調・エクスポートUI改善） ================= -->
        <?php if ($user && $user->get('i_admin') === 1): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <div class="me-auto">
                            <div class="fw-bold">エクスポート</div>
                            <div class="text-muted small">期間を選んで「予定表」または「実施表」を出力できます。</div>
                        </div>

                        <div class="btn-group" role="group" aria-label="期間プリセット">
                            <button class="btn btn-outline-secondary btn-sm" data-range-preset="this-month"><i class="bi bi-calendar2-week"></i> 今月</button>
                            <button class="btn btn-outline-secondary btn-sm" data-range-preset="next-month"><i class="bi bi-calendar2-plus"></i> 来月</button>
                            <button class="btn btn-outline-secondary btn-sm" data-range-preset="this-week"><i class="bi bi-calendar-week"></i> 今週</button>
                            <button class="btn btn-outline-secondary btn-sm" data-range-preset="last-month"><i class="bi bi-calendar2-minus"></i> 先月</button>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-md-3">
                            <label for="fromDate" class="form-label mb-1">期間開始日</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
                                <input type="date" id="fromDate" class="form-control" value="<?= date('Y-m-01') ?>">
                            </div>
                        </div>
                        <div class="col-12 col-md-3">
                            <label for="toDate" class="form-label mb-1">期間終了日</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
                                <input type="date" id="toDate" class="form-control" value="<?= date('Y-m-t') ?>">
                            </div>
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label mb-1">出力種別</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="exportType" id="typePlan" autocomplete="off" checked>
                                <label class="btn btn-outline-primary" for="typePlan"><i class="bi bi-file-earmark-excel"></i> 予定表</label>

                                <input type="radio" class="btn-check" name="exportType" id="typeActual" autocomplete="off">
                                <label class="btn btn-outline-primary" for="typeActual"><i class="bi bi-file-earmark-spreadsheet"></i> 実施表</label>
                            </div>
                            <div class="form-text">予定表＝食数予定表 / 実施表＝実施食数表</div>
                        </div>

                        <div class="col-12 col-md-3 d-grid">
                            <button class="btn btn-success" id="exportNow">
                                <span class="btn-label"><i class="bi bi-download"></i> エクスポート</span>
                                <span class="spinner-border spinner-border-sm ms-2 d-none" id="exportSpinner" role="status" aria-hidden="true"></span>
                            </button>
                            <div class="form-text text-muted mt-1">Excel（.xlsx）で保存されます。</div>
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <div class="小さな text-muted"><i class="bi bi-info-circle"></i> 選択中の期間：</div>
                        <span class="badge rounded-pill text-bg-light" id="rangeChip"><?= date('Y-m-01') ?> 〜 <?= date('Y-m-t') ?></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- === 予約コピー（週／月）: 大人向けのみ表示 === -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body d-flex flex-wrap align-items-center gap-2">
                <div class="me-auto">
                    <div class="fw-bold">予約コピー</div>
                    <div class="text-muted small">先週→指定週、または月単位で予約をコピーできます。</div>
                </div>
                <!--
                <button class="btn btn-outline-primary btn-sm" id="res-copy-btn-lastweek">先週の予約をこの週へコピー</button>
                -->
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#res-copy-modal">予約をコピー（週 / 月）</button>
            </div>
        </div>

        <!-- カレンダー -->
        <div id="calendar" aria-label="食数予約カレンダー（業務）"></div>

        <!-- 凡例 -->
        <div class="biz-note mt-3">
            <span class="me-3"><span class="legend-dot legend-green"></span>自分の予約あり</span>
            <span class="me-3"><span class="legend-dot legend-orange"></span>未予約（空）</span>
            <span class="me-3"><span class="legend-dot legend-red"></span>祝日</span>
            <span><span class="legend-dot legend-gray"></span>その他</span>
        </div>

        <!-- コピー用モーダル -->
        <div class="modal fade" id="res-copy-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-clipboard-check"></i> 予約をコピー</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="閉じる"></button>
                    </div>
                    <div class="modal-body">
                        <form id="res-copy-form">
                            <!-- ステップ1: コピー範囲 -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">
                                    <i class="bi bi-1-circle-fill text-primary"></i> コピー範囲を選択
                                </label>
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="mode" id="res-copy-mode-week" value="week" checked>
                                    <label class="btn btn-outline-primary" for="res-copy-mode-week">
                                        <i class="bi bi-calendar-week"></i> 週単位
                                    </label>
                                    <input type="radio" class="btn-check" name="mode" id="res-copy-mode-month" value="month">
                                    <label class="btn btn-outline-primary" for="res-copy-mode-month">
                                        <i class="bi bi-calendar-range"></i> 月単位
                                    </label>
                                </div>
                                <div class="form-text mt-2" id="mode-hint">
                                    週単位の場合は月曜日、月単位の場合は1日を開始日に指定してください
                                </div>
                            </div>

                            <!-- ステップ2: コピー元 -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">
                                    <i class="bi bi-2-circle-fill text-primary"></i> コピー元の開始日
                                    <small class="text-muted fw-normal">（自動入力）</small>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
                                    <input type="date" class="form-control" id="source_start" name="source_start" required>
                                    <button class="btn btn-outline-secondary" type="button" id="refresh-source" title="日付を再計算">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                </div>
                                <div class="form-text" id="source-validation"></div>
                            </div>

                            <!-- ステップ3: コピー先 -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">
                                    <i class="bi bi-3-circle-fill text-primary"></i> コピー先の開始日
                                    <small class="text-muted fw-normal">（複数選択可）</small>
                                </label>
                                <div class="mb-2">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-calendar-check"></i></span>
                                        <input type="date" class="form-control" id="target_start_input" placeholder="日付を選択">
                                        <button class="btn btn-outline-primary" type="button" id="add-target-btn">
                                            <i class="bi bi-plus-circle"></i> 追加
                                        </button>
                                    </div>
                                    <div class="form-text">日付を選択して「追加」ボタンをクリックしてください</div>
                                </div>
                                
                                <!-- 選択された日付のリスト -->
                                <div id="target-dates-list" class="border rounded p-2" style="min-height: 60px; max-height: 150px; overflow-y: auto; background-color: #f8f9fa;">
                                    <div class="text-muted text-center small py-2" id="target-dates-empty">
                                        <i class="bi bi-info-circle"></i> コピー先の日付が選択されていません
                                    </div>
                                </div>
                                
                                <!-- hidden inputs for form submission -->
                                <div id="target-dates-hidden"></div>
                            </div>

                            <!-- ステップ4: 部屋と対象 -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">
                                    <i class="bi bi-4-circle-fill text-primary"></i> 対象の部屋
                                </label>
                                <?= $this->Form->control('room_id', [
                                        'type'    => 'select',
                                        'label'   => false,
                                        'options' => $rooms ?? [],
                                        'empty'   => '所属全部屋',
                                        'class'   => 'form-select',
                                        'id'      => 'res-copy-room',
                                ]) ?>
                            </div>
                            
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="copy-only-children" name="only_children" value="1">
                                <label class="form-check-label" for="copy-only-children">
                                    <i class="bi bi-people"></i> 子供（利用者）のみコピー
                                </label>
                                <div class="form-text">職員の予約は除外されます</div>
                            </div>

                            <div class="alert alert-info small mb-0">
                                <i class="bi bi-info-circle"></i>
                                <strong>注意：</strong>既に予約がある日時はスキップされます（上書きされません）。未予約の箇所のみにコピーされます。
                            </div>
                            
                            <!-- プレビュー表示 -->
                            <div class="alert alert-light border mt-3" id="copy-preview" style="display: none;">
                                <div class="fw-bold mb-2"><i class="bi bi-eye"></i> コピー内容プレビュー</div>
                                <div id="preview-content" class="small"></div>
                            </div>

                            <input type="hidden" name="csrfToken" value="<?= h($this->request->getAttribute('csrfToken')) ?>">
                        </form>
                    </div>
                    <div class="modal-footer bg-light">
                        <button id="res-copy-submit" class="btn btn-primary" disabled>
                            <i class="bi bi-check-circle"></i> コピーを実行
                        </button>
                        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> キャンセル
                        </button>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<?php
$lunchReserved  = (bool)($todayReservation['lunch'] ?? false);
$lunchChangeUrl = $this->Url->build(['controller'=>'TReservationInfo','action'=>'edit',$userRoomId,$today,2]);
$bentoReserved  = (bool)($todayReservation['bento'] ?? false);
$bentoChangeUrl = $this->Url->build(['controller'=>'TReservationInfo','action'=>'edit',$userRoomId,$today,4]);

$js_reservedDates = array_values($myReservationDates);

$events = [];
$iconFn = function($v){ if ($v===null) return '×'; return $v ? '⚪︎' : '×'; };

foreach ($myReservationDates as $reservedDate) {
    $detail = $myReservationDetails[$reservedDate] ?? [];
    $title = sprintf(
            '朝:%s 昼:%s 夜:%s 弁:%s',
            $iconFn($detail['breakfast'] ?? null),
            $iconFn($detail['lunch']     ?? null),
            $iconFn($detail['dinner']    ?? null),
            $iconFn($detail['bento']     ?? null)
    );
    $events[] = [
            'title' => $title,
            'start' => $reservedDate,
            'allDay' => true,
            'backgroundColor' => '#28a745',
            'borderColor' => '#28a745',
            'textColor' => 'white',
            'extendedProps' => ['displayOrder' => -2],
    ];
}

// 朝昼夜弁当の食数表示（管理者：全部屋、管理者以外の職員：所属する全部屋の合計）
if (!$useKidUI && !empty($mealDataArray)) {
    $mealTypes = ['1'=>'朝','2'=>'昼','3'=>'夜','4'=>'弁'];
    foreach ($mealDataArray as $date => $meals) {
        foreach ($mealTypes as $type => $name) {
            if (isset($meals[$type]) && $meals[$type] > 0) {
                $events[] = [
                        'title' => "{$name}: {$meals[$type]}人",
                        'start' => $date,
                        'allDay' => true,
                        'extendedProps' => ['displayOrder' => (int)$type],
                ];
            }
        }
    }
}

$JS_MY_DETAILS       = json_encode($myReservationDetails, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$JS_RESERVED_DATES   = json_encode($js_reservedDates, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$JS_EXISTING_EVENTS  = json_encode($events, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$JS_TODAY            = json_encode($today, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

// 子供用: トグルURLテンプレートと初期room
$JS_TOGGLE_BASE      = json_encode($toggleBase ?? '', JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$JS_CURRENT_ROOM     = json_encode($currentRoomId ?? '', JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
?>

<div class="modal fade" id="bentoLunchWarnModal" tabindex="-1" aria-labelledby="bentoLunchWarnTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="bentoLunchWarnTitle">弁当の変更について</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button></div>
            <div class="modal-body">本日は<strong>昼食の予約が登録されています</strong>。<br>お弁当を変更する前に、<u>昼食の予約を無効（取り消し）</u>にしてください。</div>
            <div class="modal-footer">
                <a href="<?= h($lunchChangeUrl) ?>" class="btn btn-primary">昼食の予約を変更する</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
            </div>
        </div></div>
</div>

<div class="modal fade" id="lunchBentoWarnModal" tabindex="-1" aria-labelledby="lunchBentoWarnTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="lunchBentoWarnTitle">昼食の変更について</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button></div>
            <div class="modal-body">本日は<strong>弁当の予約が登録されています</strong>。<br>昼食を変更する前に、<u>弁当の予約を無効（取り消し）</u>にしてください。</div>
            <div class="modal-footer">
                <a href="<?= h($bentoChangeUrl) ?>" class="btn btn-primary">弁当の予約を変更する</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
            </div>
        </div></div>
</div>

<div class="modal fade modal-warning" id="lateNoticeModal" tabindex="-1" aria-labelledby="lateNoticeTitle" aria-hidden="true" role="alertdialog" aria-modal="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lateNoticeTitle"><i class="bi bi-exclamation-triangle-fill"></i>警告：直前の変更・追加</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="とじる"></button>
            </div>
            <div class="modal-body">
                <div id="lateNoticeBody" class="alert alert-danger mb-3"></div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="lateAgreeCheck" aria-describedby="lateAgreeHelp">
                    <label class="form-check-label" for="lateAgreeCheck">
                        <strong>発注済みであること</strong>を理解しました（内容をよく確認します）
                    </label>
                    <div id="lateAgreeHelp" class="form-text">チェックすると「同意して進む」ボタンが有効になります。</div>
                </div>
            </div>
            <div class="modal-footer">
                <a id="lateProceed" href="#" class="btn btn-primary disabled" aria-disabled="true" tabindex="-1" role="button">同意して進む</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">同意しない（戻る）</button>
            </div>
        </div></div>
</div>

<div class="modal fade" id="quickDayModal" tabindex="-1" aria-labelledby="quickDayModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xxl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="quickDayModalLabel">食数予約の追加 <small class="fw-normal">(対象日: <span id="qd-picked-date"></span>)</small></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                <div id="qd-remote-wrap" class="bg-white rounded border">
                    <div id="qd-remote-loading" class="text-center">
                        <div class="spinner-border" role="status" aria-hidden="true"></div>
                        <div class="mt-2">読み込み中...</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"
        integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0="
        crossorigin="anonymous"></script>

<?= $this->Html->script('index.global.min.js') ?>
<?= $this->Html->script('japanese-holidays.min.js') ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>
<script>
    // 予約チェックボックスのセレクタ
    const mealSelectors = [
        'input[type="checkbox"][name*="breakfast"]',
        'input[type="checkbox"][name*="lunch"]',
        'input[type="checkbox"][name*="dinner"]',
        'input[type="checkbox"][name*="bento"]'
    ];

    function enforceMealLimit(scope) {
        const root = scope || document;
        const cbs = mealSelectors.map(sel => Array.from(root.querySelectorAll(sel))).flat();
        const checked = cbs.filter(cb => cb.checked);

        // 3つ以上チェック済みなら、残りはdisabled
        if (checked.length >= 3) {
            cbs.forEach(cb => {
                if (!cb.checked) {
                    cb.disabled = true;
                    cb.title = '最大3つまで選択できます';
                }
            });
        } else {
            cbs.forEach(cb => {
                cb.disabled = false;
                cb.title = '';
            });
        }

        // 個人・集団予約の昼食と弁当排他制御
        const lunchCbs = Array.from(root.querySelectorAll('input[type="checkbox"][name*="lunch"],input[type="checkbox"][name$="[lunch]"]'));
        const bentoCbs = Array.from(root.querySelectorAll('input[type="checkbox"][name*="bento"],input[type="checkbox"][name$="[bento]"]'));

        lunchCbs.forEach((lunchCb, idx) => {
            // 対応するbentoCbを探す（同じ親要素内で）
            let bentoCb = null;
            // 個人予約
            if (lunchCb.name && lunchCb.name.includes('reservation')) {
                bentoCb = root.querySelector(`input[type="checkbox"][name="reservation[弁当]"]`);
            }
            // 集団予約
            else if (lunchCb.name && lunchCb.name.startsWith('users[')) {
                const userId = lunchCb.name.match(/^users\[(\d+)\]\[lunch\]$/);
                if (userId) {
                    bentoCb = root.querySelector(`input[type="checkbox"][name="users[${userId[1]}][bento]"]`);
                }
            }
            // Fallback: indexで対応
            if (!bentoCb && bentoCbs[idx]) bentoCb = bentoCbs[idx];

            if (lunchCb.checked) {
                if (bentoCb) {
                    bentoCb.disabled = true;
                    bentoCb.title = '昼食と弁当は同時に予約できません';
                }
            } else if (bentoCb && bentoCb.checked) {
                lunchCb.disabled = true;
                lunchCb.title = '昼食と弁当は同時に予約できません';
            } else {
                lunchCb.disabled = false;
                lunchCb.title = '';
                if (bentoCb) {
                    bentoCb.disabled = false;
                    bentoCb.title = '';
                }
            }
        });
    }

    // 変更時にバリデーション実行
    mealSelectors.forEach(sel => {
        document.querySelectorAll(sel).forEach(cb => {
            cb.addEventListener('change', () => enforceMealLimit(cb.closest('form')));
        });
    });

    // 初期表示時にも実行
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('form').forEach(f => enforceMealLimit(f));
    });

    const exportBtn = document.getElementById('exportNow');
    if (exportBtn) {
        function setExportLoading(loading) {
            const btn = document.getElementById('exportNow');
            const spn = document.getElementById('exportSpinner');
            if (!btn || !spn) return;
            btn.disabled = !!loading;
            spn.classList.toggle('d-none', !loading);
        }

        function showToast(message, type = 'success') {
            let wrap = document.getElementById('toastWrap');
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = 'toastWrap';
                wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
                document.body.appendChild(wrap);
            }
            const toastEl = document.createElement('div');
            toastEl.className = 'toast align-items-center text-bg-' + (type === 'success' ? 'success' : (type === 'warning' ? 'warning' : 'danger')) + ' border-0';
            toastEl.role = 'alert'; toastEl.ariaLive = 'assertive'; toastEl.ariaAtomic = 'true';
            toastEl.innerHTML = `
        <div class="d-flex">
          <div class="toast-body">${message}</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>`;
            wrap.appendChild(toastEl);
            const t = window.bootstrap?.Toast.getOrCreateInstance(toastEl, { delay: 3000 });
            t?.show();
            toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
        }

        function setRangePreset(preset){
            const from = document.getElementById('fromDate');
            const to   = document.getElementById('toDate');
            const chip = document.getElementById('rangeChip');
            if (!from || !to) return;

            const today = new Date(); today.setHours(0,0,0,0);
            const firstDay = (y,m)=> new Date(y, m, 1);
            const lastDay  = (y,m)=> new Date(y, m+1, 0);

            let s, e;
            switch (preset) {
                case 'this-week': {
                    const d = new Date(today);
                    const day = d.getDay();
                    const mon = new Date(d); mon.setDate(d.getDate() - ((day + 6) % 7));
                    const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
                    s = mon; e = sun; break;
                }
                case 'this-month': {
                    s = firstDay(today.getFullYear(), today.getMonth());
                    e = lastDay(today.getFullYear(), today.getMonth()); break;
                }
                case 'next-month': {
                    const y = today.getFullYear(), m = today.getMonth() + 1;
                    s = firstDay(y, m); e = lastDay(y, m); break;
                }
                case 'last-month': {
                    const y = today.getFullYear(), m = today.getMonth() - 1;
                    s = firstDay(y, m); e = lastDay(y, m); break;
                }
                default: return;
            }
            const fmt = d => d.toISOString().slice(0,10);
            from.value = fmt(s);
            to.value   = fmt(e);
            if (chip) chip.textContent = `${from.value} 〜 ${to.value}`;
        }

        document.querySelectorAll('[data-range-preset]').forEach(btn=>{
            btn.addEventListener('click', ()=> setRangePreset(btn.dataset.rangePreset));
        });

        ['fromDate','toDate'].forEach(id=>{
            document.getElementById(id)?.addEventListener('change', ()=>{
                const f = document.getElementById('fromDate')?.value;
                const t = document.getElementById('toDate')?.value;
                if (f && t) {
                    const chip = document.getElementById('rangeChip');
                    if (chip) chip.textContent = `${f} 〜 ${t}`;
                }
            });
        });

        async function downloadWorkbook(workbook, filename){
            workbook.worksheets.forEach(ws=>{
                ws.columns.forEach((col, idx)=>{
                    let maxLen=10;
                    ws.eachRow({includeEmpty:true}, row=>{
                        const v=row.getCell(idx+1).value;
                        if(v){
                            const text = typeof v==='object' ? String(v.text || (v.richText?v.richText.map(rt=>rt.text).join('') : '')) : String(v);
                            const len = Array.from(text).reduce((sum,ch)=> sum + (/[ -~]/.test(ch)?1:2), 0);
                            if(len>maxLen) maxLen=len;
                        }
                    });
                    col.width=maxLen+2;
                });
            });
            const buffer = await workbook.xlsx.writeBuffer();
            const blob = new Blob([buffer], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
            const a=document.createElement('a');
            a.href=URL.createObjectURL(blob); a.download=filename;
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
            URL.revokeObjectURL(a.href);
        }

        document.getElementById('exportNow')?.addEventListener('click', async ()=>{
            try {
                const csrfToken = document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') ?? '';
                const from = document.getElementById('fromDate')?.value;
                const to   = document.getElementById('toDate')?.value;
                if(!from || !to){ showToast('開始日・終了日を入力してください。', 'warning'); return; }
                if(from > to){ showToast('開始日は終了日以前の日付を指定してください。', 'warning'); return; }

                const isPlan = document.getElementById('typePlan')?.checked;
                const endpoint = isPlan ? '<?= $this->Url->build('/TReservationInfo/exportJson') ?>'
                    : '<?= $this->Url->build('/TReservationInfo/exportJsonrank') ?>';

                setExportLoading(true);

                const res = await fetch(`${endpoint}?from=${from}&to=${to}`, { headers:{'X-CSRF-Token': csrfToken} });
                if (!res.ok) throw new Error(`APIエラー: ${res.status}`);
                const json = await res.json();

                const isEmpty = (() => {
                    if (isPlan) {
                        const hasRooms   = json.rooms && Object.keys(json.rooms).length>0;
                        const hasOverall = json.overall && Object.keys(json.overall).length>0;
                        return !hasRooms && !hasOverall;
                    } else {
                        const rows = Array.isArray(json) ? json : Object.values(json);
                        return rows.length === 0;
                    }
                })();
                if (isEmpty) { showToast('出力対象データがありません。', 'warning'); return; }

                if (isPlan) {
                    const wb = new ExcelJS.Workbook();
                    wb.creator='食数予約システム'; wb.created=new Date(); wb.modified=new Date();

                    const addHeader = (sheet, withRoom=false)=>{
                        const header = withRoom ? ['日付','部屋名','朝食','昼食','夕食','弁当','合計'] : ['日付','朝食','昼食','夕食','弁当','合計'];
                        const row = sheet.addRow(header); row.font={bold:true}; sheet.views=[{state:'frozen',ySplit:1}];
                    };
                    const addTotalRow = (sheet, withRoom=false)=>{
                        const totals=[0,0,0,0];
                        sheet.eachRow((row,i)=>{
                            if(i===1) return;
                            const off = withRoom?2:1;
                            for(let k=0;k<totals.length;k++){ totals[k] += Number(row.getCell(off+k+1).value ?? 0); }
                        });
                        const grand = totals.reduce((a,b)=>a+b,0);
                        const vals = withRoom ? ['合計','',...totals,grand] : ['合計',...totals,grand];
                        const trow = sheet.addRow(vals); trow.font={bold:true};
                        trow.eachCell(c=>{ c.border={top:{style:'thin'}, bottom:{style:'double'}}; });
                    };

                    const hasRooms   = json.rooms && Object.keys(json.rooms).length>0;
                    const hasOverall = json.overall && Object.keys(json.overall).length>0;

                    const sh = wb.addWorksheet('全体'); addHeader(sh, true);
                    if (hasRooms){
                        const allDates=new Set(); const rooms=Object.keys(json.rooms).sort();
                        rooms.forEach(r=>{ Object.keys(json.rooms[r]??{}).forEach(d=>allDates.add(d)); });
                        [...allDates].sort().forEach(date=>{
                            rooms.forEach(r=>{
                                const c=(json.rooms[r]??{})[date]??{};
                                const total=(c['朝']??0)+(c['昼']??0)+(c['夜']??0)+(c['弁当']??0);
                                sh.addRow([date, r, c['朝']??0, c['昼']??0, c['夜']??0, c['弁当']??0, total]);
                            });
                        });
                    } else if (hasOverall){
                        Object.keys(json.overall).sort().forEach(date=>{
                            const c=json.overall[date]??{};
                            const total=(c['朝']??0)+(c['昼']??0)+(c['夜']??0)+(c['弁当']??0);
                            sh.addRow([date,'全体',c['朝']??0,c['昼']??0,c['夜']??0,c['弁当']??0,total]);
                        });
                    }
                    addTotalRow(sh, true);

                    if (hasRooms){
                        Object.keys(json.rooms).forEach(room=>{
                            const name = room.replace(/[:\\/?*\[\]]/g,'').substring(0,31) || '部屋';
                            const ws = wb.addWorksheet(name); addHeader(ws);
                            const rdata = json.rooms[room];
                            Object.keys(rdata).sort().forEach(date=>{
                                const m=rdata[date];
                                const total=(m['朝']??0)+(m['昼']??0)+(m['夜']??0)+(m['弁当']??0);
                                ws.addRow([date, m['朝']??0, m['昼']??0, m['夜']??0, m['弁当']??0, total]);
                            });
                            addTotalRow(ws);
                        });
                    }

                    await downloadWorkbook(wb, `食数予定表_${from}〜${to}.xlsx`);
                } else {
                    const rows = Array.isArray(json) ? json : Object.values(json);
                    const wb=new ExcelJS.Workbook();
                    const ws=wb.addWorksheet('実施食数表');
                    const cols=[
                        {key:'reservation_date', header:'日付'},
                        {key:'rank_name',        header:'ランク'},
                        {key:'gender',           header:'性別'},
                        {key:'breakfast',        header:'朝食'},
                        {key:'lunch',            header:'昼食'},
                        {key:'dinner',           header:'夕食'},
                        {key:'bento',            header:'弁当'},
                        {key:'total_eaters',     header:'合計'}
                    ];
                    ws.addRow(cols.map(c=>c.header)).font={bold:true};
                    rows.forEach(r => ws.addRow(cols.map(c => r[c.key] ?? '')));

                    ws.columns.forEach((col, idx)=>{
                        let maxLen=10;
                        ws.eachRow({includeEmpty:true}, row=>{
                            const v=row.getCell(idx+1).value;
                            if(v){
                                const text = typeof v==='object' ? String(v.text || (v.richText?v.richText.map(rt=>rt.text).join('') : '')) : String(v);
                                const len = Array.from(text).reduce((sum,ch)=> sum + (/[ -~]/.test(ch)?1:2), 0);
                                if(len>maxLen) maxLen=len;
                            }
                        });
                        col.width=maxLen+2;
                    });

                    const buffer = await wb.xlsx.writeBuffer();
                    const blob = new Blob([buffer], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
                    const a=document.createElement('a');
                    a.href=URL.createObjectURL(blob); a.download=`実施食数表_${from}〜${to}.xlsx`;
                    document.body.appendChild(a); a.click(); document.body.removeChild(a);
                    URL.revokeObjectURL(a.href);
                }

                showToast('エクスポートが完了しました。', 'success');
            } catch (err) {
                console.error(err);
                let msg = 'エクスポートに失敗しました。';
                if (err && err.message) msg += '\n' + err.message;
                showToast(msg, 'danger');
            } finally {
                setExportLoading(false);
            }
        });
    }
</script>
<script>
    function openModalById(id){
        var el = document.getElementById(id);
        if (!el) return;
        try {
            if (window.bootstrap && window.bootstrap.Modal) {
                var m = window.bootstrap.Modal.getOrCreateInstance(el);
                m.show();
                return;
            }
        } catch(e){}
        el.classList.add('show');
        el.style.display = 'block';
        el.removeAttribute('aria-hidden');
        el.setAttribute('aria-modal','true');
        el.scrollTop = 0;
        document.body.classList.add('modal-open');
        if (!document.getElementById('___modal-backdrop')) {
            var bd = document.createElement('div');
            bd.id='___modal-backdrop';
            bd.className='modal-backdrop fade show';
            document.body.appendChild(bd);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        var metaEl = document.querySelector('meta[name="csrfToken"]');
        var csrfToken = metaEl ? metaEl.getAttribute('content') : '';
        window.__csrfToken = csrfToken;

        if (window.jQuery && typeof jQuery.ajaxSetup === 'function') {
            jQuery.ajaxSetup({
                headers: { 'X-CSRF-Token': csrfToken },
                cache: false
            });
        }

        var IS_CHILD = <?= $isChild ? 'true' : 'false' ?>;
        var USE_KID_UI = <?= $useKidUI ? 'true' : 'false' ?>;

        var TODAY  = <?= $JS_TODAY ?>;
        var TODAY_STATE = {
            lunch: <?= $lunchReserved ? 'true' : 'false' ?>,
            bento: <?= $bentoReserved ? 'true' : 'false' ?>
        };

        var MY_DETAILS = <?= $JS_MY_DETAILS ?>;

        if (USE_KID_UI) {
            // 子供用: 部屋必須 + トグルURL生成
            const roomSelect = document.getElementById('kid-room-select');
            let currentRoomId = <?= $JS_CURRENT_ROOM ?> || '';
            const toggleBase = <?= $JS_TOGGLE_BASE ?> || '';
            function getToggleUrl(){
                if (!currentRoomId) return '';
                return toggleBase.replace('__ROOM__', encodeURIComponent(String(currentRoomId)));
            }
            if (roomSelect) {
                roomSelect.addEventListener('change', () => { currentRoomId = roomSelect.value || ''; });
            }

            var modeSelectEl = document.getElementById('kidModeSelect');
            var kidMode = modeSelectEl ? modeSelectEl.value : 'auto';

            var mealNamesShort = {1:'朝', 2:'昼', 3:'夜', 4:'弁'};
            var mealJaFull     = {1:'朝食', 2:'昼食', 3:'夕食', 4:'弁当'};

            function updateModeBadge(){
                var badge = document.getElementById('kidModeBadge');
                if (!badge) return;
                var label = kidMode === 'auto' ? '自動判定' : (kidMode === 'late' ? '直前' : '通常');
                badge.textContent = 'モード：' + label;
            }

            function applyKidModeUI(){
                var btns = document.querySelectorAll('.kid-meal-btn');
                for (var i=0; i<btns.length; i++){
                    var btn = btns[i];
                    var isMine = btn.getAttribute('data-is-mine') === '1';
                    var originalIsLast = btn.getAttribute('data-is-last-minute') === '1';
                    var targetIsLast = (kidMode === 'auto') ? originalIsLast : (kidMode === 'late');

                    var meal  = Number(btn.getAttribute('data-meal') || 0);
                    var name  = mealNamesShort[meal] || '';

                    var cap = targetIsLast ? (isMine ? '変更(直前)' : '追加(直前)') : (isMine ? '取消' : '追加');
                    btn.setAttribute('data-target-is-last', targetIsLast ? '1' : '0');

                    var capEl = btn.querySelector('.btn-cap');
                    if (capEl) {
                        capEl.innerHTML = name + '<small> ' + cap + '</small>';
                    }
                    btn.setAttribute('aria-label', name + '：' + cap);
                }
                updateModeBadge();
            }

            function filterCardsByMode(){
                var cards = document.querySelectorAll('.kid-card');
                var firstVisible = null;
                for (var i=0; i<cards.length; i++){
                    var card = cards[i];
                    var isLast = card.getAttribute('data-is-last-minute') === '1';
                    var show = true;
                    if (kidMode === 'late')   show =  isLast;
                    if (kidMode === 'normal') show = !isLast;
                    card.style.display = show ? '' : 'none';
                    if (show && !firstVisible) firstVisible = card;
                }
                if (firstVisible && firstVisible.scrollIntoView) {
                    firstVisible.scrollIntoView({ behavior:'smooth', block:'start' });
                }
            }

            applyKidModeUI();
            filterCardsByMode();
            if (modeSelectEl) {
                modeSelectEl.addEventListener('change', function(e){
                    kidMode = e.target.value || 'auto';
                    applyKidModeUI();
                    filterCardsByMode();
                });
            }

            if (!IS_CHILD) {
                kidMode = 'normal';
                if (modeSelectEl) {
                    modeSelectEl.value = 'normal';
                    modeSelectEl.disabled = true;
                }
                updateModeBadge();
                filterCardsByMode();
            }

            function setBtnReserved(btn, reserved){
                var cls = btn.classList;
                var colorTokens   = (btn.getAttribute('data-meal-class')    || 'btn-primary').split(/\s+/).filter(Boolean);
                var neutralTokens = (btn.getAttribute('data-neutral-class') || 'btn-outline-secondary').split(/\s+/).filter(Boolean);
                var legacyTokens = ['btn-outline-light', 'border'];
                for (var i=0; i<colorTokens.length; i++)   { cls.remove(colorTokens[i]); }
                for (var j=0; j<neutralTokens.length; j++) { cls.remove(neutralTokens[j]); }
                for (var k=0; k<legacyTokens.length; k++)  { cls.remove(legacyTokens[k]); }

                if (reserved){
                    for (var a=0; a<colorTokens.length; a++) { cls.add(colorTokens[a]); }
                    btn.setAttribute('data-is-mine', '1');
                } else {
                    for (var b=0; b<neutralTokens.length; b++) { cls.add(neutralTokens[b]); }
                    btn.setAttribute('data-is-mine', '0');
                }

                var meal = Number(btn.getAttribute('data-meal')||0);
                var name = mealNamesShort[meal] || '';
                var targetIsLast = btn.getAttribute('data-target-is-last') === '1';
                var capEl = btn.querySelector('.btn-cap');
                if (capEl){
                    var cap = targetIsLast ? (reserved ? '変更(直前)' : '追加(直前)') : (reserved ? '取消' : '追加');
                    capEl.innerHTML = name + '<small> ' + cap + '</small>';
                }
                btn.setAttribute('aria-label', name + '：' + (reserved ? (targetIsLast?'変更(直前)':'取消') : (targetIsLast?'追加(直前)':'追加')));
            }

            function updateDayStatus(dateStr){
                var card = document.getElementById('card-' + dateStr);
                if (!card) return;
                var detail = MY_DETAILS[dateStr] || {};
                var any = !!(detail.breakfast || detail.lunch || detail.bento || detail.dinner);
                var ok = card.querySelector('.status-flag.ok');
                var none = card.querySelector('.status-flag.none');
                if (ok && none){
                    ok.style.display = any ? 'inline-flex' : 'none';
                    none.style.display = any ? 'none' : 'inline-flex';
                }
            }

            function refreshDayUI(dateStr){
                var esc = function(s){ return (window.CSS && CSS.escape) ? CSS.escape(s) : s; };
                var detail = MY_DETAILS[dateStr] || { breakfast:false, lunch:false, dinner:false, bento:false };
                var list = document.querySelectorAll('.kid-meal-btn[data-date="' + esc(dateStr) + '"]');
                for (var i=0; i<list.length; i++){
                    var btn = list[i];
                    var key = btn.getAttribute('data-meal-key');
                    if (!key) continue;
                    setBtnReserved(btn, !!detail[key]);
                }
                updateDayStatus(dateStr);
                if (dateStr === TODAY) {
                    TODAY_STATE.lunch = !!detail.lunch;
                    TODAY_STATE.bento = !!detail.bento;
                }
            }

            function showConflict(html, onResolve, actionLabel){
                var body = document.getElementById('conflictBody');
                var act  = document.getElementById('conflictAction');
                var el   = document.getElementById('conflictModal');
                if (body) body.innerHTML = html || 'この操作は競合しています。';
                if (act) {
                    act.classList.remove('disabled');
                    act.setAttribute('aria-disabled','false');
                    act.textContent = actionLabel || '競合先を解除して続行';
                    act.onclick = function(e){
                        e.preventDefault();
                        if (typeof onResolve === 'function') onResolve();
                        if (window.bootstrap && window.bootstrap.Modal) {
                            var m = window.bootstrap.Modal.getOrCreateInstance(el);
                            if (m) m.hide();
                        } else {
                            el.classList.remove('show'); el.style.display='none';
                            var bd=document.getElementById('___modal-backdrop'); if (bd) bd.remove();
                        }
                        return false;
                    };
                }
                openModalById('conflictModal');
            }

            function showLateNotice(html, onAgree){
                var body = document.getElementById('lateNoticeBody');
                var agree = document.getElementById('lateAgreeCheck');
                var proceed = document.getElementById('lateProceed');
                var modalEl = document.getElementById('lateNoticeModal');
                if (body) body.innerHTML = html;
                if (agree){
                    agree.checked = false;
                    agree.onchange = function(){
                        if (agree.checked) {
                            if (proceed){
                                proceed.classList.remove('disabled');
                                proceed.setAttribute('aria-disabled','false');
                                proceed.setAttribute('tabindex','0');
                            }
                        } else {
                            if (proceed){
                                proceed.classList.add('disabled');
                                proceed.setAttribute('aria-disabled','true');
                                proceed.setAttribute('tabindex','-1');
                            }
                        }
                    };
                }
                if (proceed){
                    proceed.onclick = function(e){
                        if (proceed.classList.contains('disabled')) { e.preventDefault(); return false; }
                        if (window.bootstrap && window.bootstrap.Modal) {
                            var m = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                            if (m) m.hide();
                        } else {
                            modalEl.classList.remove('show'); modalEl.classList.remove('d-block'); modalEl.style.display='none';
                            var bd=document.getElementById('___modal-backdrop'); if (bd) bd.remove();
                        }
                        if (typeof onAgree === 'function') onAgree();
                        e.preventDefault();
                        return false;
                    };
                }
                openModalById('lateNoticeModal');
            }

            async function callToggle(dateStr, mealNumber, wantValue, override){
                const url = getToggleUrl();
                if (!url) {
                    const msg = '先に「利用する部屋」を選択してください。';
                    if (window.pageToast) window.pageToast(msg, 'warning'); else alert(msg);
                    throw new Error('Room not selected');
                }
                if (!csrfToken)  throw new Error('CSRFトークンが取得できていません。再読み込みしてください。');

                const res = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json; charset=utf-8',
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        date: String(dateStr),
                        meal: Number(mealNumber),
                        value: wantValue ? 1 : 0,
                        override: override ? 1 : 0
                    })
                });

                const ct = res.headers.get('content-type') || '';
                const isJson = ct.indexOf('application/json') !== -1;
                const payload = isJson ? await res.json() : { message: await res.text() };

                if (res.status === 409) {
                    const err = new Error(payload?.message || '昼食と弁当は同時に予約できません。');
                    err.name = 'Conflict';
                    err.details = payload;
                    throw err;
                }
                if (res.status === 422) {
                    const err = new Error(payload?.message || '入力が不正です。');
                    err.name = 'Unprocessable';
                    throw err;
                }
                if (res.status === 400) {
                    const err = new Error(payload?.message || '不正なリクエストです。');
                    err.name = 'BadRequest';
                    throw err;
                }

                if (payload && payload.ok === true) return payload;
                if (payload && typeof payload.status === 'string') {
                    const st = payload.status.toLowerCase();
                    if (st === 'success') return payload;
                    if (st === 'error') {
                        const msg = payload.message || '更新に失敗しました。';
                        const err = new Error(msg);
                        err.name = /2週間|１４日|14日|two/i.test(msg) ? 'RuleError' : 'ServerError';
                        throw err;
                    }
                }

                if (!res.ok) {
                    throw new Error(payload?.message || ('更新に失敗しました（' + res.status + '）'));
                }
                return payload;
            }

            function conflictPair(mealIdx){ if (mealIdx === 2) return 4; if (mealIdx === 4) return 2; return null; }

            function applyDetailsAndRefresh(date, payload, btn, mealKey){
                if (payload && typeof payload.details === 'object') {
                    var prev = MY_DETAILS[date] || { breakfast:false, lunch:false, dinner:false, bento:false };
                    MY_DETAILS[date] = {
                        breakfast: Object.prototype.hasOwnProperty.call(payload.details,'breakfast') ? payload.details.breakfast : prev.breakfast,
                        lunch:     Object.prototype.hasOwnProperty.call(payload.details,'lunch')     ? payload.details.lunch     : prev.lunch,
                        dinner:    Object.prototype.hasOwnProperty.call(payload.details,'dinner')    ? payload.details.dinner    : prev.dinner,
                        bento:     Object.prototype.hasOwnProperty.call(payload.details,'bento')     ? payload.details.bento     : prev.bento
                    };
                } else {
                    var d = MY_DETAILS[date] || { breakfast:false, lunch:false, dinner:false, bento:false };
                    if (mealKey) d[mealKey] = !!(payload && payload.value);
                    MY_DETAILS[date] = d;
                }
                refreshDayUI(date);
            }

            async function resolveConflictSequence(date, targetIdx, targetOn, btn, mealKey){
                var opponentIdx = conflictPair(targetIdx);
                if (!opponentIdx) throw new Error('競合先が特定できませんでした。');
                await callToggle(date, opponentIdx, false, false);
                var result = await callToggle(date, targetIdx, targetOn, false);
                applyDetailsAndRefresh(date, result, btn, mealKey);
            }

            var kidBtns = document.querySelectorAll('.kid-meal-btn');
            Array.prototype.forEach.call(kidBtns, function(btn){
                btn.addEventListener('click', async function(ev){
                    ev.preventDefault();
                    var date  = btn.getAttribute('data-date');
                    var mealIdx = Number(btn.getAttribute('data-meal') || 0);
                    var mealKey = btn.getAttribute('data-meal-key');
                    if (!date || !mealIdx || !mealKey) return;

                    var agreedOnce = false;
                    var detail = MY_DETAILS[date] || { breakfast:false, lunch:false, dinner:false, bento:false };
                    var current = !!detail[mealKey];
                    var nextVal = !current;

                    var localConflict =
                        nextVal &&
                        ((mealKey === 'lunch'  && (detail.bento || (date === TODAY && TODAY_STATE.bento))) ||
                            (mealKey === 'bento'  && (detail.lunch || (date === TODAY && TODAY_STATE.lunch))));

                    var isLast = (btn.getAttribute('data-target-is-last') || btn.getAttribute('data-is-last-minute')) === '1';

                    function withLateAgreement(html, action){
                        if (isLast && !agreedOnce) {
                            showLateNotice(html, function(){ agreedOnce = true; if (typeof action === 'function') action(); });
                        } else {
                            if (typeof action === 'function') action();
                        }
                    }

                    var conflictActionLabel =
                        mealIdx === 2 ? 'お弁当からお昼に登録を変更する'
                            : mealIdx === 4 ? 'お昼からお弁当に登録を変更する'
                                : '競合先を解除して続行';

                    async function doToggle(){
                        try {
                            btn.disabled = true; btn.style.opacity = .65;

                            if (localConflict) {
                                var labelFrom = mealIdx === 2 ? 'お弁当' : '昼ごはん';
                                var labelTo   = mealIdx === 2 ? '昼ごはん' : 'お弁当';

                                showConflict(
                                    'この日（' + date + '）は<strong>' + labelFrom + '</strong>の予約があります。<br><strong>' + labelFrom + '</strong>を先に<strong>取り消し</strong>てから、<strong>' + labelTo + '</strong>を登録してもよろしいですか？',
                                    async function(){
                                        var html = '日付：<strong>' + date + '</strong><br>対象：<strong>' + mealJaFull[mealIdx] + '</strong><br><br>この期間はすでに<strong>発注済</strong>です。登録内容をよく確認してください。';
                                        withLateAgreement(html, async function(){
                                            try { await resolveConflictSequence(date, mealIdx, true, btn, mealKey); }
                                            catch (ee) { alert((ee && ee.message) || '競合解消に失敗しました。'); }
                                            finally { btn.disabled = false; btn.style.opacity = 1; }
                                        });
                                    },
                                    conflictActionLabel
                                );
                                return;
                            }

                            var json = await callToggle(date, mealIdx, nextVal, false);
                            applyDetailsAndRefresh(date, json, btn, mealKey);

                        } catch (e) {
                            if (e && e.name === 'RuleError') {
                                alert(e.message || '当日から2週間後までは予約の登録ができません。');
                            } else if (e && e.name === 'Conflict') {
                                showConflict(
                                    ((e && e.message) || '昼食と弁当は同時に予約できません。') + '<br><small class="text-muted">（競合先の予約を先にOFFしてから目的の予約をONにします）</small>',
                                    async function(){
                                        var html = '日付：<strong>' + date + '</strong><br>対象：<strong>' + mealJaFull[mealIdx] + '</strong><br><br>この期間はすでに<strong>発注済</strong>です。登録内容をよく確認してください。';
                                        withLateAgreement(html, async function(){
                                            try {
                                                btn.disabled = true; btn.style.opacity = .65;
                                                try {
                                                    var over = await callToggle(date, mealIdx, nextVal, true);
                                                    applyDetailsAndRefresh(date, over, btn, mealKey);
                                                } catch (ovErr) {
                                                    await resolveConflictSequence(date, mealIdx, nextVal, btn, mealKey);
                                                }
                                            } catch (ee) {
                                                alert((ee && ee.message) || '競合解消に失敗しました。');
                                            } finally {
                                                btn.disabled = false; btn.style.opacity = 1;
                                            }
                                        });
                                    },
                                    conflictActionLabel
                                );
                            } else {
                                alert((e && e.message) || '予約の更新に失敗しました');
                            }
                        } finally {
                            if (!localConflict) { btn.disabled = false; btn.style.opacity = 1; }
                        }
                    }

                    var bodyHtml = '日付：<strong>' + date + '</strong><br>対象：<strong>' + mealJaFull[mealIdx] + '</strong><br><br>この期間はすでに<strong>発注済</strong>です。' + (nextVal ? '追加' : 'キャンセル') + 'してよいか、内容をよく確認してください。';
                    withLateAgreement(bodyHtml, doToggle);
                }, false);
            });

        } else {
            var reservedDates  = <?= $JS_RESERVED_DATES ?>;
            var existingEvents = <?= $JS_EXISTING_EVENTS ?>;

            var calendarEl    = document.getElementById('calendar');
            var fromDateInput = document.getElementById('fromDate');
            var toDateInput   = document.getElementById('toDate');

            function formatYmd(d){
                var y=d.getFullYear(), m=('0'+(d.getMonth()+1)).slice(-2), dd=('0'+d.getDate()).slice(-2);
                return y+'-'+m+'-'+dd;
            }
            function updateInputsByCalendar(view){
                if(!fromDateInput || !toDateInput) return;
                var start=view.currentStart;
                var end=new Date(view.currentEnd); end.setDate(end.getDate()-1);
                fromDateInput.value = formatYmd(start);
                toDateInput.value   = formatYmd(end);
                var chip = document.getElementById('rangeChip');
                if (chip) { chip.textContent = fromDateInput.value + ' 〜 ' + toDateInput.value; }
            }
            var defaultDate = (function(){ var d=new Date(); d.setDate(d.getDate()+14); return d; })();

            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialDate: defaultDate,
                initialView: 'dayGridMonth',
                locale: 'ja',
                firstDay: 1,
                height: 'auto',
                contentHeight: 'auto',
                expandRows: true,
                aspectRatio: 1.35,
                customButtons: { nextMonth:{ text:'次月', click:function(){ calendar.next(); } } },
                headerToolbar: { right:'prev,today,nextMonth,next', center:'' },
                buttonText: { today:'今日' },

                dayCellDidMount: function(info) {
                    var y = info.date.getFullYear();
                    var m = info.date.getMonth();
                    var d = info.date.getDate();
                    var name = (typeof JapaneseHolidays !== 'undefined' && JapaneseHolidays && typeof JapaneseHolidays.isHoliday === 'function')
                        ? JapaneseHolidays.isHoliday(new Date(y, m, d)) : null;
                    if (name) {
                        info.el.classList.add('is-holiday');
                        if (!info.el.querySelector('.fc-holiday-badge')) {
                            var badge = document.createElement('div');
                            badge.className = 'fc-holiday-badge';
                            badge.textContent = name;
                            info.el.appendChild(badge);
                        }
                    }
                },

                datesSet: function(arg){ updateInputsByCalendar(arg.view); },

                events: function(fetchInfo, successCallback){
                    var unreservedEvents=[];
                    var cur=new Date(fetchInfo.start);
                    while(cur < fetchInfo.end){
                        var dateStr = cur.toISOString().slice(0,10);
                        if(reservedDates.indexOf(dateStr) === -1){
                            unreservedEvents.push({
                                title:'未予約', start:dateStr, allDay:true,
                                backgroundColor:'#fd7e14', borderColor:'#fd7e14', textColor:'white',
                                extendedProps:{displayOrder:-10}
                            });
                        }
                        cur.setDate(cur.getDate()+1);
                    }
                    
                    var allEvents = [].concat(existingEvents, unreservedEvents);
                    successCallback(allEvents);
                },

                eventOrder: function(a,b){
                    var A = Number((a.extendedProps && typeof a.extendedProps.displayOrder !== 'undefined') ? a.extendedProps.displayOrder : 0);
                    var B = Number((b.extendedProps && typeof b.extendedProps.displayOrder !== 'undefined') ? b.extendedProps.displayOrder : 0);
                    return (isNaN(A)?0:A) - (isNaN(B)?0:B);
                },

                dateClick: function(info){
                    try {
                        window.quickOpenDayModal(info.dateStr);
                    } catch (e) {
                        console.warn('quickOpenDayModal error:', e);
                    }
                }
            });

            calendar.render();
            window.calendar = calendar;
            window.__reservationCalendar = calendar;

            if (fromDateInput) {
                fromDateInput.addEventListener('change', function(){
                    if(fromDateInput.value) calendar.gotoDate(fromDateInput.value);
                });
            }
        }
    });
</script>

<script>
    function unlockForChildren(wrap){
        if (!wrap || window.__IS_STAFF) return;
        wrap.querySelectorAll('input[type="checkbox"][name^="users"]').forEach(function(cb){
            cb.disabled = false;
            cb.removeAttribute('data-locked');
            if (cb.title &&
                (cb.title.includes('直前予約のため') || cb.title.includes('直前期間のため'))) {
                cb.removeAttribute('title');
            }
            cb.classList?.remove('deletion-blocked');
        });
    }

    function observeChildUnlock(wrap){
        if (!wrap || window.__IS_STAFF || wrap.__childUnlockObserved) return;
        wrap.__childUnlockObserved = true;

        const mo = new MutationObserver(function(){
            unlockForChildren(wrap);
        });
        mo.observe(wrap, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['disabled', 'title', 'class']
        });
        unlockForChildren(wrap);
    }

    (function(){
        var ADD_URL = '<?= preg_replace("#^https?:#", "", $this->Url->build(['controller'=>'TReservationInfo','action'=>'add'], ['fullBase'=>true])) ?>';
        var CHANGEEDIT_URL = '<?= preg_replace("#^https?:#", "", $this->Url->build(['controller'=>'TReservationInfo','action'=>'changeEdit'], ['fullBase'=>true])) ?>';
        window.__BASE_PATH   = <?= json_encode($basePath, JSON_UNESCAPED_SLASHES) ?>;
        window.__csrfToken   = <?= json_encode($csrfToken) ?>;
        window.SERVER_TODAY  = <?= json_encode($serverToday) ?>;
        window.TODAY         = <?= json_encode($serverToday) ?>;
        window.QUERY_DATE    = <?= json_encode($date) ?>;
        window.__IS_STAFF    = <?= $isStaff ? 'true' : 'false' ?>;
        var SERVER_TODAY = <?= $JS_TODAY ?>;

        // 複数部屋所属の場合の情報表示
        document.addEventListener('DOMContentLoaded', function() {
            if (window.__USER_INFO) {
                console.log('ユーザー情報:', window.__USER_INFO);
                if (window.__USER_INFO.roomCount > 1) {
                    console.log('複数部屋所属:', window.__USER_INFO.roomIds);
                    console.log('表示される食数は', window.__USER_INFO.roomCount, '部屋の合計です');
                    // 必要に応じて、複数部屋所属の旨をユーザーに表示
                    if (typeof window.pageToast === 'function') {
                        setTimeout(function() {
                            window.pageToast('複数部屋(' + window.__USER_INFO.roomCount + '部屋)の合計数を表示中', 'info');
                        }, 1000);
                    }
                } else if (window.__USER_INFO.roomCount === 1) {
                    console.log('単一部屋所属:', window.__USER_INFO.roomIds);
                } else {
                    console.log('部屋所属なし');
                }
            }
        });

        function closeModalAndRefresh(modalEl) {
            try {
                if (window.bootstrap && window.bootstrap.Modal) {
                    var m = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                    m.hide && m.hide();
                } else {
                    modalEl.classList.remove('show'); modalEl.style.display='none';
                    var bd=document.getElementById('___modal-backdrop'); if (bd) bd.remove();
                    document.body.classList.remove('modal-open');
                }
            } catch(e) {}
            if (window.__reservationCalendar && typeof window.__reservationCalendar.refetchEvents === 'function') {
                window.__reservationCalendar.refetchEvents();
            } else {
                location.reload();
            }
        }

        function getCsrfToken() {
            return (window.__csrfToken) ||
                (document.querySelector('meta[name="csrfToken"]') ? document.querySelector('meta[name="csrfToken"]').getAttribute('content') : '');
        }

        async function executeScriptsFrom(node){
            var scripts = Array.prototype.slice.call(node.querySelectorAll('script'));
            for (var i=0; i<scripts.length; i++){
                var sc = scripts[i];
                if (sc.type && sc.type !== '' && sc.type !== 'text/javascript') continue;
                var newSc = document.createElement('script');
                newSc.async = false;
                if (sc.src) {
                    await new Promise(function(resolve){
                        newSc.src = sc.src;
                        newSc.onload = resolve;
                        newSc.onerror = function(){ console.warn('script load error:', sc.src); resolve(); };
                        document.body.appendChild(newSc);
                    });
                } else {
                    newSc.text = sc.textContent || '';
                    document.body.appendChild(newSc);
                }
            }
        }

        function ensureAddModalCompat(root){
            var scope = root || document;
            var roomSelect = null;

            if (!window.GET_USERS_BY_ROOM_TPL) {
                var basePath = window.__BASE_PATH || '';
                var baseUrl = basePath + '/TReservationInfo/getUsersByRoom/';
                window.GET_USERS_BY_ROOM_TPL = baseUrl + '__RID__';
            }

            if (!window.QUERY_DATE) {
                var urlParams = new URLSearchParams(window.location.search);
                window.QUERY_DATE = urlParams.get('date') || new Date().toISOString().split('T')[0];
            }

            if (!window.buildGetUsersByRoomUrl) {
                window.buildGetUsersByRoomUrl = function(roomId) {
                    if (!roomId) {
                        return '';
                    }
                    var url = window.GET_USERS_BY_ROOM_TPL || '';
                    if (url.indexOf('__RID__') !== -1) {
                        url = url.replace('__RID__', encodeURIComponent(roomId));
                    } else {
                        url = (window.__BASE_PATH || '') + '/TReservationInfo/getUsersByRoom/' + encodeURIComponent(roomId);
                    }
                    url += (url.indexOf('?') === -1 ? '?' : '&') + 'date=' + encodeURIComponent(window.QUERY_DATE);
                    return url;
                };
            }

            if (!window.fetchUserData) {
                window.fetchUserData = function(roomId) {
                    try {
                        if (!roomId) {
                            return Promise.resolve();
                        }
                        if (!window.buildGetUsersByRoomUrl) {
                            return Promise.resolve();
                        }
                        var url = window.buildGetUsersByRoomUrl(roomId);
                        var tbody = document.getElementById('user-checkboxes') ||
                            scope.querySelector('#user-checkboxes') ||
                            document.querySelector('#qd-remote-wrap #user-checkboxes');

                        if (!tbody) {
                            setTimeout(function() {
                                var retryTbody = document.getElementById('user-checkboxes') ||
                                    document.querySelector('#qd-remote-wrap #user-checkboxes');
                                if (retryTbody) {
                                    window.fetchUserData(roomId);
                                }
                            }, 500);
                            return Promise.resolve();
                        }

                        tbody.innerHTML = '<tr><td colspan="5" class="text-center">読み込み中...</td></tr>';

                        return fetch(url, {
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                            .then(function(response) {
                                if (!response.ok) {
                                    throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                                }
                                return response.text();
                            })
                            .then(function(text) {
                                try {
                                    var data = JSON.parse(text);
                                    return data;
                                } catch (e) {
                                    throw new Error('レスポンスがJSONではありません: ' + e.message);
                                }
                            })
                            .then(function(d){
                                var users = d && d.usersByRoom;
                                if (!Array.isArray(users)) {
                                    throw new Error('usersByRoom が配列ではありません');
                                }
                                tbody.innerHTML = '';
                                if (users.length === 0) {
                                    tbody.innerHTML = '<tr><td colspan="5" class="text-muted text-center">この部屋に利用者がいません。</td></tr>';
                                    return;
                                }
                                users.forEach(function(u){
                                    var tr = document.createElement('tr');
                                    tr.innerHTML =
                                        '<td>' + (u.name || 'Unknown') + '</td>' +
                                        '<td class="text-center"><input type="checkbox" name="users['+u.id+'][1]" value="1" ' + (Number(u.morning)===1?'checked':'') + '></td>' +
                                        '<td class="text-center"><input type="checkbox" name="users['+u.id+'][2]" value="1" ' + (Number(u.noon)===1   ?'checked':'') + '></td>' +
                                        '<td class="text-center"><input type="checkbox" name="users['+u.id+'][3]" value="1" ' + (Number(u.night)===1  ?'checked':'') + '></td>' +
                                        '<td class="text-center"><input type="checkbox" name="users['+u.id+'][4]" value="1" ' + (Number(u.bento)===1  ?'checked':'') + '></td>';
                                    tbody.appendChild(tr);
                                    var lunchCb = tr.querySelector('input[name="users['+u.id+'][2]"]');
                                    var bentoCb = tr.querySelector('input[name="users['+u.id+'][4]"]');
                                    if (window.setupLunchBentoPair && lunchCb && bentoCb) {
                                        window.setupLunchBentoPair(lunchCb, bentoCb);
                                    }
                                });
                                var tableContainer = tbody.closest('.table-responsive, #user-selection-table');
                                if (tableContainer) {
                                    tableContainer.style.maxHeight = '400px';
                                    tableContainer.style.overflowY = 'auto';
                                }
                            })
                            .catch(function(e){
                                if (tbody) {
                                    tbody.innerHTML = '<tr><td colspan="5" class="text-danger text-center">利用者一覧の取得に失敗しました: ' + e.message + '</td></tr>';
                                }
                            });

                    } catch (error) {
                        console.error('[fetchUserData] error:', error);
                    }
                };
            }

            scope.querySelectorAll('form').forEach(function(f){
                if (f.action && !/^https?:\/\//.test(f.action)) {
                    try {
                        var baseAbs = (window.location.origin + (window.__BASE_PATH || '') + '/');
                        var url = new URL(f.action, baseAbs);
                        f.action = url.toString();
                    } catch(e){}
                }
            });

            scope.querySelectorAll('a[href]').forEach(function(a){
                if (a.href && !/^https?:\/\//.test(a.href) && !/^javascript:/.test(a.href) && !/^#/.test(a.href)) {
                    try {
                        var baseAbs = (window.location.origin + (window.__BASE_PATH || '') + '/');
                        var url = new URL(a.getAttribute('href'), baseAbs);
                        a.href = url.toString();
                    } catch(e){}
                }
            });

            var personalBlocks = scope.querySelectorAll('#room-selection-table, #personal-section, .personal-section, [data-section="personal"], [data-mode="personal"], [data-target="personal"]');
            var groupBlocks    = scope.querySelectorAll('#room-select-group, #user-selection-table, #group-section, .group-section, [data-section="group"], [data-mode="group"], [data-target="group"]');

            function show(elList, on){
                elList.forEach(function(el){
                    el.style.display = on ? '' : 'none';
                });
            }

            var select = scope.querySelector('#c_reservation_type');
            if (select && !select.value && !scope.querySelector('#reserve-type-hint')) {
                var hint = document.createElement('small');
                hint.id = 'reserve-type-hint';
                hint.className = 'text-muted d-block mt-1';
                hint.textContent = '※ まず予約タイプを選択してください';
                select.parentNode.appendChild(hint);
            }

            var table = scope.querySelector('#reservationTable, .reservation-table, table[data-role="reservation"], table#targetTable, table.reservation');
            var $dt   = (window.jQuery && table && jQuery.fn && jQuery.fn.DataTable && jQuery(table).data('DataTable')) ? jQuery(table).DataTable() : null;

            function toggleTable(scopeValue){
                if ($dt) {
                    $dt.search(scopeValue).draw();
                } else if (table) {
                    var rows = table.querySelectorAll('tbody tr');
                    rows.forEach(function(r){
                        r.style.display = (scopeValue && r.textContent.indexOf(scopeValue) > -1) ? '' : 'none';
                    });
                }
            }

            function clearHiddenInputs(isGroup){
                var clearTargets = isGroup
                    ? scope.querySelectorAll('[name^="meals["], input[type="hidden"][name*="room"], input[type="hidden"][name*="user"]')
                    : scope.querySelectorAll('[name^="users["], input[type="hidden"][name*="i_id_room"]');
                clearTargets.forEach(function(inp){
                    if (inp.type === 'checkbox') inp.checked = false;
                    else inp.value = '';
                });
            }

            function applyMode(val){
                var v = String(val || '').toLowerCase();
                var isGroup = /group|collect| |^2$/.test(v);
                show(personalBlocks, !isGroup);
                show(groupBlocks,    isGroup);
                toggleTable(v);
                clearHiddenInputs(isGroup);

                var hint = scope.querySelector('#reserve-type-hint');
                if (hint) hint.style.display = val ? 'none' : '';
            }

            if (select) {
                applyMode(select.value);
                select.addEventListener('change', function(){ applyMode(select.value); });
            }

            setTimeout(function() {
                roomSelect = scope.querySelector('#room-select') ||
                    scope.querySelector('select[name*="room"]') ||
                    scope.querySelector('#room_select') ||
                    scope.querySelector('.room-select');

                if (roomSelect) {
                    function handleRoomChange() {
                        var roomId = roomSelect.value;
                        var tbody = document.getElementById('user-checkboxes');
                        if (tbody) tbody.innerHTML = '';
                        if (!roomId) {
                            var groupContainer = scope.querySelector('#user-selection-table');
                            if (groupContainer) groupContainer.style.display = 'none';
                            return;
                        }
                        var groupContainer = scope.querySelector('#user-selection-table');
                        if (groupContainer) groupContainer.style.display = '';
                        window.fetchUserData(roomId);
                    }
                    roomSelect.removeEventListener('change', roomSelect._handleRoomChange || (() => {}));
                    roomSelect._handleRoomChange = handleRoomChange;
                    roomSelect.addEventListener('change', handleRoomChange);
                    if (roomSelect.value) {
                        setTimeout(function() { handleRoomChange(); }, 100);
                    }
                }
            }, 200);

            if (typeof window.initReservationForm === 'function') {
                window.initReservationForm();
            }

            // ★ 昼食⇔弁当排他制御をモーダル描画直後に適用
            if (typeof window.applyLunchBentoExclusion === 'function') {
                window.applyLunchBentoExclusion(scope);
            }
        }
        function installModalSaveBridge(modal, modalEl){
            if (!modal) return;
            if (modal.dataset.saveBridgeInstalled) return;
            modal.dataset.saveBridgeInstalled = '1';

            modal.addEventListener('reservation:saved', function(e){
                var detail = e.detail || {};
                var date   = detail.date || detail.d_reservation_date || '';
                if (window.calendar && date) {
                    window.calendar.refetchEvents();
                }
                if (modalEl && typeof window.bootstrap !== 'undefined') {
                    var bsModal = window.bootstrap.Modal.getInstance(modalEl);
                    if (bsModal) {
                        setTimeout(function(){ bsModal.hide(); }, 800);
                    }
                }
            });

            modal.addEventListener('ce:saved', function(e){
                var detail = e.detail || {};
                var date   = detail.date || '';
                if (window.calendar && date) {
                    window.calendar.refetchEvents();
                }
                if (modalEl && typeof window.bootstrap !== 'undefined') {
                    var bsModal = window.bootstrap.Modal.getInstance(modalEl);
                    if (bsModal) {
                        setTimeout(function(){ bsModal.hide(); }, 800);
                    }
                }
            });

            modal.addEventListener('change-edit:saved', function(e){
                var detail = e.detail || {};
                var date   = detail.date || '';
                if (window.calendar && date) {
                    window.calendar.refetchEvents();
                }
                if (modalEl && typeof window.bootstrap !== 'undefined') {
                    var bsModal = window.bootstrap.Modal.getInstance(modalEl);
                    if (bsModal) {
                        setTimeout(function(){ bsModal.hide(); }, 800);
                    }
                }
            });
        }

        function extractFormFragment(htmlText){
            var parser = new DOMParser();
            var doc = parser.parseFromString(htmlText, 'text/html');

            var ceRoot = doc.querySelector('#ce-root');
            if (ceRoot) return ceRoot;

            var changeForm = doc.querySelector('#change-edit-form, form#changeEditForm, form[name="change-edit"]');
            if (changeForm) {
                var card = changeForm.closest('.card');
                if (card) return card;
                return changeForm;
            }

            var addForm = doc.querySelector('form#reservation-form, form[name="reservation-add"], form[action*="/TReservationInfo/add"]');
            if (addForm) {
                var addCard = addForm.closest('.card');
                if (addCard) return addCard;
                return addForm;
            }

            var right = doc.querySelector('.col-md-9');
            if (right) return right;

            return doc.body || doc.documentElement;
        }

        function replaceWithExtract(container, html){
            var tempDiv = document.createElement('div');
            tempDiv.innerHTML = html;

            var extracted = tempDiv.querySelector('#ce-root') ||
                tempDiv.querySelector('.card') ||
                tempDiv.querySelector('form') ||
                tempDiv.firstElementChild || tempDiv;

            container.innerHTML = '';
            container.appendChild(extracted);
        }

        async function timeoutableFetch(url, opts){
            var controller = new AbortController();
            var timeoutId  = setTimeout(function(){ controller.abort(); }, 30000);

            try {
                var merged = Object.assign({}, opts, {signal: controller.signal});
                var res = await fetch(url, merged);
                clearTimeout(timeoutId);
                return res;
            } catch(e) {
                clearTimeout(timeoutId);
                throw e;
            }
        }

        async function loadInto(container, url, modalEl){
            if (!container) {
                if (modalEl) {
                    var wrap = modalEl.querySelector('#qd-remote-wrap');
                    if (wrap) {
                        wrap.innerHTML = '<div class="alert alert-danger">コンテナが見つかりません</div>';
                    }
                }
                return;
            }

            container.innerHTML = '<div class="text-center p-5"><div class="spinner-border" role="status"></div><p class="mt-2">読み込み中...</p></div>';

            try {
                var response = await timeoutableFetch(url, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'text/html, */*;q=0.1'
                    }
                });

                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                var htmlText = await response.text();
                if (htmlText.trim().length === 0) {
                    throw new Error('空のレスポンス');
                }

                try {
                    replaceWithExtract(container, htmlText);
                    if (window.__IS_STAFF && typeof enforceStaffCancelBlock === 'function'){
                        enforceStaffCancelBlock(container);
                    }
                    if (!window.__IS_STAFF) { observeChildUnlock(container); }
                } catch(extractErr) {
                    container.innerHTML = htmlText;
                    if (!window.__IS_STAFF) { observeChildUnlock(container); }
                }

                var host = container.closest('.modal') || container;

                // ★★★★★ ここからが修正箇所 ★★★★★
                // add.js の初期化関数を明示的に呼び出すことで、表示崩れを解消する
                if (window.ADD_RESERVATION && typeof window.ADD_RESERVATION.init === 'function') {
                    try {
                        console.log('[loadInto] Explicitly calling ADD_RESERVATION.init()');
                        window.ADD_RESERVATION.init(host);
                    } catch (e) {
                        console.error('Error during ADD_RESERVATION.init():', e);
                    }
                } else {
                    console.warn('[loadInto] ADD_RESERVATION.init not found. UI might be misconfigured.');
                }
                // ★★★★★ 修正箇所ここまで ★★★★★

                ensureAddModalCompat(host);

                // ★ 昼食⇔弁当排他制御をAjax描画直後にも適用
                if (typeof window.applyLunchBentoExclusion === 'function') {
                    window.applyLunchBentoExclusion(host);
                }

                installModalSaveBridge(host, modalEl || host);

            } catch(err) {
                container.innerHTML =
                    '<div class="alert alert-danger" role="alert">' +
                    '<h4 class="alert-heading">エラー</h4>' +
                    '<p>読み込みに失敗しました</p>' +
                    '<hr><p class="mb-0"><small>ページを再読み込みするか、管理者にお問い合わせください。</small></p>' +
                    '</div>';
            }
        }

        function isWithin14(dateStr){
            var target = new Date(String(dateStr) + 'T00:00:00');
            var server = new Date(String(SERVER_TODAY) + 'T00:00:00');
            var diffDays = Math.round((target.getTime() - server.getTime()) / 86400000);
            return (diffDays >= 0 && diffDays <= 14);
        }

        async function loadViewIntoModal(dateStr, useChangeEdit){
            return new Promise(function(resolve, reject){
                var modal = document.getElementById('quickDayModal');
                if (!modal) { reject(new Error('#quickDayModal not found')); return; }
                var container = document.getElementById('qd-remote-wrap');
                if (!container) { reject(new Error('#qd-remote-wrap not found')); return; }

                window.QUERY_DATE = dateStr;

                var url = useChangeEdit
                    ? CHANGEEDIT_URL + '?date=' + encodeURIComponent(dateStr) + '&modal=1'
                    : ADD_URL + '?date=' + encodeURIComponent(dateStr) + '&modal=1';

                loadInto(container, url, modal).then(resolve).catch(reject);
            });
        }

        window.quickOpenDayModal = function(dateStr){
            try{
                var useChange = isWithin14(dateStr);
                openModalById('quickDayModal');
                loadViewIntoModal(dateStr, useChange).catch(function(){});
            } catch(e){
                openModalById('quickDayModal');
            }
        };

        document.addEventListener('shown.bs.modal', function (ev) {
            var modal = ev.target;
            if (!modal || modal.id !== 'quickDayModal') return;

            var wrap = modal.querySelector('#qd-remote-wrap') || modal;
            var targetDate =
                (typeof window.QUERY_DATE === 'string' && window.QUERY_DATE) ||
                (modal.querySelector('#qd-picked-date')?.textContent?.trim()) ||
                '';

            var isLastMinute =
                !!targetDate && typeof window.isWithin14 === 'function'
                    ? window.isWithin14(targetDate)
                    : false;

            function cleanupAll() {
                wrap.querySelectorAll('input[type="checkbox"][name^="users"]').forEach(function (cb) {
                    cb.disabled = false;
                    cb.removeAttribute('data-locked');
                    if (cb.title &&
                        (cb.title.includes('直前予約のため') || cb.title.includes('直前期間のため'))) {
                        cb.removeAttribute('title');
                    }
                    cb.classList?.remove('deletion-blocked');
                });
                wrap.querySelectorAll('.staff-last-minute-notice').forEach(function(n){ n.remove(); });
            }

            function applyStaffLock() {
                wrap.querySelectorAll('input[type="checkbox"][name^="users"]').forEach(function (cb) {
                    var isStaffTarget = (typeof window.isStaffTargetCheckbox === 'function')
                        ? window.isStaffTargetCheckbox(cb)
                        : !!window.__IS_STAFF;
                    if (!isStaffTarget) return;
                    if (cb.checked) {
                        cb.disabled = true;
                        cb.dataset.locked = '1';
                        cb.title = '直前期間のため、既存予約の削除はできません。';
                        cb.classList?.add('deletion-blocked');
                    }
                });

                if (!wrap.querySelector('.staff-last-minute-notice')) {
                    var notice = document.createElement('div');
                    notice.className = 'alert alert-info staff-last-minute-notice mb-3';
                    notice.innerHTML =
                        '<i class="bi bi-info-circle"></i> ' +
                        '<strong>直前期間（当日〜14日以内）</strong>のため、職員の既存予約は変更できません。' +
                        '子供は追加・キャンセルが可能です。';
                    var anchor = wrap.querySelector('.card, form, #ce-root') || wrap.firstElementChild;
                    if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(notice, anchor);
                    else wrap.prepend(notice);
                }
            }

            cleanupAll();

            if (!window.__IS_STAFF) {
                return;
            }

            if (!isLastMinute) return;

            try {
                if (typeof window.enforceStaffCancelBlock === 'function') {
                    window.enforceStaffCancelBlock(wrap);
                } else if (typeof window.enforceLastMinuteNoUncheck === 'function') {
                    window.enforceLastMinuteNoUncheck(wrap);
                } else {
                    applyStaffLock();
                }
            } catch (e) {
                applyStaffLock();
            }
        });

        window.ensureAddModalCompat = ensureAddModalCompat;
        window.installModalSaveBridge = installModalSaveBridge;
        window.loadInto = loadInto;
    })();
</script>

<script>
    (function(){
        if (!window.GET_USERS_BY_ROOM_TPL) {
            window.GET_USERS_BY_ROOM_TPL = '<?= $this->Url->build(
                    ['controller' => 'TReservationInfo', 'action' => 'getUsersByRoom', '__RID__'],
                    ['fullBase' => true]
            ) ?>';
        }

        if (!window.QUERY_DATE) {
            window.QUERY_DATE = <?= $JS_TODAY ?>;
        }

        if (!window.buildGetUsersByRoomUrl) {
            window.buildGetUsersByRoomUrl = function(roomId){
                var url = String(window.GET_USERS_BY_ROOM_TPL || '');
                if (url.indexOf('__RID__') !== -1) url = url.replace('__RID__', encodeURIComponent(roomId));
                else url = url.replace(/\/$/, '') + '/' + encodeURIComponent(roomId);
                url += (url.indexOf('?') === -1 ? '?' : '&') + 'date=' + encodeURIComponent(window.QUERY_DATE || '');
                return url;
            };
        }

        if (!window.toggleAllUsers) {
            window.toggleAllUsers = function(mealTime, isChecked){
                var map = { morning:1, noon:2, night:3, bento:4 };
                var mealType = map[mealTime]; if (!mealType) return;

                var checkboxes = document.querySelectorAll('input[type="checkbox"][name^="users"][name$="['+mealType+']"]');
                var headerCheckbox = document.querySelector('input[type="checkbox"][onclick^="toggleAllUsers(\''+mealTime+'\',"]');

                checkboxes.forEach(function(cb){
                    cb.checked = isChecked;
                    var m = cb.name.match(/^users\[(\d+)]\[(\d+)]$/);
                    if (m && (mealType === 2 || mealType === 4)) {
                        var userId = m[1];
                        var counterpart = (mealType === 2 ? 4 : 2);
                        var other = document.querySelector('input[name="users['+userId+']['+counterpart+']"]');
                        if (other && isChecked) {
                            other.checked = false;
                            other.dispatchEvent(new Event('change'));
                        }
                    }
                    cb.dispatchEvent(new Event('change'));
                });

                if (headerCheckbox) {
                    headerCheckbox.checked = Array.prototype.every.call(checkboxes, function(c){ return c.checked; });
                }
            };
        }

        if (!window.setupLunchBentoPair) {
            window.setupLunchBentoPair = function (lunchCb, bentoCb) {
                if (!lunchCb || !bentoCb) return;
                if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;

                function updateHeader(mealType) {
                    var all = [];
                    var nodes = document.querySelectorAll('input[type="checkbox"][name^="users"]');
                    for (var i = 0; i < nodes.length; i++) {
                        var n = nodes[i];
                        var m = typeof n.name === 'string' ? n.name.match(/\[(\d+)\]$/) : null;
                        if (m && Number(m[1]) === mealType) all.push(n);
                    }

                    var mealKey = mealType === 2 ? 'noon' : 'bento';
                    var header =
                        document.querySelector('input[type="checkbox"][data-meal="' + mealKey + '"]');

                    if (!header) {
                        var cand = document.querySelectorAll('input[type="checkbox"][onclick]');
                        var needles = [
                            "toggleAllUsers('" + mealKey + "',",
                            'toggleAllUsers("' + mealKey + '",'
                        ];
                        for (var j = 0; j < cand.length && !header; j++) {
                            var v = cand[j].getAttribute('onclick') || '';
                            for (var k = 0; k < needles.length && !header; k++) {
                                if (v.indexOf(needles[k]) === 0) header = cand[j];
                            }
                        }
                    }

                    if (header) {
                        var allChecked = true;
                        for (var x = 0; x < all.length; x++) {
                            if (!all[x].checked) { allChecked = false; break; }
                        }
                        header.checked = allChecked;
                    }
                }

                function onLunchChange() {
                    if (lunchCb.checked && bentoCb.checked) {
                        bentoCb.checked = false;
                        bentoCb.dispatchEvent(new Event('change'));
                    }
                    updateHeader(2);
                    updateHeader(4);
                }

                function onBentoChange() {
                    if (bentoCb.checked && lunchCb.checked) {
                        lunchCb.checked = false;
                        lunchCb.dispatchEvent(new Event('change'));
                    }
                    updateHeader(2);
                    updateHeader(4);
                }

                lunchCb.addEventListener('change', onLunchChange);
                bentoCb.addEventListener('change', onBentoChange);

                lunchCb.dataset._paired = '1';
                bentoCb.dataset._paired = '1';

                updateHeader(2);
                updateHeader(4);
            };
        }

        if (!window.fetchUserData) {
            window.fetchUserData = function(roomId) {
                var url = window.buildGetUsersByRoomUrl(roomId);
                var tbody = document.getElementById('user-checkboxes');
                if (!tbody) { return; }

                tbody.innerHTML = '<tr><td colspan="5" class="text-center">読み込み中...</td></tr>';

                return fetch(url, { credentials: 'same-origin' })
                    .then(function(r){
                        if (!r.ok) throw new Error('HTTP '+r.status);
                        return r.json();
                    })
                    .then(function(d){
                        var users = d && d.usersByRoom;
                        if (!Array.isArray(users)) {
                            throw new Error('usersByRoom が配列ではありません');
                        }

                        tbody.innerHTML = '';

                        if (users.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="5" class="text-muted text-center">この部屋に利用者がいません。</td></tr>';
                            return;
                        }

                        users.forEach(function(u){
                            var tr = document.createElement('tr');
                            tr.innerHTML =
                                '<td>' + (u.name || 'Unknown') + '</td>' +
                                '<td class="text-center"><input type="checkbox" name="users['+u.id+'][1]" value="1" ' + (Number(u.morning)===1?'checked':'') + '></td>' +
                                '<td class="text-center"><input type="checkbox" name="users['+u.id+'][2]" value="1" ' + (Number(u.noon)===1   ?'checked':'') + '></td>' +
                                '<td class="text-center"><input type="checkbox" name="users['+u.id+'][3]" value="1" ' + (Number(u.night)===1  ?'checked':'') + '></td>' +
                                '<td class="text-center"><input type="checkbox" name="users['+u.id+'][4]" value="1" ' + (Number(u.bento)===1  ?'checked':'') + '></td>';
                            tbody.appendChild(tr);

                            var lunchCb = tr.querySelector('input[name="users['+u.id+'][2]"]');
                            var bentoCb = tr.querySelector('input[name="users['+u.id+'][4]"]');
                            if (window.setupLunchBentoPair && lunchCb && bentoCb) {
                                window.setupLunchBentoPair(lunchCb, bentoCb);
                            }
                        });

                        var tableContainer = tbody.closest('.table-responsive, #user-selection-table');
                        if (tableContainer) {
                            tableContainer.style.maxHeight = '400px';
                            tableContainer.style.overflowY = 'auto';
                        }
                    })
                    .catch(function(e){
                        tbody.innerHTML = '<tr><td colspan="5" class="text-danger text-center">利用者一覧の取得に失敗しました: ' + e.message + '</td></tr>';
                    });
            };
        }

        var _origEnsure = window.ensureAddModalCompat;
        window.ensureAddModalCompat = function(host){
            if (typeof _origEnsure === 'function') _origEnsure(host);
            var scope = host || document;

            setTimeout(function() {
                var select = scope.querySelector('#room-select');
                var groupContainer = scope.querySelector('#user-selection-table');

                function handleChange(){
                    var roomId = select && select.value;
                    var tbody = scope.querySelector('#user-checkboxes');
                    if (tbody) tbody.innerHTML = '';
                    if (!roomId) {
                        if (groupContainer) groupContainer.style.display = 'none';
                        return;
                    }
                    if (groupContainer) groupContainer.style.display = '';
                    window.fetchUserData(roomId);
                }

                if (select) {
                    select.removeEventListener('change', handleChange);
                    select.addEventListener('change', handleChange);
                    if (select.value) {
                        handleChange();
                    }
                }
            }, 100);
        };
    })();
</script>
<script>
    document.addEventListener('shown.bs.modal', function(ev) {
        var modal = ev.target;
        if (!modal) return;
        if (modal.id === 'quickDayModal') {
            var wrap = modal.querySelector('#qd-remote-wrap');
            if (wrap) {
                setTimeout(function() {
                    var dateEl = wrap.querySelector('#qd-picked-date');
                    var targetDate = dateEl ? (dateEl.textContent || '').trim() : '';

                    var isStaffAndLastMinute =
                        !!(window.__IS_STAFF && targetDate && typeof isWithin14 === 'function' && isWithin14(targetDate));

                    if (isStaffAndLastMinute && typeof enforceLastMinuteNoUncheck === 'function') {
                        enforceLastMinuteNoUncheck(wrap);
                    }

                    if (isStaffAndLastMinute) {
                        var existingNotice = wrap.querySelector('.staff-last-minute-notice');
                        if (!existingNotice) {
                            var notice = document.createElement('div');
                            notice.className = 'alert alert-info staff-last-minute-notice mb-3';
                            notice.innerHTML =
                                '<i class="bi bi-info-circle"></i> ' +
                                '<strong>直前期間のため、既存予約の削除はできません。</strong>新しい予約の追加のみ可能です。';
                            var firstCard = wrap.querySelector('.card');
                            if (firstCard && firstCard.parentNode) {
                                firstCard.parentNode.insertBefore(notice, firstCard);
                            } else {
                                wrap.prepend(notice);
                            }
                        }
                    }
                }, 100);
            }
        }
    });
</script>

<script>
    (function(){
        const copyApi   = <?= json_encode($copyApi, JSON_UNESCAPED_SLASHES) ?>;
        const copyPreviewApi = <?= json_encode($copyPreviewApi, JSON_UNESCAPED_SLASHES) ?>;
        const csrfToken = document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') || '';

        const ymdLocal = (d)=> {
            const y=d.getFullYear(), m=('0'+(d.getMonth()+1)).slice(-2), day=('0'+d.getDate()).slice(-2);
            return `${y}-${m}-${day}`;
        };
        const startOfWeek = (d)=> {
            const c = new Date(d.getFullYear(), d.getMonth(), d.getDate());
            const w = c.getDay(); const diffToMon = (w === 0 ? -6 : 1 - w);
            c.setDate(c.getDate() + diffToMon); return c;
        };
        const startOfMonth = (d)=> new Date(d.getFullYear(), d.getMonth(), 1);

        const refreshCalendarOrReload = ()=> {
            try {
                if (window.__reservationCalendar?.refetchEvents) {
                    window.__reservationCalendar.refetchEvents();
                    return;
                }
            } catch(_) {}
            location.reload();
        };

        const openModalBtn      = document.querySelector('#res-copy-modal');
        const submitBtn         = document.querySelector('#res-copy-submit');
        const lastWeekQuickBtn  = document.querySelector('#res-copy-btn-lastweek');
        const form              = document.querySelector('#res-copy-form');

        if (!form) return;

        async function doCopy(payload){
            const res = await fetch(copyApi, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json; charset=utf-8',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(payload)
            });
            const data = await res.json().catch(()=> ({}));
            if (!res.ok || data?.ok === false) {
                const msg = data?.message || `コピーに失敗しました（${res.status}）`;
                throw new Error(msg);
            }
        }

        submitBtn?.addEventListener('click', async ()=>{
            try {
                const fd = new FormData(form);
                const mode         = fd.get('mode');
                const sourceStart  = fd.get('source_start');
                const targetStart  = fd.get('target_start');
                const roomId       = fd.get('room_id') || '';
                const overwrite    = fd.get('overwrite') ? 1 : 0;

                if (!sourceStart || !targetStart) { alert('コピー元/先の開始日を入力してください。'); return; }
                if (mode !== 'week' && mode !== 'month') { alert('コピー範囲（週／月）を選択してください。'); return; }

                await doCopy({
                    mode,
                    source_start: sourceStart,
                    target_start: targetStart,
                    room_id: roomId || null,
                    overwrite
                });

                alert('コピーが完了しました。');
                try {
                    const modalEl = document.getElementById('res-copy-modal');
                    if (modalEl && window.bootstrap?.Modal) {
                        window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                    }
                } catch(_) {}
                refreshCalendarOrReload();
            } catch (e) {
                alert(e?.message || 'コピーに失敗しました。');
            }
        });

        lastWeekQuickBtn?.addEventListener('click', async ()=>{
            try {
                const base = new Date();
                const thisMon = startOfWeek(base);
                const lastMon = new Date(thisMon); lastMon.setDate(thisMon.getDate() - 7);

                await doCopy({
                    mode: 'week',
                    source_start: ymdLocal(lastMon),
                    target_start: ymdLocal(thisMon),
                    room_id: null,
                    overwrite: 0
                });

                alert('先週 → 今週 へのコピーが完了しました。');
                refreshCalendarOrReload();
            } catch (e) {
                alert(e?.message || 'コピーに失敗しました。');
            }
        });
    })();
</script>
<script>
    if (typeof enforceLastMinuteNoUncheck !== 'function') {
        function enforceLastMinuteNoUncheck(scope){
            if (typeof enforceStaffCancelBlock === 'function') {
                enforceStaffCancelBlock(scope);
            }
        }
    }
</script>

<script>
    document.addEventListener('shown.bs.modal', function(ev) {
        var modal = ev.target;
        if (modal.id !== 'quickDayModal') return;
        var isLastMinute = isWithin14(window.QUERY_DATE);
        if (!isLastMinute) return;

        var checkboxes = modal.querySelectorAll('input[type="checkbox"][name^="users"]');
        checkboxes.forEach(function(cb) {
            if (cb.checked) {
                cb.disabled = true;
                cb.title = '直前予約のため、変更できません（追加のみ可能）';
            }
        });
    });
</script>
<script>
    (function(){
        const modalEl          = document.getElementById('res-copy-modal');
        const submitBtn        = document.getElementById('res-copy-submit');
        const lastWeekQuickBtn = document.getElementById('res-copy-btn-lastweek');
        const form             = document.querySelector('#res-copy-form');
        if (!modalEl || !form || !submitBtn) return;

        const copyApi   = <?= json_encode($copyApi, JSON_UNESCAPED_SLASHES) ?>;
        const csrfToken = document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') || '';

        const sourceInput = document.getElementById('source_start');
        const targetInput = document.getElementById('target_start_input');
        const addTargetBtn = document.getElementById('add-target-btn');
        const targetDatesList = document.getElementById('target-dates-list');
        const targetDatesEmpty = document.getElementById('target-dates-empty');
        const targetDatesHidden = document.getElementById('target-dates-hidden');
        const modeWeek = document.getElementById('res-copy-mode-week');
        const modeMonth = document.getElementById('res-copy-mode-month');
        const sourceValidation = document.getElementById('source-validation');
        const refreshSourceBtn = document.getElementById('refresh-source');
        const refreshTargetBtn = document.getElementById('refresh-target');

        // 選択されたコピー先日付を管理する配列
        let targetDates = [];

        const isMonday = (d)=> d.getDay() === 1;
        const isFirst  = (d)=> d.getDate() === 1;
        const ymd      = (d)=> d.toISOString().slice(0,10);

        function parseDate(val){
            if(!val) return null;
            const d = new Date(val + 'T00:00:00');
            return isNaN(d) ? null : d;
        }
        
        function toast(msg,type='success'){
            let wrap = document.getElementById('toastWrap');
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = 'toastWrap';
                wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
                document.body.appendChild(wrap);
            }
            const el = document.createElement('div');
            el.className = 'toast align-items-center text-bg-' + (type==='success'?'success':type==='warning'?'warning':'danger') + ' border-0';
            el.innerHTML = `
      <div class="d-flex">
        <div class="toast-body">${msg}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>`;
            wrap.appendChild(el);
            const t = window.bootstrap?.Toast.getOrCreateInstance(el, { delay: 3000 });
            t?.show();
            el.addEventListener('hidden.bs.toast', ()=> el.remove());
        }

        // コピー先日付を追加
        function addTargetDate() {
            const mode = modeWeek.checked ? 'week' : 'month';
            const dateStr = targetInput.value;
            if (!dateStr) {
                toast('日付を選択してください', 'warning');
                return;
            }
            
            const date = parseDate(dateStr);
            if (!date) {
                toast('有効な日付を選択してください', 'warning');
                return;
            }
            
            // バリデーション
            if (mode === 'week' && !isMonday(date)) {
                toast('週単位の場合は月曜日を選択してください', 'warning');
                return;
            }
            if (mode === 'month' && !isFirst(date)) {
                toast('月単位の場合は1日を選択してください', 'warning');
                return;
            }
            
            // コピー元との重複チェック
            const source = parseDate(sourceInput.value);
            if (source && source.getTime() === date.getTime()) {
                toast('コピー元と同じ日付は選択できません', 'warning');
                return;
            }
            
            // 既に追加されているかチェック
            if (targetDates.some(d => d === dateStr)) {
                toast('既に追加されている日付です', 'warning');
                return;
            }
            
            targetDates.push(dateStr);
            renderTargetDates();
            targetInput.value = '';
            validateInputs();
        }

        // コピー先日付を削除
        function removeTargetDate(dateStr) {
            targetDates = targetDates.filter(d => d !== dateStr);
            renderTargetDates();
            validateInputs();
        }

        // コピー先日付のリストを描画
        function renderTargetDates() {
            if (targetDates.length === 0) {
                targetDatesEmpty.style.display = 'block';
                targetDatesList.querySelectorAll('.target-date-item').forEach(el => el.remove());
                targetDatesHidden.innerHTML = '';
                return;
            }
            
            targetDatesEmpty.style.display = 'none';
            
            // 既存のアイテムを削除
            targetDatesList.querySelectorAll('.target-date-item').forEach(el => el.remove());
            targetDatesHidden.innerHTML = '';
            
            // 日付順にソート
            const sorted = [...targetDates].sort();
            
            sorted.forEach(dateStr => {
                // 表示用のバッジ
                const badge = document.createElement('span');
                badge.className = 'badge bg-primary me-2 mb-2 target-date-item';
                badge.style.fontSize = '0.9rem';
                badge.innerHTML = `
                    <i class="bi bi-calendar-check"></i> ${dateStr}
                    <button type="button" class="btn-close btn-close-white ms-2" 
                            style="font-size: 0.7rem;" 
                            onclick="window.removeTargetDate('${dateStr}')"></button>
                `;
                targetDatesList.appendChild(badge);
                
                // hidden input
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'target_dates[]';
                hidden.value = dateStr;
                targetDatesHidden.appendChild(hidden);
            });
            
            // プレビューを更新
            validateInputs();
        }

        // グローバルに公開
        window.removeTargetDate = removeTargetDate;

        // 追加ボタンのイベント
        if (addTargetBtn) {
            addTargetBtn.addEventListener('click', addTargetDate);
        }
        
        // Enterキーで追加
        if (targetInput) {
            targetInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    addTargetDate();
                }
            });
        }

        // リアルタイムバリデーション
        async function validateInputs() {
            const mode = modeWeek.checked ? 'week' : 'month';
            const source = parseDate(sourceInput.value);
            
            let isValid = true;
            
            // コピー元のバリデーション
            if (source) {
                if (mode === 'week' && !isMonday(source)) {
                    sourceValidation.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> 週単位の場合は月曜日を選択してください</span>';
                    isValid = false;
                } else if (mode === 'month' && !isFirst(source)) {
                    sourceValidation.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> 月単位の場合は1日を選択してください</span>';
                    isValid = false;
                } else {
                    sourceValidation.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> OK</span>';
                    
                    // コピー元が有効な場合、コピー先を自動入力
                    autoFillTargetDate(mode, source);
                }
            } else {
                sourceValidation.innerHTML = '';
            }
            
            // ボタンの有効/無効
            submitBtn.disabled = !(isValid && source && targetDates.length > 0);
            
            // プレビュー表示
            const preview = document.getElementById('copy-preview');
            const previewContent = document.getElementById('preview-content');
            
            if (isValid && source && targetDates.length > 0 && preview && previewContent) {
                // プレビュー件数を取得
                await fetchPreviewCounts(mode, source);
            } else if (preview) {
                preview.style.display = 'none';
            }
        }

        // コピー先の日付を自動入力
        function autoFillTargetDate(mode, source) {
            // コピー先入力欄が空の場合のみ自動入力
            if (targetInput.value) return;
            
            let suggestedDate;
            if (mode === 'week') {
                // 週単位の場合：翌週の月曜日
                suggestedDate = new Date(source);
                suggestedDate.setDate(suggestedDate.getDate() + 7);
            } else {
                // 月単位の場合：翌月の1日
                suggestedDate = new Date(source);
                suggestedDate.setMonth(suggestedDate.getMonth() + 1);
                suggestedDate.setDate(1);
            }
            
            // 入力欄に設定
            targetInput.value = ymd(suggestedDate);
            
            // アニメーション効果を追加
            targetInput.classList.add('border-success');
            setTimeout(() => {
                targetInput.classList.remove('border-success');
            }, 1000);
        }

        // プレビュー件数を取得して表示
        async function fetchPreviewCounts(mode, source) {
            const preview = document.getElementById('copy-preview');
            const previewContent = document.getElementById('preview-content');
            
            if (!preview || !previewContent || targetDates.length === 0) return;
            
            try {
                const sourceStr = ymd(source);
                const onlyChildren = document.getElementById('copy-only-children')?.checked || false;
                const roomIdInput = document.querySelector('input[name="room_id"]');
                const roomId = roomIdInput?.value || null;
                
                let totalCount = 0;
                const results = [];
                
                // 各コピー先の件数を取得
                for (const targetStr of targetDates) {
                    const params = new URLSearchParams({
                        mode: mode,
                        source: sourceStr,
                        target: targetStr,
                        only_children: onlyChildren ? '1' : '0'
                    });
                    
                    if (roomId) {
                        params.append('room_id', roomId);
                    }
                    
                    const res = await fetch(`${copyPreviewApi}?${params.toString()}`, {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-Token': csrfToken
                        }
                    });
                    
                    if (res.ok) {
                        const data = await res.json();
                        if (data.status === 'success' && data.preview) {
                            const count = data.preview.will_copy || 0;
                            totalCount += count;
                            results.push({ target: targetStr, count: count });
                        }
                    }
                }
                
                // プレビュー表示を更新
                if (totalCount > 0) {
                    let html = `<div class="mb-2"><strong>コピー予定件数：${totalCount}件</strong></div>`;
                    if (results.length > 1) {
                        html += '<div class="small text-muted">内訳：</div><ul class="small mb-0">';
                        results.forEach(r => {
                            html += `<li>${r.target}: ${r.count}件</li>`;
                        });
                        html += '</ul>';
                    }
                    previewContent.innerHTML = html;
                    preview.style.display = 'block';
                } else {
                    previewContent.innerHTML = '<div class="text-muted">コピー対象のデータがありません</div>';
                    preview.style.display = 'block';
                }
            } catch (err) {
                console.error('Preview fetch error:', err);
                preview.style.display = 'none';
            }
        }

        // イベントリスナー
        [modeWeek, modeMonth].forEach(radio => radio.addEventListener('change', () => {
            // モード変更時にコピー先をクリアして再計算
            const source = parseDate(sourceInput.value);
            if (source && !targetInput.value) {
                const mode = modeWeek.checked ? 'week' : 'month';
                autoFillTargetDate(mode, source);
            }
            validateInputs();
        }));
        sourceInput.addEventListener('change', validateInputs);
        sourceInput.addEventListener('input', validateInputs);
        
        // 子供のみチェックボックスの変更
        const onlyChildrenCheckbox = document.getElementById('copy-only-children');
        if (onlyChildrenCheckbox) onlyChildrenCheckbox.addEventListener('change', validateInputs);

        async function postCopy(payload){
            const res = await fetch(copyApi, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type':'application/json; charset=utf-8',
                    'Accept':'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(payload)
            });
            const ct = res.headers.get('content-type') || '';
            const isJson = ct.includes('application/json');
            const data = isJson ? await res.json() : { message: await res.text() };

            if (!res.ok || data?.ok === false) {
                const msg = data?.message || `コピーに失敗しました（${res.status}）`;
                throw new Error(msg);
            }
            return data;
        }

        submitBtn.addEventListener('click', async ()=>{
            try{
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>処理中...';

                const fd = new FormData(form);
                const mode         = fd.get('mode') || 'week';
                const sourceStart  = parseDate(fd.get('source_start'));
                const onlyChildren = !!fd.get('only_children');

                if(!sourceStart || targetDates.length === 0){
                    toast('コピー元とコピー先を選択してください。','warning');
                    return;
                }

                // 複数のコピー先に対して順番に実行
                let totalCopied = 0;
                let totalSkipped = 0;
                let totalSource = 0;
                let failedCount = 0;

                for (const targetDateStr of targetDates) {
                    const payload = {
                        mode,
                        source_start: ymd(sourceStart),
                        target_start: targetDateStr,
                        room_id: null, // すべての部屋
                        overwrite: 0,
                        only_children: onlyChildren ? 1 : 0
                    };

                    try {
                        const res = await postCopy(payload);
                        
                        const total = res?.total ?? 0;
                        const copied = res?.copied ?? 0;
                        const skipped = res?.skipped ?? 0;
                        
                        totalCopied += copied;
                        totalSkipped += skipped;
                        if (totalSource === 0) totalSource = total; // 最初の1回だけ
                    } catch (e) {
                        console.error('Copy failed for', targetDateStr, e);
                        failedCount++;
                    }
                }

                // 結果メッセージ
                let message = `コピーが完了しました。\n`;
                message += `コピー先: ${targetDates.length}件\n`;
                message += `コピー元データ: ${totalSource}件\n`;
                message += `新規登録: ${totalCopied}件`;
                if (totalSkipped > 0) {
                    message += `\nスキップ（既存）: ${totalSkipped}件`;
                }
                if (failedCount > 0) {
                    message += `\n失敗: ${failedCount}件`;
                }
                
                toast(message, failedCount > 0 ? 'warning' : 'success');

                if (window.__reservationCalendar?.refetchEvents) {
                    window.__reservationCalendar.refetchEvents();
                }

                const bs = window.bootstrap?.Modal.getOrCreateInstance(modalEl);
                bs?.hide();
            } catch(e){
                console.error(e);
                toast(e.message || 'コピーに失敗しました。','danger');
            } finally {
                submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> コピーを実行';
                submitBtn.disabled = false;
            }
        });

        if (lastWeekQuickBtn) {
            lastWeekQuickBtn.addEventListener('click', async ()=>{
                try{
                    lastWeekQuickBtn.disabled = true;

                    const base = (window.__reservationCalendar?.getDate && new Date(window.__reservationCalendar.getDate())) || new Date();
                    const day = base.getDay(); const monday = new Date(base);
                    monday.setDate(base.getDate() - ((day + 6) % 7));
                    const lastMonday = new Date(monday); lastMonday.setDate(monday.getDate() - 7);

                    const payload = {
                        mode: 'week',
                        source_start: ymd(lastMonday),
                        target_start: ymd(monday),
                        room_id: document.getElementById('res-copy-room')?.value || null,
                        overwrite: 0
                    };

                    const res = await postCopy(payload);
                    
                    const total = res?.total ?? 0;
                    const copied = res?.copied ?? 0;
                    const skipped = res?.skipped ?? 0;
                    
                    let message = '先週 → 今週 へコピーしました。\n';
                    message += `コピー元: ${total}件、新規登録: ${copied}件`;
                    if (skipped > 0) {
                        message += `、スキップ: ${skipped}件`;
                    }
                    
                    toast(message, 'success');
                    window.__reservationCalendar?.refetchEvents?.();
                } catch(e){
                    console.error(e);
                    toast(e.message || 'コピーに失敗しました。','danger');
                } finally {
                    lastWeekQuickBtn.disabled = false;
                }
            });
        }

        // 日付の自動補完機能
        function autoFillDates() {
            const mode = modeWeek.checked ? 'week' : 'month';
            
            // カレンダーの現在表示日付を取得（なければ今日）
            let baseDate = new Date();
            if (window.__reservationCalendar && window.__reservationCalendar.getDate) {
                try {
                    baseDate = new Date(window.__reservationCalendar.getDate());
                } catch(e) {
                    baseDate = new Date();
                }
            }
            
            if (mode === 'week') {
                // 週単位の場合
                const dayOfWeek = baseDate.getDay();
                const currentMonday = new Date(baseDate);
                currentMonday.setDate(baseDate.getDate() - ((dayOfWeek + 6) % 7));
                
                // コピー元: 先週の月曜日
                const lastMonday = new Date(currentMonday);
                lastMonday.setDate(currentMonday.getDate() - 7);
                
                // コピー先: 今週の月曜日
                sourceInput.value = ymd(lastMonday);
                targetInput.value = ymd(currentMonday);
                
            } else {
                // 月単位の場合
                const year = baseDate.getFullYear();
                const month = baseDate.getMonth();
                
                // コピー元: 先月の1日
                const lastMonthFirst = new Date(year, month - 1, 1);
                
                // コピー先: 今月の1日
                const thisMonthFirst = new Date(year, month, 1);
                
                sourceInput.value = ymd(lastMonthFirst);
                targetInput.value = ymd(thisMonthFirst);
            }
            
            // バリデーション実行
            validateInputs();
            
            // ヒントを更新
            updateHint();
        }
        
        // モード変更時にヒントを更新
        function updateHint() {
            const mode = modeWeek.checked ? 'week' : 'month';
            const hint = document.getElementById('mode-hint');
            if (hint) {
                if (mode === 'week') {
                    hint.innerHTML = '<i class="bi bi-info-circle text-primary"></i> 週単位の場合は月曜日を開始日に指定してください（自動入力済み）';
                } else {
                    hint.innerHTML = '<i class="bi bi-info-circle text-primary"></i> 月単位の場合は1日を開始日に指定してください（自動入力済み）';
                }
            }
        }
        
        // モード変更時に自動補完
        [modeWeek, modeMonth].forEach(radio => {
            radio.addEventListener('change', function() {
                autoFillDates();
            });
        });
        
        // 再計算ボタン
        if (refreshSourceBtn) {
            refreshSourceBtn.addEventListener('click', function() {
                autoFillDates();
                toast('日付を再計算しました', 'info');
            });
        }
        if (refreshTargetBtn) {
            refreshTargetBtn.addEventListener('click', function() {
                autoFillDates();
                toast('日付を再計算しました', 'info');
            });
        }

        // モーダルが開いたときに初期化と自動補完
        modalEl.addEventListener('shown.bs.modal', function() {
            autoFillDates();
            
            // アニメーション効果で自動入力を強調
            setTimeout(function() {
                sourceInput.classList.add('border-success');
                targetInput.classList.add('border-success');
                setTimeout(function() {
                    sourceInput.classList.remove('border-success');
                    targetInput.classList.remove('border-success');
                }, 1500);
            }, 100);
        });
        
        // モーダルが閉じたときにフォームをリセット
        modalEl.addEventListener('hidden.bs.modal', function() {
            form.reset();
            sourceValidation.innerHTML = '';
            targetValidation.innerHTML = '';
            document.getElementById('copy-preview').style.display = 'none';
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> コピーを実行';
        });
    })();

    function isStaffTargetCheckbox(cb){
        if (!cb) return false;
        var tr = cb.closest('tr');
        if (tr && tr.getAttribute('data-is-staff') === '1') return true;
        var userLevel = cb.dataset.userLevel || cb.getAttribute('data-user-level');
        if (!userLevel && tr) userLevel = tr.dataset.userLevel || tr.getAttribute('data-user-level');
        if (userLevel != null && userLevel !== '') return String(userLevel) === '0';
        // 判別不能な場合はログインユーザーに合わせる
        return !!window.__IS_STAFF;
    }

    function enforceLastMinuteNoUncheck(scope){
        if (!window.__IS_STAFF) return;

        var root = scope || document;
        var dateInput = root.querySelector('input[name="date"]');
        if (!dateInput) return;

        var targetDate = dateInput.value;
        if (!targetDate) return;

        var isLastMinute = window.isWithin14 && window.isWithin14(targetDate);

        if (!isLastMinute) return;

        var checkboxes = root.querySelectorAll('input[type="checkbox"][name^="reservation"], input[type="checkbox"][name*="users"], .meal-checkbox');

        checkboxes.forEach(function(cb) {
            if (cb.checked) {
                var isStaffUser = isStaffTargetCheckbox(cb);
                if (!isStaffUser) return;

                cb.addEventListener('click', function(e) {
                    if (!cb.checked) {
                        e.preventDefault();
                        e.stopPropagation();
                        cb.checked = true;

                        var message = '職員の直前期間での予約キャンセルは禁止されています。';

                        showInlineHint(cb, message);

                        var existingWarning = root.querySelector('.last-minute-warning');
                        if (!existingWarning) {
                            var warning = document.createElement('div');
                            warning.className = 'alert alert-warning last-minute-warning mt-2';
                            warning.innerHTML = '<i class="bi bi-exclamation-triangle"></i> ' + message;
                            cb.closest('.form-check, tr, .meal-checkbox-container')?.appendChild(warning);
                            setTimeout(function() {
                                warning.remove();
                            }, 3000);
                        }
                    }
                });

                var container = cb.closest('.form-check, tr, .meal-checkbox-container');
                if (container) {
                    container.classList.add('position-relative');
                    if (!container.querySelector('.deletion-blocked')) {
                        var label = document.createElement('small');
                        label.className = 'text-muted deletion-blocked';
                        label.style.cssText = 'font-size: 0.75rem; display: block; margin-top: 0.25rem;';
                        label.textContent = '（職員：削除不可）';
                        container.appendChild(label);
                    }
                }
            }
        });
    }

    function showInlineHint(el, text){
        var root = el.closest('form') || document;
        var holder = el.closest('label') || el.closest('tr') || el.parentElement || root;
        var msg = holder.querySelector('.no-uncheck-hint');
        if (!msg) {
            msg = document.createElement('small');
            msg.className = 'text-warning no-uncheck-hint d-block';
            msg.style.cssText = 'margin-top: 0.25rem; font-weight: 500;';
            holder.appendChild(msg);
        }
        msg.textContent = text;
        clearTimeout(msg._timer);
        msg._timer = setTimeout(function(){
            if (msg.parentNode) {
                msg.remove();
            }
        }, 3000);
    }
</script>
<script>
    function isWithin14(dateStr){
        var t = new Date(String(dateStr) + 'T00:00:00');
        var s = new Date(String(window.SERVER_TODAY || window.TODAY) + 'T00:00:00');
        return Math.round((t - s) / 86400000) >= 0 && Math.round((t - s) / 86400000) <= 14;
    }

    function isStaffCancelProhibited(dateStr, turningOn){
        return !!window.__IS_STAFF && isWithin14(dateStr) && (turningOn === false);
    }

    function enforceStaffCancelBlock(scope){
        try{
            if (!window.__IS_STAFF) return;
            var dateStr = String(window.QUERY_DATE || '');
            if (!dateStr || !isWithin14(dateStr)) return;
            var root = scope || document;

            if (!root.querySelector('.staff-cancel-block-notice')) {
                var notice = document.createElement('div');
                notice.className = 'alert alert-warning staff-cancel-block-notice mb-3';
                notice.innerHTML = '<i class="bi bi-exclamation-triangle"></i> <strong>職員による直前期間（当日〜14日先）の予約削除は禁止されています。</strong>新規追加のみ可能です。';
                var firstCard = root.querySelector('.card, form');
                if (firstCard && firstCard.parentNode) {
                    firstCard.parentNode.insertBefore(notice, firstCard);
                }
            }

            var selector = [
                '#reservation-form input[type="checkbox"]',
                '#change-edit-form input[type="checkbox"]',
                '#user-selection-table input[type="checkbox"]',
                '#reservationTable input[type="checkbox"]',
                'form input[type="checkbox"][name^="users["]'
            ].join(',');

            root.querySelectorAll(selector).forEach(function(cb){
                if (cb.dataset._staffGuardApplied === '1') {
                    return;
                }
                if (!isStaffTargetCheckbox(cb)) {
                    return;
                }
                var initialState = cb.checked;
                cb.dataset._initialChecked = initialState ? '1' : '0';
                cb.dataset._staffGuardApplied = '1';

                if (initialState) {
                    cb.disabled = true;
                    cb.title = '直前期間のため削除できません';

                    var container = cb.closest('tr, .form-check, .meal-checkbox-container, label');
                    if (container && !container.querySelector('.deletion-blocked-label')) {
                        var label = document.createElement('small');
                        label.className = 'text-muted deletion-blocked-label ms-2';
                        label.style.cssText = 'font-size: 0.75rem; font-style: italic;';
                        label.textContent = '（削除不可）';
                        container.appendChild(label);
                    }
                    return;
                }

                cb.addEventListener('mousedown', function(e){
                    if (cb.dataset._initialChecked === '1' && isStaffCancelProhibited(dateStr, false)) {
                        if (cb.checked) { e.preventDefault(); e.stopPropagation(); }
                    }
                });
                cb.addEventListener('keydown', function(e){
                    if ((e.key === ' ' || e.key === 'Enter')
                        && cb.dataset._initialChecked === '1'
                        && isStaffCancelProhibited(dateStr, false)) {
                        if (cb.checked) { e.preventDefault(); e.stopPropagation(); }
                    }
                });

                cb.addEventListener('change', function(ev){
                    var turningOn = cb.checked === true;
                    if (!turningOn && cb.dataset._initialChecked === '1' && isStaffCancelProhibited(dateStr, false)) {
                        cb.checked = true;
                        if (!cb.dataset._alerted) {
                            cb.dataset._alerted = '1';
                            alert('直前（当日〜14日先）は、職員による予約の取り消しはできません。');
                        }
                        ev.preventDefault(); ev.stopPropagation(); return false;
                    }
                });
            });

            [['#select-all-1',1],['#select-all-2',2],['#select-all-3',3],['#select-all-4',4]].forEach(function(pair){
                var h = root.querySelector(pair[0]); if (!h) return;
                var clone = h.cloneNode(true); h.parentNode.replaceChild(clone, h);
                clone.addEventListener('change', function(e){
                    var toOn = !!e.target.checked;
                    root.querySelectorAll('input.meal-checkbox[data-reservation-type="'+pair[1]+'"]').forEach(function(cb){
                        if (cb.disabled) return;
                        if (!toOn && cb.dataset._initialChecked === '1' && isStaffCancelProhibited(dateStr, false) && isStaffTargetCheckbox(cb)) return;
                        cb.checked = toOn;
                    });
                    if (toOn && pair[1] === 2) root.querySelector('#select-all-4') && (root.querySelector('#select-all-4').checked = false);
                    if (toOn && pair[1] === 4) root.querySelector('#select-all-2') && (root.querySelector('#select-all-2').checked = false);
                });
            });

        }catch(e){
            console.error('[enforceStaffCancelBlock] エラー:', e);
        }
    }
    window.enforceStaffCancelBlock = enforceStaffCancelBlock;
</script>
<script>
    // javascript
    (function(){
        if (!window.fetch) return;
        const originalFetch = window.fetch.bind(window);

        window.fetch = async function(input, init){
            const res = await originalFetch(input, init);
            if (res && res.status === 409) {
                // 可能ならレスポンスJSONからメッセージを抽出
                let msg = '競合が発生しました。';
                try {
                    const j = await res.clone().json().catch(()=>null);
                    if (j && (j.message || j.errors)) {
                        msg = j.message || (typeof j.errors === 'string' ? j.errors : JSON.stringify(j.errors));
                    }
                } catch(e){ /* ignore */ }

                // conflictModal があれば中身を書き換えて表示。Bootstrapがあればそれを使う
                try {
                    const modalEl = document.getElementById('conflictModal');
                    if (modalEl) {
                        const body = modalEl.querySelector('.modal-body') || modalEl.querySelector('.modal-body .alert') || modalEl;
                        if (body) {
                            // 簡易に既存の説明やアラート領域にメッセージを入れる
                            // 既存レイアウトを壊さないよう textContent を使う
                            body.textContent = String(msg);
                        }
                        if (window.bootstrap && window.bootstrap.Modal) {
                            const inst = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                            inst.show();
                        } else {
                            // 既存の openModalById ヘルパーを使う（存在すれば）
                            if (typeof openModalById === 'function') openModalById('conflictModal');
                            else {
                                modalEl.classList.add('show');
                                modalEl.style.display = 'block';
                            }
                        }
                    } else {
                        alert(msg);
                    }
                } catch(e){
                    try { alert(msg); } catch(_) {}
                }

                // 既存の呼び出し側で catch できるようエラーを投げる（レスポンスを付与）
                const err = new Error('HTTP 409 Conflict');
                err.response = res;
                throw err;
            }
            return res;
        };
    })();
    document.addEventListener('DOMContentLoaded', function() {
        const modalEl = document.getElementById('res-copy-modal');
        const form = document.getElementById('res-copy-form');
        if (!form) return;

        form.addEventListener('submit', function(e){
            e.preventDefault();
            const fd = new FormData(form);

            // チェックボックスの値を明示的に取得
            form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                fd.set(cb.name, cb.checked ? cb.value : '');
            });

            const payload = Object.fromEntries(fd.entries());
            fetch(copyApi, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(payload)
            })
                .then(response => response.json())
                .then(data => {
                    // 成功時の処理
                    console.log('コピー完了', data);
                    // 必要ならリロードやUI更新
                })
                .catch(error => {
                    // エラー時の処理
                    console.error('コピー失敗', error);
                    alert('コピーに失敗しました');
                });
        });

        // lunch と bento の排他制御
        function setupLunchBentoPair(lunchSelector, bentoSelector) {
            const lunchCbs = document.querySelectorAll(lunchSelector);
            const bentoCbs = document.querySelectorAll(bentoSelector);

            lunchCbs.forEach((lunchCb, idx) => {
                const bentoCb = bentoCbs[idx];
                if (!lunchCb || !bentoCb) return;
                if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;

                // 初期状態反映
                if (lunchCb.checked) {
                    bentoCb.disabled = true;
                    bentoCb.title = '昼食と弁当は同時に選択できません';
                } else if (bentoCb.checked) {
                    lunchCb.disabled = true;
                    lunchCb.title = '昼食と弁当は同時に選択できません';
                }

                lunchCb.addEventListener('change', function() {
                    if (lunchCb.checked) {
                        bentoCb.checked = false;
                        bentoCb.disabled = true;
                        bentoCb.title = '昼食と弁当は同時に選択できません';
                    } else {
                        bentoCb.disabled = false;
                        bentoCb.title = '';
                    }
                });

                bentoCb.addEventListener('change', function() {
                    if (bentoCb.checked) {
                        lunchCb.checked = false;
                        lunchCb.disabled = true;
                        lunchCb.title = '昼食と弁当は同時に選択できません';
                    } else {
                        lunchCb.disabled = false;
                        lunchCb.title = '';
                    }
                });

                lunchCb.dataset._paired = '1';
                bentoCb.dataset._paired = '1';
            });
        }

        // 個人予約: name="reservation[昼食]" / name="reservation[弁当]"
        setupLunchBentoPair(
            'input[type="checkbox"][name*="lunch"]',
            'input[type="checkbox"][name*="bento"]'
        );

        // 集団予約: name="users[ID][昼食]" / name="users[ID][弁当]"
        setupLunchBentoPair(
            'input[type="checkbox"][name$="[lunch]"]',
            'input[type="checkbox"][name$="[bento]"]'
        );

        // モーダル描画後に排他制御を適用
        function applyLunchBentoExclusion(scope){
            var root = scope || document;

            // 個人予約
            var lunchCbs = Array.from(root.querySelectorAll('input[type="checkbox"][name*="lunch"]'));
            var bentoCbs = Array.from(root.querySelectorAll('input[type="checkbox"][name*="bento"]'));
            lunchCbs.forEach(function(lunchCb, idx){
                var bentoCb = bentoCbs[idx];
                if (!bentoCb) return;
                if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;
                
                // 初期状態での排他適用
                if (lunchCb.checked && bentoCb.checked) {
                    bentoCb.checked = false;
                }
                
                lunchCb.addEventListener('change', function(){
                    if (lunchCb.checked && !lunchCb.disabled) {
                        if (bentoCb && !bentoCb.disabled) {
                            bentoCb.checked = false;
                            bentoCb.dispatchEvent(new Event('change'));
                        }
                    }
                });
                bentoCb.addEventListener('change', function(){
                    if (bentoCb.checked && !bentoCb.disabled) {
                        if (lunchCb && !lunchCb.disabled) {
                            lunchCb.checked = false;
                            lunchCb.dispatchEvent(new Event('change'));
                        }
                    }
                });
                lunchCb.dataset._paired = '1';
                bentoCb.dataset._paired = '1';
            });

            // 集団予約（利用者別）- users[userId][2] と users[userId][4]
            var groupRows = root.querySelectorAll('#user-checkboxes tr, tbody tr');
            groupRows.forEach(function(tr){
                var lunchCb = tr.querySelector('input[type="checkbox"][name$="[2]"]');
                var bentoCb = tr.querySelector('input[type="checkbox"][name$="[4]"]');
                if (lunchCb && bentoCb) {
                    if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;
                    
                    // 初期状態での排他適用
                    if (lunchCb.checked && bentoCb.checked) {
                        bentoCb.checked = false;
                    }
                    
                    lunchCb.addEventListener('change', function(){
                        if (lunchCb.checked && !lunchCb.disabled) {
                            if (bentoCb && !bentoCb.disabled) {
                                bentoCb.checked = false;
                                bentoCb.dispatchEvent(new Event('change'));
                            }
                        }
                    });
                    bentoCb.addEventListener('change', function(){
                        if (bentoCb.checked && !bentoCb.disabled) {
                            if (lunchCb && !lunchCb.disabled) {
                                lunchCb.checked = false;
                                lunchCb.dispatchEvent(new Event('change'));
                            }
                        }
                    });
                    lunchCb.dataset._paired = '1';
                    bentoCb.dataset._paired = '1';
                }
            });
            
            // 直前編集モーダル（change_edit.php）: data-reservation-type属性を使用
            var changeEditRows = root.querySelectorAll('#ce-tbody tr[data-user-id], tbody tr[data-user-id]');
            if (changeEditRows.length > 0) {
                console.log('[applyLunchBentoExclusion] 直前編集モーダルの排他制御を適用します。対象行数:', changeEditRows.length);
            }
            changeEditRows.forEach(function(tr){
                var lunchCb = tr.querySelector('input.meal-checkbox[data-reservation-type="2"]');
                var bentoCb = tr.querySelector('input.meal-checkbox[data-reservation-type="4"]');
                if (lunchCb && bentoCb) {
                    if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;
                    
                    // 初期状態での排他適用
                    if (lunchCb.checked && bentoCb.checked && !lunchCb.disabled && !bentoCb.disabled) {
                        bentoCb.checked = false;
                    }
                    
                    lunchCb.addEventListener('change', function(){
                        if (lunchCb.checked && !lunchCb.disabled && lunchCb.dataset.locked !== '1') {
                            if (bentoCb && !bentoCb.disabled && bentoCb.dataset.locked !== '1') {
                                bentoCb.checked = false;
                                bentoCb.dispatchEvent(new Event('change'));
                            }
                        }
                    });
                    
                    bentoCb.addEventListener('change', function(){
                        if (bentoCb.checked && !bentoCb.disabled && bentoCb.dataset.locked !== '1') {
                            if (lunchCb && !lunchCb.disabled && lunchCb.dataset.locked !== '1') {
                                lunchCb.checked = false;
                                lunchCb.dispatchEvent(new Event('change'));
                            }
                        }
                    });
                    
                    lunchCb.dataset._paired = '1';
                    bentoCb.dataset._paired = '1';
                }
            });
        }
        
        // グローバルスコープで使えるようにする
        window.applyLunchBentoExclusion = applyLunchBentoExclusion;

        // 例：add/changeEditモーダルの内容描画後
        applyLunchBentoExclusion(modalEl);
        
        // ページロード時に全体に適用
        applyLunchBentoExclusion(document);
        
        // モーダル表示時にも適用
        document.addEventListener('shown.bs.modal', function(ev) {
            var modal = ev.target;
            if (modal) {
                setTimeout(function() {
                    applyLunchBentoExclusion(modal);
                }, 100);
            }
        });
    });
</script>
</body>

</html>
