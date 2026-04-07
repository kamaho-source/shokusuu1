<?php
/** @noinspection PhpUndefinedVariableInspection */
/** @var array<int|string,string> $authorizedRooms */
/** @noinspection PhpUndefinedVariableInspection */
/** @var int|string $currentRoomId */
/** @noinspection PhpUndefinedVariableInspection */
/** @var string $toggleBase */
/** @noinspection PhpUndefinedVariableInspection */
/** @var bool $isChild */
/** @noinspection PhpUndefinedVariableInspection */
/** @var bool $hasTodayReservation */
/** @noinspection PhpUndefinedVariableInspection */
/** @var array<string,mixed> $todayReservation */
/** @noinspection PhpUndefinedVariableInspection */
/** @var array<string,mixed> $myReservationDetails */
/** @noinspection PhpUndefinedVariableInspection */
/** @var array<int,string> $mealKeys */

// === 子供用UI: 部屋選択追加（この部屋IDで toggle を行う） ===
$authorizedRooms  = $authorizedRooms ?? ($rooms ?? []);
$currentRoomId    = $currentRoomId ?? ($this->request->getQuery('room') ?: ($userRoomId ?? (array_key_first($authorizedRooms) ?: '')));
$hasTodayReservation = isset($hasTodayReservation) && (bool)$hasTodayReservation;
$todayReservation    = $todayReservation ?? [];
$myReservationDetails = $myReservationDetails ?? [];
$mealKeys = $mealKeys ?? [1 => 'breakfast', 2 => 'lunch', 3 => 'dinner', 4 => 'bento'];

// 子供用: トグルURLテンプレート（__ROOM__ をJSで置換）
$toggleBase = $toggleBase ?? $this->Url->build(['controller' => 'TReservationInfo', 'action' => 'toggle', '__ROOM__']);

// 中学生向け UI 設定
$isChild = isset($isChild) && (bool)$isChild;
$todayDt  = new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo'));
$day14Dt  = $todayDt->modify('+14 days'); // 当日〜14日先＝直前期間（発注済）

if ($isChild) {
    // 子ども：今日から1か月後の前日まで（直前＋通常を含む全日）
    $startDt     = $todayDt;
    $showUntilDt = $todayDt->modify('+1 month')->modify('-1 day');
} else {
    // 大人：通常予約の開始日（今日+15日目）から1か月分
    $startDt     = $todayDt->modify('+15 days');
    $showUntilDt = $startDt->modify('+1 month')->modify('-1 day');
}

$daysToShow = (int)$startDt->diff($showUntilDt)->days + 1; // 両端含む
$todayKey   = $todayDt->format('Y-m-d');

$kidMeals = [
    1 => ['text'=>'朝', 'class'=>'btn-success',           'emoji'=>'☀️'],
    2 => ['text'=>'昼', 'class'=>'btn-warning text-dark', 'emoji'=>'🌞'],
    3 => ['text'=>'夜', 'class'=>'btn-primary',           'emoji'=>'🌙'],
    4 => ['text'=>'弁', 'class'=>'btn-danger',            'emoji'=>'🍱'],
];
?>


<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <div class="fw-bold"><i class="bi bi-door-open"></i> 利用する部屋</div>
        <div class="ms-2">
            <select id="kid-room-select" class="form-select form-select-sm" style="min-width: 220px;" title="利用する部屋を選択">
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

<!-- Flash Messages -->
<?= $this->Flash->render() ?>

<!-- ★ モード切替（自動 / 直前 / 通常） -->
<div class="mode-bar d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
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

<!-- きょうの状況（子どものみ表示） -->
<?php if ($isChild): ?>
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
<?php endif; ?>

<!-- 1か月分のカード -->
<?php
for ($i=0; $i<$daysToShow; $i++):
    $d        = $startDt->modify("+{$i} days");
    $dateKey  = $d->format('Y-m-d');
    $wIdx     = (int)$d->format('w');
    $w        = ['日','月','火','水','木','金','土'][$wIdx];
    $isLastMinute = $isChild && ($d >= $todayDt && $d <= $day14Dt);
    $myDetail     = $myReservationDetails[$dateKey] ?? [];
    $hasLunchForDate = (bool)($myDetail['lunch'] ?? false);
    $hasBentoForDate = (bool)($myDetail['bento'] ?? false);
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
                <a id="conflictReload" href="#" class="btn btn-outline-primary d-none">対象日を再読み込み</a>
                <a id="conflictAction" href="#" class="btn btn-primary">競合先を解除して続行</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">戻る</button>
            </div>
        </div></div>
</div>