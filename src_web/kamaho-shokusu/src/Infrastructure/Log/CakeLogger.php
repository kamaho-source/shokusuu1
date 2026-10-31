<?php
declare(strict_types=1);

namespace App\Infrastructure\Log;

use Cake\Log\Log;
use Psr\Log\AbstractLogger;

/**
 * PSR-3 のロガーを CakePHP の Log に書く（Application の UseCase から使う）。
 */
final class CakeLogger extends AbstractLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        Log::write((string)$level, (string)$message, $context);
    }
}
