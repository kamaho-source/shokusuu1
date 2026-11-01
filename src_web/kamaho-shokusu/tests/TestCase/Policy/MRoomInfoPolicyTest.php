<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Infrastructure\Persistence\Entity\MRoomInfo;
use App\Policy\MRoomInfoPolicy;
use Cake\TestSuite\TestCase;

class MRoomInfoPolicyTest extends TestCase
{
    public function testCanIndexRequiresAuthentication(): void
    {
        $policy = new MRoomInfoPolicy();
        $resource = new MRoomInfo();

        $this->assertFalse($policy->canIndex(null, $resource));
        $this->assertTrue($policy->canIndex(new PolicyTestIdentity(['i_id_user' => 1]), $resource));
    }

    public function testCanAddRequiresAdmin(): void
    {
        $policy = new MRoomInfoPolicy();
        $resource = new MRoomInfo();

        $admin = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 1]);
        $staff = new PolicyTestIdentity(['i_id_user' => 2, 'i_admin' => 0, 'i_user_level' => 0]);

        $this->assertTrue($policy->canAdd($admin, $resource));
        $this->assertFalse($policy->canAdd($staff, $resource));
    }

    /** 並び替え（#871）: 管理者は全部屋、ブロック長・職員(0,7)は所属部屋だけ、児童・その他は不可 */
    public function testCanReorderMembersByRoleAndAffiliation(): void
    {
        $policy = new MRoomInfoPolicy(new TestRoomAccessService([
            11 => [1], 12 => [1], 13 => [1], 14 => [1], 15 => [1], 16 => [1],
        ]));
        $room1 = new MRoomInfo(['i_id_room' => 1]);
        $room2 = new MRoomInfo(['i_id_room' => 2]);

        $cases = [
            '管理者・所属外'         => [['i_id_user' => 10, 'i_admin' => 1, 'i_user_level' => 0], $room2, true],
            'システム管理者・所属外' => [['i_id_user' => 10, 'i_admin' => 3, 'i_user_level' => 0], $room2, true],
            'ブロック長・所属'       => [['i_id_user' => 11, 'i_admin' => 2, 'i_user_level' => 0], $room1, true],
            'ブロック長・所属外'     => [['i_id_user' => 11, 'i_admin' => 2, 'i_user_level' => 0], $room2, false],
            '職員0・所属'            => [['i_id_user' => 12, 'i_admin' => 0, 'i_user_level' => 0], $room1, false],
            '職員7・所属'            => [['i_id_user' => 13, 'i_admin' => 0, 'i_user_level' => 7], $room1, false],
            '職員0・所属外'          => [['i_id_user' => 12, 'i_admin' => 0, 'i_user_level' => 0], $room2, false],
            '児童・所属'             => [['i_id_user' => 14, 'i_admin' => 0, 'i_user_level' => 1], $room1, false],
            'その他・所属'           => [['i_id_user' => 15, 'i_admin' => 0, 'i_user_level' => 3], $room1, false],
            '役職未設定・所属'       => [['i_id_user' => 16, 'i_admin' => 0, 'i_user_level' => null], $room1, false],
        ];
        foreach ($cases as $label => [$identity, $room, $expected]) {
            $this->assertSame($expected, $policy->canReorderMembers(new PolicyTestIdentity($identity), $room), $label);
        }
        $this->assertFalse($policy->canReorderMembers(null, $room1), '未ログイン');
    }
}
