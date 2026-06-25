<?php
declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\AiUserRole;
use App\Application\AI\SystemPromptProviderInterface;
use Cake\Core\Exception\CakeException;

/**
 * ロールに応じたMarkdownファイルからシステムプロンプトを組み立てる実装。
 *
 * ai_assistant.md（ベース）の {{URL_LIST}} プレースホルダーを
 * ロール別 urls/{role}.md の内容で置換して返す。
 */
final class SystemPromptProvider implements SystemPromptProviderInterface
{
    private const BASE_FILE       = __DIR__ . '/Prompts/ai_assistant.md';
    private const URLS_DIR        = __DIR__ . '/Prompts/urls/';
    private const URL_PLACEHOLDER = '{{URL_LIST}}';

    private const VALID_ROLES = [
        AiUserRole::CHILD,
        AiUserRole::GENERAL,
        AiUserRole::BLOCK_LEADER,
        AiUserRole::ADMIN,
    ];

    /**
     * @param string $role AiUserRole::* 定数
     * @throws CakeException プロンプトファイルが読み込めない場合
     */
    public function get(string $role): string
    {
        if (!in_array($role, self::VALID_ROLES, true)) {
            $role = AiUserRole::GENERAL;
        }

        $base    = $this->readFile(self::BASE_FILE);
        $urlList = $this->readFile(self::URLS_DIR . $role . '.md');

        return str_replace(self::URL_PLACEHOLDER, $urlList, $base);
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new CakeException('AI prompt file could not be read: ' . $path);
        }

        return $content;
    }
}
