<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\RestoreUser;

use App\Application\Port\AuditActor;
use App\Application\UseCase\DeleteUser\DeleteUserInput;
use App\Application\UseCase\DeleteUser\DeleteUserUseCase;
use App\Application\UseCase\RestoreUser\RestoreUserInput;
use App\Application\UseCase\RestoreUser\RestoreUserUseCase;
use App\Infrastructure\Log\AuditLogAdapter;
use App\Infrastructure\Persistence\CakeTransactionManager;
use App\Infrastructure\Repository\CakeReservationRepository;
use App\Infrastructure\Repository\CakeUserAccountRepository;
use Cake\Chronos\ClockFactory;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * 削除 → 復元で、削除の直前に所属していた部屋だけが戻ることを、本物の DB で確かめる（#936）。
 * 利用者 50 を使う。部屋 1・2 に所属中、部屋 3 は部屋異動で外れた昔の所属。
 */
class RestoreUserRoomsIntegrationTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.TIndividualReservationInfo',
        'app.TAuditLog',
    ];

    private const USER = 50;

    protected function setUp(): void
    {
        parent::setUp();
        $db = ConnectionManager::get('test');
        $db->insert('m_user_info', ['i_id_user' => self::USER, 'c_login_account' => 'restore_test', 'c_user_name' => '復元テスト', 'i_del_flag' => 0, 'i_enable' => 0, 'dt_update' => '2026-01-01 00:00:00']);
        foreach ([[1, 0, '2026-01-01 00:00:00'], [2, 0, '2026-01-01 00:00:00'], [3, 1, '2026-04-01 10:00:00']] as [$room, $active, $updated]) {
            $db->insert('m_user_group', ['i_id_user' => self::USER, 'i_id_room' => $room, 'active_flag' => $active, 'dt_update' => $updated, 'c_update_user' => 'seed']);
        }
    }

    private function repo(): CakeUserAccountRepository
    {
        return new CakeUserAccountRepository($this->getTableLocator()->get('MUserInfo'));
    }

    private function delete(): void
    {
        $tables = $this->getTableLocator();
        (new DeleteUserUseCase(
            $this->repo(),
            new CakeReservationRepository($tables->get('TIndividualReservationInfo')),
            new CakeTransactionManager($tables->get('MUserInfo')->getConnection()),
            new AuditLogAdapter(),
            new ClockFactory('Asia/Tokyo'),
        ))->execute(new DeleteUserInput(self::USER, new AuditActor('管理者', 1, 'admin', '')));
    }

    private function restore(): int
    {
        $users = $this->getTableLocator()->get('MUserInfo');

        return (new RestoreUserUseCase($this->repo(), new CakeTransactionManager($users->getConnection()), new AuditLogAdapter()))
            ->execute(new RestoreUserInput(self::USER, new AuditActor('管理者', 1, 'admin', '')))->restoredRoomCount;
    }

    /** @return array<int, array{int, int|null}> 部屋ID => [active_flag, i_left_by_deletion] */
    private function memberships(): array
    {
        $rows = ConnectionManager::get('test')->execute('SELECT i_id_room, active_flag, i_left_by_deletion FROM m_user_group WHERE i_id_user = ? ORDER BY i_id_room', [self::USER])->fetchAll('assoc');
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['i_id_room']] = [(int)$r['active_flag'], $r['i_left_by_deletion'] === null ? null : (int)$r['i_left_by_deletion']];
        }

        return $map;
    }

    public function testDeleteThenRestore_bringsBackOnlyRoomsBeforeDeletion(): void
    {
        $this->delete();
        $this->assertSame([1 => [1, 1], 2 => [1, 1], 3 => [1, null]], $this->memberships(), '削除で外した行にだけ印が付く');
        $user  = ConnectionManager::get('test')->execute('SELECT dt_update FROM m_user_info WHERE i_id_user = ?', [self::USER])->fetch('assoc');
        $group = ConnectionManager::get('test')->execute('SELECT dt_update FROM m_user_group WHERE i_id_user = ? AND i_id_room = 1', [self::USER])->fetch('assoc');
        $this->assertSame($user['dt_update'], $group['dt_update'], '削除済みにした時刻と、所属を外した時刻が同じ');
        $this->assertSame([1, 2], $this->repo()->roomsLeftByDeletion(self::USER));

        $this->assertSame(2, $this->restore());
        $this->assertSame([1 => [0, null], 2 => [0, null], 3 => [1, null]], $this->memberships(), '部屋異動で外れた部屋 3 は戻らない。印は消える');
    }

    public function testRoleChangeWhileDeleted_doesNotLoseRoomsBeforeDeletion(): void
    {
        $this->delete();
        $this->repo()->updateRole(self::USER, 2, 'システム管理者');

        $this->assertSame(2, $this->restore());
        $this->assertSame([0, null], $this->memberships()[1]);
    }

    /** 印の列を足す前に削除された人：削除と同じ時刻に外れた行を、削除前の所属とみなす */
    public function testLegacyDeletedUser_restoresRoomsLeftAtTheDeletionTime(): void
    {
        $this->seedLegacyDeletion('2025-09-20 16:04:14', ['2025-09-20 16:04:14', '2025-09-20 16:04:16']);

        $this->assertSame([1, 2], $this->repo()->roomsLeftByDeletion(self::USER));
        $this->assertSame(2, $this->restore());
        $this->assertSame([1 => [0, null], 2 => [0, null], 3 => [1, null]], $this->memberships());
    }

    /** 印の無い削除済みの人を更新するときは、先に印を付けておく（時刻がずれても失わない） */
    public function testLegacyDeletedUser_updateFreezesRoomsBeforeDeletion(): void
    {
        $this->seedLegacyDeletion('2025-09-20 16:04:14', ['2025-09-20 16:04:14', '2025-09-20 16:04:14']);

        $this->repo()->updateReportAccess(self::USER, 1, 'システム管理者');

        $this->assertSame([1 => [1, 1], 2 => [1, 1], 3 => [1, null]], $this->memberships(), '更新の前に、削除で外した行へ印が付く');
        $this->assertSame(2, $this->restore());
    }

    /** 時刻がずれていて見分けられない人は、所属なしで復元する（本番の ID 2 と同じ状態） */
    public function testLegacyDeletedUser_withShiftedTime_restoresWithoutRooms(): void
    {
        $this->seedLegacyDeletion('2025-11-25 16:06:54', ['2025-09-20 15:46:50', '2025-09-20 15:46:50']);

        $this->assertSame([], $this->repo()->roomsLeftByDeletion(self::USER));
        $this->assertSame(0, $this->restore());
        $this->assertSame([1 => [1, null], 2 => [1, null], 3 => [1, null]], $this->memberships(), 'どの部屋も戻さない');
    }

    /**
     * 修正前の削除の状態を作る（印なしで外し、利用者を削除済みにする）。部屋 3 は 2026-04-01 の部屋異動で外れたまま。
     *
     * @param array{string, string} $leftAt 部屋 1・2 を外した時刻
     */
    private function seedLegacyDeletion(string $deletedAt, array $leftAt): void
    {
        $db = ConnectionManager::get('test');
        $db->update('m_user_info', ['i_del_flag' => 1, 'i_enable' => 1, 'dt_update' => $deletedAt], ['i_id_user' => self::USER]);
        $db->update('m_user_group', ['active_flag' => 1, 'dt_update' => $leftAt[0]], ['i_id_user' => self::USER, 'i_id_room' => 1]);
        $db->update('m_user_group', ['active_flag' => 1, 'dt_update' => $leftAt[1]], ['i_id_user' => self::USER, 'i_id_room' => 2]);
    }
}
