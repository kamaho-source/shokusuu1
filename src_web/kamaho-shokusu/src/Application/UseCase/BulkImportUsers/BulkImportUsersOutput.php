<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkImportUsers;

/**
 * 利用者の一括登録の結果（画面に返す集計）。
 */
final class BulkImportUsersOutput
{
    /**
     * @param array<int, list<string>> $errors 行番号 => 理由
     */
    public function __construct(
        public readonly int $processed,
        public readonly int $created,
        public readonly int $skipped,
        public readonly int $failed,
        public readonly array $errors,
    ) {}

    /**
     * @return array{processed: int, created: int, skipped: int, failed: int, errors: array<int, list<string>>}
     */
    public function toArray(): array
    {
        return [
            'processed' => $this->processed,
            'created'   => $this->created,
            'skipped'   => $this->skipped,
            'failed'    => $this->failed,
            'errors'    => $this->errors,
        ];
    }
}
