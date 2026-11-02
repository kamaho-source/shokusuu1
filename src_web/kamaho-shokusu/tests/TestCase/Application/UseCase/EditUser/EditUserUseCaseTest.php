<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\EditUser;

use App\Application\Port\AuditActor;
use App\Application\UseCase\EditUser\EditUserInput;
use App\Application\UseCase\EditUser\EditUserOutput;
use App\Application\UseCase\EditUser\EditUserUseCase;
use App\Domain\Entity\UserAccount;
use App\Domain\Exception\NotFoundException;
use App\Test\TestCase\Application\Fake\FakeRoomMembershipRepository;
use App\Test\TestCase\Application\Fake\FakeUserAccountRepository;
use App\Test\TestCase\Application\Fake\SpyAuditLog;
use App\Test\TestCase\Application\Fake\SpyTransaction;
use Cake\Chronos\Chronos;
use Cake\Chronos\ClockFactory;
use PHPUnit\Framework\TestCase;

/**
 * EditUserUseCase のテスト（Repository・監査ログ・トランザクションは Fake）。今は 2026-11-02 09:00 に固定する。
 */
class EditUserUseCaseTest extends TestCase
{
    private FakeUserAccountRepository $users;
    private SpyTransaction $transaction;
    private SpyAuditLog $audit;
    private EditUserUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        Chronos::setTestNow(new Chronos('2026-11-02 09:00:00', 'Asia/Tokyo'));
        $this->users = new FakeUserAccountRepository();
        $this->users->users[20] = new UserAccount(20, '児童さん', 0, 0, 0, 1, 'child20');
        $this->users->users[21] = new UserAccount(21, '職員さん', 0, 0, 0, 0, 'staff21');
        $this->users->users[22] = new UserAccount(22, '削除済みさん', 0, 0, 1, 1, 'deleted22');
        $this->transaction = new SpyTransaction();
        $this->audit       = new SpyAuditLog();
        $this->useCase     = new EditUserUseCase(
            $this->users,
            // 編集する人（管理者 1）は部屋 1・2・5 に所属。部屋 3・4・9 は所属外（#821 で選べる部屋は編集する人の所属部屋だけ）
            new FakeRoomMembershipRepository(
                ['20:1', '20:2', '21:3', '22:4', '1:1', '1:2', '1:5'],
                [],
                [1 => '部屋1', 2 => '部屋2', 3 => '部屋3', 4 => '部屋4', 5 => '部屋5', 9 => '部屋9'],
            ),
            $this->transaction,
            $this->audit,
            new ClockFactory('Asia/Tokyo'),
        );
    }

    protected function tearDown(): void
    {
        Chronos::setTestNow(null);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $posted
     */
    private function edit(int $userId, array $posted, bool $canChangeRole = true, bool $canEditRooms = true, bool $actorIsAdmin = true): EditUserOutput
    {
        return $this->useCase->execute(new EditUserInput(
            $userId, $posted, new AuditActor('管理者', 1, 'admin', '10.0.0.1'), $canChangeRole, $canEditRooms, $actorIsAdmin,
        ));
    }

    /**
     * @return array{int, array<string, mixed>, list<string>}
     */
    private function saved(): array
    {
        $this->assertCount(1, $this->users->profileUpdates);

        return $this->users->profileUpdates[0];
    }

    public function testAdminEditsOther_savesAllowedFieldsAndCheckedRoomsInOneTransaction(): void
    {
        $result = $this->edit(20, [
            'c_login_account' => 'child20b', 'c_user_name' => '児童さん改', 'i_user_gender' => '2', 'i_user_rank' => '3',
            'i_user_level' => '1', 'i_admin' => '1', 'i_user_age' => '40', 'i_del_flag' => '1',
            'rooms' => ['1' => '0', '2' => '1', '5' => '1'],
        ]);

        $this->assertSame(EditUserOutput::UPDATED, $result->status);
        [, $data, $fields] = $this->saved();
        $this->assertSame(['c_login_account', 'c_user_name', 'c_update_user', 'dt_update', 'i_user_gender', 'i_user_rank', 'i_user_level', 'i_id_staff'], $fields);
        $this->assertSame('child20b', $data['c_login_account']);
        $this->assertSame('管理者', $data['c_update_user']);
        $this->assertSame('2026-11-02 09:00:00', $data['dt_update']);
        $this->assertNull($data['i_id_staff'], '職員以外は職員IDを持たない');
        $this->assertArrayNotHasKey('i_admin', $data, '権限は送られても受け取らない');
        $this->assertArrayNotHasKey('i_user_age', $data);
        $this->assertSame(['freeze:20', 'replace:20:2,5'], $this->users->roomOps, 'チェックした部屋だけに差し替える');
        $this->assertSame(['begin', 'commit'], $this->transaction->calls);
        $this->assertCount(1, $this->audit->records);
        $this->assertTrue($this->audit->records[0]['succeeded']);
        $this->assertSame(['user_name' => '児童さん改', 'changes' => ['c_user_name' => ['児童さん', '児童さん改']]], $this->audit->records[0]['detail']);
    }

    public function testStaffEditsChild_onlyNameChanges_andRoomsStayAsTheyAre(): void
    {
        $result = $this->edit(20, [
            'c_login_account' => 'hijack', 'c_user_name' => '名前だけ', 'i_user_gender' => '2', 'i_user_rank' => '3', 'i_user_level' => '0',
            'rooms' => ['9' => '1'],
        ], canChangeRole: false, canEditRooms: false, actorIsAdmin: false);

        $this->assertSame(EditUserOutput::UPDATED, $result->status);
        [, $data, $fields] = $this->saved();
        $this->assertSame(['c_login_account', 'c_user_name', 'c_update_user', 'dt_update'], $fields);
        $this->assertSame('child20', $data['c_login_account'], 'ログインIDは変えられない');
        $this->assertSame(['freeze:20', 'replace:20:1,2'], $this->users->roomOps, '所属部屋は今のまま');
    }

    public function testSelfEdit_nonAdmin_cannotChangeRankOrLevel(): void
    {
        $this->edit(21, ['c_user_name' => '職員さん', 'i_user_gender' => '1', 'i_user_rank' => '3', 'i_user_level' => '1', 'i_id_staff' => '12345'], canChangeRole: false, actorIsAdmin: false);

        [, $data, $fields] = $this->saved();
        $this->assertSame(['c_login_account', 'c_user_name', 'c_update_user', 'dt_update', 'i_user_gender', 'i_id_staff'], $fields, '職員は自分の職員IDを変えられる');
        $this->assertSame('12345', $data['i_id_staff']);
        $this->assertArrayNotHasKey('i_user_rank', $data);
        $this->assertArrayNotHasKey('i_user_level', $data);
    }

    public function testSelfEdit_emptyStaffId_keepsIt(): void
    {
        $this->edit(21, ['c_user_name' => '職員さん', 'i_id_staff' => ' '], canChangeRole: false, actorIsAdmin: false);

        [, $data] = $this->saved();
        $this->assertArrayNotHasKey('i_id_staff', $data);
    }

    public function testSelfEdit_invalidStaffId_isRejectedWithoutSaving(): void
    {
        $result = $this->edit(21, ['c_user_name' => '職員さん', 'i_id_staff' => '12a'], canChangeRole: false, actorIsAdmin: false);

        $this->assertSame(EditUserOutput::REJECTED, $result->status);
        $this->assertSame('職員IDは9桁以内の数字で入力してください。', $result->message);
        $this->assertSame([], $this->users->profileUpdates);
        $this->assertSame([], $this->transaction->calls);
        $this->assertSame([], $this->audit->records);
    }

    public function testInvalidProfile_isRejectedWithoutSaving(): void
    {
        $result = $this->edit(20, ['c_user_name' => 'x', 'i_user_gender' => '99']);

        $this->assertSame(EditUserOutput::REJECTED, $result->status);
        $this->assertSame('性別・年代・役職の選択が正しくありません。', $result->message);
        $this->assertSame([], $this->transaction->calls);
    }

    /** 編集する人の所属外の部屋（3）の所属は残し、所属外の部屋（9）にチェックしても付けない */
    public function testRoomsOutsideActorsRooms_areKeptAndNotAdded(): void
    {
        $this->edit(21, ['c_user_name' => '職員さん', 'rooms' => ['1' => '1', '3' => '0', '9' => '1']]);

        $this->assertSame(['freeze:21', 'replace:21:1,3'], $this->users->roomOps);
    }

    public function testDeletedUser_roomsAreNotChanged(): void
    {
        $this->edit(22, ['c_user_name' => '削除済みさん', 'rooms' => ['1' => '1']]);

        $this->assertSame(['freeze:22', 'replace:22:4'], $this->users->roomOps);
    }

    public function testProfileSaveFails_rollsBackAndReturnsFieldErrorsAndCheckedRooms(): void
    {
        $this->users->failSave = true;

        $result = $this->edit(20, ['c_user_name' => '重複', 'rooms' => ['2' => '1']]);

        $this->assertSame(EditUserOutput::SAVE_FAILED, $result->status);
        $this->assertSame(['c_user_name' => ['unique' => 'このユーザー名は既に使用されています。']], $result->fieldErrors);
        $this->assertSame([2], $result->roomIds, '画面にチェックしたまま出し直す');
        $this->assertSame(['begin', 'rollback'], $this->transaction->calls);
        $this->assertSame([], $this->audit->records, '従来どおり、保存できなかっただけでは監査ログに残さない');
    }

    public function testRoomsSaveFails_rollsBackTheProfileToo(): void
    {
        $this->users->failRooms = true;

        $result = $this->edit(20, ['c_user_name' => '児童さん', 'rooms' => ['2' => '1']]);

        $this->assertSame(EditUserOutput::SAVE_FAILED, $result->status);
        $this->assertSame(['begin', 'rollback'], $this->transaction->calls);
    }

    public function testDbError_rollsBackRecordsFailureAndRethrows(): void
    {
        $this->users->profileThrows = new \RuntimeException('接続断');

        try {
            $this->edit(20, ['c_user_name' => '児童さん']);
            $this->fail('例外を投げ直すこと');
        } catch (\RuntimeException $e) {
            $this->assertSame('接続断', $e->getMessage());
        }
        $this->assertSame(['begin', 'rollback'], $this->transaction->calls);
        $this->assertCount(1, $this->audit->records);
        $this->assertFalse($this->audit->records[0]['succeeded']);
        $this->assertSame(['error' => '接続断'], $this->audit->records[0]['detail']);
    }

    public function testMissingUser_throwsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->edit(999, ['c_user_name' => 'x']);
    }
}
