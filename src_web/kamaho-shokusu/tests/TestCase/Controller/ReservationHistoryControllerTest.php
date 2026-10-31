<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ReservationHistoryController 統合テスト
 *
 * - 職員・ブロック長・管理者・子どもは開ける。子どもは自分の分だけで、部屋集計は見られない
 * - 入力ミスは400、見られない相手・部屋は403
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

    /** 対象者を指定しないときは、ログインしている本人が選ばれる（どのロールでも） */
    public function testIndex_defaultTargetIsLoggedInUser(): void
    {
        foreach ([2, 1, 4] as $userId) {
            $this->login($userId);
            $this->get('/ReservationHistory?' . self::PERIOD);
            $this->assertResponseOk();
            $this->assertMatchesRegularExpression('/<option value="' . $userId . '" selected>/', (string)$this->_response->getBody(), "ユーザー{$userId}");
            $this->assertResponseNotContains('代理で閲覧');
        }
    }

    /** 画面のタブのリンクをたどると、そのタブに切り替わる（href の & が二重にエスケープされていない） */
    public function testIndex_tabLinksSwitchTab(): void
    {
        $this->login(2);
        $this->get('/ReservationHistory?' . self::PERIOD);
        $body = (string)$this->_response->getBody();
        $this->assertStringNotContainsString('&amp;amp;', $body);

        $expected = ['summary' => 'の予約済みの人数です', 'log' => '対象：'];
        foreach ($expected as $tab => $text) {
            $this->assertSame(1, preg_match('/href="([^"]*tab=' . $tab . ')"/', $body, $m), $tab);
            $this->get(html_entity_decode($m[1]));
            $this->assertResponseOk();
            $this->assertMatchesRegularExpression('/class="nav-link active" href="[^"]*tab=' . $tab . '"/', (string)$this->_response->getBody(), $tab);
            $this->assertResponseContains($text);
        }
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

    /** 子どもは自分の予約と経緯だけ見られる。部屋集計（部屋の情報）は出さず、直接開いても403 */
    public function testIndex_child_seesOwnOnlyWithoutRoomSummary(): void
    {
        $this->login(3);
        $this->get('/ReservationHistory?' . self::PERIOD);
        $this->assertResponseOk();
        $this->assertResponseContains('● 予約済み');
        $this->assertResponseNotContains('部屋集計');
        $this->assertResponseNotContains('tab=summary');

        $this->get('/ReservationHistory?' . self::PERIOD . '&tab=summary&room=1');
        $this->assertResponseCode(403);
        $this->assertResponseContains('部屋集計は職員・管理者だけが見られます');

        $this->get('/ReservationHistory?' . self::PERIOD . '&user=2');
        $this->assertResponseCode(403);
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

    /** 未来日の予約も見られる。初期の期間は今月1日〜今月末日 */
    public function testIndex_futureReservationsAreShown(): void
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
        $future = $today->modify('+30 days')->format('Y-m-d');
        $t = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $t->save($t->newEntity([
            'i_id_user' => 3, 'd_reservation_date' => $future, 'i_reservation_type' => 2, 'i_id_room' => 1,
            'eat_flag' => 1, 'i_change_flag' => null, 'i_version' => 1,
        ], ['validate' => false, 'accessibleFields' => ['*' => true]]));

        $this->login(2);
        $this->get('/ReservationHistory?from=' . $today->format('Y-m-d') . '&to=' . $today->modify('+60 days')->format('Y-m-d') . '&user=3');
        $this->assertResponseOk();
        $this->assertResponseContains($future);
        $this->assertResponseContains('● 予約済み');

        $this->get('/ReservationHistory');
        $this->assertResponseOk();
        $this->assertResponseContains('value="' . $today->format('Y-m-01') . '"');
        $this->assertResponseContains('value="' . $today->format('Y-m-t') . '"');
    }

    /** 期間の長さは制限しない。開始日が終了日より後なら400 */
    public function testIndex_longPeriodOkAndReversedPeriod400(): void
    {
        $this->login(2);
        $this->get('/ReservationHistory?from=2024-01-01&to=2024-12-31&user=3');
        $this->assertResponseOk();
        $this->assertResponseContains('2024-09-05');

        $this->get('/ReservationHistory?from=2024-09-30&to=2024-09-01&user=3');
        $this->assertResponseCode(400);
        $this->assertResponseContains('開始日が終了日より後');
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

    /** 職員以上はダッシュボードのカード、子どもはナビゲーションバーから開く */
    public function testEntryPoints_cardForStaffAndNavForChild(): void
    {
        $this->login(2);
        $this->get('/');
        $this->assertResponseOk();
        $this->assertResponseContains('予約と変更の経緯を確認する');
        $this->assertResponseNotContains('bi-clock-history');

        $this->login(3);
        $this->get('/ReservationHistory?' . self::PERIOD);
        $this->assertResponseOk();
        $this->assertResponseContains('bi-clock-history"></i>予約履歴');
        $this->assertResponseContains('予約へ戻る');
        $this->assertResponseNotContains('ダッシュボードへ戻る');
    }
}
