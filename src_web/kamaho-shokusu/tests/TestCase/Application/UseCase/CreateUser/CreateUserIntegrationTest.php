<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\CreateUser;

use App\Application\Port\AuditActor;
use App\Application\UseCase\CreateUser\CreateUserInput;
use App\Application\UseCase\CreateUser\CreateUserOutput;
use App\Application\UseCase\CreateUser\CreateUserUseCase;
use App\Infrastructure\Log\AuditLogAdapter;
use App\Infrastructure\Repository\CakeUserAccountRepository;
use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\Chronos\Chronos;
use Cake\Chronos\ClockFactory;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * 利用者の新規登録を、本物の Repository・監査ログにつないで確かめる（旧 UserCreateServiceTest を引き継ぐ）。
 */
class CreateUserIntegrationTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TAuditLog',
    ];

    protected function tearDown(): void
    {
        Chronos::setTestNow(null);
        parent::tearDown();
    }

    private function useCase(): CreateUserUseCase
    {
        return new CreateUserUseCase(
            new CakeUserAccountRepository($this->getTableLocator()->get('MUserInfo')),
            new AuditLogAdapter(),
            new ClockFactory('Asia/Tokyo'),
        );
    }

    /**
     * @param array<string, mixed> $override
     * @param list<int> $allowed
     */
    private function create(array $override = [], bool $childOnly = false, array $allowed = [1], string $actor = 'adminuser', int $actorId = 8, string $ip = '10.1.2.3'): CreateUserOutput
    {
        return $this->useCase()->execute(new CreateUserInput(
            $override + [
                'c_login_account' => 'new_test_user',
                'c_login_passwd'  => 'plain-pass',
                'c_user_name'     => 'テスト 太郎',
                'role'            => '0',
                'staff_id'        => '1234',
                'age'             => '30',
                'age_group'       => '4',
                'i_user_gender'   => '1',
                'MUserGroup'      => [['i_id_room' => '1']],
            ],
            $childOnly,
            $allowed,
            new AuditActor($actor, $actorId, 'login_' . $actor, $ip),
        ));
    }

    /** @return array<string, mixed> */
    private function savedUser(string $login = 'new_test_user'): array
    {
        return ConnectionManager::get('test')->execute('SELECT * FROM m_user_info WHERE c_login_account = ?', [$login])->fetch('assoc') ?: [];
    }

    private function lastAudit(): array
    {
        return ConnectionManager::get('test')->execute('SELECT * FROM t_audit_log ORDER BY i_id_audit DESC LIMIT 1')->fetch('assoc') ?: [];
    }

    public function testCreate_savesUserWithProfileRoomsAndHashedPassword(): void
    {
        Chronos::setTestNow(new Chronos('2026-11-02 10:00:00', 'Asia/Tokyo'));
        $maxDisp = (int)ConnectionManager::get('test')->execute('SELECT MAX(i_disp_no) FROM m_user_info')->fetchColumn(0);

        $this->assertSame(CreateUserOutput::CREATED, $this->create()->status);

        $user = $this->savedUser();
        $this->assertSame('テスト 太郎', $user['c_user_name']);
        $this->assertSame([0, 30, 1, 4, 1234, 0, 0, $maxDisp + 1], array_map('intval', [$user['i_user_level'], $user['i_user_age'], $user['i_user_gender'], $user['i_user_rank'], $user['i_id_staff'], $user['i_del_flag'], $user['i_enable'], $user['i_disp_no']]));
        $this->assertNull($user['i_admin'], '権限は送られた値から受け取らない');
        $this->assertSame('adminuser', $user['c_create_user']);
        $this->assertSame('2026-11-02 10:00:00', $user['dt_create']);
        $this->assertTrue((new DefaultPasswordHasher())->check('plain-pass', $user['c_login_passwd']), 'パスワードはハッシュにして保存する');
        $groups = ConnectionManager::get('test')->execute('SELECT i_id_room, active_flag, c_create_user FROM m_user_group WHERE i_id_user = ?', [$user['i_id_user']])->fetchAll('assoc');
        $this->assertSame([['i_id_room' => 1, 'active_flag' => 0, 'c_create_user' => 'adminuser']], array_map(static fn($g) => ['i_id_room' => (int)$g['i_id_room'], 'active_flag' => (int)$g['active_flag'], 'c_create_user' => $g['c_create_user']], $groups));
    }

    public function testCreate_auditLogFieldsAndDetail(): void
    {
        $before = (int)ConnectionManager::get('test')->execute('SELECT COUNT(*) FROM t_audit_log')->fetchColumn(0);

        $this->create();

        $this->assertSame($before + 1, (int)ConnectionManager::get('test')->execute('SELECT COUNT(*) FROM t_audit_log')->fetchColumn(0));
        $log = $this->lastAudit();
        $this->assertSame(['user', 'user_create', 'adminuser', 8, 'm_user_info', (string)$this->savedUser()['i_id_user'], '10.1.2.3', 1], [$log['c_category'], $log['c_action'], $log['c_actor_user_name'], (int)$log['i_actor_user_id'], $log['c_target_table'], $log['c_target_id'], $log['c_ip_address'], (int)$log['i_result']]);
        $this->assertSame(['user_name' => 'テスト 太郎', 'login_account' => 'new_test_user'], json_decode((string)$log['c_detail'], true));
    }

    public function testChildOnlyActor_alwaysCreatesChildWithoutStaffId(): void
    {
        $this->assertSame(CreateUserOutput::CREATED, $this->create(['role' => '1'], true, [1])->status);
        $user = $this->savedUser();
        $this->assertSame(1, (int)$user['i_user_level']);
        $this->assertNull($user['i_id_staff']);
    }

    /**
     * @return array<string, array{array<string, mixed>, bool, list<int>, string}>
     */
    public static function rejections(): array
    {
        return [
            '職員が児童以外を登録'     => [['role' => '0'], true, [1], '管理者以外は児童のみ登録できます。'],
            '年齢が範囲外'           => [['age' => '0'], false, [1], '性別・年代・年齢を選択してください。'],
            '性別が未選択'           => [['i_user_gender' => ''], false, [1], '性別・年代・年齢を選択してください。'],
            '職員IDが10桁'          => [['staff_id' => '1234567890'], false, [1], '職員IDは9桁以内の数字で入力してください。'],
            '所属部屋がない職員'      => [['role' => '1'], true, [], '所属している部屋がないため、ユーザーを登録できません。管理者に所属部屋の設定を依頼してください。'],
            '登録先の部屋が未選択'     => [['role' => '1', 'MUserGroup' => []], true, [1], '登録先の部屋を選択してください。'],
            '所属していない部屋'      => [['role' => '1', 'MUserGroup' => [['i_id_room' => '2']]], true, [1], '自分が所属していない部屋には登録できません。'],
            '管理者が所属していない部屋' => [['MUserGroup' => [['i_id_room' => '2']]], false, [1], '自分が所属していない部屋には登録できません。'],
            '所属部屋の無い管理者が部屋を選ぶ' => [[], false, [], '自分が所属していない部屋には登録できません。'],
            'ログインIDの重複'       => [['c_login_account' => 'admin_user'], false, [1], 'このログインIDは既に使用されています。他のIDをお試しください。'],
        ];
    }

    /**
     * @dataProvider rejections
     * @param array<string, mixed> $override
     * @param list<int> $allowed
     */
    public function testRejections_saveNothingAndExplain(array $override, bool $childOnly, array $allowed, string $message): void
    {
        $before = (int)ConnectionManager::get('test')->execute('SELECT COUNT(*) FROM m_user_info')->fetchColumn(0);

        $result = $this->create($override, $childOnly, $allowed);

        $this->assertSame(CreateUserOutput::REJECTED, $result->status);
        $this->assertSame($message, $result->message);
        $this->assertSame($before, (int)ConnectionManager::get('test')->execute('SELECT COUNT(*) FROM m_user_info')->fetchColumn(0));
    }

    public function testTableValidation_returnsFieldErrorsWithoutSaving(): void
    {
        $result = $this->create(['c_user_name' => '']);

        $this->assertSame(CreateUserOutput::INVALID_FIELDS, $result->status);
        $this->assertArrayHasKey('c_user_name', $result->fieldErrors);
        $this->assertSame([], $this->savedUser());
    }

    /** 管理者は部屋を選ばなくても登録できる（所属部屋が無くても） */
    public function testAdmin_withoutRoom_isCreated(): void
    {
        $this->assertSame(CreateUserOutput::CREATED, $this->create(['MUserGroup' => []], false, [])->status);
        $id = (int)$this->savedUser()['i_id_user'];
        $this->assertSame(0, (int)ConnectionManager::get('test')->execute('SELECT COUNT(*) FROM m_user_group WHERE i_id_user = ?', [$id])->fetchColumn(0));
    }
}
