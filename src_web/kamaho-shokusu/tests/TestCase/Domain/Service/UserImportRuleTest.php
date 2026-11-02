<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Service\UserImportRule;
use PHPUnit\Framework\TestCase;

/**
 * 一括登録の表記ゆれの読み方。
 */
class UserImportRuleTest extends TestCase
{
    public function testNormalizeRole(): void
    {
        foreach (['0' => 0, '１' => 1, '3' => 3, '2' => null, 'Staff' => 0, 'スタッフ' => 0, '子ども' => 1, 'ボランティア' => 3,
            '常勤職員' => 0, '小学生の児童' => 1, '外部講師' => 3, '先生' => null, '' => null, ' ' => null] as $raw => $want) {
            $this->assertSame($want, UserImportRule::normalizeRole((string)$raw), (string)$raw);
        }
    }

    public function testNormalizeAgeGroup(): void
    {
        foreach (['1' => 1, '7' => 7, '8' => null, '3~5才' => 1, '３－５' => 1, '低学年' => 2, '中学年' => 3, '高学年' => 4,
            '中学生' => 5, '高校生' => 6, '成人' => 7, '大人' => 7, '幼児' => null] as $raw => $want) {
            $this->assertSame($want, UserImportRule::normalizeAgeGroup((string)$raw), (string)$raw);
        }
    }

    public function testNormalizeGender(): void
    {
        foreach (['1' => 1, '2' => 2, '3' => null, '男' => 1, '女性' => 2, 'Male' => 1, ' f ' => 2, 'x' => null, '' => null] as $raw => $want) {
            $this->assertSame($want, UserImportRule::normalizeGender((string)$raw), (string)$raw);
        }
    }
}
