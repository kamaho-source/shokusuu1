<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\ValueObject;

use App\Domain\ValueObject\UserProfileOptions;
use PHPUnit\Framework\TestCase;

/**
 * UserProfileOptions のテスト（#824）。
 */
class UserProfileOptionsTest extends TestCase
{
    /**
     * 年齢 → 年代は、年度更新バッチ（FiscalRolloverCommand）が年代を進める境目と同じ。
     * 6→7 低学年、9→10 中学年、11→12 高学年、12→13 中学生、15→16 高校生、18→19 大人。
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public static function ages(): array
    {
        return [
            '1歳' => [1, 1], '6歳' => [6, 1],
            '7歳' => [7, 2], '9歳' => [9, 2],
            '10歳' => [10, 3], '11歳' => [11, 3],
            '12歳' => [12, 4],
            '13歳' => [13, 5], '15歳' => [15, 5],
            '16歳' => [16, 6], '18歳' => [18, 6],
            '19歳' => [19, 7], '80歳' => [80, 7],
        ];
    }

    /**
     * @dataProvider ages
     */
    public function testAgeGroupForAge(int $age, int $group): void
    {
        $this->assertSame($group, UserProfileOptions::ageGroupForAge($age));
    }

    public function testLabel(): void
    {
        $this->assertSame('女性', UserProfileOptions::label(UserProfileOptions::GENDERS, 2));
        $this->assertSame('未設定', UserProfileOptions::label(UserProfileOptions::GENDERS, null));
        $this->assertSame('未設定', UserProfileOptions::label(UserProfileOptions::GENDERS, ''));
        $this->assertSame('定義外の値（7）', UserProfileOptions::label(UserProfileOptions::USER_LEVELS, 7));
    }
}
