<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationVersionService;
use App\Service\RoomService;
use App\Service\UserCreateService;
use App\Service\UserDeletionService;
use App\Service\UserEditService;
use App\Service\UserRestoreService;
use App\Service\UserRoomAssignmentService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * 利用者・部屋の構成が変わったとき、版数が進むことを検証する。
 *
 * 食数一括管理の行は利用者、列は部屋から作られる。版数が進まないと、
 * 入居者を追加しても他の職員の画面には行が増えないまま残る。
 */
class ReservationVersionBumpTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TAuditLog',
        'app.TIndividualReservationInfo',
    ];

    private function newUserEntity(string $loginAccount): \Cake\Datasource\EntityInterface
    {
        $table = TableRegistry::getTableLocator()->get('MUserInfo');

        return $table->newEntity([
            'c_login_account' => $loginAccount,
            'c_login_passwd'  => 'hashed_password',
            'c_user_name'     => 'テスト 太郎',
            'i_admin'         => 0,
            'i_user_level'    => 1,
            'i_user_gender'   => 1,
            'i_user_age'      => 10,
            'i_user_rank'     => 1,
            'i_disp_no'       => 99,
            'i_enable'        => 0,
            'i_del_flag'      => 0,
            'dt_create'       => date('Y-m-d H:i:s'),
            'c_create_user'   => 'admin',
        ]);
    }

    private function existingUser(): \App\Model\Entity\MUserInfo
    {
        return TableRegistry::getTableLocator()->get('MUserInfo')->find()->firstOrFail();
    }

    /**
     * 処理の前後で版数が進んだことを確かめる。
     *
     * @param callable $operation 検証対象の処理
     * @param string   $message   進まなかったときの説明
     * @return void
     */
    private function assertBumps(callable $operation, string $message): void
    {
        $before = ReservationVersionService::current();
        $operation();
        $this->assertGreaterThan($before, ReservationVersionService::current(), $message);
    }

    public function testUserCreateBumpsVersion(): void
    {
        $this->assertBumps(
            fn() => (new UserCreateService())->saveWithRooms(
                $this->newUserEntity('bump_create_user'),
                [['i_id_room' => 1]],
                'admin'
            ),
            '利用者を追加しても版数が進まない'
        );
    }

    public function testUserEditBumpsVersion(): void
    {
        $user = $this->existingUser();

        $this->assertBumps(
            fn() => (new UserEditService())->updateWithRooms(
                $user,
                ['c_user_name' => '変更後 名前'],
                [1],
                'admin'
            ),
            '利用者を編集しても版数が進まない'
        );
    }

    public function testUserDeletionBumpsVersion(): void
    {
        $user = $this->existingUser();

        $this->assertBumps(
            fn() => (new UserDeletionService())->softDelete($user, 'admin'),
            '利用者を退所させても版数が進まない'
        );
    }

    public function testUserRestoreBumpsVersion(): void
    {
        $table = TableRegistry::getTableLocator()->get('MUserInfo');
        $user  = $this->existingUser();
        $user->i_del_flag = 1;
        $table->saveOrFail($user);

        $this->assertBumps(
            fn() => (new UserRestoreService())->restore($user, 'admin'),
            '利用者を復帰させても版数が進まない'
        );
    }

    public function testRoomAssignmentBumpsVersion(): void
    {
        $roomName = (string)TableRegistry::getTableLocator()->get('MRoomInfo')
            ->find()
            ->firstOrFail()
            ->get('c_room_name');

        $this->assertBumps(
            fn() => (new UserRoomAssignmentService())->assign(
                (int)$this->existingUser()->get('i_id_user'),
                [$roomName],
                'admin'
            ),
            '部屋の割り当てを変えても版数が進まない'
        );
    }

    public function testRoomSoftDeleteBumpsVersion(): void
    {
        $room = TableRegistry::getTableLocator()->get('MRoomInfo')->find()->firstOrFail();

        $this->assertBumps(
            fn() => (new RoomService())->softDelete($room, 'admin'),
            '部屋を削除しても版数が進まない'
        );
    }
}
