<?php
// JS config for TReservationInfo index
use Cake\Core\Configure;

$pastDateUnavailableMessage = (string)Configure::read(
    'App.messages.pastDateUnavailable',
    '過去日の内容はこの画面では表示できません。修正が必要な場合は管理者にお問い合わせください。'
);
?>
<script>
    window.__TRESP = {
        basePath: <?= json_encode($basePath, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        getUsersByRoomTpl: <?= json_encode($getUsersByRoomTpl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        queryDate: <?= json_encode($date ?? $today, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        isStaff: <?= $isStaff ? 'true' : 'false' ?>,
        isChild: <?= $isChild ? 'true' : 'false' ?>,
        isAdmin: <?= $isAdmin ? 'true' : 'false' ?>,
        userLevel: <?= $user ? (int)$user->get('i_user_level') : 'null' ?>,
        roomId: <?= $userRoomId !== null ? (int)$userRoomId : 'null' ?>,
        roomIds: <?= json_encode(array_values($userRoomIds ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        roomCount: <?= count($userRoomIds ?? []) ?>,
        primaryRoomId: <?= (int)$userRoomId ?>,
        isKidUI: <?= $useKidUI ? 'true' : 'false' ?>,
        todayJs: <?= $JS_TODAY ?>,
        todayState: {
            lunch: <?= $lunchReserved ? 'true' : 'false' ?>,
            bento: <?= $bentoReserved ? 'true' : 'false' ?>
        },
        myDetails: <?= $JS_MY_DETAILS ?>,
        currentRoom: <?= $JS_CURRENT_ROOM ?>,
        toggleBase: <?= $JS_TOGGLE_BASE ?>,
        addUrl: '<?= preg_replace("#^https?:#", "", $this->Url->build(["controller"=>"TReservationInfo","action"=>"add"], ["fullBase"=>true])) ?>',
        changeEditUrl: '<?= preg_replace("#^https?:#", "", $this->Url->build(["controller"=>"TReservationInfo","action"=>"changeEdit"], ["fullBase"=>true])) ?>',
        csrfToken: <?= json_encode($csrfToken) ?>,
        serverToday: <?= json_encode($serverToday) ?>,
        copyApi: <?= json_encode($copyApi, JSON_UNESCAPED_SLASHES) ?>,
        copyPreviewApi: <?= json_encode($copyPreviewApi, JSON_UNESCAPED_SLASHES) ?>,
        exportJsonUrl: <?= json_encode($this->Url->build('/TReservationInfo/exportJson'), JSON_UNESCAPED_SLASHES) ?>,
        exportJsonRankUrl: <?= json_encode($this->Url->build('/TReservationInfo/exportJsonrank'), JSON_UNESCAPED_SLASHES) ?>,
        reservedDates: <?= $JS_RESERVED_DATES ?>,
        existingEvents: <?= $JS_EXISTING_EVENTS ?>
        ,pastDateUnavailableMessage: <?= json_encode($pastDateUnavailableMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
    };
</script>
