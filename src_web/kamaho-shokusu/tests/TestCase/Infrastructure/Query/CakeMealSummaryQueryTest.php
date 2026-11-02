<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Query;

use App\Application\Query\Dto\StaffMealCount;
use App\Infrastructure\Query\CakeMealSummaryQuery;
use Cake\Chronos\ClockFactory;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * 控除表のための、職員ごとの月の食数（CakeMealSummaryQuery）。旧 MealSummaryExportServiceTest の aggregate の部分を引き継ぐ。
 */
class CakeMealSummaryQueryTest extends TestCase
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

    /** @return list<array{staff_id: int|string|null, meal_counts: array<string, int>}> */
    private function aggregate(int $year, int $month): array
    {
        return array_map(
            static fn(StaffMealCount $c): array => ['staff_id' => $c->staffId, 'meal_counts' => $c->counts],
            (new CakeMealSummaryQuery(new ClockFactory('Asia/Tokyo')))->staffMealCounts($year, $month)
        );
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
        
        $sep = $this->totalsByStaff($this->aggregate(2026, 9));
        $this->assertSame([9002 => 1, 9006 => 2], $sep); // 9/1 と 9/30 は9月、10/1 は含まない

        $oct = $this->totalsByStaff($this->aggregate(2026, 10));
        $this->assertSame([9002 => 0, 9006 => 1], $oct);

        $aug = $this->totalsByStaff($this->aggregate(2026, 8));
        $this->assertSame([9002 => 0], $aug); // 実績のない月は削除済み職員を載せない
    }

    /** 承認機能オンのときは、承認済み(2)のみを数える（削除済み職員も同様） */
    public function testAggregate_approvalOn_countsOnlyApproved(): void
    {
        Configure::write('Features.approval', true);
        $this->seedStaffAndMeals();
        $db = ConnectionManager::get('test');
        $db->update('t_individual_reservation_info', ['i_approval_status' => 2], ['i_id_user' => 6, 'd_reservation_date' => '2026-09-30']);

        $sep = $this->totalsByStaff($this->aggregate(2026, 9));

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

        $this->aggregate(2026, 9);

        $reservationQueries = array_filter($queries, fn(string $m) => str_contains($m, 'FROM t_individual_reservation_info'));
        $this->assertCount(1, $reservationQueries);
    }

    public function testApprovalFilterConditions(): void
    {
        Configure::write('Features.approval', true);
        $this->assertSame(['i_approval_status' => 2], CakeMealSummaryQuery::approvalFilterConditions());
        Configure::write('Features.approval', false);
        $this->assertSame([], CakeMealSummaryQuery::approvalFilterConditions());
    }

    /** 食数の内訳は 弁当・朝・昼・夕 の順のキー（Excel の列と同じ）で、知らない食事は数えない */
    public function testCountsByMealTypeInExportOrder(): void
    {
        Configure::write('Features.approval', false);
        $this->seedStaffAndMeals();
        $db = ConnectionManager::get('test');
        // 9/16 の夕食は直前に取り消し（i_change_flag=0）たので数えない
        foreach ([['2026-09-11', 1, 1, null], ['2026-09-12', 4, 1, null], ['2026-09-13', 4, 1, null], ['2026-09-14', 9, 1, null], ['2026-09-16', 3, 1, 0]] as [$d, $t, $eat, $chg]) {
            $db->insert('t_individual_reservation_info', [
                'i_id_user' => 2, 'd_reservation_date' => $d, 'i_reservation_type' => $t, 'i_id_room' => 1,
                'eat_flag' => $eat, 'i_change_flag' => $chg, 'i_version' => 1, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
            ]);
        }
        $row = array_values(array_filter($this->aggregate(2026, 9), static fn($r) => (int)$r['staff_id'] === 9002))[0];

        $this->assertSame(['bento' => 2, 'morning' => 1, 'lunch' => 1, 'dinner' => 0], $row['meal_counts']);
    }
}
