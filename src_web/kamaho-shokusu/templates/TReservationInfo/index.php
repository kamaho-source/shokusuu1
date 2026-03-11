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
$mealLabels = [1=>'朝食',2=>'昼食',3=>'夕食',4=>'弁当'];
$mealKeys   = [1=>'breakfast',2=>'lunch',3=>'dinner',4=>'bento'];

// 子供用UIで使う初期値を先に確定（JS埋め込みで参照）
$authorizedRooms = $authorizedRooms ?? ($rooms ?? []);
$currentRoomId = $currentRoomId ?? ($this->request->getQuery('room') ?: ($userRoomId ?? (array_key_first($authorizedRooms) ?: '')));
$toggleBase = $toggleBase ?? $this->Url->build(['controller'=>'TReservationInfo','action'=>'toggle','__ROOM__']);

// ===== JS埋め込み用データを先に生成（head内で参照するため） =====
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
                        'extendedProps' => [
                            'displayOrder' => (int)$type,
                            'isMealCount'  => true,
                            'mealType'     => (int)$type,
                        ],
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

// 予約コピーAPI（JSON）
$copyApi = $this->Url->build(['controller' => 'TReservationInfo', 'action' => 'copy', '_ext' => 'json'], ['fullBase' => false]);
$copyPreviewApi = $this->Url->build(['controller' => 'TReservationInfo', 'action' => 'copyPreview', '_ext' => 'json'], ['fullBase' => false]);

$jsConfigVars = compact(
    'basePath',
    'getUsersByRoomTpl',
    'date',
    'today',
    'isStaff',
    'isChild',
    'isAdmin',
    'user',
    'userRoomId',
    'userRoomIds',
    'useKidUI',
    'JS_TODAY',
    'lunchReserved',
    'bentoReserved',
    'JS_MY_DETAILS',
    'JS_CURRENT_ROOM',
    'JS_TOGGLE_BASE',
    'csrfToken',
    'serverToday',
    'copyApi',
    'copyPreviewApi',
    'JS_RESERVED_DATES',
    'JS_EXISTING_EVENTS'
);
$toolbarVars = compact('useKidUI', 'isStaff', 'mkUrl');
$modalVars = compact('lunchChangeUrl', 'bentoChangeUrl');
$kidSectionVars = compact(
    'authorizedRooms',
    'rooms',
    'currentRoomId',
    'userRoomId',
    'toggleBase',
    'hasTodayReservation',
    'todayReservation',
    'myReservationDetails',
    'mealKeys',
    'mealLabels'
);
/** @noinspection PhpUndefinedVariableInspection */
$calRoomId = isset($calRoomId) ? $calRoomId : null;
/** @noinspection PhpUndefinedVariableInspection */
$canViewAllRooms = isset($canViewAllRooms) ? (bool)$canViewAllRooms : $isAdmin;
$bizSectionVars = [
    'user' => $user,
    'rooms' => $rooms ?? [],
    'isAdmin' => $isAdmin,
    'canViewAllRooms' => $canViewAllRooms,
    'calRoomId' => $calRoomId,
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <title><?= h((string)$this->fetch('title')) ?></title>
    <?= $this->element('TReservationInfo/head_assets', ['jsConfigVars' => $jsConfigVars]) ?>
</head>
<body>
<div class="container">
    <?= $this->element('TReservationInfo/toolbar', $toolbarVars) ?>
    <?php if ($useKidUI): ?>
        <?= $this->element('TReservationInfo/kid_section', $kidSectionVars) ?>

    <?php else: ?>
        <?= $this->element('TReservationInfo/biz_section', $bizSectionVars) ?>

    <?php endif; ?>
</div>

<?= $this->element('TReservationInfo/modals', $modalVars) ?>
<script src="https://code.jquery.com/jquery-3.5.1.min.js"
        integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0="
        crossorigin="anonymous"></script>
<?= $this->Html->script('index.global.min.js') ?>
<?= $this->Html->script('japanese-holidays.min.js') ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>
<?= $this->Html->script('pages/treservation_index.inline.js') ?>
</body>
</html>