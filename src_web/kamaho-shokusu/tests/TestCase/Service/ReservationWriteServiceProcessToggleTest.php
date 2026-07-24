<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Domain\Exception\InvalidInputException;
use App\Service\ReservationWriteService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ReservationWriteService::processToggle のテスト。
 */
class ReservationWriteServiceProcessToggleTest extends TestCase
{
    protected array $fixtures = [
        'app.TIndividualReservationInfo',
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.MUserGroup',
    ];

    private ReservationWriteService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $userTable        = TableRegistry::getTableLocator()->get('MUserInfo');
        $roomTable        = TableRegistry::getTableLocator()->get('MRoomInfo');

        $this->service = new ReservationWriteService(
            $reservationTable,
            $userTable,
            $roomTable,
            '/webroot/'
        );
    }

    public function testProcessToggle_rejectsPastDate(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('過去日の予約は変更できません。');

        $this->service->processToggle(
            roomId: 1,
            payload: [
                'date' => '2020-01-01',
                'meal' => 1,
                'value' => 1,
                'userId' => 1,
            ],
            loginUserId: 1,
            loginUserName: '管理者ユーザー',
        );
    }
}
