<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\BulkImportUsers;

use App\Application\Port\AuditActor;
use App\Application\UseCase\BulkImportUsers\BulkImportUsersInput;
use App\Application\UseCase\BulkImportUsers\BulkImportUsersOutput;
use App\Application\UseCase\BulkImportUsers\BulkImportUsersUseCase;
use App\Test\TestCase\Application\Fake\FakeRoomMembershipRepository;
use App\Test\TestCase\Application\Fake\FakeUserAccountRepository;
use App\Test\TestCase\Application\Fake\SpyAuditLog;
use App\Test\TestCase\Application\Fake\SpyTransaction;
use Cake\Chronos\Chronos;
use Cake\Chronos\ClockFactory;
use PHPUnit\Framework\TestCase;

/**
 * BulkImportUsersUseCase のテスト（Repository・監査ログ・トランザクションは Fake）。
 * 操作者 2 は部屋 1 に所属している。
 */
class BulkImportUsersUseCaseTest extends TestCase
{
    private FakeUserAccountRepository $users;
    private FakeRoomMembershipRepository $rooms;
    private SpyTransaction $transaction;
    private SpyAuditLog $audit;

    protected function setUp(): void
    {
        parent::setUp();
        Chronos::setTestNow(new Chronos('2026-11-02 09:00:00', 'Asia/Tokyo'));
        $this->users = new FakeUserAccountRepository();
        $this->rooms = new FakeRoomMembershipRepository(['2:1']);
        $this->rooms->roomIdsByName = ['ひまわり' => 1, 'さくら' => 5];
        $this->transaction = new SpyTransaction();
        $this->audit       = new SpyAuditLog();
    }

    protected function tearDown(): void
    {
        Chronos::setTestNow(null);
        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function import(array $records, bool $admin = true): BulkImportUsersOutput
    {
        $useCase = new BulkImportUsersUseCase($this->users, $this->rooms, $this->transaction, $this->audit, new ClockFactory('Asia/Tokyo'));

        return $useCase->execute(new BulkImportUsersInput($records, new AuditActor('職員さん', 2, 'staff', '10.0.0.1'), $admin));
    }

    public function testValidRows_areSavedWithNormalizedValuesAndRooms(): void
    {
        $this->rooms->members = ['2:1', '2:5'];  // 管理者も登録先は自分の所属部屋だけ（#821）
        $result = $this->import([
            ['_row' => 2, 'login_id' => ' kid1 ', 'name' => '児童A', 'role' => '児童', 'password' => 'p1', 'age' => '9', 'gender' => '女', 'age_group' => '中学年', 'room_name1' => 'ひまわり', 'room_name2' => 'さくら'],
            ['_row' => 3, 'login_id' => 'staff1', 'name' => '職員B', 'role' => 'ｓｔａｆｆ', 'staff_id' => '42', 'age' => '-1'],
        ]);

        $this->assertSame(['processed' => 2, 'created' => 2, 'skipped' => 0, 'failed' => 0, 'errors' => []], $result->toArray());
        [$kid, $staff] = $this->users->imported;
        $this->assertSame(['c_login_account' => 'kid1', 'c_user_name' => '児童A', 'i_user_level' => 1, 'c_login_passwd' => 'p1', 'i_del_flag' => 0, 'i_enable' => 0,
            'i_disp_no' => 100, 'dt_create' => '2026-11-02 09:00:00', 'c_create_user' => '職員さん', 'i_user_age' => 9, 'i_user_gender' => 2, 'i_user_rank' => 3], $kid);
        $this->assertSame(101, $staff['i_disp_no']);
        $this->assertSame('42', $staff['i_id_staff']);
        $this->assertSame(0, $staff['i_user_level']);
        $this->assertArrayNotHasKey('i_user_age', $staff, '0 以下の年齢は入れない');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $staff['c_login_passwd'], '空ならランダムなパスワード');
        $this->assertSame(['join:701:1', 'join:701:5'], $this->users->roomOps);
        $this->assertSame(['begin', 'commit'], $this->transaction->calls);
        $this->assertTrue($this->audit->records[0]['succeeded']);
        $this->assertSame(['processed' => 2, 'created' => 2, 'skipped' => 0, 'failed' => 0], $this->audit->records[0]['detail']);
    }

    public function testNonAdmin_onlyChildrenIntoOwnRooms(): void
    {
        $result = $this->import([
            ['_row' => 1, 'login_id' => 'a', 'name' => 'A', 'role' => '職員', 'staff_id' => '1', 'room_name1' => 'ひまわり'],
            ['_row' => 2, 'login_id' => 'b', 'name' => 'B', 'role' => '児童'],
            ['_row' => 3, 'login_id' => 'c', 'name' => 'C', 'role' => '児童', 'room_name1' => 'さくら'],
            ['_row' => 4, 'login_id' => 'd', 'name' => 'D', 'role' => '児童', 'room_name1' => 'ひまわり'],
        ], admin: false);

        $this->assertSame([
            1 => ['管理者以外は児童（role=児童）のみ登録できます。'],
            2 => ['登録先の部屋（room_name1）が必要です。'],
            3 => ['自分が所属していない部屋、または存在しない部屋には登録できません。'],
        ], $result->errors);
        $this->assertSame(1, $result->created);
        $this->assertSame(['join:701:1'], $this->users->roomOps);
        $this->assertFalse($this->audit->records[0]['succeeded']);
        $this->assertSame('一括登録で3件が失敗しました（1件成功・0件スキップ）', $this->audit->records[0]['failureReason']);
    }

    public function testNonAdminWithoutRooms_failsEveryRow(): void
    {
        $this->rooms->members = [];

        $result = $this->import([['_row' => 1, 'login_id' => 'a', 'name' => 'A', 'role' => '児童', 'room_name1' => 'ひまわり']], admin: false);

        $this->assertSame([1 => ['所属している部屋がないため登録できません。']], $result->errors);
    }

    /** 管理者も登録先は自分の所属部屋だけ（#821）。部屋名が空なら部屋なしで登録できる */
    public function testAdmin_onlyOwnRooms_roomOptional(): void
    {
        $result = $this->import([
            ['_row' => 1, 'login_id' => 'a', 'name' => 'A', 'role' => '児童', 'room_name1' => 'ひまわり'],
            ['_row' => 2, 'login_id' => 'b', 'name' => 'B', 'role' => '職員', 'staff_id' => '1', 'room_name1' => 'さくら'],
            ['_row' => 3, 'login_id' => 'c', 'name' => 'C', 'role' => '児童', 'room_name1' => '無い部屋'],
            ['_row' => 4, 'login_id' => 'd', 'name' => 'D', 'role' => '児童', 'room_name1' => 'ひまわり', 'room_name2' => 'さくら'],
            ['_row' => 5, 'login_id' => 'e', 'name' => 'E', 'role' => '職員', 'staff_id' => '2'],
        ]);

        $this->assertSame([
            2 => ['自分が所属していない部屋、または存在しない部屋には登録できません。'],
            3 => ['自分が所属していない部屋、または存在しない部屋には登録できません。'],
            4 => ['自分が所属していない部屋、または存在しない部屋には登録できません。'],
        ], $result->errors);
        $this->assertSame(2, $result->created);
        $this->assertSame(['a', 'e'], array_column($this->users->imported, 'c_login_account'));
        $this->assertSame(['join:701:1'], $this->users->roomOps, '所属外の部屋 5 には所属させない');
    }

    /** 所属部屋の無い管理者は、部屋名があれば拒否、無ければ登録できる */
    public function testAdminWithoutRooms_canImportOnlyWithoutRoom(): void
    {
        $this->rooms->members = [];

        $result = $this->import([
            ['_row' => 1, 'login_id' => 'a', 'name' => 'A', 'role' => '児童', 'room_name1' => 'ひまわり'],
            ['_row' => 2, 'login_id' => 'b', 'name' => 'B', 'role' => '児童'],
        ]);

        $this->assertSame([1 => ['自分が所属していない部屋、または存在しない部屋には登録できません。']], $result->errors);
        $this->assertSame(1, $result->created);
        $this->assertSame([], $this->users->roomOps);
    }

    public function testRowChecks_inOrder(): void
    {
        $this->users->loginAccounts = ['taken'];

        $result = $this->import([
            ['_row' => 1, 'login_id' => '', 'name' => 'A', 'role' => '児童'],
            ['_row' => 2, 'login_id' => 'taken', 'name' => 'B', 'role' => 'xx'],
            ['_row' => 3, 'login_id' => 'r', 'name' => 'C', 'role' => 'xx'],
            ['_row' => 4, 'login_id' => 's', 'name' => 'D', 'role' => '職員'],
            ['_row' => 5, 'login_id' => 't', 'name' => 'E', 'role' => '児童', 'room_name1' => '無い部屋'],
        ]);

        $this->assertSame([
            1 => ['必須項目（login_id, name, role）のいずれかが空です。'],
            2 => ['c_login_account が既に存在します。'],
            3 => ['role の値が不正です（職員/児童/その他 または 0/1/3 を指定してください）。'],
            4 => ['role=職員 のため staff_id が必須です。'],
            5 => ['自分が所属していない部屋、または存在しない部屋には登録できません。'],
        ], $result->errors, '管理者でも存在しない部屋は拒否する（#821 以前は部屋なしで登録して注意だけ出していた）');
        $this->assertSame(['processed' => 5, 'created' => 0, 'skipped' => 1, 'failed' => 4], array_diff_key($result->toArray(), ['errors' => 1]));
    }

    public function testSaveFailure_isCountedAsFailed(): void
    {
        $this->users->failSave = true;

        $result = $this->import([['_row' => 1, 'login_id' => 'a', 'name' => 'A', 'role' => '児童']]);

        $this->assertSame([1 => ['保存に失敗しました。']], $result->errors);
        $this->assertSame(1, $result->failed);
    }

    public function testDbError_rollsBackAndRethrowsWithoutAudit(): void
    {
        $this->users->importThrows = new \RuntimeException('接続断');

        try {
            $this->import([['_row' => 1, 'login_id' => 'a', 'name' => 'A', 'role' => '児童']]);
            $this->fail('例外を投げ直すこと');
        } catch (\RuntimeException $e) {
            $this->assertSame('接続断', $e->getMessage());
        }
        $this->assertSame(['begin', 'rollback'], $this->transaction->calls);
        $this->assertSame([], $this->audit->records);
    }
}
