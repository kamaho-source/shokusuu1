<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * TReservationInfoController の各アクション（カレンダー系API・add・changeEdit など）のテスト。
 *
 * 既存の TReservationInfoControllerTest（画面表示・権限マトリクス）を補う。
 */
class TReservationInfoControllerActionsTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.MUserGroup',
        'app.TIndividualReservationInfo',
        'app.TReservationInfo',
        'app.TAuditLog',
    ];

    /** @param bool $json true のとき JSON を要求する（false は画面表示として扱われる） */
    private function login(int $id, string $account, int $admin, int $level, bool $json = true): void
    {
        $this->session(['Auth' => [
            'i_id_user' => $id, 'c_login_account' => $account, 'c_user_name' => 'テスト' . $id,
            'i_admin' => $admin, 'i_user_level' => $level, 'i_id_room' => 1,
        ]]);
        $this->enableCsrfToken();
        if ($json) {
            $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        }
    }

    private function loginAdmin(bool $json = true): void
    {
        $this->login(1, 'admin_user', 1, 0, $json);
    }

    private function loginStaff(bool $json = true): void
    {
        $this->login(2, 'staff_user', 0, 0, $json);
    }

    private function loginChild(bool $json = true): void
    {
        $this->login(3, 'non_staff_user', 0, 1, $json);
    }

    private function day(int $offset): string
    {
        return Date::today()->addDays($offset)->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array)json_decode((string)$this->_response->getBody(), true);
    }

    private function seed(int $userId, string $date, int $type, int $eat, int $change, int $approval = 0, int $room = 1): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => $type,
            'i_id_room' => $room, 'eat_flag' => $eat, 'i_change_flag' => $change, 'i_version' => 1,
            'i_approval_status' => $approval,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
    }

    private function row(int $userId, string $date, int $type): ?\Cake\Datasource\EntityInterface
    {
        return $this->getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => $userId, 'i_id_room' => 1, 'd_reservation_date' => $date, 'i_reservation_type' => $type])
            ->first();
    }

    // ---- events ----

    public function testEvents_invalidRangesReturn400(): void
    {
        $this->loginAdmin();
        $cases = [
            'no end'      => '?start=2026-01-01',
            'no start'    => '?end=2026-01-31',
            'garbage'     => '?start=zzz&end=yyy',
            'reversed'    => '?start=2026-02-01&end=2026-01-01',
            'too long'    => '?start=2026-01-01&end=2028-01-01',
        ];
        foreach ($cases as $name => $query) {
            $this->get('/TReservationInfo/events' . $query);
            $this->assertResponseCode(400, $name);
            $this->assertResponseContains('Invalid date range');
        }
    }

    public function testEvents_returnsTotalsOfOwnRoom(): void
    {
        $date = $this->day(30);
        $this->seed(3, $date, 1, 1, 1);
        $this->seed(2, $date, 1, 1, 1);
        $this->loginStaff();
        $this->get('/TReservationInfo/events?start=' . $date . '&end=' . $date);

        $this->assertResponseOk();
        $this->assertIsArray($this->json()['data']['events']);
    }

    // ---- calendarEvents ----

    public function testCalendarEvents_returnsEventsForRange(): void
    {
        $date = $this->day(30);
        $this->seed(2, $date, 3, 1, 1);
        $this->loginStaff();
        $this->get('/TReservationInfo/calendarEvents?start=' . $date . '&end=' . $this->day(31));

        $this->assertResponseOk();
        $this->assertTrue($this->json()['ok']);
        $this->assertIsArray($this->json()['data']['events']);
    }

    public function testCalendarEvents_reversedAndTooLargeRangesReturn400(): void
    {
        $this->loginStaff();
        $this->get('/TReservationInfo/calendarEvents?start=2026-02-01&end=2026-01-01');
        $this->assertResponseCode(400);
        $this->assertResponseContains('Invalid date range');

        $this->get('/TReservationInfo/calendarEvents?start=2026-01-01&end=2028-01-01');
        $this->assertResponseCode(400);
        $this->assertResponseContains('Date range too large');
    }

    // ---- index / view / roomDetails ----

    public function testIndex_withReservationsAndRoomFilterExposesOwnReservationsAndRoomNames(): void
    {
        $date = $this->day(5);
        $this->seed(3, $date, 3, 1, 1);
        $this->loginChild(false);
        $this->get('/TReservationInfo?cal_room_id=1');

        $this->assertResponseOk();
        $this->assertSame(1, $this->viewVariable('calRoomId'));
        $this->assertContains($date, $this->viewVariable('myReservationDates'));
        $this->assertSame([1], array_keys($this->viewVariable('reservationRoomNames')));
        $this->assertFalse($this->viewVariable('isAdmin'));
    }

    public function testIndex_unselectableRoomFallsBackToDefaultRoom(): void
    {
        $this->loginStaff(false);
        $this->get('/TReservationInfo?cal_room_id=999');

        $this->assertResponseOk();
        $this->assertSame(1, $this->viewVariable('calRoomId'));
    }

    public function testView_withDateAndRoomRenders(): void
    {
        $this->loginAdmin(false);
        $this->get('/TReservationInfo/view?date=' . $this->day(20) . '&room_id=1');

        $this->assertResponseOk();
    }

    public function testRoomDetails_rendersEatersAndNonEaters(): void
    {
        $date = $this->day(20);
        $this->seed(3, $date, 3, 1, 1);
        $this->loginAdmin(false);
        $this->get('/TReservationInfo/roomDetails/1/' . $date . '/3');

        $this->assertResponseOk();
        $this->assertSame($date, $this->viewVariable('date'));
        $this->assertNotEmpty($this->viewVariable('eatUsers'));
    }

    public function testRoomDetails_badDateFormatIsRejected(): void
    {
        $this->loginAdmin(false);
        $this->get('/TReservationInfo/roomDetails/1/20-01-2030/3');

        $this->assertResponseCode(500);
    }

    // ---- getUsersByRoom / getPersonalReservation / checkDuplicateReservation ----

    public function testGetUsersByRoom_returnsUsersWithMealFlags(): void
    {
        $date = $this->day(30);
        $this->seed(3, $date, 1, 1, 1);
        $this->loginAdmin();
        $this->get('/TReservationInfo/getUsersByRoom/1?date=' . $date);

        $this->assertResponseOk();
        $users = $this->json()['data']['usersByRoom'];
        $this->assertNotEmpty($users);
    }

    public function testGetUsersByRoom_otherRoomIsForbidden(): void
    {
        $this->loginStaff();
        $this->get('/TReservationInfo/getUsersByRoom/99?date=' . $this->day(30));

        $this->assertResponseCode(403);
    }

    public function testGetPersonalReservation_missingDateReturns400(): void
    {
        $this->loginChild();
        $this->get('/TReservationInfo/getPersonalReservation');

        $this->assertResponseCode(400);
        $this->assertResponseContains('日付が指定されていません。');
    }

    public function testGetPersonalReservation_returnsOwnReservation(): void
    {
        $date = $this->day(30);
        $this->seed(3, $date, 3, 1, 1);
        $this->loginChild();
        $this->get('/TReservationInfo/getPersonalReservation?date=' . $date);

        $this->assertResponseOk();
        $this->assertTrue($this->json()['ok']);
    }

    public function testCheckDuplicateReservation_detectsExistingAndBuildsEditUrl(): void
    {
        $date = $this->day(30);
        $this->seed(3, $date, 3, 1, 1);
        $this->loginAdmin();
        $this->post('/TReservationInfo/checkDuplicateReservation', ['d_reservation_date' => $date, 'i_id_room' => 1, 'reservation_type' => 3]);

        $this->assertResponseOk();
        $data = $this->json()['data'];
        $this->assertTrue($data['isDuplicate']);
        $this->assertStringContainsString('TReservationInfo/edit', $data['editUrl']);
    }

    public function testCheckDuplicateReservation_noDuplicate(): void
    {
        $this->loginAdmin();
        $this->post('/TReservationInfo/checkDuplicateReservation', ['d_reservation_date' => $this->day(30), 'i_id_room' => 1, 'reservation_type' => 2]);

        $this->assertResponseOk();
        $this->assertFalse($this->json()['data']['isDuplicate']);
    }

    public function testCheckDuplicateReservation_missingFieldsReturn400(): void
    {
        $this->loginAdmin();
        $this->post('/TReservationInfo/checkDuplicateReservation', ['i_id_room' => 1]);

        $this->assertResponseCode(400);
        $this->assertFalse($this->json()['data']['isDuplicate']);
    }

    // ---- add ----

    public function testAdd_getRendersFormWithRooms(): void
    {
        $this->loginAdmin(false);
        $this->get('/TReservationInfo/add?date=' . $this->day(30));

        $this->assertResponseOk();
        $this->assertArrayHasKey(1, $this->viewVariable('roomList'));
        $this->assertNull($this->viewVariable('errorMessage'));
    }

    public function testAdd_modalRendersWithoutLayoutAndFrameHeaders(): void
    {
        $this->loginAdmin(false);
        $this->get('/TReservationInfo/add?modal=1&date=' . $this->day(30));

        $this->assertResponseOk();
        $this->assertSame('sameorigin', strtolower($this->_response->getHeaderLine('X-Frame-Options')));
        $this->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }

    public function testAdd_getWithTooEarlyDateShowsErrorMessage(): void
    {
        $this->loginAdmin(false);
        $this->get('/TReservationInfo/add?date=' . $this->day(3));

        $this->assertResponseOk();
        $this->assertNotEmpty($this->viewVariable('errorMessage'));
    }

    public function testAdd_postIndividualAjaxRegistersMeal(): void
    {
        $date = $this->day(30);
        $this->loginStaff();
        $this->configRequest(['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
        $this->post('/TReservationInfo/add?date=' . $date, ['reservation_type' => '1', 'meals' => [3 => [1 => 1]]]);

        $this->assertResponseOk();
        $this->assertTrue($this->json()['ok']);
        $this->assertSame(1, (int)$this->row(2, $date, 3)->eat_flag);
    }

    public function testAdd_postGroupAjaxRegistersUsers(): void
    {
        $date = $this->day(30);
        $this->loginAdmin();
        $this->configRequest(['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
        $this->post('/TReservationInfo/add?date=' . $date, ['reservation_type' => '2', 'i_id_room' => 1, 'users' => [3 => [1 => 1]]]);

        $this->assertResponseOk();
        $this->assertNotNull($this->row(3, $date, 1));
    }

    public function testAdd_postLunchAndBentoTogetherIsRejectedAjax(): void
    {
        $this->loginStaff();
        $this->configRequest(['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
        $this->post('/TReservationInfo/add?date=' . $this->day(30), ['reservation_type' => '1', 'lunch' => 1, 'bento' => 1, 'meals' => [2 => [1 => 1]]]);

        $this->assertResponseCode(409);
        $this->assertResponseContains('昼食と弁当は同時に予約できません');
    }

    public function testAdd_postLunchAndBentoTogetherRedirectsWithFlashWhenNotAjax(): void
    {
        $date = $this->day(30);
        $this->loginStaff(false);
        $this->post('/TReservationInfo/add?date=' . $date, ['reservation_type' => '1', 'lunch' => 1, 'bento' => 1]);

        $this->assertRedirectContains('/TReservationInfo');
        $this->assertFlashMessage('昼食と弁当は同時に予約できません。どちらか一方を選択してください。');
    }

    public function testAdd_postWithTooEarlyDateIsRejected(): void
    {
        $this->loginStaff();
        $this->configRequest(['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
        $this->post('/TReservationInfo/add?date=' . $this->day(2), ['reservation_type' => '1', 'meals' => [3 => [1 => 1]]]);

        $this->assertResponseCode(422);
    }

    public function testAdd_postWithTooEarlyDateRedirectsWithFlashWhenNotAjax(): void
    {
        $this->loginStaff(false);
        $this->post('/TReservationInfo/add?date=' . $this->day(2), ['reservation_type' => '1', 'meals' => [3 => [1 => 1]]]);

        $this->assertRedirectContains('/TReservationInfo');
        $this->assertFlashElement('flash/error');
    }

    public function testAdd_postNonAjaxSuccessRedirectsWithSuccessFlash(): void
    {
        $date = $this->day(30);
        $this->loginStaff(false);
        $this->post('/TReservationInfo/add?date=' . $date, ['reservation_type' => '1', 'meals' => [1 => [1 => 1]]]);

        $this->assertRedirectContains('/TReservationInfo');
        $this->assertNotNull($this->row(2, $date, 1));
    }

    public function testAdd_postNonAjaxFailureRedirectsWithErrorFlash(): void
    {
        $this->loginStaff(false);
        $this->post('/TReservationInfo/add?date=' . $this->day(30), ['reservation_type' => '1']);

        $this->assertRedirectContains('/TReservationInfo');
        $this->assertFlashElement('flash/error');
    }

    // ---- changeEdit ----

    public function testChangeEdit_getJsonReturnsRoomUsersAndReservations(): void
    {
        $date = $this->day(3);
        $this->seed(3, $date, 3, 0, 1);
        $this->loginStaff();
        $this->get('/TReservationInfo/change-edit/1/' . $date);

        $this->assertResponseOk();
        $data = $this->json()['data'];
        $this->assertSame(['id' => 1, 'name' => 'Lorem ipsum dolor sit amet'], $data['contextRoom']);
        $this->assertSame($date, $data['date']);
        $this->assertNotEmpty($data['users']);
    }

    public function testChangeEdit_getHtmlRendersEditScreen(): void
    {
        $date = $this->day(3);
        $this->seed(2, $date, 3, 0, 1);
        $this->loginStaff(false);
        $this->get('/TReservationInfo/change-edit/1/' . $date);

        $this->assertResponseOk();
        $this->assertTemplate('change_edit');
        $this->assertSame($date, $this->viewVariable('date'));
        $this->assertCount(1, $this->viewVariable('individualReservations'));
    }

    public function testChangeEdit_childWithoutRoomMembership_isForbiddenNotServerError(): void
    {
        $this->getTableLocator()->get('MUserGroup')->deleteAll(['i_id_user' => 3]);
        $this->loginChild();

        $this->get('/TReservationInfo/changeEdit?date=' . $this->day(3));

        $this->assertResponseCode(403);
        $this->assertSame('forbidden', $this->json()['status']);
    }

    public function testChangeEdit_modalShellResolvesDefaultRoom(): void
    {
        $date = $this->day(3);
        $this->seed(2, $date, 3, 0, 1);
        $this->loginStaff(false);
        $this->get('/TReservationInfo/changeEdit?modal=1&date=' . $date);

        $this->assertResponseOk();
        $this->assertTemplate('change_edit');
        $this->assertArrayHasKey(1, $this->viewVariable('rooms'));
    }

    public function testChangeEdit_postCreatesAndUpdatesRows(): void
    {
        $date = $this->day(3);
        $this->seed(3, $date, 3, 0, 1);
        $this->loginStaff();
        $this->post('/TReservationInfo/change-edit/1/' . $date, ['users' => [3 => [1 => ['i_change_flag' => 1], 3 => ['i_change_flag' => 0]]]]);

        $this->assertResponseOk();
        $body = $this->json();
        $this->assertTrue($body['ok']);
        $this->assertSame('直前予約を更新しました。', $body['message']);
        $this->assertNotNull($this->row(3, $date, 1));
        $this->assertSame(0, (int)$this->row(3, $date, 3)->i_change_flag);
    }

    public function testChangeEdit_postHtmlRedirectsToIndexWithFlash(): void
    {
        $date = $this->day(3);
        $this->loginStaff(false);
        $this->post('/TReservationInfo/change-edit/1/' . $date, ['users' => [3 => [1 => ['i_change_flag' => 1]]]]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertNotNull($this->row(3, $date, 1));
    }

    public function testChangeEdit_postIndividualReservationTypeUsesPersonalFlow(): void
    {
        $date = $this->day(3);
        $this->loginStaff();
        $this->post('/TReservationInfo/change-edit/1/' . $date, ['reservation_type' => '1', 'meals' => [1 => [1 => 1]]]);

        $this->assertResponseOk();
        $body = $this->json();
        $this->assertSame('直前予約（個人）を更新しました。', $body['message']);
        $this->assertSame($date, $body['date']);
        $this->assertNotNull($this->row(2, $date, 1));
    }

    public function testChangeEdit_postIndividualWithoutDateReturns422(): void
    {
        $this->loginStaff();
        $this->post('/TReservationInfo/changeEdit', ['reservation_type' => '1', 'meals' => [1 => [1 => 1]]]);

        $this->assertResponseCode(422);
        $this->assertResponseContains('日付が指定されていません。');
    }

    public function testChangeEdit_postIndividualWithoutDateRedirectsWhenNotJson(): void
    {
        $this->loginStaff(false);
        $this->post('/TReservationInfo/changeEdit', ['reservation_type' => '1', 'meals' => [1 => [1 => 1]]]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('日付が指定されていません。');
    }

    public function testChangeEdit_postIndividualDomainErrorIsReturnedAsJson(): void
    {
        // 過去日は直前編集でも変更できない
        $date = $this->day(-3);
        $this->loginStaff();
        $this->post('/TReservationInfo/change-edit/1/' . $date, ['reservation_type' => '1', 'meals' => [1 => [1 => 1]]]);

        $this->assertTrue($this->_response->getStatusCode() >= 400);
        $this->assertFalse($this->json()['ok']);
        $this->assertNull($this->row(2, $date, 1));
    }

    public function testChangeEdit_postIndividualDomainErrorRedirectsWhenNotJson(): void
    {
        $this->loginStaff(false);
        $this->post('/TReservationInfo/change-edit/1/' . $this->day(-3), ['reservation_type' => '1', 'meals' => [1 => [1 => 1]]]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashElement('flash/error');
    }

    public function testChangeEdit_approvedReservationReturns409(): void
    {
        $date = $this->day(3);
        $this->seed(3, $date, 3, 1, 1, 1);
        $this->loginStaff();
        $this->post('/TReservationInfo/change-edit/1/' . $date, ['users' => [3 => [3 => ['i_change_flag' => 0]]]]);

        $this->assertResponseCode(409);
        $this->assertSame('approved', $this->json()['status']);
        $this->assertSame(1, (int)$this->row(3, $date, 3)->i_change_flag);
    }

    public function testChangeEdit_approvedReservationShowsFlashWhenNotJson(): void
    {
        $this->enableRetainFlashMessages();
        $date = $this->day(3);
        $this->seed(3, $date, 3, 1, 1, 1);
        $this->loginStaff(false);
        $this->post('/TReservationInfo/change-edit/1/' . $date, ['users' => [3 => [3 => ['i_change_flag' => 0]]]]);

        $this->assertResponseOk();
        $this->assertFlashElement('flash/error');
        $this->assertSame(1, (int)$this->row(3, $date, 3)->i_change_flag);
    }

    public function testChangeEdit_lunchAndBentoTogetherReturns409(): void
    {
        $date = $this->day(3);
        $this->loginStaff();
        $this->post('/TReservationInfo/change-edit/1/' . $date, ['users' => [3 => [2 => ['i_change_flag' => 1], 4 => ['i_change_flag' => 1]]]]);

        $this->assertResponseCode(409);
        $this->assertSame('conflict', $this->json()['status']);
        $this->assertNull($this->row(3, $date, 2));
        $this->assertNull($this->row(3, $date, 4));
    }

    public function testChangeEdit_acceptsRawJsonBody(): void
    {
        $date = $this->day(3);
        $token = (new \Cake\Http\Middleware\CsrfProtectionMiddleware())->createToken();
        $this->session(['Auth' => [
            'i_id_user' => 2, 'c_login_account' => 'staff_user', 'c_user_name' => 'テスト2',
            'i_admin' => 0, 'i_user_level' => 0, 'i_id_room' => 1,
        ]]);
        $this->cookie('csrfToken', $token);
        $this->configRequest([
            'headers' => ['Accept' => 'application/json', 'X-CSRF-Token' => $token],
            'input'   => json_encode(['users' => [3 => [1 => ['i_change_flag' => 1]]]]),
        ]);
        $this->post('/TReservationInfo/change-edit/1/' . $date);

        $this->assertResponseOk();
        $this->assertNotNull($this->row(3, $date, 1));
    }

    public function testChangeEdit_roomOutsideUsersRoomsIsForbiddenJson(): void
    {
        $this->loginStaff();
        $this->get('/TReservationInfo/change-edit/99/' . $this->day(3));

        $this->assertResponseCode(403);
        $this->assertSame('この部屋を操作する権限がありません。', $this->json()['message']);
    }

    public function testChangeEdit_roomOutsideUsersRoomsRedirectsWhenNotJson(): void
    {
        $this->loginStaff(false);
        $this->get('/TReservationInfo/change-edit/99/' . $this->day(3));

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashElement('flash/error');
    }

    public function testChangeEdit_missingRoomAndDateReturns400Json(): void
    {
        $this->loginStaff();
        $this->get('/TReservationInfo/changeEdit');

        $this->assertResponseCode(400);
        $this->assertSame('直前予約の取得中にエラーが発生しました。', $this->json()['message']);
    }
}
