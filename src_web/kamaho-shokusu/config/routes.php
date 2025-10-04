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

        // ホーム
        $builder->connect('/', ['controller' => 'Pages', 'action' => 'display', 'home']);

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

        // bulkAddSubmit（POST 専用）
        $builder->connect(
            '/TReservationInfo/bulkAddSubmit',
            ['controller' => 'TReservationInfo', 'action' => 'bulkAddSubmit']
        )->setMethods(['POST']);

        // 週一括フォーム・関連 API
        $builder->connect('/TReservationInfo/bulk-add-form', ['controller' => 'TReservationInfo', 'action' => 'bulkAddForm']);

        $builder->connect(
            '/TReservationInfo/getUsersByRoomForBulk/:roomId',
            ['controller' => 'TReservationInfo', 'action' => 'getUsersByRoomForBulk']
        )
            ->setPass(['roomId'])
            ->setPatterns(['roomId' => '\d+'])
            ->setMethods(['GET']);

        $builder->connect(
            '/TReservationInfo/getUsersByRoom/:roomId',
            ['controller' => 'TReservationInfo', 'action' => 'getUsersByRoom']
        )
            ->setPass(['roomId'])
            ->setPatterns(['roomId' => '\d+'])
            ->setMethods(['GET']);

        $builder->connect(
            '/TReservationInfo/roomDetails/:roomId/:date/:mealType',
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
            '/TReservationInfo/getUsersByRoomForEdit/:roomId',
            ['controller' => 'TReservationInfo', 'action' => 'getUsersByRoomForEdit']
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

        $builder->connect('/TReservationInfo/changeEdit/*', ['controller' => 'TReservationInfo', 'action' => 'changeEdit']);
        $builder->connect(
            '/TReservationInfo/changeEdit/:roomId/:date/:mealType',
            ['controller' => 'TReservationInfo', 'action' => 'changeEdit']
        )
            ->setPass(['roomId', 'date', 'mealType'])
            ->setPatterns([
                'roomId'   => '\d+',
                'date'     => '\d{4}-\d{2}-\d{2}',
                'mealType' => '\d+',
            ]);

        // MMealPriceInfo
        $builder->connect('/MMealPriceInfo', ['controller'=> 'MMealPriceInfo', 'action'=>'index']);
        $builder->connect('/MMealPriceInfo/add', ['controller'=>'MMealPriceInfo', 'action'=>'add']);
        $builder->connect('/MMealPriceInfo/GetMealSummary', ['controller'=>'MMealPriceInfo', 'action'=>'GetMealSummary']);

        // MUserInfo
        $builder->connect('/MUserInfo', ['controller' => 'MUserInfo', 'action' => 'index']);
        $builder->connect('/MUserInfo/admin_change_password', ['controller' => 'MUserInfo', 'action' => 'adminChangePassword']);
        $builder->connect('/MUserInfo/changePassword', ['controller' => 'MUserInfo', 'action' => 'changePassword']);
        $builder->connect('/MUserInfo/AdminChangePassword', ['controller' => 'MUserInfo', 'action' => 'AdminChangePassword']);
        $builder->connect('/MUserInfo/update-admin-status', ['controller' => 'MUserInfo', 'action' => 'updateAdminStatus'])->setMethods(['POST']);
        $builder->connect('/MUserInfo/login', ['controller' => 'MUserInfo', 'action' => 'login']);
        $builder->connect('/MUserInfo/add', ['controller' => 'MUserInfo', 'action' => 'add']);
        $builder->connect('/MUserInfo/edit/*', ['controller' => 'MUserInfo', 'action' => 'edit']);
        $builder->connect('/MUserInfo/delete/*', ['controller' => 'MUserInfo', 'action' => 'delete']);
        $builder->connect('/MUserInfo/logout', ['controller' => 'MUserInfo', 'action' => 'logout']);
        $builder->connect('/MUserInfo/view/*', ['controller' => 'MUserInfo', 'action' => 'view']);
        $builder->connect('/MUserInfo/', ['controller' => 'MUserInfo', 'action' => 'index']);

        // Pages
        $builder->connect('/pages/*', 'Pages::display');

        // 日付別ビュー
        $builder->connect(
            '/TReservationInfo/view/:date',
            ['controller' => 'TReservationInfo', 'action' => 'view']
        )->setPass(['date']);

        // === 予約トグル（POST）: roomId あり版 と なし版 の両方を受け付ける ===
        $builder->connect(
            '/TReservationInfo/toggle/:roomId',
            ['controller' => 'TReservationInfo', 'action' => 'toggle']
        )
            ->setPatterns(['roomId' => '\d+'])
            ->setPass(['roomId'])
            ->setMethods(['POST']);

        $builder->connect(
            '/TReservationInfo/toggle',
            ['controller' => 'TReservationInfo', 'action' => 'toggle']
        )->setMethods(['POST']);

        // フォールバック
        $builder->fallbacks();
    });
};
