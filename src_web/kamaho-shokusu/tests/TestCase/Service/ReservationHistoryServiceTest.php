<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationHistoryService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ReservationHistoryService テスト
 *
 * 期間の検証・閲覧権限は外部依存なし。実食・経緯・集計は DB（フィクスチャ）で確かめる。
 */
class ReservationHistoryServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.TIndividualReservationInfo',
        'app.TAuditLog',
        'app.MUserGroup',
        'app.MRoomInfo',
    ];

    private ReservationHistoryService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ReservationHistoryService();
        // フィクスチャの既存行（2024-09-07）と混ざらないよう、テスト用の予約・監査ログは別の日付・ユーザーで入れる
        TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->deleteAll([]);
        TableRegistry::getTableLocator()->get('TAuditLog')->deleteAll([]);
    }

    // ---- validatePeriod ----

    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function periodProvider(): array
    {
        return [
            '正常（昨日まで）' => ['2026-10-01', '2026-10-29', null],
            '1日だけ' => ['2026-10-29', '2026-10-29', null],
            '93日ちょうど' => ['2026-07-29', '2026-10-29', null],
            '94日は超過' => ['2026-07-28', '2026-10-29', '期間は最大93日までです。'],
            '開始が終了より後' => ['2026-10-20', '2026-10-10', '開始日が終了日より後になっています。'],
            '終了日が今日' => ['2026-10-01', '2026-10-30', '終了日は昨日以前にしてください（当日以降は予約画面で確認します）。'],
            '終了日が未来' => ['2026-10-01', '2026-11-05', '終了日は昨日以前にしてください（当日以降は予約画面で確認します）。'],
            '日付でない' => ['abc', '2026-10-29', '日付の形式が正しくありません。'],
            '存在しない日付' => ['2026-02-30', '2026-10-29', '日付の形式が正しくありません。'],
        ];
    }

    /**
     * @dataProvider periodProvider
     */
    public function testValidatePeriod(string $from, string $to, ?string $expected): void
    {
        $this->assertSame($expected, $this->svc->validatePeriod($from, $to, '2026-10-30'));
    }

    // ---- canView ----

    public function testCanView(): void
    {
        $admin = ['id' => 1, 'admin' => 1, 'level' => 0];
        $staff = ['id' => 2, 'admin' => 0, 'level' => 0];
        $staff7 = ['id' => 5, 'admin' => 0, 'level' => 7];
        $child = ['id' => 3, 'admin' => 0, 'level' => 1];
        $otherStaff = ['id' => 6, 'admin' => 0, 'level' => 0];

        $this->assertTrue($this->svc->canView($staff, $staff), '本人は可');
        $this->assertTrue($this->svc->canView($staff, $child), '職員→子どもは可');
        $this->assertTrue($this->svc->canView($staff7, $child), '職員(level7)→子どもは可');
        $this->assertFalse($this->svc->canView($staff, $otherStaff), '職員→他の職員は不可');
        $this->assertFalse($this->svc->canView($staff, $admin), '職員→管理者は不可');
        $this->assertTrue($this->svc->canView($admin, $staff), '管理者→全員');
        $this->assertFalse($this->svc->canView($child, $staff), '子ども→他人は不可');
        $this->assertFalse($this->svc->canView($child, ['id' => 7, 'admin' => 0, 'level' => 1]), '子ども→他の子どもは不可');
    }

    public function testSelectableTargets_staffSeesChildrenAndSelfOnly(): void
    {
        $staff = $this->svc->findUser(2);
        $this->assertNotNull($staff);
        $ids = array_keys($this->svc->selectableTargets($staff));
        $this->assertContains(2, $ids, '本人');
        $this->assertContains(3, $ids, '子ども（level1）');
        $this->assertNotContains(1, $ids, '管理者は選べない');
    }

    // ---- mealRows ----

    public function testMealRows_ateAndReservedOnly(): void
    {
        $t = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $row = fn(string $d, int $type, ?int $eat, ?int $chg, int $room = 1) => $t->save($t->newEntity([
            'i_id_user' => 3, 'd_reservation_date' => $d, 'i_reservation_type' => $type, 'i_id_room' => $room,
            'eat_flag' => $eat, 'i_change_flag' => $chg, 'i_version' => 1,
        ], ['validate' => false, 'accessibleFields' => ['*' => true]]));
        $row('2024-09-02', 1, 1, 1);      // 予約して食べた
        $row('2024-09-02', 3, 1, 0);      // 予約したが食べなかった（実食で外した）
        $row('2024-09-03', 1, 0, 1);      // 予約なしで食べた
        $row('2024-09-03', 2, 1, null);   // 実食未入力 → 予約（eat_flag）で数える
        $row('2024-09-03', 2, 0, 0, 2);   // 同じ食事の別部屋の行（どれかが真なら真）
        $row('2024-08-31', 1, 1, 1);      // 期間外

        $rows = $this->svc->mealRows(3, '2024-09-01', '2024-09-30');

        $this->assertSame(['2024-09-02', '2024-09-03'], array_keys($rows));
        $this->assertSame(['reserved' => true, 'ate' => true], $rows['2024-09-02'][1]);
        $this->assertSame(['reserved' => true, 'ate' => false], $rows['2024-09-02'][3]);
        $this->assertSame(['reserved' => false, 'ate' => true], $rows['2024-09-03'][1]);
        $this->assertSame(['reserved' => true, 'ate' => true], $rows['2024-09-03'][2]);
        $this->assertSame([], $this->svc->mealRows(2, '2024-09-01', '2024-09-30'), '他の人の分は出ない');
    }

    // ---- roomSummary ----

    public function testRoomSummary_countsAteOnlyPerRoom(): void
    {
        $t = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        foreach ([[3, 1, 1], [2, 1, 1], [3, 1, 2]] as [$user, $room, $chg]) {
            $t->save($t->newEntity([
                'i_id_user' => $user, 'd_reservation_date' => '2024-09-02', 'i_reservation_type' => 1, 'i_id_room' => $room,
                'eat_flag' => 1, 'i_change_flag' => $user === 2 ? 0 : 1, 'i_version' => 1,
            ], ['validate' => false, 'accessibleFields' => ['*' => true]]));
        }

        $sum = $this->svc->roomSummary(1, '2024-09-01', '2024-09-30');

        $this->assertSame(['2024-09-02' => [1 => 1]], $sum, '部屋1: ユーザー3は食べた(1)、ユーザー2は食べていない(0)');
        $this->assertSame([], $this->svc->roomSummary(9, '2024-09-01', '2024-09-30'));
    }

    // ---- logRows ----

    public function testLogRows_targetDetectionAndRoleLabels(): void
    {
        $t = TableRegistry::getTableLocator()->get('TAuditLog');
        $log = fn(string $action, ?string $targetId, int $actor, string $actorName, array $detail, string $at, int $result = 1) => $t->save($t->newEntity([
            'c_category' => 'reservation', 'c_action' => $action, 'c_target_table' => 'x', 'c_target_id' => $targetId,
            'i_actor_user_id' => $actor, 'c_actor_user_name' => $actorName, 'i_result' => $result,
            'c_detail' => json_encode($detail), 'dt_create' => $at,
        ], ['validate' => false, 'accessibleFields' => ['*' => true]]));
        // 対象者3: 本人の予約(旧形式=user_idなし)、職員2による代理予約、管理者1による代理取消、実食保存、失敗、期間外、他人
        $log('reservation_toggle', 'room:1', 3, '子ども', ['date' => '2024-09-05', 'meal' => 1, 'value' => 1], '2024-08-20 09:00:00');
        $log('reservation_toggle', 'room:1', 2, '職員', ['date' => '2024-09-05', 'meal' => 3, 'value' => 1, 'user_id' => 3], '2024-08-21 09:00:00');
        $log('reservation_toggle', 'room:1', 1, '管理者', ['date' => '2024-09-05', 'meal' => 3, 'value' => 0, 'user_id' => 3], '2024-08-22 09:00:00');
        $log('actual_meal_save', '3:2024-09-05:1:1', 2, '職員', ['checked' => true, 'meal_type' => 1], '2024-09-06 10:00:00');
        $log('reservation_toggle', 'room:1', 2, '職員', ['date' => '2024-09-05', 'meal' => 1, 'value' => 1, 'user_id' => 3], '2024-08-23 09:00:00', 0); // 失敗は出さない
        $log('reservation_toggle', 'room:1', 2, '職員', ['date' => '2024-10-05', 'meal' => 1, 'value' => 1, 'user_id' => 3], '2024-08-24 09:00:00'); // 期間外
        $log('reservation_toggle', 'room:1', 2, '職員', ['date' => '2024-09-05', 'meal' => 1, 'value' => 1, 'user_id' => 33], '2024-08-25 09:00:00'); // ユーザー33（3ではない）
        $log('actual_meal_save', '33:2024-09-05:1:1', 2, '職員', ['checked' => true, 'meal_type' => 1], '2024-09-06 11:00:00');

        $rows = $this->svc->logRows(3, '2024-09-01', '2024-09-30');

        $this->assertCount(4, $rows);
        // 新しい順
        $this->assertSame('ate', $rows[0]['kind']);
        $this->assertSame('代理', $rows[0]['actor']);
        $this->assertSame('cancel', $rows[1]['kind']);
        $this->assertSame('管理者', $rows[1]['actor']);
        $this->assertSame('reserve', $rows[2]['kind']);
        $this->assertSame('代理', $rows[2]['actor']);
        $this->assertSame('本人', $rows[3]['actor']);
        $this->assertSame('夜', ReservationHistoryService::MEAL_LABELS[$rows[1]['meal']]);
        // 名前は既定では出ない（IP・失敗理由も含まない）
        $this->assertStringNotContainsString('職員', $rows[0]['actor']);
        $withName = $this->svc->logRows(3, '2024-09-01', '2024-09-30', true);
        $this->assertSame('代理（職員）', $withName[0]['actor']);
    }
}
