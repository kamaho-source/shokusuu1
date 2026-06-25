<?php
declare(strict_types=1);

namespace App\Application\AI;

/**
 * AIアシスタントのシステムプロンプトを提供するインターフェース。
 */
interface SystemPromptProviderInterface
{
    /**
     * システムプロンプト文字列を返す。
     */
    public function get(): string;
}
