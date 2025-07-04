<?php
/**
 * Routes configuration.
 *
 * In this file, you set up routes to your controllers and their actions.
 * Routes are very important mechanism that allows you to freely connect
 * different URLs to chosen controllers and their actions (functions).
 *
 * It's loaded within the context of `Application::routes()` method which
 * receives a `RouteBuilder` instance `$routes` as method argument.
 *
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;
use Cake\Routing\Router;

return function (RouteBuilder $routes): void {

    $routes->setRouteClass(DashedRoute::class);

    $routes->scope('/', function (RouteBuilder $builder): void {
        $builder->setExtensions(['json']);

        $builder->connect('/', ['controller' => 'Pages', 'action' => 'display', 'home']);
        $builder->connect('/MRoomInfo', ['controller' => 'MRoomInfo', 'action' => 'index']);
        $builder->connect('/MRoomInfo/add', ['controller' => 'MRoomInfo', 'action' => 'add']);
        $builder->connect('/MRoomInfo/edit/*', ['controller' => 'MRoomInfo', 'action' => 'edit']);
        $builder->connect('/MRoomInfo/delete/*', ['controller' => 'MRoomInfo', 'action' => 'delete']);
        $builder->connect('/TReservationInfo', ['controller' => 'TReservationInfo', 'action' => 'index']);
        $builder->setExtensions(['json']);
        $builder->connect('TReservationInfo/events', ['controller' => 'TReservationInfo', 'action' => 'events']);
        $builder->connect('/TReservationInfo/add', ['controller' => 'TReservationInfo', 'action' => 'add']);
        $builder->connect('TReservationInfo/edit/*', ['controller' => 'TReservationInfo', 'action' => 'edit']);

        // bulkAddSubmit用 POST専用ルート
        $builder->connect(
            '/TReservationInfo/bulkAddSubmit',
            ['controller' => 'TReservationInfo', 'action' => 'bulkAddSubmit'],
            ['method' => ['POST']]
        );

        $builder->connect(
            '/TReservationInfo/getUsersByRoomForBulk/:roomId',
            ['controller' => 'TReservationInfo', 'action' => 'getUsersByRoomForBulk'],
            ['pass' => ['roomId'], 'method' => ['GET']]
        );


        $builder->connect('/TReservationInfo/bulk-add-form', ['controller' => 'TReservationInfo', 'action' => 'bulkAddForm']);
        $builder->connect('/TReservation-info/getUsersByRoom/:roomId', ['controller' => 'TReservationInfo', 'action' => 'getUsersByRoom'])
            ->setPass(['roomId'])
            ->setPatterns(['roomId' => '\d+']);
        $builder->connect(
            '/TReservationInfo/roomDetails/:roomId/:date/:mealType',
            ['controller' => 'TReservationInfo', 'action' => 'roomDetails'])
            ->setPass(['roomId', 'date', 'mealType'])
            ->setPatterns(['roomId' => '\d+', 'date' => '\d{4}-\d{2}-\d{2}', 'mealType' => '\d+'])
            ->setMethods(['GET']);
        $builder->connect(
            '/TReservationInfo/getUsersByRoomForEdit/:roomId',
            ['controller' => 'TReservationInfo', 'action' => 'getUsersByRoomForEdit'],
            ['pass' => ['roomId'], 'roomId' => '\d+']
        );
        $builder->connect(
            '/TReservationInfo/checkDuplicateReservation',
            ['controller' => 'TReservationInfo', 'action' => 'checkDuplicateReservation']
        )->setMethods(['POST']);
        $builder->connect('/TReservationInfo/getPersonalReservation', [
            'controller' => 'TReservationInfo',
            'action' => 'getPersonalReservation'
        ]);


        $builder->connect('MMealPriceInfo/', ['controller'=> 'MMealPriceInfo', 'action'=>'index']);
        $builder->connect('MMealPriceInfo/add', ['controller'=>'MMealPriceInfo', 'action'=>'add']);
        $builder->connect('MMealPriceInfo/GetMealSummary', ['controller'=>'MMealPriceInfo', 'action'=>'GetMealSummary']);

        $builder->connect('/TReservationInfo/edit/*', ['controller' => 'TReservationInfo', 'action' => 'edit']);
        $builder->connect('/TReservationInfo/delete/*', ['controller' => 'TReservationInfo', 'action' => 'delete']);
        $builder->connect('/MUserInfo', ['controller' => 'MUserInfo', 'action' => 'index']);
        $builder->connect('/MUserInfo/admin_change_password', ['controller' => 'MUserInfo', 'action' => 'adminChangePassword']);
        $builder->connect('/MUserInfo/changePassword', ['controller' => 'MUserInfo', 'action' => 'changePassword']);
        $builder->connect('/MUserInfo/update-admin-status', ['controller' => 'MUserInfo', 'action' => 'updateAdminStatus'])->setMethods(['POST']);
        $builder->connect('/MUserInfo/login', ['controller' => 'MUserInfo', 'action' => 'login']);
        $builder->connect('/MUserInfo/add', ['controller' => 'MUserInfo', 'action' => 'add']);
        $builder->connect('/MUserInfo/edit/*', ['controller' => 'MUserInfo', 'action' => 'edit']);
        $builder->connect('/MUserInfo/delete/*', ['controller' => 'MUserInfo', 'action' => 'delete']);
        $builder->connect('/MUserInfo/logout', ['controller' => 'MUserInfo', 'action' => 'logout']);
        $builder->connect('/MUserInfo/view/*', ['controller' => 'MUserInfo', 'action' => 'view']);
        $builder->connect('/MUserInfo/', ['controller' => 'MUserInfo', 'action' => 'index']);
        // $builder->connect('/MUserInfo/adminChangePassword', ['controller' => 'MUserInfo', 'action' => 'adminChangePassword']);

        $builder->connect('/pages/*', 'Pages::display');

        $builder->connect(
            '/TReservationInfo/view/:date',
            ['controller' => 'TReservationInfo', 'action' => 'view'],
            ['pass' => ['date']]
        );

        $builder->fallbacks();
    });

    /*
     * If you need a different set of middleware or none at all,
     * open new scope and define routes there.
     *
     * ```
     * $routes->scope('/api', function (RouteBuilder $builder): void {
     *     // No $builder->applyMiddleware() here.
     *
     *     // Parse specified extensions from URLs
     *     // $builder->setExtensions(['json', 'xml']);
     *
     *     // Connect API actions here.
     * });
     * ```
     */
};