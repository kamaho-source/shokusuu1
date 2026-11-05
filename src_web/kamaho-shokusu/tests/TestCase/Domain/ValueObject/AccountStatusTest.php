<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\ValueObject;

use App\Domain\ValueObject\AccountStatus;
use Cake\TestSuite\TestCase;

/**
 * 無効アカウント（削除済み・無効）の判定のテスト。
 */
class AccountStatusTest extends TestCase
{
    /**
     * @return array<string, array{int|string|bool|null, int|string|bool|null, bool}>
     */
    public static function flagProvider(): array
    {
        return [
            '有効（0, 0）'            => [0, 0, false],
            '未設定（null, null）'    => [null, null, false],
            '削除済み（int 1）'       => [1, 0, true],
            '無効（int 1）'           => [0, 1, true],
            '削除済み（文字列 "1"）'  => ['1', '0', true],
            '無効（文字列 "1"）'      => ['0', '1', true],
            '無効（bool true）'       => [false, true, true],
            '有効（文字列 "0"）'      => ['0', '0', false],
            '1 以外の値は無効でない'  => [2, '2', false],
        ];
    }

    /**
     * @dataProvider flagProvider
     */
    public function testIsDisabled(int|string|bool|null $delFlag, int|string|bool|null $enable, bool $expected): void
    {
        $this->assertSame($expected, AccountStatus::isDisabled($delFlag, $enable));
    }
}
