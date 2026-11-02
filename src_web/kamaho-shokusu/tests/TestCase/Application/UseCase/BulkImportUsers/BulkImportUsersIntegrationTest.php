<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\BulkImportUsers;

use App\Application\Port\AuditActor;
use App\Application\UseCase\BulkImportUsers\BulkImportUsersInput;
use App\Application\UseCase\BulkImportUsers\BulkImportUsersUseCase;
use App\Infrastructure\Log\AuditLogAdapter;
use App\Infrastructure\Persistence\CakeTransactionManager;
use App\Infrastructure\Repository\CakeRoomMembershipRepository;
use App\Infrastructure\Repository\CakeUserAccountRepository;
use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\Chronos\ClockFactory;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * 利用者の一括登録を、本物の Repository・トランザクション・監査ログにつないで確かめる（旧 UserBulkImportServiceTest を引き継ぐ）。
 * フィクスチャ: 部屋 1（Lorem ipsum dolor sit amet）。職員 2 は部屋 1 に所属、システム管理者 4 はどこにも所属していない。
 */
class BulkImportUsersIntegrationTest extends TestCase
{
    private const ROOM1 = 'Lorem ipsum dolor sit amet';

    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TAuditLog',
    ];

    /**
     * @param list<array<string, mixed>> $records
     * @return array{processed: int, created: int, skipped: int, failed: int, errors: array<int, list<string>>}
     */
    private function import(array $records, int $actorId = 1, bool $admin = true): array
    {
        $useCase = new BulkImportUsersUseCase(
            new CakeUserAccountRepository($this->getTableLocator()->get('MUserInfo')),
            new CakeRoomMembershipRepository(),
            new CakeTransactionManager(ConnectionManager::get('test')),
            new AuditLogAdapter(),
            new ClockFactory('Asia/Tokyo'),
        );

        return $useCase->execute(new BulkImportUsersInput($records, new AuditActor('admin', $actorId, 'admin_user', '127.0.0.1'), $admin))->toArray();
    }

    public function testEmptyRecords_returnZeroCounts(): void
    {
        $this->assertSame(['processed' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []], $this->import([]));
    }

    public function testValidRow_createsUserWithHashedPasswordRoomAndAudit(): void
    {
        $result = $this->import([['_row' => 1, 'login_id' => 'bulk_new', 'name' => '一括テスト太郎', 'role' => '児童', 'password' => 'testPass123', 'room_name1' => self::ROOM1]]);

        $this->assertSame(1, $result['created']);
        $user = $this->getTableLocator()->get('MUserInfo')->find()->where(['c_login_account' => 'bulk_new'])->firstOrFail();
        $this->assertTrue((new DefaultPasswordHasher())->check('testPass123', (string)$user->c_login_passwd));
        $this->assertSame('admin', $user->c_create_user);
        $this->assertSame(1, $this->getTableLocator()->get('MUserGroup')->find()->where(['i_id_user' => $user->i_id_user, 'i_id_room' => 1, 'active_flag' => 0])->count());
        $log = $this->getTableLocator()->get('TAuditLog')->find()->where(['c_action' => 'user_bulk_import'])->firstOrFail();
        $this->assertSame(1, (int)$log->i_result);
    }

    public function testDuplicateLoginId_isSkipped(): void
    {
        $result = $this->import([['_row' => 1, 'login_id' => 'admin_user', 'name' => 'テスト', 'role' => '職員', 'staff_id' => 'S999']]);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['created']);
    }

    /** テーブルの入力チェックに当たった行は、項目名つきの理由を返して保存しない */
    public function testTableValidationError_isReportedPerField(): void
    {
        $result = $this->import([['_row' => 1, 'login_id' => 'too_old', 'name' => 'A', 'role' => '児童', 'age' => '90']]);

        $this->assertSame([1 => ['i_user_age: 年齢は80以下で指定してください。']], $result['errors']);
        $this->assertSame(0, $this->getTableLocator()->get('MUserInfo')->find()->where(['c_login_account' => 'too_old'])->count());
    }

    public function testNonAdmin_ownRoomAllowed_otherOrNoAffiliationRejected(): void
    {
        $own = $this->import([['_row' => 1, 'login_id' => 'room_ok', 'name' => 'B', 'role' => '児童', 'room_name1' => self::ROOM1]], actorId: 2, admin: false);
        $this->assertSame(1, $own['created']);

        $groups = $this->getTableLocator()->get('MUserGroup');
        $groups->saveOrFail($groups->newEntity(['i_id_user' => 4, 'i_id_room' => 99, 'active_flag' => 0, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't']));
        $other = $this->import([['_row' => 1, 'login_id' => 'room_ng', 'name' => 'A', 'role' => '児童', 'room_name1' => self::ROOM1]], actorId: 4, admin: false);
        $this->assertSame([1 => ['自分が所属していない部屋、または存在しない部屋には登録できません。']], $other['errors']);

        $groups->deleteAll(['i_id_user' => 4]);
        $none = $this->import([['_row' => 1, 'login_id' => 'room_ng2', 'name' => 'A', 'role' => '児童', 'room_name1' => self::ROOM1]], actorId: 4, admin: false);
        $this->assertSame([1 => ['所属している部屋がないため登録できません。']], $none['errors']);
    }

    public function testAdmin_canImportWithoutRoom(): void
    {
        $this->assertSame(1, $this->import([['_row' => 1, 'login_id' => 'nr_c', 'name' => 'C', 'role' => '児童']])['created']);
    }

    /** 管理者・システム管理者も、所属していない部屋には取り込めない（#821）。部屋名が空なら取り込める */
    public function testSystemAdmin_roomOutsideOwn_isRejected_withoutRoom_isCreated(): void
    {
        $result = $this->import([
            ['_row' => 1, 'login_id' => 'sa_ng', 'name' => 'A', 'role' => '児童', 'room_name1' => self::ROOM1],
            ['_row' => 2, 'login_id' => 'sa_ok', 'name' => 'B', 'role' => '児童'],
        ], actorId: 4);

        $this->assertSame([1 => ['自分が所属していない部屋、または存在しない部屋には登録できません。']], $result['errors']);
        $users = $this->getTableLocator()->get('MUserInfo');
        $this->assertSame(0, $users->find()->where(['c_login_account' => 'sa_ng'])->count());
        $this->assertSame(1, $users->find()->where(['c_login_account' => 'sa_ok'])->count());
    }

    /** 管理者でも、同じ名前の所属外の部屋ではなく自分の所属部屋に所属させる */
    public function testAdmin_duplicateRoomName_joinsOwnRoomOnly(): void
    {
        $rooms = $this->getTableLocator()->get('MRoomInfo');
        $rooms->saveOrFail($rooms->newEntity(['i_id_room' => 50, 'c_room_name' => self::ROOM1, 'i_disp_no' => 50, 'i_del_flg' => 0], ['accessibleFields' => ['i_id_room' => true]]));

        $result = $this->import([['_row' => 1, 'login_id' => 'adm_dup', 'name' => 'A', 'role' => '児童', 'room_name1' => self::ROOM1]]);

        $this->assertSame(1, $result['created']);
        $userId = $this->getTableLocator()->get('MUserInfo')->find()->where(['c_login_account' => 'adm_dup'])->firstOrFail()->i_id_user;
        $this->assertSame([1], array_map('intval', $this->getTableLocator()->get('MUserGroup')->find()->where(['i_id_user' => $userId])->all()->extract('i_id_room')->toList()));
    }

    /** 部屋名が重複していても、所属させるのは自分の所属部屋のほう（確かめた部屋と同じ部屋） */
    public function testDuplicateRoomName_joinsTheActorsOwnRoom(): void
    {
        $rooms = $this->getTableLocator()->get('MRoomInfo');
        $rooms->saveOrFail($rooms->newEntity(['i_id_room' => 50, 'c_room_name' => self::ROOM1, 'i_disp_no' => 50, 'i_del_flg' => 0], ['accessibleFields' => ['i_id_room' => true]]));
        $groups = $this->getTableLocator()->get('MUserGroup');
        $groups->saveOrFail($groups->newEntity(['i_id_user' => 4, 'i_id_room' => 50, 'active_flag' => 0, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't']));

        $result = $this->import([['_row' => 1, 'login_id' => 'dup_room', 'name' => 'A', 'role' => '児童', 'room_name1' => self::ROOM1]], actorId: 4, admin: false);

        $this->assertSame(1, $result['created']);
        $userId = $this->getTableLocator()->get('MUserInfo')->find()->where(['c_login_account' => 'dup_room'])->firstOrFail()->i_id_user;
        $this->assertSame([50], array_map('intval', $groups->find()->where(['i_id_user' => $userId])->all()->extract('i_id_room')->toList()));
    }
}
