<?php
declare(strict_types=1);

namespace App\Test\TestCase\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * 層の依存方向の検査（CLAUDE.md「依存方向は外側 → 内側の一方向のみ」）。
 *
 * PHP のトークンからクラス名の参照（use 文・完全修飾名・修飾名）を拾う。コメントと文字列は見ない。
 *
 * - Domain・Application は CakePHP 本体（Cake\…）に依存しない。日付型の cakephp/chronos（Cake\Chronos\…）は別パッケージなので許可する。
 * - Domain は外側の層（Application・Service・Model・Controller・Infrastructure など）を参照しない。
 * - Application は外側の層（Service・Model・Controller・Infrastructure など）を参照しない。
 */
class LayerDependencyTest extends TestCase
{
    private const FRAMEWORK = '/^Cake\\\\(?!Chronos\\\\)/';

    private const OUTER_THAN_DOMAIN = '/^App\\\\(Application|Service|Model|Controller|Infrastructure|View|Policy|Command|Middleware)\\\\/';

    private const OUTER_THAN_APPLICATION = '/^App\\\\(Service|Model|Controller|Infrastructure|View|Policy|Command|Middleware)\\\\/';

    /**
     * @return list<string> 参照したクラス名のうち $pattern に当たるもの（"相対パス: 名前"、重複なし・ソート済み）
     */
    private static function references(string $layer, string $pattern): array
    {
        $root  = dirname(__DIR__, 3) . '/src/' . $layer;
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $layer . substr($file->getPathname(), strlen($root));
            foreach (token_get_all((string)file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $name = ltrim($token[1], '\\');
                if (preg_match($pattern, $name)) {
                    $found["{$path}: {$name}"] = true;
                }
            }
        }
        $found = array_keys($found);
        sort($found);

        return $found;
    }

    public function testDomainAndApplicationDoNotDependOnCakePhpFramework(): void
    {
        $this->assertSame([], array_merge(
            self::references('Domain', self::FRAMEWORK),
            self::references('Application', self::FRAMEWORK)
        ));
    }

    public function testDomainDoesNotDependOnOuterLayers(): void
    {
        $this->assertSame([], self::references('Domain', self::OUTER_THAN_DOMAIN));
    }

    public function testApplicationDoesNotDependOnOuterLayers(): void
    {
        $this->assertSame([], self::references('Application', self::OUTER_THAN_APPLICATION));
    }
}
