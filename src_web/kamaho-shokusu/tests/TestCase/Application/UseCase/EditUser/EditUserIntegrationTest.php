<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\EditUser;

use App\Application\Port\AuditActor;
use App\Application\UseCase\EditUser\EditUserInput;
use App\Application\UseCase\EditUser\EditUserOutput;
use App\Application\UseCase\EditUser\EditUserUseCase;
use App\Infrastructure\Log\AuditLogAdapter;
use App\Infrastructure\Repository\CakeRoomMembershipRepository;
use App\Infrastructure\Repository\CakeUserAccountRepository;
use App\Infrastructure\Persistence\CakeTransactionManager;
use Cake\Chronos\ClockFactory;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * 利用者の編集を、本物の Repository・トランザクション・監査ログにつないで確かめる（旧 UserEditServiceTest を引き継ぐ）。
 */
class EditUserIntegrationTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TAuditLog',
    ];

    /**
     * 編集する人（管理者 1）は部屋 1・2 に所属する。部屋 1 だけのフィクスチャに部屋 2 を足す（#821 で、選べる部屋は編集する人の所属部屋だけになった）。
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->addRoom(2);
        $this->join(1, 2);
    }

    private function addRoom(int $roomId, int $delFlg = 0): void
    {
        ConnectionManager::get('test')->insert('m_room_info', ['i_id_room' => $roomId, 'c_room_name' => '部屋' . $roomId, 'i_disp_no' => $roomId, 'i_enable' => 1, 'i_del_flg' => $delFlg]);
    }

    private function join(int $userId, int $roomId): void
    {
        ConnectionManager::get('test')->insert('m_user_group', ['i_id_user' => $userId, 'i_id_room' => $roomId, 'active_flag' => 0]);
    }

    private function useCase(): EditUserUseCase
    {
        return new EditUserUseCase(
            new CakeUserAccountRepository($this->getTableLocator()->get('MUserInfo')),
            new CakeRoomMembershipRepository(),
            new CakeTransactionManager(ConnectionManager::get('test')),
            new AuditLogAdapter(),
            new ClockFactory('Asia/Tokyo'),
        );
    }

    /**
     * @param array<string, mixed> $posted
     */
    private function edit(int $userId, array $posted, bool $canChangeRole = true): EditUserOutput
    {
        return $this->useCase()->execute(new EditUserInput($userId, $posted, new AuditActor('admin', 1, 'admin_user', '127.0.0.1'), $canChangeRole, true, true));
    }

    /**
     * @return list<int>
     */
    private function activeRooms(int $userId): array
    {
        return array_map('intval', $this->getTableLocator()->get('MUserGroup')->find()
            ->where(['i_id_user' => $userId, 'active_flag' => 0])->orderByAsc('i_id_room')->all()->extract('i_id_room')->toList());
    }

    public function testUpdatesNameAndRooms(): void
    {
        $result = $this->edit(3, ['c_user_name' => '更新テスト名前', 'rooms' => ['1' => '1', '2' => '1']]);

        $this->assertSame(EditUserOutput::UPDATED, $result->status);
        $this->assertSame('更新テスト名前', $this->getTableLocator()->get('MUserInfo')->get(3)->c_user_name);
        $this->assertSame([1, 2], $this->activeRooms(3));
    }

    public function testNoRoomsChecked_removesCurrentRooms(): void
    {
        $this->edit(2, ['c_user_name' => '職員ユーザー']);

        $this->assertSame(0, $this->getTableLocator()->get('MUserGroup')->find()->where(['i_id_user' => 2])->count());
    }

    /**
     * 部屋異動で無効化された旧部屋(active_flag=1)の行は、ユーザー編集の保存で復活させない。
     */
    public function testDoesNotReviveRoomLeftByTransfer(): void
    {
        $groups = $this->getTableLocator()->get('MUserGroup');
        $groups->saveOrFail($groups->newEntity([
            'i_id_user' => 2, 'i_id_room' => 2, 'active_flag' => 1,
            'dt_create' => '2024-09-07 15:59:52', 'c_create_user' => 'system',
            'dt_update' => '2024-09-07 15:59:52', 'c_update_user' => 'system',
        ]));

        $this->edit(2, ['c_user_name' => '名前だけ修正', 'rooms' => ['1' => '1']]);

        $this->assertSame([1], $this->activeRooms(2));
        $this->assertSame(1, (int)$groups->find()->where(['i_id_user' => 2, 'i_id_room' => 2])->firstOrFail()->active_flag);
    }

    public function testRecordsAuditLogWithChanges(): void
    {
        $this->edit(2, ['c_user_name' => '監査テスト', 'rooms' => ['1' => '1']]);

        $log = $this->getTableLocator()->get('TAuditLog')->find()->where(['c_action' => 'user_update'])->orderByDesc('i_id_audit')->firstOrFail();
        $this->assertSame('2', (string)$log->c_target_id);
        $detail = json_decode((string)$log->c_detail, true);
        $this->assertSame(['職員ユーザー', '監査テスト'], $detail['changes']['c_user_name']);
    }

    /** 他の人と同じログインIDにすると、理由を返し、所属部屋の変更も取り消す */
    public function testDuplicateLoginAccount_returnsFieldErrorAndRollsBackRooms(): void
    {
        $result = $this->edit(3, ['c_login_account' => 'admin_user', 'c_user_name' => '一般ユーザー', 'rooms' => ['2' => '1']]);

        $this->assertSame(EditUserOutput::SAVE_FAILED, $result->status);
        $this->assertArrayHasKey('c_login_account', $result->fieldErrors);
        $this->assertSame([2], $result->roomIds);
        $this->assertSame('non_staff_user', $this->getTableLocator()->get('MUserInfo')->get(3)->c_login_account);
        $this->assertSame([1], $this->activeRooms(3), '所属部屋は元のまま');
    }

    /** 部屋異動で外れた部屋にもう一度チェックすると、その行を所属中で作り直す（行は1つのまま） */
    public function testRecheckingRoomLeftByTransfer_rejoinsIt(): void
    {
        $groups = $this->getTableLocator()->get('MUserGroup');
        $groups->saveOrFail($groups->newEntity([
            'i_id_user' => 2, 'i_id_room' => 2, 'active_flag' => 1,
            'dt_create' => '2024-09-07 15:59:52', 'c_create_user' => 'system',
        ]));

        $result = $this->edit(2, ['c_user_name' => '職員ユーザー', 'rooms' => ['1' => '1', '2' => '1']]);

        $this->assertSame(EditUserOutput::UPDATED, $result->status);
        $this->assertSame([1, 2], $this->activeRooms(2));
        $this->assertSame(1, $groups->find()->where(['i_id_user' => 2, 'i_id_room' => 2])->count());
    }

    /** 変えてよい項目に無い値は、送られても保存しない（役職を変えられない人の職員ID） */
    public function testFieldsNotAllowed_areNotSaved(): void
    {
        $this->edit(3, ['c_user_name' => '一般ユーザー', 'i_id_staff' => '999', 'rooms' => ['1' => '1']], canChangeRole: false);

        $this->assertNull($this->getTableLocator()->get('MUserInfo')->get(3)->i_id_staff);
    }

    /**
     * 保存では選べる部屋だけチェックどおりにし、選べない部屋（所属外 3・削除済み 4）の既存の所属は残す。
     * 選べない部屋にチェックを送っても付与しない。
     */
    public function testRoomsOutsideActorsChoice_areKeptAndCannotBeAdded(): void
    {
        $this->addRoom(3);
        $this->addRoom(4, 1);
        $this->addRoom(5);
        $this->join(1, 4);
        $this->join(3, 3);
        $this->join(3, 4);

        $result = $this->edit(3, ['c_user_name' => '一般ユーザー', 'rooms' => ['1' => '0', '2' => '1', '3' => '0', '4' => '0', '5' => '1']]);

        $this->assertSame(EditUserOutput::UPDATED, $result->status);
        $this->assertSame([2, 3, 4], $this->activeRooms(3), '1 は外れ 2 が付く。3・4 は残り、選べない 5 は付かない');
    }
}
