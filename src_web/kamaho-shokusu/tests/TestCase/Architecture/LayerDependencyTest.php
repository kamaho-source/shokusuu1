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
 * - Domain・Application は時計を直接読まない（今日・現在時刻は PSR-20 ClockInterface で受け取る）。
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

    /**
     * Domain・Application で時計を直接読んでいる箇所（Xxx::now()・Xxx::today() など、time()・date() など）。
     *
     * @return list<string> "相対パス:行: 呼び出し"
     */
    private static function clockReads(string $layer): array
    {
        $root  = dirname(__DIR__, 3) . '/src/' . $layer;
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path   = $layer . substr($file->getPathname(), strlen($root));
            $tokens = array_values(array_filter(
                token_get_all((string)file_get_contents($file->getPathname())),
                static fn($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ));
            foreach ($tokens as $i => $t) {
                if (!is_array($t) || $t[0] !== T_STRING) {
                    continue;
                }
                $prev = $tokens[$i - 1] ?? null;
                $next = $tokens[$i + 1] ?? null;
                $name = strtolower($t[1]);
                $isStaticClock = is_array($prev) && $prev[0] === T_DOUBLE_COLON
                    && in_array($name, ['now', 'today', 'yesterday', 'tomorrow'], true);
                $isClockFunction = $next === '(' && in_array($name, ['time', 'date', 'strtotime', 'microtime', 'hrtime'], true)
                    && !(is_array($prev) && in_array($prev[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION], true));
                if ($isStaticClock || $isClockFunction) {
                    $found[] = "{$path}:{$t[2]}: {$t[1]}";
                }
            }
        }
        sort($found);

        return $found;
    }

    public function testDomainAndApplicationDoNotReadTheClockDirectly(): void
    {
        $this->assertSame([], array_merge(self::clockReads('Domain'), self::clockReads('Application')));
    }
}
