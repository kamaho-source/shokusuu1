<?php
declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\SystemPromptProviderInterface;
use Cake\Core\Exception\CakeException;

/**
 * Markdownファイルからシステムプロンプトを読み込む実装。
 */
final class SystemPromptProvider implements SystemPromptProviderInterface
{
    private const PROMPT_FILE = __DIR__ . '/Prompts/ai_assistant.md';

    /**
     * @throws CakeException プロンプトファイルが読み込めない場合
     */
    public function get(): string
    {
        $content = file_get_contents(self::PROMPT_FILE);
        if ($content === false) {
            throw new CakeException('AI system prompt file could not be read: ' . self::PROMPT_FILE);
        }

        return $content;
    }
}
