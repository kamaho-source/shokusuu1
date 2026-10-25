<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\I18n\Date;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * MealCountsApiController::index の統合テスト。
 */
class MealCountsApiControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.TIndividualReservationInfo',
    ];

    private const TOKEN = 'test-meal-counts-token';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('MEAL_COUNTS_API_TOKEN=' . self::TOKEN);
    }

    protected function tearDown(): void
    {
        putenv('MEAL_COUNTS_API_TOKEN');
        parent::tearDown();
    }

    private function getWithToken(string $url, string $token = self::TOKEN): void
    {
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $token]]);
        $this->get($url);
    }

    public function testIndex_noToken_returns401(): void
    {
        $this->get('/api/meal-counts?from=2024-09-01&to=2024-09-30');

        $this->assertResponseCode(401);
    }

    public function testIndex_wrongToken_returns401(): void
    {
        $this->getWithToken('/api/meal-counts?from=2024-09-01&to=2024-09-30', 'wrong');

        $this->assertResponseCode(401);
    }

    public function testIndex_tokenNotConfigured_returns401(): void
    {
        putenv('MEAL_COUNTS_API_TOKEN');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ']]);
        $this->get('/api/meal-counts?from=2024-09-01&to=2024-09-30');

        $this->assertResponseCode(401);
    }

    public function testIndex_invalidRange_returns400(): void
    {
        $this->getWithToken('/api/meal-counts?from=2024-09-01&to=2024-12-31');

        $this->assertResponseCode(400);
    }

    public function testIndex_returnsCountsPerRoomDateMeal(): void
    {
        $this->getWithToken('/api/meal-counts?from=2024-09-01&to=2024-09-30');

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertTrue($body['ok']);
        $this->assertSame([['room_id' => 1, 'room_name' => 'Lorem ipsum dolor sit amet']], $body['data']['rooms']);
        $this->assertSame([[
            'date' => '2024-09-07',
            'room_id' => 1,
            'room_name' => 'Lorem ipsum dolor sit amet',
            'meal_type' => 1,
            'count' => 1,
        ]], $body['data']['items']);
    }

    /**
     * 直近（今日+14日以内）は i_change_flag で数える。eat_flag=1 でも直前に取り消したら数えない。
     */
    public function testIndex_nearDateUsesChangeFlag(): void
    {
        $date = Date::today('Asia/Tokyo')->addDays(3)->format('Y-m-d');
        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $base = ['d_reservation_date' => $date, 'i_reservation_type' => 2, 'i_id_room' => 1, 'eat_flag' => 1, 'i_version' => 1];
        $table->saveManyOrFail($table->newEntities([
            ['i_id_user' => 1] + $base + ['i_change_flag' => 0],
            ['i_id_user' => 2] + $base + ['i_change_flag' => 1],
        ], ['accessibleFields' => ['*' => true]]));

        $this->getWithToken("/api/meal-counts?from={$date}&to={$date}");

        $this->assertResponseOk();
        $items = json_decode((string)$this->_response->getBody(), true)['data']['items'];
        $this->assertCount(1, $items);
        $this->assertSame(1, $items[0]['count']);
    }
}
