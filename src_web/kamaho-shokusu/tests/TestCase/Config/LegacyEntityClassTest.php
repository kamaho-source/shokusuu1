<?php
declare(strict_types=1);

namespace App\Test\TestCase\Config;

use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;

/**
 * Entity を移す前のセッション（旧クラス名 App\Model\Entity\Xxx で保存された Entity）を、移したあとも読み戻せることのテスト。
 */
class LegacyEntityClassTest extends TestCase
{
    public function testSessionWithLegacyEntityClassName_isRestoredAsEntity(): void
    {
        $user = $this->getTableLocator()->get('MUserInfo')->newEmptyEntity();
        $user->set(['i_id_user' => 34, 'c_login_account' => 'someone']);

        // セッションに残っている旧クラス名の形にする（移す前のコードなら元々この名前）
        $legacy = 'App\\Model\\Entity\\MUserInfo';
        $serialized = (string)preg_replace(
            '/^O:\d+:"[^"]+"/',
            'O:' . strlen($legacy) . ':"' . $legacy . '"',
            serialize($user)
        );

        $restored = unserialize($serialized);

        $this->assertInstanceOf(EntityInterface::class, $restored);
        $this->assertInstanceOf(get_class($user), $restored);
        // SessionAuthenticator は配列として読む
        $this->assertSame('someone', $restored['c_login_account']);
    }
}
