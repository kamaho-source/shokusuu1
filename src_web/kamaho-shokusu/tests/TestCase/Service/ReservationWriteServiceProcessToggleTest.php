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

    // ----------------------------------------------------------------
    // processBulkToggle（エクセル食数予約の一括登録）
    // ----------------------------------------------------------------

    public function testProcessBulkToggle_emptyItemsReturnsEmpty(): void
    {
        $this->assertSame([], $this->service->processBulkToggle([], 1, '管理者ユーザー'));
    }

    public function testProcessBulkToggle_returnsResultPerItemInOrder(): void
    {
        // いずれも過去日で失敗するが、item 単位で結果が順序どおり返ることを確認する。
        $items = [
            ['roomId' => 1, 'userId' => 1, 'date' => '2020-01-01', 'meal' => 1, 'value' => 1],
            ['roomId' => 1, 'userId' => 1, 'date' => '2020-01-02', 'meal' => 2, 'value' => 1],
        ];

        $results = $this->service->processBulkToggle($items, 1, '管理者ユーザー');

        $this->assertCount(2, $results);
        $this->assertFalse($results[0]['ok']);
        $this->assertFalse($results[1]['ok']);
        $this->assertStringContainsString('過去日', $results[0]['message']);
    }

    public function testProcessBulkToggle_oneFailureDoesNotStopOthers(): void
    {
        // 1件目は過去日エラー、2件目は存在しないユーザー。どちらも独立して失敗結果になり、
        // 片方の失敗が全体を止めない（一部成功・一部失敗の許容）ことを確認する。
        $future = date('Y-m-d', strtotime('+20 days'));
        $items = [
            ['roomId' => 1, 'userId' => 1,     'date' => '2020-01-01', 'meal' => 1, 'value' => 1],
            ['roomId' => 1, 'userId' => 99999, 'date' => $future,      'meal' => 1, 'value' => 1],
        ];

        $results = $this->service->processBulkToggle($items, 1, '管理者ユーザー');

        $this->assertCount(2, $results);
        $this->assertFalse($results[0]['ok']);
        $this->assertFalse($results[1]['ok']);
    }

    public function testProcessBulkToggle_successForValidFutureItem(): void
    {
        // 管理者(user1)が自分の所属部屋(room1)へ未来日の予約を登録 → 成功。
        $future = date('Y-m-d', strtotime('+20 days'));
        $items = [
            ['roomId' => 1, 'userId' => 1, 'date' => $future, 'meal' => 1, 'value' => 1],
        ];

        $results = $this->service->processBulkToggle($items, 1, '管理者ユーザー');

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['ok'], $results[0]['message'] ?? '');
    }

    public function testProcessBulkToggle_userIdOmittedFallsBackToLoginUser(): void
    {
        // userId を省略した item は「自分の予約」として扱われ、ログインユーザー(user1)で成功する。
        // （processToggle が userId 未指定時に loginUserId を使う挙動に揃っていることの確認）
        $future = date('Y-m-d', strtotime('+21 days'));
        $items = [
            ['roomId' => 1, 'date' => $future, 'meal' => 2, 'value' => 1],
        ];

        $results = $this->service->processBulkToggle($items, 1, '管理者ユーザー');

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['ok'], $results[0]['message'] ?? '');
    }

    public function testProcessBulkToggle_systemErrorHidesSqlAndShowsErrorCode(): void
    {
        // 1件目: DB エラー（デッドロック）、2件目: DB 接続エラー、3件目: 通常のエラー（過去日）。
        $service = new class (
            TableRegistry::getTableLocator()->get('TIndividualReservationInfo'),
            TableRegistry::getTableLocator()->get('MUserInfo'),
            TableRegistry::getTableLocator()->get('MRoomInfo'),
            '/webroot/'
        ) extends ReservationWriteService {
            public function processToggle(int $roomId, array $payload, int $loginUserId, string $loginUserName, bool $invalidateCache = true): array
            {
                return match ((int)$payload['meal']) {
                    1 => throw new \Cake\Database\Exception\QueryException(
                        'UPDATE t_individual_reservation_info SET i_change_flag = 1',
                        new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock')
                    ),
                    2 => throw new \PDOException('SQLSTATE[HY000] [2002] Connection refused'),
                    default => parent::processToggle($roomId, $payload, $loginUserId, $loginUserName, $invalidateCache),
                };
            }
        };
        $items = [
            ['roomId' => 1, 'userId' => 1, 'date' => '2099-01-01', 'meal' => 1, 'value' => 1],
            ['roomId' => 1, 'userId' => 1, 'date' => '2099-01-01', 'meal' => 2, 'value' => 1],
            ['roomId' => 1, 'userId' => 1, 'date' => '2020-01-01', 'meal' => 3, 'value' => 1],
        ];

        $results = $service->processBulkToggle($items, 1, '管理者ユーザー');

        $this->assertSame(
            '予約の更新に失敗しました（他の操作と同時に更新されたため。もう一度お試しください [エラーコード: DB-1213]）',
            $results[0]['message']
        );
        $this->assertSame(
            '予約の更新に失敗しました（システムエラーのため。管理者にお問い合わせください [エラーコード: DB-2002]）',
            $results[1]['message']
        );
        foreach ([$results[0]['message'], $results[1]['message']] as $msg) {
            $this->assertStringNotContainsString('SQLSTATE', $msg);
            $this->assertStringNotContainsString('t_individual_reservation_info', $msg);
            $this->assertStringNotContainsString('データベース', $msg);
        }
        // 通常のエラーはこれまでどおり具体的な理由が出る（エラーコードは付かない）。
        $this->assertStringContainsString('過去日', $results[2]['message']);
        $this->assertStringNotContainsString('エラーコード', $results[2]['message']);
    }
}
