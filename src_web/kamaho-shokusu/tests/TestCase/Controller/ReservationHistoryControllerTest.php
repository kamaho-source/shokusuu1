<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ReservationHistoryController 統合テスト
 *
 * - 職員・管理者は開ける。子ども・一般はダッシュボードへ戻される
 * - 入力ミスは400、見られない相手・部屋は403
 * - ダッシュボードのカードは職員・管理者にだけ出る
 */
class ReservationHistoryControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MUserInfo',
        'app.MNotice',
        'app.TIndividualReservationInfo',
        'app.TAuditLog',
        'app.MUserGroup',
        'app.MRoomInfo',
    ];

    private const PERIOD = 'from=2024-09-01&to=2024-09-30';

    protected function setUp(): void
    {
        parent::setUp();
        $t = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $t->save($t->newEntity([
            'i_id_user' => 3, 'd_reservation_date' => '2024-09-05', 'i_reservation_type' => 1, 'i_id_room' => 1,
            'eat_flag' => 1, 'i_change_flag' => 1, 'i_version' => 1,
        ], ['validate' => false, 'accessibleFields' => ['*' => true]]));
    }

    /** 1=管理者 2=職員(level0) 3=子ども(level1) 4=システム管理者 */
    private function login(int $userId): void
    {
        $u = TableRegistry::getTableLocator()->get('MUserInfo')->get($userId);
        $this->session(['Auth' => [
            'i_id_user' => $userId,
            'c_login_account' => $u->c_login_account,
            'c_user_name' => $u->c_user_name,
            'i_admin' => $u->i_admin,
            'i_user_level' => $u->i_user_level,
            'i_id_room' => 1,
        ]]);
    }

    public function testIndex_staff_seesChildReservations(): void
    {
        $this->login(2);
        $this->get('/ReservationHistory?' . self::PERIOD . '&user=3');
        $this->assertResponseOk();
        $this->assertResponseContains('2024-09-05');
        $this->assertResponseContains('● 予約済み');
        $this->assertResponseContains('代理で閲覧');
        // 実食機能はオフのため「食べた」「実食」は出さない
        $this->assertResponseNotContains('食べた');
        $this->assertResponseNotContains('実食');
    }

    /** タブは左から 予約状況 → 部屋集計 → 変更の経緯 */
    public function testIndex_tabOrder(): void
    {
        $this->login(2);
        $this->get('/ReservationHistory?' . self::PERIOD . '&user=3');
        $body = (string)$this->_response->getBody();
        $pos = array_map(fn($l) => strpos($body, '>' . $l . '</a>'), ['予約状況', '部屋集計', '変更の経緯']);
        $this->assertNotContains(false, $pos);
        $this->assertTrue($pos[0] < $pos[1] && $pos[1] < $pos[2]);
    }

    public function testIndex_admin_ok(): void
    {
        $this->login(1);
        $this->get('/ReservationHistory?' . self::PERIOD . '&user=3');
        $this->assertResponseOk();
    }

    public function testIndex_child_redirectedToDashboard(): void
    {
        $this->login(3);
        $this->get('/ReservationHistory?' . self::PERIOD);
        $this->assertRedirect();
    }

    public function testIndex_guest_redirectedToLogin(): void
    {
        $this->get('/ReservationHistory?' . self::PERIOD);
        $this->assertRedirect();
    }

    public function testIndex_staffViewingAdmin_forbidden403(): void
    {
        $this->login(2);
        $this->get('/ReservationHistory?' . self::PERIOD . '&user=1');
        $this->assertResponseCode(403);
        $this->assertResponseContains('この人の履歴を見る権限がありません');
        $this->assertResponseNotContains('2024-09-05');
    }

    public function testIndex_endDateToday_badRequest400(): void
    {
        $this->login(2);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
        $this->get('/ReservationHistory?from=2024-09-01&to=' . $today . '&user=3');
        $this->assertResponseCode(400);
        $this->assertResponseContains('昨日以前');
    }

    public function testIndex_periodOver93Days_badRequest400(): void
    {
        $this->login(2);
        $this->get('/ReservationHistory?from=2024-01-01&to=2024-09-30&user=3');
        $this->assertResponseCode(400);
        $this->assertResponseContains('最大93日');
    }

    public function testIndex_summaryOfUnaffiliatedRoom_forbidden403(): void
    {
        $this->login(2);
        $this->get('/ReservationHistory?' . self::PERIOD . '&tab=summary&room=999');
        $this->assertResponseCode(403);
        $this->assertResponseContains('この部屋の集計を見る権限がありません');
    }

    public function testIndex_logTab_ok(): void
    {
        $this->login(2);
        $this->get('/ReservationHistory?' . self::PERIOD . '&user=3&tab=log');
        $this->assertResponseOk();
        $this->assertResponseContains('変更の経緯');
    }

    /** 変更の経緯に操作者の名前も出す。記録開始日より前を含む期間は注記を出す */
    public function testIndex_logTab_showsActorNameAndUnrecordedNote(): void
    {
        $t = TableRegistry::getTableLocator()->get('TAuditLog');
        $t->deleteAll([]);
        $t->save($t->newEntity([
            'c_category' => 'reservation', 'c_action' => 'reservation_toggle', 'c_target_table' => 't_reservation_info',
            'c_target_id' => 'room:1', 'i_actor_user_id' => 2, 'c_actor_user_name' => '山田代理',
            'i_result' => 1, 'c_detail' => json_encode(['date' => '2024-09-05', 'meal' => 1, 'value' => 1, 'user_id' => 3]),
            'dt_create' => '2024-09-03 09:00:00',
        ], ['validate' => false, 'accessibleFields' => ['*' => true]]));

        $this->login(2);
        $this->get('/ReservationHistory?' . self::PERIOD . '&user=3&tab=log');
        $this->assertResponseOk();
        $this->assertResponseContains('代理（山田代理）');
        $this->assertResponseContains('2024-09-03 より前の変更の経緯は記録されていません');

        $this->get('/ReservationHistory?from=2024-09-04&to=2024-09-30&user=3&tab=log');
        $this->assertResponseNotContains('記録されていません');
    }

    public function testDashboard_cardShownToStaffOnly(): void
    {
        $this->login(2);
        $this->get('/');
        $this->assertResponseOk();
        $this->assertResponseContains('予約履歴');
        $this->assertResponseContains('/ReservationHistory');

        $this->login(3);
        $this->get('/');
        $this->assertResponseOk();
        $this->assertResponseNotContains('/ReservationHistory');
    }
}
