<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Domain\Exception\InvalidInputException;
use App\Service\MealSummaryExportService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * MealSummaryExportService（食事給与控除表）のテスト
 *
 * 控除額の算出は誤りが許されないため、以下を固定する:
 *   - 単価が引けない場合は 0 円で「成功」せず必ずエラーにすること
 *   - m_meal_price_info.i_fiscal_year は暦年（1〜12月）で突き合わせること
 *   - 管理者最終承認済み(status=2)のみを集計すること
 */
class MealSummaryExportServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MMealPriceInfo',
        'app.MUserInfo',
        'app.TIndividualReservationInfo',
        'app.MRoomInfo',
    ];

    private MealSummaryExportService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new MealSummaryExportService();
        $this->insertStaffUser(10, 1010, '職員テスト');
    }

    /** 職員番号を持つ集計対象ユーザーを追加する */
    private function insertStaffUser(int $userId, int $staffId, string $name): void
    {
        ConnectionManager::get('test')->insert('m_user_info', [
            'i_id_user'       => $userId,
            'i_id_staff'      => $staffId,
            'c_login_account' => 'staff_' . $userId,
            'c_login_passwd'  => 'dummy_password',
            'c_user_name'     => $name,
            'i_admin'         => 0,
            'i_user_level'    => 0,
            'i_disp_no'       => $userId,
            'i_enable'        => 0,
            'i_del_flag'      => 0,
            'dt_create'       => '2024-01-01 00:00:00',
            'c_create_user'   => 'system',
            'dt_update'       => '2024-01-01 00:00:00',
            'c_update_user'   => 'system',
        ]);
    }

    /** 個別予約を追加する */
    private function insertMeal(int $userId, string $date, int $mealType, int $status, int $changeFlag = 1): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user'          => $userId,
            'd_reservation_date' => $date,
            'i_reservation_type' => $mealType,
            'i_id_room'          => 1,
            'eat_flag'           => 1,
            'i_change_flag'      => $changeFlag,
            'i_version'          => 1,
            'i_approval_status'  => $status,
            'dt_create'          => '2024-01-01 00:00:00',
            'c_create_user'      => 'system',
        ]);
    }

    /** 指定年の単価行を追加する */
    private function insertPrice(int $year, ?int $morning, ?int $lunch, ?int $dinner, ?int $bento): void
    {
        ConnectionManager::get('test')->insert('m_meal_price_info', [
            'i_fiscal_year'   => $year,
            'i_morning_price' => $morning,
            'i_lunch_price'   => $lunch,
            'i_dinner_price'  => $dinner,
            'i_bento_price'   => $bento,
            'dt_create'       => '2024-01-01 00:00:00',
            'c_create_user'   => 'system',
        ]);
    }

    // ------------------------------------------------------------------
    // 単価が引けないケース
    // ------------------------------------------------------------------

    /**
     * 単価が未登録の年を指定した場合、全員 0 円の控除表を出さずエラーにする。
     */
    public function testAggregateThrowsWhenPriceRowIsMissing(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('2030年の食事単価が登録されていません。');

        $this->service->aggregate(2030, 4);
    }

    /**
     * 単価行はあるが金額が未設定の場合もエラーにする（未設定分が 0 円で紛れ込むのを防ぐ）。
     */
    public function testAggregateThrowsWhenPriceIsNull(): void
    {
        $this->insertPrice(2031, 300, null, 600, 450);

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('昼食');

        $this->service->aggregate(2031, 4);
    }

    /**
     * プレビュー側も同じく単価未登録でエラーにする。
     */
    public function testAggregatePreviewThrowsWhenPriceRowIsMissing(): void
    {
        $this->expectException(InvalidInputException::class);

        $this->service->aggregatePreview(2030, 4);
    }

    // ------------------------------------------------------------------
    // 集計内容
    // ------------------------------------------------------------------

    /**
     * 暦年（1〜12月）で単価と食数を突き合わせ、承認済みのみを控除額に含める。
     */
    public function testAggregateSumsApprovedMealsWithCalendarYearPrice(): void
    {
        // fixture: 2024年 → 朝300 / 昼500 / 夜600 / 弁当450
        $this->insertMeal(10, '2024-03-01', 1, 2); // 朝食・承認済み → 300
        $this->insertMeal(10, '2024-03-02', 2, 2); // 昼食・承認済み → 500
        $this->insertMeal(10, '2024-03-03', 3, 1); // ブロック長承認止まり → 対象外
        $this->insertMeal(10, '2024-03-04', 4, 2, 0); // 直前キャンセル済み → 対象外
        $this->insertMeal(10, '2024-04-01', 1, 2); // 別の月 → 対象外

        $rows = $this->service->aggregate(2024, 3);

        $target = null;
        foreach ($rows as $row) {
            if ((int)$row['staff_id'] === 1010) {
                $target = $row;
                break;
            }
        }

        $this->assertNotNull($target, '職員番号を持つユーザーが集計されていない');
        $this->assertSame(1, $target['meal_counts']['morning']);
        $this->assertSame(1, $target['meal_counts']['lunch']);
        $this->assertSame(0, $target['meal_counts']['dinner'], '未承認の食事が集計されている');
        $this->assertSame(0, $target['meal_counts']['bento'], 'キャンセル済みの食事が集計されている');
        $this->assertSame(800, $target['total_price'], '控除額が単価×回数と一致しない');
    }
}
