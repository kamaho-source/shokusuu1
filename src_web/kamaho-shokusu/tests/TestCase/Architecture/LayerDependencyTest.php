<?php
declare(strict_types=1);

namespace App\Test\TestCase\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * 層の依存方向の検査（CLAUDE.md「依存方向は外側 → 内側の一方向のみ」）。
 *
 * - Domain・Application は CakePHP 本体（Cake\…）に依存しない。日付型の cakephp/chronos（Cake\Chronos\…）は別パッケージなので許可する。
 * - Domain は外側の層（Application・Service・Model・Controller・Infrastructure）を参照しない。
 *
 * ponytail: use 文だけを見る。完全修飾名での直接参照までは検出しない。
 */
class LayerDependencyTest extends TestCase
{
    /**
     * @return array<string, string> 相対パス => ソース
     */
    private static function sources(string $layer): array
    {
        $root = dirname(__DIR__, 3) . '/src/' . $layer;
        $out  = [];
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $out[$layer . substr($file->getPathname(), strlen($root))] = (string)file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    /**
     * @return list<string> 違反した use 文（"ファイル: use ..."）
     */
    private static function violations(string $layer, string $forbiddenPattern): array
    {
        $found = [];
        foreach (self::sources($layer) as $path => $src) {
            preg_match_all('/^use\s+(?:function\s+|const\s+)?([^;]+);/m', $src, $m);
            foreach ($m[1] as $name) {
                if (preg_match($forbiddenPattern, $name)) {
                    $found[] = "{$path}: use {$name}";
                }
            }
        }

        return $found;
    }

    public function testDomainAndApplicationDoNotDependOnCakePhpFramework(): void
    {
        $pattern = '/^Cake\\\\(?!Chronos\\\\)/';
        $this->assertSame([], array_merge(self::violations('Domain', $pattern), self::violations('Application', $pattern)));
    }

    public function testDomainDoesNotDependOnOuterLayers(): void
    {
        $this->assertSame([], self::violations('Domain', '/^App\\\\(Application|Service|Model|Controller|Infrastructure|View|Policy)\\\\/'));
    }
}
