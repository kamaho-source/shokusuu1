<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Domain\Exception\ConflictException;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\UnauthorizedException;
use App\Service\ReservationWriteService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ReservationWriteService のテスト
 *
 * 確認項目:
 *   processIndividualReservation — 新規作成・重複スキップ・再活性・非活性・エラー系
 *   processGroupReservation     — 新規作成・重複スキップ・エラー系
 */
class ReservationWriteServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.TIndividualReservationInfo',
        'app.MRoomInfo',
        'app.MUserInfo',
    ];

    private ReservationWriteService $service;
    private $reservationTable;

    public function setUp(): void
    {
        parent::setUp();

        $this->reservationTable = TableRegistry::getTableLocator()->get(
            'TIndividualReservationInfo',
            ['table' => 't_individual_reservation_info']
        );
        $userTable  = TableRegistry::getTableLocator()->get('MUserInfo',  ['table' => 'm_user_info']);
        $roomTable  = TableRegistry::getTableLocator()->get('MRoomInfo',  ['table' => 'm_room_info']);

        $this->service = new ReservationWriteService(
            $this->reservationTable,
            $userTable,
            $roomTable,
            '/webroot/'
        );
    }

    // -------------------------------------------------------------------------
    // ヘルパー
    // -------------------------------------------------------------------------

    private function alwaysValid(): callable
    {
        return fn() => true;
    }

    private function alwaysFail(): callable
    {
        return fn() => '過去日は予約できません。';
    }

    /** フィクスチャ定義の部屋IDに合わせたマップ */
    private function rooms(): array
    {
        return [1 => ['i_id_room' => 1, 'c_room_name' => 'テスト部屋']];
    }

    private function insertReservation(array $override = []): void
    {
        $now = DateTime::now('Asia/Tokyo')->format('Y-m-d H:i:s');
        $defaults = [
            'i_id_user'          => 1,
            'd_reservation_date' => '2026-07-01',
            'i_reservation_type' => 1,
            'i_id_room'          => 1,
            'eat_flag'           => 1,
            'i_change_flag'      => 1,
            'i_version'          => 1,
            'c_create_user'      => 'test',
            'dt_create'          => $now,
            'c_update_user'      => 'test',
            'dt_update'          => $now,
        ];
        ConnectionManager::get('test')->insert(
            't_individual_reservation_info',
            array_merge($defaults, $override)
        );
    }

    private function fetchReservation(int $userId, string $date, int $mealType, int $roomId): ?object
    {
        return $this->reservationTable->find()
            ->where([
                'i_id_user'          => $userId,
                'd_reservation_date' => $date,
                'i_reservation_type' => $mealType,
                'i_id_room'          => $roomId,
            ])
            ->first();
    }

    // =========================================================================
    // processIndividualReservation
    // =========================================================================

    public function testIndividualNewReservationCreatesRecord(): void
    {
        $result = $this->service->processIndividualReservation(
            '2026-07-01',
            json_encode(['meals' => ['1' => ['1' => 1]]]),
            $this->rooms(),
            1,
            'テストユーザー',
            $this->alwaysValid()
        );

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $row = $this->fetchReservation(1, '2026-07-01', 1, 1);
        $this->assertNotNull($row, 'レコードが作成されていない');
        $this->assertSame(1, (int)$row->eat_flag);
        $this->assertSame(1, (int)$row->i_change_flag);
    }

    public function testIndividualDuplicateIsSkipped(): void
    {
        $this->insertReservation(['eat_flag' => 1]);

        $result = $this->service->processIndividualReservation(
            '2026-07-01',
            json_encode(['meals' => ['1' => ['1' => 1]]]),
            $this->rooms(),
            1,
            'テストユーザー',
            $this->alwaysValid()
        );

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertNotEmpty($result['data']['skipped'], 'skipped が空');
    }

    public function testIndividualReactivatesInactiveReservation(): void
    {
        $this->insertReservation(['eat_flag' => 0, 'i_change_flag' => 0]);

        $result = $this->service->processIndividualReservation(
            '2026-07-01',
            json_encode(['meals' => ['1' => ['1' => 1]]]),
            $this->rooms(),
            1,
            'テストユーザー',
            $this->alwaysValid()
        );

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $row = $this->fetchReservation(1, '2026-07-01', 1, 1);
        $this->assertSame(1, (int)$row->eat_flag, 'eat_flag が 1 に戻っていない');
    }

    public function testIndividualDeselectDeactivatesExistingRecord(): void
    {
        $this->insertReservation(['eat_flag' => 1]);

        $result = $this->service->processIndividualReservation(
            '2026-07-01',
            json_encode(['meals' => ['1' => ['1' => 0]]]),
            $this->rooms(),
            1,
            'テストユーザー',
            $this->alwaysValid()
        );

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $row = $this->fetchReservation(1, '2026-07-01', 1, 1);
        $this->assertSame(0, (int)$row->eat_flag, 'eat_flag が 0 になっていない');
        $this->assertSame(0, (int)$row->i_change_flag, 'i_change_flag が 0 になっていない');
    }

    public function testIndividualInvalidJsonReturnsError400(): void
    {
        try {
            $this->service->processIndividualReservation(
                '2026-07-01',
                'invalid-json{{{',
                $this->rooms(),
                1,
                'テストユーザー',
                $this->alwaysValid()
            );
            $this->fail('InvalidInputException が投げられていない');
        } catch (InvalidInputException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    public function testIndividualMissingMealsKeyReturnsError422(): void
    {
        try {
            $this->service->processIndividualReservation(
                '2026-07-01',
                json_encode(['wrong_key' => []]),
                $this->rooms(),
                1,
                'テストユーザー',
                $this->alwaysValid()
            );
            $this->fail('InvalidInputException が投げられていない');
        } catch (InvalidInputException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testIndividualDateValidationFailureReturnsError422(): void
    {
        try {
            $this->service->processIndividualReservation(
                '2020-01-01',
                json_encode(['meals' => ['1' => ['1' => 1]]]),
                $this->rooms(),
                1,
                'テストユーザー',
                $this->alwaysFail()
            );
            $this->fail('InvalidInputException が投げられていない');
        } catch (InvalidInputException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testIndividualMultipleRoomsSameMealReturnsError409(): void
    {
        $this->expectException(ConflictException::class);

        $this->service->processIndividualReservation(
            '2026-07-01',
            json_encode(['meals' => ['1' => ['1' => 1, '2' => 1]]]),
            [1 => [], 2 => []],
            1,
            'テストユーザー',
            $this->alwaysValid()
        );
    }

    public function testIndividualUnauthorizedRoomReturnsError403(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->service->processIndividualReservation(
            '2026-07-01',
            json_encode(['meals' => ['1' => ['99' => 1]]]),
            $this->rooms(),
            1,
            'テストユーザー',
            $this->alwaysValid()
        );
    }

    // =========================================================================
    // processGroupReservation
    // =========================================================================

    public function testGroupNewReservationCreatesRecord(): void
    {
        $result = $this->service->processGroupReservation(
            '2026-07-01',
            json_encode(['users' => ['1' => ['1' => 1]], 'i_id_room' => 1]),
            $this->rooms(),
            'システム管理者',
            $this->alwaysValid()
        );

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $row = $this->fetchReservation(1, '2026-07-01', 1, 1);
        $this->assertNotNull($row, 'レコードが作成されていない');
        $this->assertSame(1, (int)$row->eat_flag);
        $this->assertSame(1, (int)$row->i_change_flag);
    }

    public function testGroupDuplicateIsSkipped(): void
    {
        $this->insertReservation(['eat_flag' => 1]);

        $result = $this->service->processGroupReservation(
            '2026-07-01',
            json_encode(['users' => ['1' => ['1' => 1]], 'i_id_room' => 1]),
            $this->rooms(),
            'システム管理者',
            $this->alwaysValid()
        );

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertNotEmpty($result['data']['skipped'], 'skipped が空');
    }

    public function testGroupInvalidJsonReturnsError400(): void
    {
        try {
            $this->service->processGroupReservation(
                '2026-07-01',
                'bad-json{{{',
                $this->rooms(),
                'システム管理者',
                $this->alwaysValid()
            );
            $this->fail('InvalidInputException が投げられていない');
        } catch (InvalidInputException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    public function testGroupMissingUsersKeyReturnsError422(): void
    {
        try {
            $this->service->processGroupReservation(
                '2026-07-01',
                json_encode(['wrong_key' => []]),
                $this->rooms(),
                'システム管理者',
                $this->alwaysValid()
            );
            $this->fail('InvalidInputException が投げられていない');
        } catch (InvalidInputException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testGroupDateValidationFailureReturnsError422(): void
    {
        try {
            $this->service->processGroupReservation(
                '2020-01-01',
                json_encode(['users' => ['1' => ['1' => 1]], 'i_id_room' => 1]),
                $this->rooms(),
                'システム管理者',
                $this->alwaysFail()
            );
            $this->fail('InvalidInputException が投げられていない');
        } catch (InvalidInputException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testGroupDeactivatesExistingReservationOnDeselect(): void
    {
        $this->insertReservation(['eat_flag' => 1]);

        $result = $this->service->processGroupReservation(
            '2026-07-01',
            json_encode(['users' => ['1' => ['1' => 0]], 'i_id_room' => 1]),
            $this->rooms(),
            'システム管理者',
            $this->alwaysValid()
        );

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $row = $this->fetchReservation(1, '2026-07-01', 1, 1);
        $this->assertSame(0, (int)$row->eat_flag, 'eat_flag が 0 になっていない');
    }
}
