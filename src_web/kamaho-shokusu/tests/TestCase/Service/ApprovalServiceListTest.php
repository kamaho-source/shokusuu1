<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ApprovalService;
use App\Infrastructure\Persistence\Table\TReservationInfoTable;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

/**
 * ApprovalService の予約情報への反映のテスト（一覧・件数・集計は CakeApprovalQueryTest）。
 *
 * 前提データ（setUp で投入。日付はすべて過去日なので eat_flag がそのまま数えられる）:
 *   - ユーザー1,2 は大人(i_user_level=7)、ユーザー3 は子ども(i_user_level=1) = 一覧に出ない
 *   - ユーザー1,2,3,6 はブロック1に所属（ユーザー6は削除済み）
 */
class ApprovalServiceListTest extends TestCase
{
    protected array $fixtures = [
        'app.TIndividualReservationInfo',
        'app.TReservationInfo',
        'app.TApprovalLog',
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.MUserGroup',
        'app.TAuditLog',
    ];

    private ApprovalService $service;
    private Table $individual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ApprovalService(new \App\Infrastructure\Query\CakeRoomQuery());
        $this->individual = $this->getTableLocator()->get('TIndividualReservationInfo');

        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_user_level' => 7], ['i_id_user IN' => [1, 2]]);
        // 既存フィクスチャ(user1 / 2024-09-07 / 朝)は未承認のまま使う
        $this->add(2, '2024-09-07', 1, 1, 0);          // 未承認
        $this->add(2, '2024-09-08', 1, 3, 1);          // ブロック長承認済
        $this->add(1, '2024-09-09', 1, 1, 2);          // 最終承認済 食べる
        $this->add(2, '2024-09-09', 1, 1, 2, 0);       // 最終承認済 食べない
        $this->add(1, '2024-09-09', 1, 3, 2);          // 最終承認済 夕
        $this->add(2, '2024-09-10', 1, 2, 2);          // 最終承認済 昼
        $this->add(2, '2024-09-11', 1, 4, 2);          // 最終承認済 弁当
        $this->add(3, '2024-09-09', 1, 1, 0);          // 子ども（対象外）
        $this->add(6, '2024-09-09', 1, 1, 2);          // 削除済みユーザー（反映対象外）
    }

    private function add(int $userId, string $date, int $roomId, int $type, int $status, int $eat = 1): void
    {
        $this->individual->saveOrFail($this->individual->newEntity([
            'i_id_user' => $userId,
            'd_reservation_date' => $date,
            'i_id_room' => $roomId,
            'i_reservation_type' => $type,
            'eat_flag' => $eat,
            'dt_create' => '2024-09-01 00:00:00',
            'c_create_user' => 't',
            'dt_update' => '2024-09-01 00:00:00',
            'c_update_user' => 't',
        ]));
        // i_approval_status は一括代入できない項目なので別途更新する
        $this->individual->updateAll(['i_approval_status' => $status], [
            'i_id_user' => $userId,
            'd_reservation_date' => $date,
            'i_id_room' => $roomId,
            'i_reservation_type' => $type,
        ]);
    }

    public function testReflectToReservation_upsertsCountsExcludingDeletedUsers(): void
    {
        [$groups, $records] = $this->service->reflectToReservation(null, null, null, 'actor');

        // 最終承認済みのうち削除済みユーザー(6)を除く 5 件 / 2024-09-09朝,夕・09-10昼・09-11弁当 の 4 グループ
        $this->assertSame(5, $records);
        $this->assertSame(4, $groups);

        $reservation = $this->getTableLocator()->get('TReservationInfo');
        $breakfast = $reservation->find()->where(['d_reservation_date' => '2024-09-09', 'c_reservation_type' => 1])->firstOrFail();
        $this->assertSame(1, (int)$breakfast->i_taberu_ninzuu);
        $this->assertSame(1, (int)$breakfast->i_tabenai_ninzuu);
        $this->assertSame('actor', $breakfast->c_create_user);
    }

    public function testReflectToReservation_secondRunUpdatesExistingRows(): void
    {
        $this->service->reflectToReservation(1, '2024-09-09', '2024-09-09', 'first');
        $this->individual->updateAll(['eat_flag' => 0], ['i_id_user' => 1, 'd_reservation_date' => '2024-09-09', 'i_reservation_type' => 1]);

        [$groups, $records] = $this->service->reflectToReservation(1, '2024-09-09', '2024-09-09', 'second');
        $this->assertSame(2, $groups);
        $this->assertSame(3, $records);

        $reservation = $this->getTableLocator()->get('TReservationInfo');
        $this->assertSame(
            2,
            $reservation->find()->where(['d_reservation_date' => '2024-09-09'])->count(),
            '既存行は更新され重複して増えない'
        );
        $breakfast = $reservation->find()->where(['d_reservation_date' => '2024-09-09', 'c_reservation_type' => 1])->firstOrFail();
        $this->assertSame(0, (int)$breakfast->i_taberu_ninzuu);
        $this->assertSame(2, (int)$breakfast->i_tabenai_ninzuu);
        $this->assertSame('second', $breakfast->c_update_user);
        $this->assertSame('first', $breakfast->c_create_user);
    }

    public function testReflectToReservation_nothingMatching_returnsZeroes(): void
    {
        $reservation = $this->getTableLocator()->get('TReservationInfo');
        $before = $reservation->find()->count();

        $this->assertSame([0, 0], $this->service->reflectToReservation(99, null, null, 'a'));
        $this->assertSame([0, 0], $this->service->reflectToReservation(null, '2030-01-01', '2030-12-31', 'a'));
        $this->assertSame($before, $reservation->find()->count());
    }

    /**
     * 集計(t_reservation_info)の保存を失敗させる。$throw=true は DB エラー、false は save() が false を返す（検証エラーなど）。
     */
    private function failReservationSave(bool $throw): void
    {
        $mock = $this->getMockBuilder(TReservationInfoTable::class)
            ->onlyMethods(['save'])
            ->setConstructorArgs([['connection' => ConnectionManager::get('test'), 'alias' => 'TReservationInfo', 'table' => 't_reservation_info']])
            ->getMock();
        $save = $mock->method('save');
        $throw ? $save->willThrowException(new \RuntimeException('db down')) : $save->willReturn(false);
        $this->getTableLocator()->set('TReservationInfo', $mock);
    }

    /**
     * #989 不具合3: 集計への反映で失敗したら、承認状態・承認ログも取り消され、監査ログに「成功」が残らない。
     *
     * @return array<string, array{bool}>
     */
    public static function reflectFailureProvider(): array
    {
        return ['例外' => [true], 'save が false' => [false]];
    }

    /**
     * @dataProvider reflectFailureProvider
     */
    public function testAdminApprove_reflectFailure_rollsBackApproval(bool $throw): void
    {
        $this->failReservationSave($throw);
        $key = ['i_id_user' => 2, 'd_reservation_date' => '2024-09-08', 'i_id_room' => 1, 'i_reservation_type' => 3];

        try {
            $this->service->adminApprove([$key], 1, 'admin');
            $this->fail('反映の失敗は例外で知らせるはず');
        } catch (\RuntimeException $e) {
            // 想定どおり
        }

        $row = $this->individual->find()->where($key)->firstOrFail();
        $this->assertSame(1, (int)$row->i_approval_status, '承認状態は元のまま（ブロック長承認済）');
        $this->assertSame(0, $this->getTableLocator()->get('TApprovalLog')->find()->count(), '承認ログも取り消される');
        $this->assertFalse(
            $this->getTableLocator()->get('TAuditLog')->exists(['c_action' => 'approval_admin', 'i_result' => 1]),
            '監査ログに成功と記録しない'
        );
        $this->assertTrue(
            $this->getTableLocator()->get('TAuditLog')->exists(['c_action' => 'approval_admin', 'i_result' => 0]),
            '取り消したことは失敗として監査ログに残す'
        );
    }
}
