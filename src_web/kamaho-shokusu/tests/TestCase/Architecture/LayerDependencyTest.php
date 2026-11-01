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
 * - Domain は App\Domain 以外のアプリのクラスを参照しない。
 * - Application は App\Domain・App\Application 以外のアプリのクラスを参照しない。
 *   外側の層を列挙せず許可する側を書くので、Exception・Console・Log など新しい置き場もすり抜けない。
 * - Domain・Application は時計を直接読まない（今日・現在時刻は PSR-20 ClockInterface で受け取る）。
 */
class LayerDependencyTest extends TestCase
{
    private const FRAMEWORK = '/^Cake\\\\(?!Chronos\\\\)/';

    private const OUTER_THAN_DOMAIN = '/^App\\\\(?!Domain(\\\\|$))/';

    private const OUTER_THAN_APPLICATION = '/^App\\\\(?!(Domain|Application)(\\\\|$))/';

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
     * Domain・Application で時計を直接読んでいる箇所（Xxx::now()・Xxx::today() など、time()・date() など（\\time() も）、
     * 引数なしや 'now' などの相対指定の new ChronosDate()・new DateTimeImmutable() など）。
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
                if (!is_array($t) || !in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                    continue;
                }
                $prev = $tokens[$i - 1] ?? null;
                $next = $tokens[$i + 1] ?? null;
                $arg  = $tokens[$i + 2] ?? null;
                $name = strtolower(ltrim($t[1], '\\'));
                $isStaticClock = $t[0] === T_STRING && is_array($prev) && $prev[0] === T_DOUBLE_COLON
                    && in_array($name, ['now', 'today', 'yesterday', 'tomorrow'], true);
                $isClockFunction = $next === '(' && in_array($name, self::CLOCK_FUNCTIONS, true)
                    && !(is_array($prev) && in_array($prev[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION, T_NEW], true));
                // new ChronosDate() / new ChronosDate('today') など。日付の文字列（'2026-11-01'）や変数を渡すものは時計を読まない
                $isClockConstructor = is_array($prev) && $prev[0] === T_NEW
                    && in_array(substr((string)strrchr('\\' . $name, '\\'), 1), self::DATE_CLASSES, true)
                    && ($next !== '(' || $arg === ')'
                        || (is_array($arg) && $arg[0] === T_CONSTANT_ENCAPSED_STRING && !preg_match('/^.\d/', $arg[1])));
                if ($isStaticClock || $isClockFunction || $isClockConstructor) {
                    $found[] = "{$path}:{$t[2]}: {$t[1]}";
                }
            }
        }
        sort($found);

        return $found;
    }

    private const CLOCK_FUNCTIONS = [
        'time', 'date', 'strtotime', 'microtime', 'hrtime', 'mktime', 'gmmktime', 'gmdate', 'idate',
        'getdate', 'localtime', 'gettimeofday', 'date_create', 'date_create_immutable',
    ];

    private const DATE_CLASSES = ['chronosdate', 'chronos', 'chronostime', 'datetime', 'datetimeimmutable', 'date'];

    public function testDomainAndApplicationDoNotReadTheClockDirectly(): void
    {
        $this->assertSame([], array_merge(self::clockReads('Domain'), self::clockReads('Application')));
    }
}
