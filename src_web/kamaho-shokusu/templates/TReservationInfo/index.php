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
        .kid-card .h5{font-size:1.15rem;}
        .kid-meal-btn{font-size:1.1rem; padding-top:.9rem; padding-bottom:.9rem;}
        .kid-chip{font-size:.95rem;}
        .kid-head { background:#f5fbff; border:1px solid #e6f2ff; border-radius:.5rem; padding:.75rem 1rem;}
        .kid-help li{margin:.25rem 0;}
        .kid-badge-soft { font-weight:600; }

        /* 予約状態の強調表示 */
        .status-flag {
            display:inline-flex;
            align-items:center;
            gap:.4rem;
            font-weight:700;
            font-size:.95rem;
            padding:.35rem .6rem;
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

        /* 日まとめ予約のボタン */
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
        .modal-warning .modal-title i {
            margin-right:.4rem;
        }
        .modal-warning .modal-body .alert {
            margin-bottom:0;
        }
        .modal-warning .btn-primary {
            background:#dc3545;
            border-color:#dc3545;
        }
        .modal-warning .btn-primary:disabled,
        .modal-warning .btn-primary.disabled {
            background:#dc3545;
            border-color:#dc3545;
            opacity:.65;
        }
        .modal-warning .form-check-label strong {
            text-decoration: underline;
        }

        /* モード切替の見出し行 */
        .mode-bar {
            background:#fff;
            border:1px solid #e6f2ff;
            border-left:4px solid #0d6efd;
            border-radius:.5rem;
            padding:.5rem .75rem;
        }

        /* 直前/通常の補助表示パネル */
        .assistant-panel {
            background:#fff;
            border:1px solid #e9ecef;
            border-radius:.5rem;
            padding:1rem;
        }
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
        $daysToShow = 28;                             // 4週間
        $todayKey   = $todayDt->format('Y-m-d');

        // URLヘルパ
        $urlHelper = $this->Url;
        $buildEditUrl = function(string $date, int $mealType) use ($userRoomId, $urlHelper){
            return $urlHelper->build([
                    'controller'=>'TReservationInfo',
                    'action'    =>'edit',
                    $userRoomId, $date, $mealType
            ]);
        };
        // add（1日まとめ入力）はクエリで date を渡す
        $buildAddUrl = function(string $date) use ($userRoomId, $urlHelper){
            $base = $urlHelper->build(['controller'=>'TReservationInfo','action'=>'add',$userRoomId]);
            return $base . '?' . http_build_query(['date' => $date]);
        };
        // 週一括：?date=月曜日（大人と共通のエンドポイント想定）
        $buildBulkUrl = function(string $mondayYmd) use ($urlHelper){
            return $urlHelper->build('/TReservationInfo/bulkAddForm') . '?date=' . rawurlencode($mondayYmd);
        };

        $kidMeals = [
                1 => ['text'=>'朝ごはん', 'class'=>'btn-success',           'emoji'=>'☀️'],
                2 => ['text'=>'昼ごはん', 'class'=>'btn-warning text-dark', 'emoji'=>'🌞'],
                3 => ['text'=>'夜ごはん', 'class'=>'btn-primary',           'emoji'=>'🌙'],
                4 => ['text'=>'お弁当',   'class'=>'btn-danger',            'emoji'=>'🍱'],
        ];

        // 直前編集（0〜14日先）用セレクトに出す日付配列
        $lateDates = [];
        for ($i=0; $i<=14; $i++) {
            $d = $todayDt->modify("+{$i} days");
            $lateDates[] = [
                    'ymd'  => $d->format('Y-m-d'),
                    'w'    => ['日','月','火','水','木','金','土'][(int)$d->format('w')],
            ];
        }
        // 通常予約（15日目以降〜表示期間内）の日付配列
        $normalDates = [];
        for ($i=15; $i<$daysToShow; $i++) {
            $d = $todayDt->modify("+{$i} days");
            $normalDates[] = [
                    'ymd'  => $d->format('Y-m-d'),
                    'w'    => ['日','月','火','水','木','金','土'][(int)$d->format('w')],
            ];
        }
        ?>

        <!-- ★ モード切替（自動 / 直前編集 / 通常予約） -->
        <div class="mode-bar d-flex align-items-center justify-content-between mb-3">
            <div class="small text-muted">
                <i class="bi bi-sliders"></i>
                モードを切り替えると、ボタン押下時の遷移先を切り替えられます。ページ遷移は行わず、<u>この画面上の表示のみ切替</u>します。
            </div>
            <div class="d-flex align-items-center gap-2">
                <span id="kidModeBadge" class="badge text-bg-light">モード：自動判定</span>
                <label for="kidModeSelect" class="form-label m-0 small fw-bold">モード</label>
                <select id="kidModeSelect" class="form-select form-select-sm" style="max-width: 220px;">
                    <option value="auto" selected>自動（日付に応じて判定）</option>
                    <option value="late">直前編集モード（常に編集）</option>
                    <option value="normal">通常予約モード（追加優先）</option>
                </select>
            </div>
        </div>
        <!-- ★ 直前/通常の補助表示（index 上で確認できるように） -->

        <!-- ヘッダー（要点のみ） -->
        <div class="kid-head mb-3">
            <div class="fw-bold mb-1">📌 使い方のポイント</div>
            <ul class="kid-help mb-0 ps-3">
                <li>⏰ <strong>きょう〜14日先</strong>は <strong>変更・追加OK</strong>（ただし<strong>発注済</strong>なので注意モーダルが出ます）</li>
                <li>🗓️ <strong>15日目以降</strong>は <strong>新規登録OK</strong>（<u>add</u>ページで朝/昼/夜/弁当をまとめて入力）</li>
                <li>🧰 <strong>月曜日</strong>は <span class="week-ribbon">週まとめ予約</span> ボタンが出ます（15日目以降の週のみ有効）</li>
            </ul>
        </div>

        <!-- きょうの状況 -->
        <div class="reservation-status my-3 text-center">
            <?php if ($hasTodayReservation): ?>
                <div class="alert alert-success py-3">
                    <div class="fw-bold" style="font-size:1.15rem;">📆 きょう（<?= h($todayKey) ?>）：予約あり</div>
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
                    <div class="fw-bold" style="font-size:1.15rem;">📆 きょう（<?= h($todayKey) ?>）：予約なし</div>
                    <div class="mt-1 small">直前（きょう〜14日先）でも<strong>変更・追加OK</strong>ですが、<strong>発注済</strong>です。</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- 28日分のカード（★月曜日に「週まとめ予約」ボタンを表示） -->
        <?php for ($i=0; $i<$daysToShow; $i++):
            $d        = $todayDt->modify("+{$i} days");
            $dateKey  = $d->format('Y-m-d');
            $wIdx     = (int)$d->format('w');
            $w        = ['日','月','火','水','木','金','土'][$wIdx];
            $isMonday = ($wIdx === 1);
            $isLastMinute = ($d >= $todayDt && $d <= $day14Dt); // 当日〜14日先：直前（発注済）
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
                                <span class="badge bg-warning text-dark ms-2 kid-badge-soft">直前（発注済／変更・追加OK）</span>
                            <?php else: ?>
                                <span class="badge bg-success ms-2 kid-badge-soft">新規登録OK（1日まとめて追加）</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($isMonday): ?>
                            <div>
                                <?php if ($isLastMinute): ?>
                                    <a href="javascript:void(0)"
                                       class="btn btn-outline-secondary btn-sm week-bulk-link disabled"
                                       aria-disabled="true"
                                       tabindex="-1"
                                       title="直前（きょう〜14日先）は週まとめは使えません">
                                        <i class="bi bi-calendar-week"></i>
                                        週まとめ予約（<?= h($weekLabel) ?>）
                                    </a>
                                <?php else: ?>
                                    <a href="<?= h($bulkUrl) ?>"
                                       class="btn btn-outline-primary btn-sm week-bulk-link"
                                       data-week-start="<?= h($dateKey) ?>"
                                       data-week-label="<?= h($weekLabel) ?>">
                                        <i class="bi bi-calendar-week"></i>
                                        週まとめ予約（<?= h($weekLabel) ?>）
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="row g-2 mt-3">
                        <?php foreach ($kidMeals as $type => $info):
                            $mealKey = $mealKeys[$type];
                            $isMine  = (bool)($myDetail[$mealKey] ?? false);

                            if ($isLastMinute) {
                                $href = $buildEditUrl($dateKey, $type); // 直前は常に edit
                                $btnText = $isMine
                                        ? "{$info['emoji']} {$info['text']}：変更する 🔁（直前）"
                                        : "{$info['emoji']} {$info['text']}：追加する 🆕（直前）";
                            } else {
                                $href = $isMine ? $buildEditUrl($dateKey, $type) : $buildAddUrl($dateKey);
                                $btnText = $isMine
                                        ? "{$info['emoji']} {$info['text']}：変更する 🔁"
                                        : "{$info['emoji']} {$info['text']}：まとめページで追加 🆕";
                            }
                            ?>
                            <div class="col-12 col-md-6 col-lg-3">
                                <a
                                        href="<?= $href ?>"
                                        class="btn kid-meal-btn w-100 <?= $info['class'] ?> <?= $isMine ? '' : 'btn-outline-light border' ?>"
                                        data-date="<?= h($dateKey) ?>"
                                        data-meal="<?= (int)$type ?>"
                                        data-has-lunch="<?= $hasLunchForDate ? '1' : '0' ?>"
                                        data-has-bento="<?= $hasBentoForDate ? '1' : '0' ?>"
                                        data-is-last-minute="<?= $isLastMinute ? '1' : '0' ?>"
                                        data-is-mine="<?= $isMine ? '1' : '0' ?>"
                                ><?= h($btnText) ?></a>
                                <div class="mt-2">
                                    <?php if ($isMine): ?>
                                        <span class="status-flag ok"><i class="bi bi-check-circle-fill"></i>現在：予約あり</span>
                                    <?php else: ?>
                                        <span class="status-flag none"><i class="bi bi-dash-circle"></i>現在：未予約</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!$isLastMinute): ?>
                        <div class="mt-3">
                            <a href="<?= h($buildAddUrl($dateKey)) ?>"
                               class="btn btn-outline-primary w-100 bulk-day-btn"
                               data-date="<?= h($dateKey) ?>">
                                <i class="bi bi-ui-checks-grid"></i> この日をまとめて予約（朝・昼・夜・弁当）
                            </a>
                        </div>
                    <?php else: ?>
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
                            <li>15日目以降：<strong>新規登録OK</strong>（add ページで1日まとめて追加）</li>
                            <li>昼と弁当は同時に予約しないように注意</li>
                            <li><strong>月曜日の「週まとめ予約」</strong>は15日目以降の週で利用できます</li>
                        </ul>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                    </div>
                </div></div>
        </div>

        <!-- 昼⇔弁当 競合モーダル（警告） -->
        <div class="modal fade modal-warning" id="conflictModal" tabindex="-1" aria-labelledby="conflictTitle" aria-hidden="true" role="alertdialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="conflictTitle"><i class="bi bi-exclamation-octagon-fill"></i>警告：予約の競合</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="とじる"></button>
                    </div>
                    <div class="modal-body">
                        <div id="conflictBody" class="alert alert-danger mb-0"></div>
                    </div>
                    <div class="modal-footer">
                        <a id="conflictAction" href="#" class="btn btn-primary">先に別の予約を変更</a>
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

                        <!-- プリセット -->
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
        const TODAY  = '<?= h($today) ?>';
        const LUNCH_RESERVED_TODAY = <?= $lunchReserved ? 'true' : 'false' ?>;
        const BENTO_RESERVED_TODAY = <?= $bentoReserved ? 'true' : 'false' ?>;

        // PHP 側の自分の予約詳細を JS に渡す（直前/通常選択の描画に使用）
        const MY_DETAILS = <?= json_encode($myReservationDetails, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

        if (IS_CHILD) {
            // ▼ 直前/通常の強制切替に使うベースURL（クリック時にのみ使用）
            const EDIT_BASE = '<?= h($this->Url->build(["controller"=>"TReservationInfo","action"=>"edit",$userRoomId,])) ?>';
            const ADD_BASE  = '<?= h($this->Url->build(["controller"=>"TReservationInfo","action"=>"add",$userRoomId])) ?>';

            // モード（auto / late / normal）
            let kidMode = document.getElementById('kidModeSelect')?.value || 'auto';

            // 表示だけを切り替える（href はクリック時に決定）
            const mealNames  = {1:'朝ごはん', 2:'昼ごはん', 3:'夜ごはん', 4:'お弁当'};
            const mealEmojis = {1:'☀️',      2:'🌞',      3:'🌙',      4:'🍱'};

            function updateModeBadge() {
                const badge = document.getElementById('kidModeBadge');
                if (!badge) return;
                const label = kidMode === 'auto' ? '自動判定'
                    : kidMode === 'late' ? '直前編集'
                        : '通常予約';
                badge.textContent = `モード：${label}`;
            }

            function applyKidModeUI() {
                document.querySelectorAll('.kid-meal-btn').forEach(btn => {
                    const date  = btn.dataset.date;
                    const meal  = Number(btn.dataset.meal || 0);
                    const isMine = btn.dataset.isMine === '1';
                    const originalIsLast = btn.dataset.isLastMinute === '1';

                    const targetIsLast = (kidMode === 'auto') ? originalIsLast
                        : (kidMode === 'late') ? true
                            : false; // normal

                    const emoji = mealEmojis[meal] || '';
                    const name  = mealNames[meal]  || '';

                    let label = '';
                    if (targetIsLast) {
                        label = isMine
                            ? `${emoji} ${name}：変更する 🔁（直前）`
                            : `${emoji} ${name}：追加する 🆕（直前）`;
                    } else {
                        label = isMine
                            ? `${emoji} ${name}：変更する 🔁`
                            : `${emoji} ${name}：まとめページで追加 🆕`;
                    }

                    btn.textContent = label;
                    btn.setAttribute('aria-label', label);
                    btn.dataset.targetIsLast = targetIsLast ? '1' : '0';
                });

                updateModeBadge();
            }

            // === 期間フィルタ（auto:全部, late:直前のみ, normal:通常のみ） ===
            function filterCardsByMode() {
                const cards = document.querySelectorAll('.kid-card');
                const latePanel  = document.getElementById('latePanel');
                const normalPanel= document.getElementById('normalPanel');

                cards.forEach(card => {
                    const isLast = card.dataset.isLastMinute === '1';
                    let show = true;
                    if (kidMode === 'late')   show =  isLast;     // 直前のみ
                    if (kidMode === 'normal') show = !isLast;     // 通常のみ
                    card.style.display = show ? '' : 'none';
                });

                // 補助パネルの表示切替
                if (kidMode === 'late') {
                    latePanel?.classList.remove('d-none');
                    normalPanel?.classList.add('d-none');
                } else if (kidMode === 'normal') {
                    normalPanel?.classList.remove('d-none');
                    latePanel?.classList.add('d-none');
                } else {
                    // auto は従来通り両方表示
                    normalPanel?.classList.remove('d-none');
                    latePanel?.classList.remove('d-none');
                }

                // 先頭可視カードへスクロール（視認性）
                const firstVisible = Array.from(document.querySelectorAll('.kid-card'))
                    .find(c => c.style.display !== 'none');
                if (firstVisible) firstVisible.scrollIntoView({ behavior:'smooth', block:'start' });
            }

            // 初期反映
            applyKidModeUI();
            filterCardsByMode();

            // ▼ モード選択時：ページ遷移せず、その場で表示更新＆フィルタ
            document.getElementById('kidModeSelect')?.addEventListener('change', (e) => {
                kidMode = e.target.value || 'auto';
                applyKidModeUI();
                filterCardsByMode();
                // モード切替時、右ペインの選択日を先頭に
                if (kidMode === 'late') {
                    const sel = document.getElementById('lateDateSelect');
                    if (sel) {
                        renderLateInfo(sel.value);
                        const card = document.getElementById(`card-${sel.value}`);
                        if (card && card.style.display !== 'none') {
                            card.scrollIntoView({ behavior:'smooth', block:'start' });
                        }
                    }
                } else if (kidMode === 'normal') {
                    const sel = document.getElementById('normalDateSelect');
                    if (sel) {
                        renderNormalInfo(sel.value);
                        const card = document.getElementById(`card-${sel.value}`);
                        if (card && card.style.display !== 'none') {
                            card.scrollIntoView({ behavior:'smooth', block:'start' });
                        }
                    }
                }
            });

            // ▼ 直前編集セレクト：選択した日付の情報を index 上に表示
            const lateSelect = document.getElementById('lateDateSelect');
            const lateInfo   = document.getElementById('lateDateInfo');

            function renderLateInfo(dateStr){
                if(!lateInfo) return;
                // 曜日算出
                const d = new Date(dateStr + 'T00:00:00');
                const w = ['日','月','火','水','木','金','土'][d.getDay()];
                const detail = (MY_DETAILS && MY_DETAILS[dateStr]) ? MY_DETAILS[dateStr] : {};
                const flag = (k)=> (detail && detail[k]) ? 'success' : 'secondary';
                const mark = (k)=> (detail && detail[k]) ? '○' : '－';

                const html = `
                    <div class="alert alert-danger">
                        <div class="fw-bold mb-2">選択中：${dateStr}（${w}） — 直前（発注済）</div>
                        <div>
                            <span class="badge kid-chip bg-${flag('breakfast')} mx-1">☀️ 朝：${mark('breakfast')}</span>
                            <span class="badge kid-chip bg-${flag('lunch')} mx-1">🌞 昼：${mark('lunch')}</span>
                            <span class="badge kid-chip bg-${flag('dinner')} mx-1">🌙 夜：${mark('dinner')}</span>
                            <span class="badge kid-chip bg-${flag('bento')} mx-1">🍱 弁当：${mark('bento')}</span>
                        </div>
                        <div class="small mt-2">※この期間は<strong>発注済</strong>です。変更・追加の前に内容をよく確認してください。</div>
                    </div>
                `;
                lateInfo.innerHTML = html;

                // 直前モード中は、選択日にスクロール
                if (kidMode === 'late') {
                    const card = document.getElementById(`card-${dateStr}`);
                    if (card && card.style.display !== 'none') {
                        card.scrollIntoView({ behavior:'smooth', block:'start' });
                    }
                }
            }

            if (lateSelect) {
                renderLateInfo(lateSelect.value);
                lateSelect.addEventListener('change', ()=> renderLateInfo(lateSelect.value));
            }

            // ▼ 通常予約セレクト：選択日の情報＋add への導線、さらに表示先頭に
            const normalSelect = document.getElementById('normalDateSelect');
            const normalInfo   = document.getElementById('normalDateInfo');

            function renderNormalInfo(dateStr){
                if(!normalInfo || !dateStr) return;
                const d = new Date(dateStr + 'T00:00:00');
                const w = ['日','月','火','水','木','金','土'][d.getDay()];
                const detail = (MY_DETAILS && MY_DETAILS[dateStr]) ? MY_DETAILS[dateStr] : {};
                const flag = (k)=> (detail && detail[k]) ? 'success' : 'secondary';
                const mark = (k)=> (detail && detail[k]) ? '○' : '－';

                const addHref  = ADD_BASE + `?date=${encodeURIComponent(dateStr)}`;
                const html = `
                    <div class="alert alert-success">
                        <div class="fw-bold mb-2">選択中：${dateStr}（${w}） — 通常予約（新規登録OK）</div>
                        <div>
                            <span class="badge kid-chip bg-${flag('breakfast')} mx-1">☀️ 朝：${mark('breakfast')}</span>
                            <span class="badge kid-chip bg-${flag('lunch')} mx-1">🌞 昼：${mark('lunch')}</span>
                            <span class="badge kid-chip bg-${flag('dinner')} mx-1">🌙 夜：${mark('dinner')}</span>
                            <span class="badge kid-chip bg-${flag('bento')} mx-1">🍱 弁当：${mark('bento')}</span>
                        </div>
                        <div class="mt-2 d-grid">
                            <a class="btn btn-outline-primary" href="${addHref}">
                                <i class="bi bi-ui-checks-grid"></i> この日をまとめて予約（add）
                            </a>
                        </div>
                    </div>
                `;
                normalInfo.innerHTML = html;

                // 通常モード中は、選択日にスクロール
                if (kidMode === 'normal') {
                    const card = document.getElementById(`card-${dateStr}`);
                    if (card && card.style.display !== 'none') {
                        card.scrollIntoView({ behavior:'smooth', block:'start' });
                    }
                }
            }

            if (normalSelect) {
                renderNormalInfo(normalSelect.value);
                normalSelect.addEventListener('change', ()=> renderNormalInfo(normalSelect.value));
            }

            // ▼ 子ども用：各ボタンクリック（遷移はクリック時のみ）
            document.querySelectorAll('.kid-meal-btn').forEach(btn => {
                btn.addEventListener('click', (ev) => {
                    const date  = btn.dataset.date;
                    const meal  = Number(btn.dataset.meal || 0);
                    const isMine = btn.dataset.isMine === '1';
                    const targetIsLast = btn.dataset.targetIsLast === '1';
                    const origHref   = btn.getAttribute('href') || '#';

                    // 同日の「昼⇔弁当」重複回避
                    const hasLunch = btn.dataset.hasLunch === '1';
                    const hasBento = btn.dataset.hasBento === '1';
                    if (meal === 4 && (hasLunch || (date === TODAY && LUNCH_RESERVED_TODAY))) {
                        ev.preventDefault();
                        showConflict(
                            `この日（${date}）は「昼ごはん」の予約があります。<br>「お弁当」を変更/追加する前に、昼の予約を調整してください。`,
                            EDIT_BASE + `/${date}/2`
                        );
                        return;
                    }
                    if (meal === 2 && (hasBento || (date === TODAY && BENTO_RESERVED_TODAY))) {
                        ev.preventDefault();
                        showConflict(
                            `この日（${date}）は「お弁当」の予約があります。<br>「昼ごはん」を変更/追加する前に、弁当の予約を調整してください。`,
                            EDIT_BASE + `/${date}/4`
                        );
                        return;
                    }

                    // 遷移先を決定
                    let nextHref;
                    if (kidMode === 'auto') {
                        nextHref = origHref;
                    } else if (targetIsLast) {
                        nextHref = EDIT_BASE + `/${date}/${meal}`;
                    } else {
                        nextHref = isMine
                            ? (EDIT_BASE + `/${date}/${meal}`)
                            : (ADD_BASE + `?date=${encodeURIComponent(date)}`);
                    }

                    // 直前扱いなら注意モーダル
                    if (targetIsLast) {
                        ev.preventDefault();
                        const map = {1:'朝食',2:'昼食',3:'夕食',4:'弁当'};
                        const actionText = isMine ? '変更' : (kidMode==='late' ? '変更' : '追加');
                        const bodyHtml = `日付：<strong>${date}</strong><br>対象：<strong>${map[meal] || ''}</strong><br><br><span class="fw-bold">この期間はすでに<strong>発注済</strong>です。</span><br>${actionText}してよいか、内容をよく確認してください。`;
                        showLateNotice(bodyHtml, nextHref);
                        return;
                    }

                    // 通常確認
                    ev.preventDefault();
                    const goingToAdd = nextHref.includes('/add/') || nextHref.includes('?date=');
                    const msg = goingToAdd
                        ? `日付：${date}\n1日まとめて追加ページ（add）を開きます。よろしいですか？`
                        : `日付：${date}\n編集ページを開きます。よろしいですか？`;
                    if (confirm(msg)) window.location.href = nextHref;
                }, false);
            });

            // 週まとめ予約ボタン
            document.querySelectorAll('.week-bulk-link').forEach(link => {
                link.addEventListener('click', (ev) => {
                    if (link.classList.contains('disabled')) {
                        ev.preventDefault();
                        return;
                    }
                    const label = link.dataset.weekLabel || '';
                    if (!confirm(`「${label}」の週まとめ予約ページを開きます。よろしいですか？`)) {
                        ev.preventDefault();
                    }
                }, false);
            });

            function showConflict(html, actionHref){
                const body = document.getElementById('conflictBody');
                const act  = document.getElementById('conflictAction');
                if (body) body.innerHTML = html;
                if (act)  act.setAttribute('href', actionHref);
                const el = document.getElementById('conflictModal');
                if (el && window.bootstrap?.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(el).show();
                } else {
                    alert('先に反対の予約を調整してください。');
                }
            }

            // 直前注意モーダル（警告感強化）
            function showLateNotice(html, href){
                const body = document.getElementById('lateNoticeBody');
                const proceed = document.getElementById('lateProceed');
                const agree = document.getElementById('lateAgreeCheck');
                const modalEl = document.getElementById('lateNoticeModal');

                if (body) body.innerHTML = html;

                if (proceed) {
                    proceed.classList.add('disabled');
                    proceed.setAttribute('aria-disabled', 'true');
                    proceed.setAttribute('tabindex', '-1');
                    proceed.setAttribute('href', href || '#');

                    proceed.onclick = (e) => {
                        if (proceed.classList.contains('disabled')) {
                            e.preventDefault();
                            return false;
                        }
                        if (modalEl && window.bootstrap?.Modal) {
                            const m = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                            m.hide();
                            setTimeout(() => { window.location.href = proceed.getAttribute('href') || '#'; }, 120);
                            e.preventDefault();
                            return false;
                        }
                        return true;
                    };
                }

                if (agree) {
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

                if (modalEl && window.bootstrap?.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
                } else {
                    const ok = confirm('直前（発注済）です。内容をよく確認してください。続けますか？');
                    if (ok && href) window.location.href = href;
                }
            }

        } else {
            /* ==================== 大人向け（業務システム調） ==================== */
            const reservedDates = [
                <?php foreach ($myReservationDates as $reservedDate): ?>
                '<?= h($reservedDate) ?>',
                <?php endforeach; ?>
            ];

            <?php
            $icon = static function ($v) { if ($v===null) return '×'; return $v ? '⚪︎' : '×'; };
            ?>
            const existingEvents = [
                <?php foreach ($myReservationDates as $reservedDate): ?>
                <?php
                $detail = $myReservationDetails[$reservedDate] ?? [];
                $title = sprintf('朝:%s 昼:%s 夜:%s 弁当:%s',
                        $icon($detail['breakfast'] ?? null),
                        $icon($detail['lunch']     ?? null),
                        $icon($detail['dinner']    ?? null),
                        $icon($detail['bento']     ?? null)
                );
                ?>
                {
                    title: '<?= h($title) ?>',
                    start: '<?= h($reservedDate) ?>',
                    allDay: true,
                    backgroundColor: '#28a745',
                    borderColor: '#28a745',
                    textColor: 'white',
                    extendedProps: { displayOrder: -2 }
                },
                <?php endforeach; ?>

                <?php if (!empty($mealDataArray)): ?>
                <?php
                $mealTypes = ['1'=>'朝','2'=>'昼','3'=>'夜','4'=>'弁当'];
                $selfKeys  = ['1'=>'breakfast','2'=>'lunch','3'=>'dinner','4'=>'bento'];
                foreach ($mealDataArray as $date => $meals):
                foreach ($mealTypes as $type => $name):
                if (isset($meals[$type]) && $meals[$type] > 0):
                if ($isChild) {
                    $selfKey = $selfKeys[$type] ?? null;
                    $selfMark = $selfKey ? $icon(($myReservationDetails[$date][$selfKey] ?? null)) : '×';
                    $userName = $user ? $user->get('c_user_name') : '';
                    $titleForType = "{$name}: {$selfMark} {$userName}";
                    $bgColor = ($selfMark === '⚪︎') ? '#28a745' : '#fd7e14';
                } else {
                    $titleForType = "{$name}: {$meals[$type]}人";
                    $bgColor = null;
                }
                ?>
                {
                    title: '<?= h($titleForType) ?>',
                    start: '<?= $date ?>',
                    allDay: true,
                    extendedProps: { displayOrder: <?= (int)$type ?> }<?php if ($isChild): ?>,
                    backgroundColor: '<?= $bgColor ?>',
                    borderColor: '<?= $bgColor ?>',
                    textColor: 'white'<?php endif; ?>
                },
                <?php
                endif; endforeach; endforeach;
                endif;
                ?>
            ];

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
                // チップ更新（管理者カードが表示されている場合）
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

            // ======== エクスポートUI（統合版） ========
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
                            // 予定表
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
                            // 実施表
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
