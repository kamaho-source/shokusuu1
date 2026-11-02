<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Service\UserProfileRule;
use PHPUnit\Framework\TestCase;

/**
 * 利用者のプロフィールと登録先の部屋のルールのテスト。
 */
class UserProfileRuleTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function ok(array $override = []): array
    {
        return $override + ['role' => '1', 'age' => '10', 'age_group' => '2', 'i_user_gender' => '2', 'staff_id' => ''];
    }

    public function testStaffId_upToNineDigitsOnly(): void
    {
        $this->assertTrue(UserProfileRule::isValidStaffId('123456789'));
        $this->assertFalse(UserProfileRule::isValidStaffId('1234567890'));
        $this->assertFalse(UserProfileRule::isValidStaffId('12a'));
        $this->assertFalse(UserProfileRule::isValidStaffId(''));
    }

    public function testCreationError_inScreenOrder(): void
    {
        $this->assertNull(UserProfileRule::creationError(true, self::ok(), 1));
        $this->assertSame('管理者以外は児童のみ登録できます。', UserProfileRule::creationError(true, self::ok(['role' => '0', 'age' => '0']), 1), '権限の誤りを先に出す');
        $this->assertSame('性別・年代・年齢を選択してください。', UserProfileRule::creationError(false, self::ok(['age' => '81']), 1));
        $this->assertSame('性別・年代・年齢を選択してください。', UserProfileRule::creationError(false, self::ok(['age_group' => '99']), 1));
        $this->assertSame('職員IDは9桁以内の数字で入力してください。', UserProfileRule::creationError(false, self::ok(['role' => '0', 'staff_id' => ' x ']), 0));
        $this->assertNull(UserProfileRule::creationError(false, self::ok(['role' => '0', 'staff_id' => ' 42 ']), 0), '前後の空白は無視する');
    }

    public function testRoomsError(): void
    {
        // 管理者以外（required = true）
        $this->assertStringStartsWith('所属している部屋がないため', (string)UserProfileRule::roomsError([], [1], true));
        $this->assertSame('登録先の部屋を選択してください。', UserProfileRule::roomsError([1], [0], true));
        $this->assertSame('自分が所属していない部屋には登録できません。', UserProfileRule::roomsError([1], [1, 2], true));
        $this->assertNull(UserProfileRule::roomsError([1, 2], [2], true));
        // 管理者・システム管理者（required = false）: 部屋の選択は任意だが、所属外の部屋は拒否する（#821）
        $this->assertNull(UserProfileRule::roomsError([1], [], false), '管理者は部屋を選ばなくてもよい');
        $this->assertNull(UserProfileRule::roomsError([1], [0], false));
        $this->assertNull(UserProfileRule::roomsError([], [], false), '所属部屋の無い管理者も部屋なしでは登録できる');
        $this->assertNull(UserProfileRule::roomsError([1, 2], [2], false));
        $this->assertSame('自分が所属していない部屋には登録できません。', UserProfileRule::roomsError([1], [2], false), '管理者でも所属外の部屋は拒否');
        $this->assertSame('自分が所属していない部屋には登録できません。', UserProfileRule::roomsError([], [1], false), '所属部屋の無い管理者は部屋を選べない');
    }
}
