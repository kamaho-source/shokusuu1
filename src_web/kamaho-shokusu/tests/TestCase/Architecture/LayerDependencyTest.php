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
 *   既存の違反は KNOWN_APPLICATION_VIOLATIONS に載せて許容する（#920 第4段階で解消して、この一覧から消す）。
 */
class LayerDependencyTest extends TestCase
{
    private const FRAMEWORK = '/^Cake\\\\(?!Chronos\\\\)/';

    private const OUTER_THAN_DOMAIN = '/^App\\\\(Application|Service|Model|Controller|Infrastructure|View|Policy|Command|Middleware)\\\\/';

    private const OUTER_THAN_APPLICATION = '/^App\\\\(Service|Model|Controller|Infrastructure|View|Policy|Command|Middleware)\\\\/';

    /** 既存の違反（UseCase が Service を呼んでいる）。解消したら消す。 */
    private const KNOWN_APPLICATION_VIOLATIONS = [
        'Application/UseCase/ChangeMealRoom/ChangeMealRoomUseCase.php: App\\Service\\ReservationWriteService',
        'Application/UseCase/DirectRegisterMeals/DirectRegisterMealsUseCase.php: App\\Service\\ReservationWriteService',
    ];

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

    public function testApplicationDoesNotDependOnOuterLayersExceptKnownViolations(): void
    {
        // 新しい違反が増えたら失敗する。既存の違反を解消したら KNOWN_APPLICATION_VIOLATIONS から消すよう失敗で知らせる
        $this->assertSame(self::KNOWN_APPLICATION_VIOLATIONS, self::references('Application', self::OUTER_THAN_APPLICATION));
    }
}
