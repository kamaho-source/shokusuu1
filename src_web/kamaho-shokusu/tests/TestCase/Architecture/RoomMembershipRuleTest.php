<?php
declare(strict_types=1);

namespace App\Test\TestCase\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * 「部屋に所属している」（m_user_group.active_flag）の判定が1か所にだけあることの検査（#936 段階3）。
 *
 * src のコード（コメントは見ない）で、active_flag と 0・1 を直接並べて書いている箇所が無いこと。
 * 判定は RoomMembershipStatus（値と isActive）、クエリの条件は MUserGroupTable::activeCondition() を使う。
 */
class RoomMembershipRuleTest extends TestCase
{
    private const ALLOWED = [
        'Domain/ValueObject/RoomMembershipStatus.php',
    ];

    public function testActiveFlagValuesAreNotWrittenDirectly(): void
    {
        $root  = dirname(__DIR__, 3) . '/src';
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || in_array($path, self::ALLOWED, true)) {
                continue;
            }
            $code = '';
            foreach (token_get_all((string)file_get_contents($file->getPathname())) as $token) {
                $code .= is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : (is_array($token) ? $token[1] : $token);
            }
            // 'active_flag' => 0、active_flag === 0、active_flag', 0、active_flag = 0 など
            if (preg_match_all("/active_flag['\"]?\\s*(=>|===|!==|==|!=|=|,)\\s*\\(?\\s*[01]\\b/", $code, $m)) {
                foreach ($m[0] as $hit) {
                    $found[] = $path . ': ' . $hit;
                }
            }
        }

        $this->assertSame([], $found, 'RoomMembershipStatus / MUserGroupTable::activeCondition() を使うこと');
    }
}
