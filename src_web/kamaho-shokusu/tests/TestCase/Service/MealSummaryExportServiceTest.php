<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\MealSummaryExportService;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * MealSummaryExportService テスト
 *
 * 承認機能フラグ(Features.approval)によって、食事控除表(集計)の
 * 承認ステータス絞り込みが切り替わることを検証する。
 */
class MealSummaryExportServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.TIndividualReservationInfo',
        'app.MMealPriceInfo',
    ];

    private mixed $originalApproval = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalApproval = Configure::read('Features.approval');
    }

    protected function tearDown(): void
    {
        Configure::write('Features.approval', $this->originalApproval);
        parent::tearDown();
    }

    public function testApprovalFilter_whenApprovalEnabled_limitsToApproved(): void
    {
        Configure::write('Features.approval', true);

        // 承認ON: 承認済み(2)のみを集計対象にする
        $this->assertSame(
            ['i_approval_status' => 2],
            MealSummaryExportService::approvalFilterConditions()
        );
    }

    public function testApprovalFilter_whenApprovalDisabled_noStatusFilter(): void
    {
        Configure::write('Features.approval', false);

        // 承認OFF: 承認ステータスで絞らない（有効予約をそのまま集計）
        $this->assertSame([], MealSummaryExportService::approvalFilterConditions());
    }

    /** user 2=有効職員, user 6=削除済み職員（MUserInfoFixture）に職員IDを付け、予約を入れる */
    private function seedStaffAndMeals(): void
    {
        $db = ConnectionManager::get('test');
        $db->execute('UPDATE m_user_info SET i_id_staff = NULL');
        $db->update('m_user_info', ['i_id_staff' => 9002], ['i_id_user' => 2]);
        $db->update('m_user_info', ['i_id_staff' => 9006], ['i_id_user' => 6]);
        $db->execute('DELETE FROM t_individual_reservation_info');
        // [user, date, type(2=昼)]: 削除済みは 9月に2食、10月に1食 / 有効職員は 9月に1食
        foreach ([[6, '2026-09-30', 2], [6, '2026-09-01', 3], [6, '2026-10-01', 2], [2, '2026-09-15', 2]] as [$u, $d, $t]) {
            $db->insert('t_individual_reservation_info', [
                'i_id_user' => $u, 'd_reservation_date' => $d, 'i_reservation_type' => $t, 'i_id_room' => 1,
                'eat_flag' => 1, 'i_version' => 1,
                'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
            ]);
        }
    }

    /** @return array<int, int> staff_id => 食事の合計回数 */
    private function totalsByStaff(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['staff_id']] = array_sum($r['meal_counts']);
        }
        return $out;
    }

    /** 削除済み職員は、実績のある月だけ載り、月の境界（月初・月末）も正しく数える */
    public function testAggregate_deletedStaffOnlyWhenMonthHasMeals(): void
    {
        Configure::write('Features.approval', false);
        $this->seedStaffAndMeals();
        $service = new MealSummaryExportService();

        $sep = $this->totalsByStaff($service->aggregate(2026, 9));
        $this->assertSame([9002 => 1, 9006 => 2], $sep); // 9/1 と 9/30 は9月、10/1 は含まない

        $oct = $this->totalsByStaff($service->aggregate(2026, 10));
        $this->assertSame([9002 => 0, 9006 => 1], $oct);

        $aug = $this->totalsByStaff($service->aggregate(2026, 8));
        $this->assertSame([9002 => 0], $aug); // 実績のない月は削除済み職員を載せない
    }

    /** 承認機能オンのときは、承認済み(2)のみを数える（削除済み職員も同様） */
    public function testAggregate_approvalOn_countsOnlyApproved(): void
    {
        Configure::write('Features.approval', true);
        $this->seedStaffAndMeals();
        $db = ConnectionManager::get('test');
        $db->update('t_individual_reservation_info', ['i_approval_status' => 2], ['i_id_user' => 6, 'd_reservation_date' => '2026-09-30']);

        $sep = $this->totalsByStaff((new MealSummaryExportService())->aggregate(2026, 9));

        $this->assertSame(1, $sep[9006]);
    }

    /** 予約の取得は、職員の人数に関わらず1回のクエリ（N+1 でない） */
    public function testAggregate_queriesReservationsOnce(): void
    {
        Configure::write('Features.approval', false);
        $this->seedStaffAndMeals();
        $db = ConnectionManager::get('test');
        $db->execute("UPDATE m_user_info SET i_id_staff = 9000 + i_id_user");

        $queries = [];
        $logger = new class ($queries) extends \Psr\Log\AbstractLogger {
            public function __construct(public array &$q) {}
            public function log($level, string|\Stringable $message, array $context = []): void { $this->q[] = (string)$message; }
        };
        $db->getDriver()->setLogger($logger);

        (new MealSummaryExportService())->aggregate(2026, 9);

        $reservationQueries = array_filter($queries, fn(string $m) => str_contains($m, 'FROM t_individual_reservation_info'));
        $this->assertCount(1, $reservationQueries);
    }

    /** 未承認プレビューは、未承認(0)・ブロック長承認済(1)のみを状態別の行で返す */
    public function testAggregatePreview_splitsByStatusAndSkipsOthers(): void
    {
        $this->seedStaffAndMeals();
        $db = ConnectionManager::get('test');
        $db->update('t_individual_reservation_info', ['i_approval_status' => 1], ['i_id_user' => 6, 'd_reservation_date' => '2026-09-30']);
        $db->update('t_individual_reservation_info', ['i_approval_status' => 2], ['i_id_user' => 2]); // 承認済みは対象外

        $rows = (new MealSummaryExportService())->aggregatePreview(2026, 9)['rows'];

        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['staff_id'] . ':' . $r['approval_status']] = array_sum($r['meal_counts']);
        }
        $this->assertSame(['9006:0' => 1, '9006:1' => 1], $byKey);
    }
}
