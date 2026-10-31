<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationHistoryService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ReservationHistoryService テスト
 *
 * 期間の検証は外部依存なし。見られる範囲・予約状況・経緯・集計は DB（フィクスチャ）で確かめる。
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

    // ---- selectableTargets（見られる範囲） ----

    /**
     * 部屋1: 管理者1・職員2・子ども3・ブロック長8・その他9（6は削除済み）
     * 部屋2: 子ども7・職員10（部屋1の人とは別）。職員11 は部屋1を退所済み
     */
    private function seedRooms(): void
    {
        $users = TableRegistry::getTableLocator()->get('MUserInfo');
        $groups = TableRegistry::getTableLocator()->get('MUserGroup');
        $add = function (int $id, int $admin, ?int $level, array $rooms, int $active = 0) use ($users, $groups): void {
            $users->save($users->newEntity([
                'i_id_user' => $id, 'c_user_name' => 'u' . $id, 'i_admin' => $admin, 'i_user_level' => $level,
                'i_disp_no' => $id, 'i_del_flag' => 0,
            ], ['validate' => false, 'accessibleFields' => ['*' => true]]));
            foreach ($rooms as $room) {
                $groups->save($groups->newEntity(
                    ['i_id_user' => $id, 'i_id_room' => $room, 'active_flag' => $active],
                    ['validate' => false, 'accessibleFields' => ['*' => true]]
                ));
            }
        };
        $add(7, 0, 1, [2]);
        $add(8, 2, 0, [1]);
        $add(9, 0, 3, [1]);
        $add(10, 0, 0, [2]);
        $add(11, 0, 1, [1], 1);
    }

    /** @return list<int> */
    private function targetsOf(int $userId): array
    {
        $actor = $this->svc->findUser($userId);
        $this->assertNotNull($actor);

        return array_keys($this->svc->selectableTargets($actor));
    }

    public function testSelectableTargets_staffSeesSelfAndChildrenOfOwnRooms(): void
    {
        $this->seedRooms();
        $this->assertSame([2, 3], $this->targetsOf(2), '本人が先頭。部屋1の子どもだけ（大人・他の部屋の子ども・退所者・削除済みは出ない）');
        $this->assertSame([10, 7], $this->targetsOf(10), '部屋2の職員は部屋2の子どもだけ');
    }

    public function testSelectableTargets_blockLeaderSeesAdultsAndChildrenOfOwnRooms(): void
    {
        $this->seedRooms();
        $this->assertSame([8, 1, 2, 3, 9], $this->targetsOf(8), '本人が先頭。部屋1の大人と子ども（他の部屋・退所者・削除済みは出ない）');
    }

    public function testSelectableTargets_adminSeesEveryone(): void
    {
        $this->seedRooms();
        $ids = $this->targetsOf(5);
        $this->assertSame(5, $ids[0], '本人が先頭');
        $this->assertEqualsCanonicalizing([1, 2, 3, 4, 5, 7, 8, 9, 10, 11], $ids, '削除済み(6)以外の全員');
        $this->assertSame(4, $this->targetsOf(4)[0], 'システム管理者も本人が先頭');
        $this->assertCount(10, $this->targetsOf(4));
    }

    public function testSelectableTargets_childSeesSelfOnly(): void
    {
        $this->seedRooms();
        $this->assertSame([3], $this->targetsOf(3));
    }

    // ---- mealRows ----

    public function testMealRows_bookedByLastMinuteFlag(): void
    {
        $t = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $row = fn(string $d, int $type, ?int $eat, ?int $chg, int $room = 1) => $t->save($t->newEntity([
            'i_id_user' => 3, 'd_reservation_date' => $d, 'i_reservation_type' => $type, 'i_id_room' => $room,
            'eat_flag' => $eat, 'i_change_flag' => $chg, 'i_version' => 1,
        ], ['validate' => false, 'accessibleFields' => ['*' => true]]));
        $row('2024-09-02', 1, 1, 1);      // 予約済み
        $row('2024-09-02', 3, 1, 0);      // 直前編集で取消（eat_flag は元の予約のまま） → 予約済みではない
        $row('2024-09-03', 1, 0, 1);      // 直前編集で追加 → 予約済み
        $row('2024-09-03', 2, 1, null);   // 直前編集なし → eat_flag で判定
        $row('2024-09-03', 2, 0, 0, 2);   // 同じ食事の別部屋の行（どれかが予約済みなら予約済み）
        $row('2024-09-04', 1, 1, 0);      // 取消だけの日 → 行を出さない
        $row('2024-08-31', 1, 1, 1);      // 期間外

        $rows = $this->svc->mealRows(3, '2024-09-01', '2024-09-30');

        $this->assertSame([
            '2024-09-02' => [1 => true],
            '2024-09-03' => [1 => true, 2 => true],
        ], $rows);
        $this->assertSame([], $this->svc->mealRows(2, '2024-09-01', '2024-09-30'), '他の人の分は出ない');
    }

    // ---- roomSummary ----

    public function testRoomSummary_countsBookedOnlyPerRoom(): void
    {
        $t = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        foreach ([[3, 1, 1], [2, 1, 1], [3, 1, 2]] as [$user, $room, $chg]) {
            $t->save($t->newEntity([
                'i_id_user' => $user, 'd_reservation_date' => '2024-09-02', 'i_reservation_type' => 1, 'i_id_room' => $room,
                'eat_flag' => 1, 'i_change_flag' => $user === 2 ? 0 : 1, 'i_version' => 1,
            ], ['validate' => false, 'accessibleFields' => ['*' => true]]));
        }

        $sum = $this->svc->roomSummary(1, '2024-09-01', '2024-09-30');

        $this->assertSame(['2024-09-02' => [1 => 1]], $sum, '部屋1: ユーザー3は予約済み、ユーザー2は直前編集で取消');
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
        // 対象者3: 本人の予約(旧形式=user_idなし)、職員2による代理予約、管理者1による代理取消、実食保存（出さない）、失敗、期間外、他人
        $log('reservation_toggle', 'room:1', 3, '子ども', ['date' => '2024-09-05', 'meal' => 1, 'value' => 1], '2024-08-20 09:00:00');
        $log('reservation_toggle', 'room:1', 2, '職員', ['date' => '2024-09-05', 'meal' => 3, 'value' => 1, 'user_id' => 3], '2024-08-21 09:00:00');
        $log('reservation_toggle', 'room:1', 1, '管理者', ['date' => '2024-09-05', 'meal' => 3, 'value' => 0, 'user_id' => 3], '2024-08-22 09:00:00');
        $log('actual_meal_save', '3:2024-09-05:1:1', 2, '職員', ['checked' => true, 'meal_type' => 1], '2024-09-06 10:00:00');
        $log('reservation_toggle', 'room:1', 2, '職員', ['date' => '2024-09-05', 'meal' => 1, 'value' => 1, 'user_id' => 3], '2024-08-23 09:00:00', 0); // 失敗は出さない
        $log('reservation_toggle', 'room:1', 2, '職員', ['date' => '2024-10-05', 'meal' => 1, 'value' => 1, 'user_id' => 3], '2024-08-24 09:00:00'); // 期間外
        $log('reservation_toggle', 'room:1', 2, '職員', ['date' => '2024-09-05', 'meal' => 1, 'value' => 1, 'user_id' => 33], '2024-08-25 09:00:00'); // ユーザー33（3ではない）
        $log('actual_meal_save', '33:2024-09-05:1:1', 2, '職員', ['checked' => true, 'meal_type' => 1], '2024-09-06 11:00:00');

        $rows = $this->svc->logRows(3, '2024-09-01', '2024-09-30');

        // 新しい順。実食機能はオフのため実食保存は出さない
        $this->assertSame(['cancel', 'reserve', 'reserve'], array_column($rows, 'kind'));
        $this->assertSame(['管理者', '代理', '本人'], array_column($rows, 'actor'));
        $this->assertSame('夜', ReservationHistoryService::MEAL_LABELS[$rows[0]['meal']]);
        // 名前は指定したときだけ（IP・失敗理由は含まない）
        $withName = $this->svc->logRows(3, '2024-09-01', '2024-09-30', true);
        $this->assertSame('代理（職員）', $withName[1]['actor']);
    }

    public function testLogStartDate(): void
    {
        $t = TableRegistry::getTableLocator()->get('TAuditLog');
        $t->deleteAll([]);
        $this->assertNull($this->svc->logStartDate());

        foreach (['2024-08-20 09:00:00', '2024-08-15 23:59:00'] as $at) {
            $t->save($t->newEntity([
                'c_category' => 'reservation', 'c_action' => 'reservation_toggle', 'c_target_table' => 'x',
                'i_actor_user_id' => 1, 'c_actor_user_name' => 't', 'i_result' => 1, 'c_detail' => '{}', 'dt_create' => $at,
            ], ['validate' => false, 'accessibleFields' => ['*' => true]]));
        }
        $this->assertSame('2024-08-15', $this->svc->logStartDate());
    }
}
