<?php
/**
 * Routes configuration.
 */

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;
use Cake\Routing\Router;

return function (RouteBuilder $routes): void {

    $routes->setRouteClass(DashedRoute::class);

    $routes->scope('/', function (RouteBuilder $builder): void {

        // JSON 拡張を有効化（このスコープ全体）
        $builder->setExtensions(['json']);

        // ホーム（専用アクション）
        $builder->connect('/', ['controller' => 'Pages', 'action' => 'dashboard']);

        // MRoomInfo
        $builder->connect('/MRoomInfo', ['controller' => 'MRoomInfo', 'action' => 'index']);
        $builder->connect('/MRoomInfo/add', ['controller' => 'MRoomInfo', 'action' => 'add']);
        $builder->connect('/MRoomInfo/edit/*', ['controller' => 'MRoomInfo', 'action' => 'edit']);
        $builder->connect('/MRoomInfo/delete/*', ['controller' => 'MRoomInfo', 'action' => 'delete']);

        // TReservationInfo（一覧など）
        $builder->connect('/TReservationInfo', ['controller' => 'TReservationInfo', 'action' => 'index']);
        $builder->connect('/TReservationInfo/events', ['controller' => 'TReservationInfo', 'action' => 'events']);
        $builder->connect('/TReservationInfo/add', ['controller' => 'TReservationInfo', 'action' => 'add']);
        $builder->connect('/TReservationInfo/edit/*', ['controller' => 'TReservationInfo', 'action' => 'edit']);
        $builder->connect('/TReservationInfo/delete/*', ['controller' => 'TReservationInfo', 'action' => 'delete']);

        // ── 一括予約（ReservationBulkController） ──
        $builder->connect(
            '/TReservationInfo/bulkAddSubmit',
            ['controller' => 'ReservationBulk', 'action' => 'bulkAddSubmit']
        )->setMethods(['POST']);

        $builder->connect('/TReservationInfo/bulk-add-form', ['controller' => 'ReservationBulk', 'action' => 'bulkAddForm']);
        $builder->connect('/TReservationInfo/bulk-change-edit-form', ['controller' => 'ReservationBulk', 'action' => 'bulkChangeEditForm']);
        $builder->connect('/TReservationInfo/bulk-change-edit-submit', ['controller' => 'ReservationBulk', 'action' => 'bulkChangeEditSubmit'])->setMethods(['POST']);

        $builder->connect(
            '/TReservationInfo/getUsersByRoomForBulk/{roomId}',
            ['controller' => 'ReservationBulk', 'action' => 'getUsersByRoomForBulk']
        )
            ->setPass(['roomId'])
            ->setPatterns(['roomId' => '\d+'])
            ->setMethods(['GET']);

        $builder->connect(
            '/TReservationInfo/getUsersByRoom/{roomId}',
            ['controller' => 'TReservationInfo', 'action' => 'getUsersByRoom']
        )
            ->setPass(['roomId'])
            ->setPatterns(['roomId' => '\d+'])
            ->setMethods(['GET']);

        $builder->connect(
            '/TReservationInfo/roomDetails/{roomId}/{date}/{mealType}',
            ['controller' => 'TReservationInfo', 'action' => 'roomDetails']
        )
            ->setPass(['roomId', 'date', 'mealType'])
            ->setPatterns([
                'roomId'   => '\d+',
                'date'     => '\d{4}-\d{2}-\d{2}',
                'mealType' => '\d+',
            ])
            ->setMethods(['GET']);

        $builder->connect(
            '/TReservationInfo/getUsersByRoomForEdit/{roomId}',
            ['controller' => 'ReservationReport', 'action' => 'getUsersByRoomForEdit']
        )
            ->setPass(['roomId'])
            ->setPatterns(['roomId' => '\d+'])
            ->setMethods(['GET']);

        $builder->connect(
            '/TReservationInfo/checkDuplicateReservation',
            ['controller' => 'TReservationInfo', 'action' => 'checkDuplicateReservation']
        )->setMethods(['POST']);

        $builder->connect(
            '/TReservationInfo/getPersonalReservation',
            ['controller' => 'TReservationInfo', 'action' => 'getPersonalReservation']
        )->setMethods(['GET']);

        $builder->connect(
            '/TReservationInfo/getReservationSnapshots',
            ['controller' => 'ReservationBulk', 'action' => 'getReservationSnapshots']
        )->setMethods(['POST']);

        $builder->connect('/TReservationInfo/changeEdit/*', ['controller' => 'TReservationInfo', 'action' => 'changeEdit']);
        $builder->connect(
            '/TReservationInfo/changeEdit/{roomId}/{date}/{mealType}',
            ['controller' => 'TReservationInfo', 'action' => 'changeEdit']
        )
            ->setPass(['roomId', 'date', 'mealType'])
            ->setPatterns([
                'roomId'   => '\d+',
                'date'     => '\d{4}-\d{2}-\d{2}',
                'mealType' => '\d+',
            ]);

        // ce-change-edit.js が生成するURLはダッシュ区切りアクション名を使うため明示的にルートを登録する
        $builder->connect(
            '/TReservationInfo/change-edit/{roomId}/{date}/{mealType}',
            ['controller' => 'TReservationInfo', 'action' => 'changeEdit']
        )
            ->setPass(['roomId', 'date', 'mealType'])
            ->setPatterns([
                'roomId'   => '\d+',
                'date'     => '\d{4}-\d{2}-\d{2}',
                'mealType' => '[1-4]',
            ])
            ->setMethods(['GET', 'POST', 'PUT']);

        $builder->connect(
            '/TReservationInfo/change-edit/{roomId}/{date}',
            ['controller' => 'TReservationInfo', 'action' => 'changeEdit']
        )
            ->setPass(['roomId', 'date'])
            ->setPatterns([
                'roomId' => '\d+',
                'date'   => '\d{4}-\d{2}-\d{2}',
            ])
            ->setMethods(['GET', 'POST']);

        // ===== 小文字パス（API） =====
        // 3セグメント版（従来：{roomId}/{date}/{mealType}）
        // ※ changeEdit は TReservationInfoController に実装されているため controller を揃える
        $builder->connect(
            '/t-individual-reservation-info/change-edit/{roomId}/{date}/{mealType}',
            ['controller' => 'TReservationInfo', 'action' => 'changeEdit']
        )
            ->setPass(['roomId', 'date', 'mealType'])
            ->setPatterns([
                'roomId'   => '\d+',
                'date'     => '\d{4}-\d{2}-\d{2}',
                'mealType' => '[1-4]',
            ])
            ->setMethods(['GET', 'POST', 'PUT']);

        // ★ 2セグメント版（ALL モード：{roomId}/{date}）
        // Accept: application/json 付きの GET は JSON を返却。拡張子なしアクセスにも対応。
        $builder->connect(
            '/t-individual-reservation-info/change-edit/{roomId}/{date}',
            ['controller' => 'TReservationInfo', 'action' => 'changeEdit']
        )
            ->setPass(['roomId', 'date'])
            ->setPatterns([
                'roomId' => '\d+',
                'date'   => '\d{4}-\d{2}-\d{2}',
            ])
            ->setMethods(['GET', 'POST']);

        // MMealPriceInfo
        $builder->connect('/MMealPriceInfo', ['controller'=> 'MMealPriceInfo', 'action'=>'index']);
        $builder->connect('/MMealPriceInfo/add', ['controller'=>'MMealPriceInfo', 'action'=>'add']);
        $builder->connect('/MMealPriceInfo/GetMealSummary', ['controller'=>'MMealPriceInfo', 'action'=>'GetMealSummary']);
        $builder->connect('/MMealPriceInfo/exportMealSummary', ['controller'=>'MMealPriceInfo', 'action'=>'exportMealSummary'])->setMethods(['GET']);
        $builder->connect('/MMealPriceInfo/exportMealSummaryPreview', ['controller'=>'MMealPriceInfo', 'action'=>'exportMealSummaryPreview'])->setMethods(['GET']);

        // MUserInfo
        $builder->connect('/MUserInfo', ['controller' => 'MUserInfo', 'action' => 'index']);
        $builder->connect('/MUserInfo/admin_change_password', ['controller' => 'MUserInfo', 'action' => 'adminChangePassword']);
        $builder->connect('/MUserInfo/update-admin-status', ['controller' => 'MUserInfo', 'action' => 'updateAdminStatus'])->setMethods(['POST']);
        $builder->connect('/MUserInfo/update-user-level', ['controller' => 'MUserInfo', 'action' => 'updateUserLevel'])->setMethods(['POST']);
        $builder->connect('/MUserInfo/update-system-admin-status', ['controller' => 'MUserInfo', 'action' => 'updateSystemAdminStatus'])->setMethods(['POST']);
        $builder->connect('/MUserInfo/generalPasswordReset', ['controller' => 'MUserInfo', 'action' => 'generalPasswordReset']);
        $builder->connect('/MUserInfo/login', ['controller' => 'MUserInfo', 'action' => 'login']);
        $builder->connect('/MUserInfo/add', ['controller' => 'MUserInfo', 'action' => 'add']);
        $builder->connect('/MUserInfo/edit/*', ['controller' => 'MUserInfo', 'action' => 'edit']);
        $builder->connect('/MUserInfo/delete/*', ['controller' => 'MUserInfo', 'action' => 'delete']);
        $builder->connect('/MUserInfo/restore/*', ['controller' => 'MUserInfo', 'action' => 'restore']);
        $builder->connect('/MUserInfo/logout', ['controller' => 'MUserInfo', 'action' => 'logout']);
        $builder->connect('/MUserInfo/view/*', ['controller' => 'MUserInfo', 'action' => 'view']);

        // 監査ログ（システム管理者専用）
        $builder->connect('/AuditLog', ['controller' => 'AuditLog', 'action' => 'index'])->setMethods(['GET']);
        $builder->connect('/AuditLog/export', ['controller' => 'AuditLog', 'action' => 'export'])->setMethods(['GET']);

        // 機能使用頻度ダッシュボード（システム管理者専用）
        $builder->connect('/FeatureUsageSummary', ['controller' => 'FeatureUsageSummary', 'action' => 'index'])->setMethods(['GET']);

        // 部屋使用率（システム管理者専用）
        $builder->connect('/RoomUsage', ['controller' => 'RoomUsage', 'action' => 'index'])->setMethods(['GET']);
        $builder->connect('/RoomUsage/roomUsage', ['controller' => 'RoomUsage', 'action' => 'roomUsage'])->setMethods(['GET']);
        $builder->connect('/RoomUsage/lowUsageRooms', ['controller' => 'RoomUsage', 'action' => 'lowUsageRooms'])->setMethods(['GET']);

        // MRoomTransferSchedule（部屋異動予約）
        $builder->connect('/MRoomTransferSchedule', ['controller' => 'MRoomTransferSchedule', 'action' => 'index']);
        $builder->connect('/MRoomTransferSchedule/add', ['controller' => 'MRoomTransferSchedule', 'action' => 'add']);
        $builder->connect(
            '/MRoomTransferSchedule/cancel/{id}',
            ['controller' => 'MRoomTransferSchedule', 'action' => 'cancel']
        )
            ->setPass(['id'])
            ->setPatterns(['id' => '\d+'])
            ->setMethods(['POST']);

        // Pages
        $builder->connect('/pages/*', 'Pages::display');

        // 日付別ビュー
        $builder->connect(
            '/TReservationInfo/view/{date}',
            ['controller' => 'TReservationInfo', 'action' => 'view']
        )->setPass(['date']);

        // ── カレンダークリック直接登録（ReservationDirectRegisterController） ──
        $builder->connect(
            '/TReservationInfo/direct-register',
            ['controller' => 'ReservationDirectRegister', 'action' => 'register']
        )->setMethods(['POST']);

        // ── 予約トグル（ReservationToggleController） ──
        $builder->connect(
            '/TReservationInfo/toggle/{roomId}',
            ['controller' => 'ReservationToggle', 'action' => 'toggle']
        )
            ->setPatterns(['roomId' => '\d+'])
            ->setPass(['roomId'])
            ->setMethods(['POST']);

        $builder->connect(
            '/TReservationInfo/toggle',
            ['controller' => 'ReservationToggle', 'action' => 'toggle']
        )->setMethods(['POST']);

        // ── 食数レポート（ReservationReportController） ──
        $builder->connect(
            '/TReservationInfo/getRoomMealCounts/{roomId}',
            ['controller' => 'ReservationReport', 'action' => 'getRoomMealCounts']
        )
            ->setPatterns(['roomId' => '\d+'])
            ->setPass(['roomId'])
            ->setMethods(['GET']);

        $builder->connect(
            '/TReservationInfo/getRoomMealCounts',
            ['controller' => 'ReservationReport', 'action' => 'getRoomMealCounts']
        )->setMethods(['GET']);

        $builder->connect(
            '/TReservationInfo/getAllRoomsMealCounts',
            ['controller' => 'ReservationReport', 'action' => 'getAllRoomsMealCounts']
        )->setMethods(['GET']);

        $builder->connect(
            '/TReservationInfo/getMealCounts/{date}',
            ['controller' => 'ReservationReport', 'action' => 'getMealCounts']
        )
            ->setPass(['date'])
            ->setPatterns(['date' => '\d{4}-\d{2}-\d{2}']);

        $builder->connect(
            '/TReservationInfo/exportJson',
            ['controller' => 'ReservationReport', 'action' => 'exportJson']
        )->setMethods(['GET']);

        $builder->connect(
            '/TReservationInfo/exportJsonrank',
            ['controller' => 'ReservationReport', 'action' => 'exportJsonrank']
        )->setMethods(['GET']);

        $builder->connect(
            '/TReservationInfo/reportNoMeal',
            ['controller' => 'ReservationReport', 'action' => 'reportNoMeal']
        )->setMethods(['POST']);

        $builder->connect(
            '/TReservationInfo/reportEat',
            ['controller' => 'ReservationReport', 'action' => 'reportEat']
        )->setMethods(['POST']);

        // ── 実食確認管理（ReservationActualMealController） ──
        $builder->connect(
            '/TReservationInfo/actual-meal-management',
            ['controller' => 'ReservationActualMeal', 'action' => 'actualMealManagement']
        )->setMethods(['GET']);

        $builder->connect(
            '/TReservationInfo/actual-meal-save',
            ['controller' => 'ReservationActualMeal', 'action' => 'actualMealSave']
        )->setMethods(['POST']);

        $builder->connect(
            '/TReservationInfo/actual-meal-request-approval',
            ['controller' => 'ReservationActualMeal', 'action' => 'actualMealRequestApproval']
        )->setMethods(['POST']);

        $builder->connect(
            '/TReservationInfo/my-actual-meal',
            ['controller' => 'ReservationActualMeal', 'action' => 'myActualMeal']
        )->setMethods(['GET']);

        $builder->connect(
            '/TReservationInfo/meal-count-grid',
            ['controller' => 'ReservationActualMeal', 'action' => 'mealCountGrid']
        )->setMethods(['GET']);

        // ── 予約コピー（ReservationCopyController） ──
        // メソッド制限をルーターで行わずコントローラーの allowMethod() に委ねる（405 を正しく返すため）
        $builder->connect(
            '/TReservationInfo/copy',
            ['controller' => 'ReservationCopy', 'action' => 'copy']
        );

        $builder->connect(
            '/TReservationInfo/copyPreview',
            ['controller' => 'ReservationCopy', 'action' => 'copyPreview']
        )->setMethods(['GET', 'POST']);

        // ------------------------------------------------------------------
        // Approval（承認フロー）
        // ------------------------------------------------------------------
        // ブロック長用 承認一覧（GET）
        $builder->connect(
            '/Approval/blockLeaderIndex',
            ['controller' => 'Approval', 'action' => 'blockLeaderIndex']
        )->setMethods(['GET']);

        // ブロック長による承認（POST/JSON）
        $builder->connect(
            '/Approval/blockLeaderApprove',
            ['controller' => 'Approval', 'action' => 'blockLeaderApprove']
        )->setMethods(['POST']);

        // ブロック長による差し戻し（POST/JSON）
        $builder->connect(
            '/Approval/blockLeaderReject',
            ['controller' => 'Approval', 'action' => 'blockLeaderReject']
        )->setMethods(['POST']);

        // 管理者用 承認一覧（GET）
        $builder->connect(
            '/Approval/adminIndex',
            ['controller' => 'Approval', 'action' => 'adminIndex']
        )->setMethods(['GET']);

        // 管理者による最終承認（POST/JSON）
        $builder->connect(
            '/Approval/adminApprove',
            ['controller' => 'Approval', 'action' => 'adminApprove']
        )->setMethods(['POST']);

        // 管理者による差し戻し（POST/JSON）
        $builder->connect(
            '/Approval/adminReject',
            ['controller' => 'Approval', 'action' => 'adminReject']
        )->setMethods(['POST']);

        // 通知一覧（GET）
        $builder->connect(
            '/Notifications',
            ['controller' => 'Notifications', 'action' => 'index']
        )->setMethods(['GET']);

        // 通知既読化（POST/JSON）
        $builder->connect(
            '/Notifications/markRead',
            ['controller' => 'Notifications', 'action' => 'markRead']
        )->setMethods(['POST']);

        // 通知一括既読化（POST/JSON）
        $builder->connect(
            '/Notifications/markAllRead',
            ['controller' => 'Notifications', 'action' => 'markAllRead']
        )->setMethods(['POST']);

        // 承認済みを食数テーブルへ反映（POST/JSON）
        $builder->connect(
            '/Approval/adminReflect',
            ['controller' => 'Approval', 'action' => 'adminReflect']
        )->setMethods(['POST']);

        // フィードバック・お問い合わせ
        $builder->connect('/Contacts', ['controller' => 'Contacts', 'action' => 'index']);
        $builder->connect('/Contacts/admin', ['controller' => 'Contacts', 'action' => 'adminIndex'])->setMethods(['GET']);
        $builder->connect('/Contacts/admin/{id}', ['controller' => 'Contacts', 'action' => 'adminDetail'])
            ->setPass(['id'])
            ->setPatterns(['id' => '\d+']);

        // ── LP画像管理（管理者専用） ──
        $builder->connect('/LpImage', ['controller' => 'LpImage', 'action' => 'index'])->setMethods(['GET']);
        $builder->connect('/LpImage/add', ['controller' => 'LpImage', 'action' => 'add'])->setMethods(['POST']);
        $builder->connect('/LpImage/toggle/{id}', ['controller' => 'LpImage', 'action' => 'toggle'])
            ->setMethods(['POST'])
            ->setPass(['id'])
            ->setPatterns(['id' => '\d+']);
        $builder->connect('/LpImage/delete/{id}', ['controller' => 'LpImage', 'action' => 'delete'])
            ->setMethods(['POST', 'DELETE'])
            ->setPass(['id'])
            ->setPatterns(['id' => '\d+']);

        // ── 統計AI（管理者専用） ──
        $builder->connect('/StatsAi', ['controller' => 'StatsAi', 'action' => 'index'])->setMethods(['GET']);
        $builder->connect('/StatsAi/askStream', ['controller' => 'StatsAi', 'action' => 'askStream'])->setMethods(['POST']);

        // ── AIアシスタント ──
        $builder->connect('/AiAssistant/ask', ['controller' => 'AiAssistant', 'action' => 'ask'])->setMethods(['POST']);
        $builder->connect('/AiAssistant/askStream', ['controller' => 'AiAssistant', 'action' => 'askStream'])->setMethods(['POST']);
        $builder->connect('/AiAssistant/suggestions', ['controller' => 'AiAssistant', 'action' => 'suggestions'])->setMethods(['GET']);
        $builder->connect('/AiAssistant/feedback', ['controller' => 'AiAssistant', 'action' => 'feedback'])->setMethods(['POST']);

        // フォールバック
        $builder->fallbacks(DashedRoute::class);
    });
};