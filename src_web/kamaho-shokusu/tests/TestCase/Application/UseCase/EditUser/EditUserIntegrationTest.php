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
     * @param array<string, mixed> $posted
     */
    private function edit(int $userId, array $posted, bool $canChangeRole = true): EditUserOutput
    {
        $useCase = new EditUserUseCase(
            new CakeUserAccountRepository($this->getTableLocator()->get('MUserInfo')),
            new CakeRoomMembershipRepository(),
            new CakeTransactionManager(ConnectionManager::get('test')),
            new AuditLogAdapter(),
            new ClockFactory('Asia/Tokyo'),
        );

        return $useCase->execute(new EditUserInput($userId, $posted, new AuditActor('admin', 1, 'admin_user', '127.0.0.1'), $canChangeRole, true, true));
    }

    /**
     * @return list<int>
     */
    private function activeRooms(int $userId): array
    {
        return array_map('intval', $this->getTableLocator()->get('MUserGroup')->find()
            ->where(['i_id_user' => $userId, 'active_flag' => 0])->all()->extract('i_id_room')->toList());
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
}
