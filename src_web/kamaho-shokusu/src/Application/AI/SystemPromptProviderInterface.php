<?php
declare(strict_types=1);

namespace App\Application\AI;

/**
 * AIアシスタントのシステムプロンプトを提供するインターフェース。
 */
interface SystemPromptProviderInterface
{
    /**
     * ロールに応じたシステムプロンプト文字列を返す。
     *
     * @param string $role AiUserRole::* 定数
     */
    public function get(string $role): string;
}
