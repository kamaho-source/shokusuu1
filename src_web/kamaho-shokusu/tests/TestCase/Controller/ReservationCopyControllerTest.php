<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\I18n\Date;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ReservationCopyController（予約の週／月コピーとプレビュー）のテスト。
 */
class ReservationCopyControllerTest extends TestCase
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

    private function login(int $userId = 1, int $admin = 1, int $level = 0): void
    {
        $this->session(['Auth' => [
            'i_id_user' => $userId, 'c_login_account' => [1 => 'admin_user', 2 => 'staff_user', 3 => 'non_staff_user'][$userId], 'c_user_name' => 'テスト' . $userId,
            'i_admin' => $admin, 'i_user_level' => $level, 'i_id_room' => 1,
        ]]);
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
    }

    /** JSON の生ボディで送る（フォーム値が空のときの読み取り経路） */
    private function postRawJson(string $url, string $raw): void
    {
        $token = (new CsrfProtectionMiddleware())->createToken();
        $this->session(['Auth' => [
            'i_id_user' => 1, 'c_login_account' => 'admin_user', 'c_user_name' => 'テスト1',
            'i_admin' => 1, 'i_user_level' => 0, 'i_id_room' => 1,
        ]]);
        $this->cookie('csrfToken', $token);
        $this->configRequest(['headers' => ['Accept' => 'application/json', 'X-CSRF-Token' => $token], 'input' => $raw]);
        $this->post($url);
    }

    /** 2か月先の月曜（コピー元の週） */
    private function srcMonday(): Date
    {
        $d = Date::today()->addMonths(2);

        return $d->subDays((int)$d->format('N') - 1);
    }

    private function seed(int $userId, int $roomId, Date $date, int $type = 1, int $eat = 1): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'd_reservation_date' => $date->format('Y-m-d'), 'i_reservation_type' => $type,
            'i_id_room' => $roomId, 'eat_flag' => $eat, 'i_change_flag' => $eat, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
    }

    private function countOn(Date $date, int $userId = 3): int
    {
        return $this->getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => $userId, 'd_reservation_date' => $date->format('Y-m-d'), 'eat_flag' => 1])
            ->count();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array)json_decode((string)$this->_response->getBody(), true);
    }

    public function testCopy_weekCopiesReservationsToSameWeekday(): void
    {
        $src = $this->srcMonday();
        $dst = $src->addDays(7);
        $this->seed(3, 1, $src->addDays(2));
        $this->login();

        $this->post('/TReservationInfo/copy', ['mode' => 'week', 'source' => $src->format('Y-m-d'), 'target' => $dst->format('Y-m-d'), 'room_id' => 1]);

        $this->assertResponseOk();
        $body = $this->json();
        $this->assertTrue($body['ok']);
        $this->assertSame('週コピーが完了しました。', $body['message']);
        $this->assertSame(['mode' => 'week', 'total' => 1, 'copied' => 1, 'skipped' => 0, 'invalid_date' => 0, 'affected' => 1], $body['data']);
        $this->assertSame(1, $this->countOn($dst->addDays(2)));
    }

    public function testCopy_weekSnapsGivenDatesToMonday(): void
    {
        $src = $this->srcMonday();
        $dst = $src->addDays(14);
        $this->seed(3, 1, $src);
        $this->login();

        // 水曜日を渡しても、その週の月曜に揃えてコピーする
        $this->post('/TReservationInfo/copy', ['mode' => 'week', 'source_start' => $src->addDays(2)->format('Y-m-d'), 'target_start' => $dst->addDays(2)->format('Y-m-d')]);

        $this->assertResponseOk();
        $this->assertSame(1, $this->json()['data']['copied']);
        $this->assertSame(1, $this->countOn($dst));
    }

    public function testCopy_secondCopySkipsAlreadyCopiedRows(): void
    {
        $src = $this->srcMonday();
        $dst = $src->addDays(7);
        $this->seed(3, 1, $src);
        $this->login();
        $params = ['mode' => 'week', 'source' => $src->format('Y-m-d'), 'target' => $dst->format('Y-m-d')];

        $this->post('/TReservationInfo/copy', $params);
        $this->post('/TReservationInfo/copy', $params);

        $this->assertResponseOk();
        $data = $this->json()['data'];
        $this->assertSame(0, $data['copied']);
        $this->assertSame(1, $data['skipped']);
        $this->assertSame(1, $this->countOn($dst));
    }

    public function testCopy_onlyChildrenCopiesChildReservationsOnly(): void
    {
        $src = $this->srcMonday();
        $dst = $src->addDays(7);
        $this->seed(3, 1, $src);   // 児童（level 1）
        $this->seed(2, 1, $src);   // 職員（level 0）
        $this->login();

        $this->post('/TReservationInfo/copy', ['mode' => 'week', 'source' => $src->format('Y-m-d'), 'target' => $dst->format('Y-m-d'), 'only_children' => '1']);

        $this->assertResponseOk();
        $this->assertSame(1, $this->countOn($dst, 3));
        $this->assertSame(0, $this->countOn($dst, 2));
    }

    public function testCopy_monthCopiesToSameDayOfNextMonth(): void
    {
        $first = new Date(Date::today()->addMonths(2)->format('Y-m-01'));
        $dstFirst = $first->addMonths(1);
        $this->seed(3, 1, $first->addDays(4));
        $this->login();

        $this->post('/TReservationInfo/copy', ['mode' => 'month', 'source' => $first->format('Y-m-d'), 'target' => $dstFirst->format('Y-m-d'), 'room_id' => 1]);

        $this->assertResponseOk();
        $body = $this->json();
        $this->assertSame('月コピーが完了しました。', $body['message']);
        $this->assertSame(1, $body['data']['copied']);
        $this->assertSame(1, $this->countOn($dstFirst->addDays(4)));
    }

    public function testCopy_pastTargetIsCountedAsInvalidDate(): void
    {
        $src = $this->srcMonday();
        $dst = Date::today()->subDays(30);
        $dst = $dst->subDays((int)$dst->format('N') - 1);
        $this->seed(3, 1, $src);
        $this->login();

        $this->post('/TReservationInfo/copy', ['mode' => 'week', 'source' => $src->format('Y-m-d'), 'target' => $dst->format('Y-m-d')]);

        $this->assertResponseOk();
        $data = $this->json()['data'];
        $this->assertSame(0, $data['copied']);
        $this->assertSame(1, $data['invalid_date']);
    }

    public function testCopy_invalidParamsReturn422(): void
    {
        $this->login();
        $cases = [
            'mode'   => [['mode' => 'year', 'source' => '2030-01-01', 'target' => '2030-02-01'], 'mode は'],
            'target' => [['mode' => 'week', 'source' => '2030-01-01'], 'YYYY-MM-DD 形式で指定してください'],
            'format' => [['mode' => 'week', 'source' => 'zzz', 'target' => 'yyy'], 'YYYY-MM-DD 形式で指定してください'],
        ];
        foreach ($cases as $name => [$data, $message]) {
            $this->post('/TReservationInfo/copy', $data);
            $this->assertResponseCode(422, $name);
            $this->assertResponseContains($message);
        }
    }

    public function testCopy_roomWithoutMembershipIsForbidden(): void
    {
        ConnectionManager::get('test')->insert('m_room_info', [
            'i_id_room' => 2, 'c_room_name' => '部屋2', 'i_disp_no' => 2, 'i_enable' => 1, 'i_del_flg' => 0,
            'dt_create' => '2024-07-29 09:11:06', 'c_create_user' => 't',
        ]);
        $src = $this->srcMonday();
        $this->login();

        $this->post('/TReservationInfo/copy', ['mode' => 'week', 'source' => $src->format('Y-m-d'), 'target' => $src->addDays(7)->format('Y-m-d'), 'room_id' => 2]);

        $this->assertResponseCode(403);
        $this->assertResponseContains('所属していない部屋はコピーできません。');
    }

    public function testCopy_withoutAnyRoomIsForbiddenWithDifferentReason(): void
    {
        ConnectionManager::get('test')->delete('m_user_group', ['i_id_user' => 1]);
        $src = $this->srcMonday();
        $this->login();

        $this->post('/TReservationInfo/copy', ['mode' => 'week', 'source' => $src->format('Y-m-d'), 'target' => $src->addDays(7)->format('Y-m-d')]);

        $this->assertResponseCode(403);
        $this->assertResponseContains('所属している部屋がないため、コピーできません。');
    }

    public function testCopy_nonAdminIsDenied(): void
    {
        $src = $this->srcMonday();
        $this->login(3, 0, 1);

        $this->post('/TReservationInfo/copy', ['mode' => 'week', 'source' => $src->format('Y-m-d'), 'target' => $src->addDays(7)->format('Y-m-d')]);

        $this->assertResponseCode(403);
    }

    public function testCopyPreview_weekReturnsCountsWithoutWriting(): void
    {
        $src = $this->srcMonday();
        $dst = $src->addDays(7);
        $this->seed(3, 1, $src->addDays(1));
        $this->login();

        $this->post('/TReservationInfo/copyPreview', ['mode' => 'week', 'source' => $src->format('Y-m-d'), 'target' => $dst->format('Y-m-d')]);

        $this->assertResponseOk();
        $preview = $this->json()['data']['preview'];
        $this->assertSame(1, $preview['total']);
        $this->assertSame(0, $this->countOn($dst->addDays(1)));
    }

    public function testCopyPreview_monthViaGetQuery(): void
    {
        $first = new Date(Date::today()->addMonths(2)->format('Y-m-01'));
        $this->seed(3, 1, $first->addDays(3));
        $this->login();

        $this->get('/TReservationInfo/copyPreview?' . http_build_query([
            'mode' => 'month', 'source' => $first->format('Y-m-d'), 'target' => $first->addMonths(1)->format('Y-m-d'),
        ]));

        $this->assertResponseOk();
        $this->assertSame(1, $this->json()['data']['preview']['total']);
    }

    public function testCopyPreview_invalidParamsReturn422(): void
    {
        $this->login();
        $this->post('/TReservationInfo/copyPreview', ['mode' => 'x']);

        $this->assertResponseCode(422);
    }

    public function testCopyPreview_roomWithoutMembershipIsForbidden(): void
    {
        $src = $this->srcMonday();
        $this->login();

        $this->post('/TReservationInfo/copyPreview', ['mode' => 'week', 'source' => $src->format('Y-m-d'), 'target' => $src->addDays(7)->format('Y-m-d'), 'room_id' => 99]);

        $this->assertResponseCode(403);
    }

    public function testCopyPreview_nonAdminIsDenied(): void
    {
        $this->login(3, 0, 1);
        $this->post('/TReservationInfo/copyPreview', ['mode' => 'week']);

        $this->assertResponseCode(403);
    }

    public function testCopy_acceptsRawJsonBody(): void
    {
        $src = $this->srcMonday();
        $dst = $src->addDays(7);
        $this->seed(3, 1, $src);

        $this->postRawJson('/TReservationInfo/copy', json_encode(['mode' => 'week', 'source' => $src->format('Y-m-d'), 'target' => $dst->format('Y-m-d')]));

        $this->assertResponseOk();
        $this->assertSame(1, $this->json()['data']['copied']);
        $this->assertSame(1, $this->countOn($dst));
    }

    public function testCopy_brokenJsonBodyIsTreatedAsMissingParams(): void
    {
        $this->postRawJson('/TReservationInfo/copy', '{broken');

        $this->assertResponseCode(422);
    }

    public function testCopyPreview_acceptsRawJsonBody(): void
    {
        $src = $this->srcMonday();
        $this->seed(3, 1, $src);

        $this->postRawJson('/TReservationInfo/copyPreview', json_encode(['mode' => 'week', 'source' => $src->format('Y-m-d'), 'target' => $src->addDays(7)->format('Y-m-d')]));

        $this->assertResponseOk();
        $this->assertSame(1, $this->json()['data']['preview']['total']);
    }

    public function testCopyPreview_brokenJsonBodyIsTreatedAsMissingParams(): void
    {
        $this->postRawJson('/TReservationInfo/copyPreview', '{broken');

        $this->assertResponseCode(422);
    }
}
