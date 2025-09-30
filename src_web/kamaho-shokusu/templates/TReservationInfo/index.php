<?php
$this->assign('title', '食数予約');
$user = $this->request->getAttribute('identity');
$isChild = ($user && (int)$user->get('i_user_level') === 1);
// 所属部屋 ID はコントローラで $userRoomId 渡し済み想定
$myReservationDates   = $myReservationDates   ?? [];
$myReservationDetails = $myReservationDetails ?? [];
$mealDataArray        = $mealDataArray        ?? [];

// 今日
$today = date('Y-m-d');
// 今日の予約情報（参考用）
$todayReservation = $myReservationDetails[$today] ?? [];
$hasTodayReservation = !empty($todayReservation) && (
                ($todayReservation['breakfast'] ?? false) ||
                ($todayReservation['lunch'] ?? false) ||
                ($todayReservation['dinner'] ?? false) ||
                ($todayReservation['bento'] ?? false)
        );
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>食数予約</title>
    <meta name="csrfToken" content="<?= h($this->request->getAttribute('csrfToken')) ?>">

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
            border:2px solid transparent;
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

        /* 日まとめ予約ボタン（ここでは未使用のため記述のみ） */
        .bulk-day-btn { border-style:dashed !important; }

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

        /* モード切替の見出し行 */
        .mode-bar {
            background:#fff;
            border:1px solid #e6f2ff;
            border-left:4px solid #0d6efd;
            border-radius:.5rem;
            padding:.5rem .75rem;
        }

        .assistant-panel { background:#fff; border:1px solid #e9ecef; border-radius:.5rem; padding:1rem; }
        .date-badge { margin:.15rem .2rem; }
        .late-select-wrap .form-select { min-width: 220px; }
    </style>
</head>
<body>
<div class="container">
    <h1 class="mt-2 mb-3"><?= $isChild ? '🍚 食数予約（中高生向け）' : '食数予約（業務）' ?></h1>

    <?php
    $mealLabels = [1=>'朝食',2=>'昼食',3=>'夕食',4=>'弁当'];
    $mealKeys   = [1=>'breakfast',2=>'lunch',3=>'dinner',4=>'bento'];
    ?>

    <?php if ($isChild): ?>
        <?php
        // 中学生向け UI 設定
        $todayDt    = new DateTimeImmutable('today');
        $day14Dt    = $todayDt->modify('+14 days');   // 当日〜14日先＝直前期間（発注済）
        $daysToShow = 31;                             // 4週間
        $todayKey   = $todayDt->format('Y-m-d');

        // URL（トグル用APIを想定）
        $toggleUrl = $this->Url->build(['controller'=>'TReservationInfo','action'=>'toggle',$userRoomId]);

        // ★ $this をクロージャで使わないように URL ヘルパを退避
        $urlHelper = $this->Url;

        // 週一括（参考：そのまま）
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

        <!-- ★ モード切替（自動 / 直前編集 / 通常予約） -->
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

        <!-- 28日分のカード（★月曜日に「週まとめ予約」ボタンを表示） -->
        <?php
        for ($i=0; $i<$daysToShow; $i++):
            $d        = $todayDt->modify("+{$i} days");
            $dateKey  = $d->format('Y-m-d');
            $wIdx     = (int)$d->format('w');
            $w        = ['日','月','火','水','木','金','土'][$wIdx];
            $isMonday = ($wIdx === 1);
            $isLastMinute = ($d >= $todayDt && $d <= $day14Dt); // 当日〜14日先
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

                    <!-- ▼ 4分割の小さなボタン（常に4列=col-3） -->
                    <div class="row g-2 mt-3">
                        <?php foreach ($kidMeals as $type => $info):
                            $mealKey = $mealKeys[$type];
                            $isMine  = (bool)($myDetail[$mealKey] ?? false);
                            $btnCap  = $isLastMinute ? ($isMine ? '変更(直前)' : '追加(直前)') : ($isMine ? '取消' : '追加'); // 視覚的な説明
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
                        <?php $selfAny = ($myDetail['breakfast']??false)||($myDetail['lunch']??false)||($myDetail['dinner']??false)||($myDetail['bento']??false); ?>
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

        <!-- 昼⇔弁当 競合モーダル（警告 + 確認） -->
        <div class="modal fade modal-warning" id="conflictModal" tabindex="-1" aria-labelledby="conflictTitle" aria-hidden="true" role="alertdialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="conflictTitle"><i class="bi bi-exclamation-octagon-fill"></i>警告：予約の競合</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="とじる"></button>
                    </div>
                    <div class="modal-body">
                        <div id="conflictBody" class="alert alert-danger mb-3"></div>
                        <div class="small text-muted">「競合先を解除して続行」を押すと、<u>競合している予約を先に取り消し</u>、その後に<strong>目的の予約</strong>を登録します。</div>
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
                        <div class="small text-muted"><i class="bi bi-info-circle"></i> 選択中の期間：</div>
                        <span class="badge rounded-pill text-bg-light" id="rangeChip"><?= date('Y-m-01') ?> 〜 <?= date('Y-m-t') ?></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- カレンダー -->
        <div id="calendar" aria-label="食数予約カレンダー（業務）"></div>

        <!-- 凡例 -->
        <div class="biz-note mt-3">
            <span class="me-3"><span class="legend-dot legend-green"></span>自分の予約あり</span>
            <span class="me-3"><span class="legend-dot legend-orange"></span>未予約（空）</span>
            <span class="me-3"><span class="legend-dot legend-red"></span>祝日</span>
            <span><span class="legend-dot legend-gray"></span>その他</span>
        </div>

    <?php endif; ?>
</div>

<?php
// 当日 昼⇔弁当 変更ガード（既存管理モーダルで使用）
$lunchReserved  = (bool)($todayReservation['lunch'] ?? false);
$lunchChangeUrl = $this->Url->build(['controller'=>'TReservationInfo','action'=>'edit',$userRoomId,$today,2]);
$bentoReserved  = (bool)($todayReservation['bento'] ?? false);
$bentoChangeUrl = $this->Url->build(['controller'=>'TReservationInfo','action'=>'edit',$userRoomId,$today,4]);

/* ======= JS に渡すための配列をここで完成させてから JSON で一括出力 ======= */

// 1) 自分の予約日（未予約表示の判定に使う）
$js_reservedDates = array_values($myReservationDates);

// 2) 既存イベント（自分の予約行 + 集計行）
$events = [];
$iconFn = function($v){ if ($v===null) return '×'; return $v ? '⚪︎' : '×'; };

// 自分の予約あり行
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

// 集計行（大人向けのみ）
if (!$isChild && !empty($mealDataArray)) {
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

// JSON を一括で
$JS_MY_DETAILS       = json_encode($myReservationDetails, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$JS_RESERVED_DATES   = json_encode($js_reservedDates, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$JS_EXISTING_EVENTS  = json_encode($events, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$JS_TODAY            = json_encode($today, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$JS_TOGGLE_URL       = json_encode($toggleUrl ?? '', JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
?>

<!-- 管理側モーダル（既存） -->
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

<!-- 直前期間の注意モーダル（警告感強化） -->
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

<!-- ライブラリ -->
<?= $this->Html->script('jquery-3.5.1.slim.min.js') ?>
<?= $this->Html->script('index.global.min.js') ?> <!-- FullCalendar -->
<?= $this->Html->script('japanese-holidays.min.js') ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>
<!-- Bootstrap 5 JS は default.php で読み込み想定 -->

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const csrfToken = document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') ?? '';
        const IS_CHILD  = <?= $isChild ? 'true' : 'false' ?>;

        // 参考（今日の状態）
        const TODAY  = <?= $JS_TODAY ?>;
        // ← 更新可能な“今日”の状態（競合チェックで使用）
        const TODAY_STATE = {
            lunch: <?= $lunchReserved ? 'true' : 'false' ?>,
            bento: <?= $bentoReserved ? 'true' : 'false' ?>,
        };

        // 自分の予約詳細（frontでトグル後に更新する）
        const MY_DETAILS = <?= $JS_MY_DETAILS ?>;

        if (IS_CHILD) {
            // APIエンドポイント（トグル）
            const TOGGLE_URL = <?= $JS_TOGGLE_URL ?>;

            // モード（auto / late / normal）
            let kidMode = document.getElementById('kidModeSelect')?.value || 'auto';

            // 表示用マップ
            const mealNamesShort = {1:'朝', 2:'昼', 3:'夜', 4:'弁'};
            const mealKeyMap     = {1:'breakfast', 2:'lunch', 3:'dinner', 4:'bento'};
            const mealJaFull     = {1:'朝食', 2:'昼食', 3:'夕食', 4:'弁当'};

            function updateModeBadge() {
                const badge = document.getElementById('kidModeBadge');
                if (!badge) return;
                const label = kidMode === 'auto' ? '自動判定'
                    : kidMode === 'late' ? '直前'
                        : '通常';
                badge.textContent = `モード：${label}`;
            }

            // ラベル（小さく）を書き替え
            function applyKidModeUI() {
                document.querySelectorAll('.kid-meal-btn').forEach(btn => {
                    const isMine = btn.dataset.isMine === '1';
                    const originalIsLast = btn.dataset.isLastMinute === '1';
                    const targetIsLast = (kidMode === 'auto') ? originalIsLast
                        : (kidMode === 'late') ? true
                            : false; // normal

                    const meal  = Number(btn.dataset.meal || 0);
                    const name  = mealNamesShort[meal] || '';

                    let cap = '';
                    if (targetIsLast) cap = isMine ? '変更(直前)' : '追加(直前)';
                    else              cap = isMine ? '取消'       : '追加';

                    btn.dataset.targetIsLast = targetIsLast ? '1' : '0';
                    const capEl = btn.querySelector('.btn-cap');
                    if (capEl) capEl.innerHTML = `${name}<small> ${cap}</small>`;
                    btn.setAttribute('aria-label', `${name}：${cap}`);
                });
                updateModeBadge();
            }

            // 期間フィルタ（auto:全部, late:直前のみ, normal:通常のみ）
            function filterCardsByMode() {
                const cards = document.querySelectorAll('.kid-card');
                cards.forEach(card => {
                    const isLast = card.dataset.isLastMinute === '1';
                    let show = true;
                    if (kidMode === 'late')   show =  isLast;
                    if (kidMode === 'normal') show = !isLast;
                    card.style.display = show ? '' : 'none';
                });
                const firstVisible = Array.from(document.querySelectorAll('.kid-card')).find(c => c.style.display !== 'none');
                if (firstVisible) firstVisible.scrollIntoView({ behavior:'smooth', block:'start' });
            }

            // 初期反映
            applyKidModeUI();
            filterCardsByMode();

            document.getElementById('kidModeSelect')?.addEventListener('change', (e) => {
                kidMode = e.target.value || 'auto';
                applyKidModeUI();
                filterCardsByMode();
            });

            // ===== ボタン見た目の更新（成功後） =====
            function setBtnReserved(btn, reserved){
                const cls = btn.classList;

                // データ属性から色クラスと中立クラスを取得（空白で分割してトークン化）
                const colorTokens   = (btn.dataset.mealClass    || 'btn-primary').split(/\s+/).filter(Boolean);
                const neutralTokens = (btn.dataset.neutralClass || 'btn-outline-secondary').split(/\s+/).filter(Boolean);

                // 念のため旧クラスも除去対象に含める（以前のUIで使っていたもの）
                const legacyTokens = ['btn-outline-light', 'border'];

                // いったん両方の集合を外す
                cls.remove(...colorTokens, ...neutralTokens, ...legacyTokens);

                // 付け直し
                if (reserved){
                    colorTokens.forEach(t => cls.add(t));
                    btn.dataset.isMine = '1';
                } else {
                    neutralTokens.forEach(t => cls.add(t));
                    btn.dataset.isMine = '0';
                }

                const meal = Number(btn.dataset.meal||0);
                const name = mealNamesShort[meal] || '';
                const targetIsLast = btn.dataset.targetIsLast === '1';
                const capEl = btn.querySelector('.btn-cap');
                if (capEl){
                    let cap = '';
                    if (targetIsLast) cap = reserved ? '変更(直前)' : '追加(直前)';
                    else              cap = reserved ? '取消'       : '追加';
                    capEl.innerHTML = `${name}<small> ${cap}</small>`;
                }
                btn.setAttribute('aria-label', `${name}：${reserved ? (targetIsLast?'変更(直前)':'取消') : (targetIsLast?'追加(直前)':'追加')}`);
            }

            function updateDayStatus(dateStr){
                const card = document.getElementById(`card-${dateStr}`);
                if (!card) return;
                const detail = MY_DETAILS[dateStr] || {};
                const any = !!(detail.breakfast || detail.lunch || detail.bento || detail.dinner);
                const ok = card.querySelector('.status-flag.ok');
                const none = card.querySelector('.status-flag.none');
                if (ok && none){
                    ok.style.display = any ? 'inline-flex' : 'none';
                    none.style.display = any ? 'none' : 'inline-flex';
                }
            }

            // その日全体を“同期更新”するヘルパ
            function refreshDayUI(dateStr){
                const esc = (s)=> (window.CSS && CSS.escape) ? CSS.escape(s) : s;
                const detail = MY_DETAILS[dateStr] || { breakfast:false, lunch:false, dinner:false, bento:false };
                // 4ボタンをまとめて再描画
                document.querySelectorAll(`.kid-meal-btn[data-date="${esc(dateStr)}"]`).forEach(btn=>{
                    const key = btn.dataset.mealKey;
                    if (!key) return;
                    setBtnReserved(btn, !!detail[key]);
                });
                // ステータス旗
                updateDayStatus(dateStr);
                // 今日なら“今日状態”も更新（次回の競合事前チェック用）
                if (dateStr === TODAY) {
                    TODAY_STATE.lunch = !!detail.lunch;
                    TODAY_STATE.bento = !!detail.bento;
                }
            }

            // ====== 競合モーダル（確認つき） & 直前モーダル ======
            function showConflict(html, onResolve){
                const body = document.getElementById('conflictBody');
                const act  = document.getElementById('conflictAction');
                const el   = document.getElementById('conflictModal');

                if (body) body.innerHTML = html || 'この操作は競合しています。';
                if (act) {
                    act.classList.remove('disabled');
                    act.setAttribute('aria-disabled','false');
                    act.onclick = (e)=>{
                        e.preventDefault();
                        if (onResolve) onResolve();
                        if (el && window.bootstrap?.Modal) {
                            window.bootstrap.Modal.getOrCreateInstance(el).hide();
                        }
                        return false;
                    };
                }
                if (el && window.bootstrap?.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(el).show();
                } else {
                    if (onResolve && confirm('競合しています。競合先を解除して続行しますか？')) onResolve();
                }
            }

            function showLateNotice(html, onAgree){
                const body = document.getElementById('lateNoticeBody');
                const agree = document.getElementById('lateAgreeCheck');
                const proceed = document.getElementById('lateProceed');
                const modalEl = document.getElementById('lateNoticeModal');

                if (body) body.innerHTML = html;

                if (agree){
                    agree.checked = false;
                    agree.onchange = () => {
                        if (agree.checked) {
                            proceed.classList.remove('disabled');
                            proceed.setAttribute('aria-disabled', 'false');
                            proceed.setAttribute('tabindex', '0');
                        } else {
                            proceed.classList.add('disabled');
                            proceed.setAttribute('aria-disabled', 'true');
                            proceed.setAttribute('tabindex', '-1');
                        }
                    };
                }

                if (proceed){
                    proceed.onclick = (e) => {
                        if (proceed.classList.contains('disabled')) { e.preventDefault(); return false; }
                        if (modalEl && window.bootstrap?.Modal) {
                            const m = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                            m.hide();
                        }
                        onAgree?.();
                        e.preventDefault();
                        return false;
                    };
                }
                if (modalEl && window.bootstrap?.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
                } else {
                    if (confirm('直前（発注済）です。続けますか？')) onAgree?.();
                }
            }

            // ====== API呼び出し（override 対応） ======
            async function callToggle(dateStr, mealNumber, wantValue, override=false){
                if (!TOGGLE_URL) throw new Error('トグルURLが未設定です。');
                if (!csrfToken)  throw new Error('CSRFトークンが取得できていません。再読み込みしてください。');

                const res = await fetch(TOGGLE_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json; charset=utf-8',
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        date:  dateStr,
                        meal:  Number(mealNumber),
                        value: wantValue ? 1 : 0,
                        override: override ? 1 : 0
                    })
                });

                const ct = res.headers.get('content-type') || '';
                const parse = async () =>
                    ct.includes('application/json') ? (await res.json())
                        : { ok:false, message: await res.text() };
                const data = await parse();

                if (res.status === 409) {
                    const err = new Error(data?.message || '昼食と弁当は同時に予約できません。');
                    err.name = 'Conflict';
                    err.details = data;
                    throw err;
                }
                if (res.status === 422) throw new Error(data?.message || '入力が不正です。');
                if (res.status === 400) throw new Error(data?.message || '不正なリクエストです。');
                if (!res.ok || !data || data.ok !== true) {
                    throw new Error(data?.message || `更新に失敗しました（${res.status}）`);
                }
                return data; // { ok:true, value, details }
            }

            // 競合ペアの相手を取得（昼↔弁当）
            function conflictPair(mealIdx){
                if (mealIdx === 2) return 4; // lunch -> bento
                if (mealIdx === 4) return 2; // bento -> lunch
                return null;
            }

            // 成功レスポンスを MY_DETAILS へ反映 & UI同期更新
            function applyDetailsAndRefresh(date, payload, btn, mealKey){
                if (payload && typeof payload.details === 'object') {
                    // detailsに正しい4食分だけが入っているか確認し、なければ既存値を維持
                    const prev = MY_DETAILS[date] || { breakfast:false, lunch:false, dinner:false, bento:false };
                    MY_DETAILS[date] = {
                        breakfast: 'breakfast' in payload.details ? payload.details.breakfast : prev.breakfast,
                        lunch:     'lunch'     in payload.details ? payload.details.lunch     : prev.lunch,
                        dinner:    'dinner'    in payload.details ? payload.details.dinner    : prev.dinner,
                        bento:     'bento'     in payload.details ? payload.details.bento     : prev.bento,
                    };
                } else {
                    const d = MY_DETAILS[date] || { breakfast:false, lunch:false, dinner:false, bento:false };
                    if (mealKey) d[mealKey] = !!(payload?.value);
                    MY_DETAILS[date] = d;
                }
                refreshDayUI(date);
            }

            // 競合時：確認して「競合先 OFF → 目的 ON」を連続実行（サーバ override 未実装でも動く）
            async function resolveConflictSequence(date, targetIdx, targetOn, btn, mealKey){
                const opponentIdx = conflictPair(targetIdx);
                if (!opponentIdx) throw new Error('競合先が特定できませんでした。');

                // 1) 競合先 OFF
                await callToggle(date, opponentIdx, /*off*/ false, /*override*/ false);
                // 2) 目的を希望状態に
                const result = await callToggle(date, targetIdx, targetOn, /*override*/ false);
                applyDetailsAndRefresh(date, result, btn, mealKey);
            }

            // ====== トグル要求（クリック） ======
            document.querySelectorAll('.kid-meal-btn').forEach(btn => {
                btn.addEventListener('click', async (ev) => {
                    ev.preventDefault();
                    const date  = btn.dataset.date;
                    const mealIdx = Number(btn.dataset.meal || 0);
                    const mealKey = btn.dataset.mealKey;  // breakfast / lunch / dinner / bento
                    if (!date || !mealIdx || !mealKey) return;

                    // 現在値を取得
                    const detail = MY_DETAILS[date] || { breakfast:false, lunch:false, dinner:false, bento:false };
                    const current = !!detail[mealKey];
                    const nextVal = !current;

                    // 昼⇔弁当の競合（追加時のみ：ここで確認を出してシーケンス実行）
                    const localConflict =
                        nextVal &&
                        ((mealKey === 'lunch'  && (detail.bento || (date === TODAY && TODAY_STATE.bento))) ||
                            (mealKey === 'bento'  && (detail.lunch || (date === TODAY && TODAY_STATE.lunch))));

                    const isLast = (btn.dataset.targetIsLast || btn.dataset.isLastMinute) === '1';

                    const doToggle = async () => {
                        try {
                            btn.disabled = true; btn.style.opacity = .65;

                            // ローカルで競合している場合：まず確認して競合解除→登録
                            if (localConflict) {
                                const labelFrom = mealIdx === 2 ? 'お弁当' : '昼ごはん';
                                const labelTo   = mealIdx === 2 ? '昼ごはん' : 'お弁当';

                                showConflict(
                                    `この日（${date}）は<strong>${labelFrom}</strong>の予約があります。<br>` +
                                    `<strong>${labelFrom}</strong>を先に<strong>取り消し</strong>てから、<strong>${labelTo}</strong>を登録してもよろしいですか？`,
                                    async () => {
                                        try {
                                            // 直前期間なら念のため同意もらう
                                            if (isLast) {
                                                showLateNotice(
                                                    `日付：<strong>${date}</strong><br>対象：<strong>${mealJaFull[mealIdx]}</strong><br><br>` +
                                                    `この期間はすでに<strong>発注済</strong>です。登録内容をよく確認してください。`,
                                                    async () => {
                                                        try {
                                                            await resolveConflictSequence(date, mealIdx, /*on*/ true, btn, mealKey);
                                                        } catch (ee) {
                                                            alert(ee?.message || '競合解消に失敗しました。');
                                                        } finally {
                                                            btn.disabled = false; btn.style.opacity = 1;
                                                        }
                                                    }
                                                );
                                            } else {
                                                await resolveConflictSequence(date, mealIdx, /*on*/ true, btn, mealKey);
                                                btn.disabled = false; btn.style.opacity = 1;
                                            }
                                        } catch (seqErr) {
                                            alert(seqErr?.message || '競合解消に失敗しました。');
                                            btn.disabled = false; btn.style.opacity = 1;
                                        }
                                    }
                                );
                                return; // モーダルで承認後に処理される
                            }

                            // 通常経路：そのまま POST
                            const json = await callToggle(date, mealIdx, nextVal);
                            applyDetailsAndRefresh(date, json, btn, mealKey);

                        } catch (e) {
                            if (e?.name === 'Conflict') {
                                // サーバ側で競合判定された場合：override を試し、無理なら手動シーケンス
                                showConflict(
                                    (e.message || '昼食と弁当は同時に予約できません。') +
                                    '<br><small class="text-muted">（競合先の予約を先にOFFしてから目的の予約をONにします）</small>',
                                    async () => {
                                        try {
                                            btn.disabled = true; btn.style.opacity = .65;
                                            // 1) まず override でサーバ任せ（実装されていれば一発）
                                            try {
                                                const over = await callToggle(date, mealIdx, nextVal, /*override*/ true);
                                                applyDetailsAndRefresh(date, over, btn, mealKey);
                                            } catch (ovErr) {
                                                // 2) override が未実装/失敗なら手動シーケンス
                                                await resolveConflictSequence(date, mealIdx, nextVal, btn, mealKey);
                                            }
                                        } catch (ee) {
                                            alert(ee?.message || '競合解消に失敗しました。');
                                        } finally {
                                            btn.disabled = false; btn.style.opacity = 1;
                                        }
                                    }
                                );
                            } else {
                                alert(e?.message || '予約の更新に失敗しました');
                            }
                        } finally {
                            // 通常経路の終了ハンドリング（モーダル経路では個別 finally 済）
                            if (!localConflict) { btn.disabled = false; btn.style.opacity = 1; }
                        }
                    };

                    if (isLast) {
                        const bodyHtml = `日付：<strong>${date}</strong><br>対象：<strong>${mealJaFull[mealIdx]}</strong><br><br>` +
                            `この期間はすでに<strong>発注済</strong>です。${nextVal ? '追加' : 'キャンセル'}してよいか、内容をよく確認してください。`;
                        showLateNotice(bodyHtml, doToggle);
                    } else {
                        // 即時トグル
                        doToggle();
                    }
                }, false);
            });

            // 週まとめ予約ボタン
            document.querySelectorAll('.week-bulk-link').forEach(link => {
                link.addEventListener('click', (ev) => {
                    if (link.classList.contains('disabled')) {
                        ev.preventDefault(); return;
                    }
                    const label = link.dataset.weekLabel || '';
                    if (!confirm(`「${label}」の週まとめ予約ページを開きます。よろしいですか？`)) {
                        ev.preventDefault();
                    }
                }, false);
            });

        } else {
            /* ==================== 大人向け（業務システム調） ==================== */
            const reservedDates  = <?= $JS_RESERVED_DATES ?>;
            const existingEvents = <?= $JS_EXISTING_EVENTS ?>;

            const calendarEl    = document.getElementById('calendar');
            const fromDateInput = document.getElementById('fromDate');
            const toDateInput   = document.getElementById('toDate');

            function formatYmd(d){
                const y=d.getFullYear(), m=String(d.getMonth()+1).padStart(2,'0'), dd=String(d.getDate()).padStart(2,'0');
                return `${y}-${m}-${dd}`;
            }
            function updateInputsByCalendar(view){
                if(!fromDateInput || !toDateInput) return;
                const start=view.currentStart;
                const end=new Date(view.currentEnd); end.setDate(end.getDate()-1);
                fromDateInput.value = formatYmd(start);
                toDateInput.value   = formatYmd(end);
                const chip = document.getElementById('rangeChip');
                if (chip) chip.textContent = `${fromDateInput.value} 〜 ${toDateInput.value}`;
            }
            const defaultDate = (()=>{ const d=new Date(); d.setDate(d.getDate()+14); return d; })();

            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialDate: defaultDate,
                initialView: 'dayGridMonth',
                locale: 'ja',
                firstDay: 1,
                height: 'auto',
                contentHeight: 'auto',
                expandRows: true,
                aspectRatio: 1.35,
                customButtons: { nextMonth:{ text:'次月', click:()=>calendar.next() } },
                headerToolbar: { right:'prev,today,nextMonth,next', center:'' },
                buttonText: { today:'今日' },
                datesSet: (arg)=>updateInputsByCalendar(arg.view),

                events: (fetchInfo, successCallback)=>{
                    const holidayEvents=[];
                    for(let y=fetchInfo.start.getFullYear(); y<=fetchInfo.end.getFullYear(); y++){
                        const holidays = JapaneseHolidays.getHolidaysOf(y) ?? [];
                        holidays.forEach(h=>{
                            holidayEvents.push({
                                title: h.name,
                                start: `${y}-${String(h.month).padStart(2,'0')}-${String(h.date).padStart(2,'0')}`,
                                allDay: true,
                                backgroundColor:'#dc3545', borderColor:'#dc3545', textColor:'white',
                                extendedProps:{displayOrder:0}
                            });
                        });
                    }
                    const unreservedEvents=[];
                    const cur=new Date(fetchInfo.start);
                    while(cur < fetchInfo.end){
                        const dateStr = cur.toISOString().slice(0,10);
                        if(!reservedDates.includes(dateStr)){
                            unreservedEvents.push({
                                title:'未予約', start:dateStr, allDay:true,
                                backgroundColor:'#fd7e14', borderColor:'#fd7e14', textColor:'white',
                                extendedProps:{displayOrder:-10}
                            });
                        }
                        cur.setDate(cur.getDate()+1);
                    }
                    successCallback([...existingEvents, ...holidayEvents, ...unreservedEvents]);
                },

                eventOrder: (a,b)=>{
                    const A = Number(a.extendedProps?.displayOrder ?? 0);
                    const B = Number(b.extendedProps?.displayOrder ?? 0);
                    return (isNaN(A)?0:A) - (isNaN(B)?0:B);
                },

                dateClick: info=>{
                    const clickedDate = new Date(info.dateStr);
                    const today = new Date(); today.setHours(0,0,0,0);
                    const diffDays = (clickedDate - today)/86400000;
                    const isMonday = clickedDate.getDay()===1;
                    const within14 = diffDays>=0 && diffDays<=14;

                    if (isMonday && !within14) {
                        if (confirm('週の一括予約フォームを開きます。よろしいですか？')) {
                            window.location.href = '<?= $this->Url->build("/TReservationInfo/bulkAddForm") ?>?date=' + info.dateStr;
                        } else {
                            window.location.href = '<?= $this->Url->build("/TReservationInfo/view") ?>?date=' + info.dateStr;
                        }
                        return;
                    }
                    window.location.href = '<?= $this->Url->build("/TReservationInfo/view") ?>?date=' + info.dateStr;
                }
            });

            calendar.render();
            fromDateInput?.addEventListener('change', ()=>{ if(fromDateInput?.value) calendar.gotoDate(fromDateInput.value); });

            // ======== エクスポートUI（既存のまま） ========
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
                            const day = d.getDay(); // 0:日
                            const mon = new Date(d); mon.setDate(d.getDate() - ((day + 6) % 7)); // 月
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
                                {key:'total_eaters',     header:'合計'},
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
        }
    });
</script>
</body>
</html>
