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
     * 年度更新バッチ（FiscalRolloverCommand）が年代を進める境目。以前バッチに直書きしていた表と同じであること。
     * 6→7 低学年、9→10 中学年、11→12 高学年、12→13 中学生、15→16 高校生、18→19 大人。
     */
    public function testAgeGroupFromAgeMatchesFiscalRollover(): void
    {
        $this->assertSame([7 => 2, 10 => 3, 12 => 4, 13 => 5, 16 => 6, 19 => 7], UserProfileOptions::AGE_GROUP_FROM_AGE);
    }

    public function testLabel(): void
    {
        $this->assertSame('女性', UserProfileOptions::label(UserProfileOptions::GENDERS, 2));
        $this->assertSame('未設定', UserProfileOptions::label(UserProfileOptions::GENDERS, null));
        $this->assertSame('未設定', UserProfileOptions::label(UserProfileOptions::GENDERS, ''));
        $this->assertSame('定義外の値（7）', UserProfileOptions::label(UserProfileOptions::USER_LEVELS, 7));
    }
}
