<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkChangeEditReservations;

/**
 * 直前編集の一括保存の結果。
 */
final class BulkChangeEditReservationsOutput
{
    /**
     * @param list<string> $skipped
     */
    private function __construct(
        public readonly bool $ok,
        /** 失敗の理由（成功時は null） */
        public readonly ?string $message = null,
        public readonly int $updated = 0,
        public readonly int $created = 0,
        /** 飛ばした行の理由（成功時のみ） */
        public readonly array $skipped = [],
        /** 他の操作と競合した日付（競合以外は null） */
        public readonly ?string $conflictDate = null,
    ) {}

    /**
     * 保存した。飛ばした行は $skipped に理由を積む。
     *
     * @param list<string> $skipped
     */
    public static function succeeded(int $updated, int $created, array $skipped): self
    {
        return new self(true, null, $updated, $created, $skipped);
    }

    /** 保存しなかった。$message に理由、競合なら $conflictDate も */
    public static function failed(string $message, ?string $conflictDate = null): self
    {
        return new self(false, $message, conflictDate: $conflictDate);
    }

    /**
     * 従来の戻り値と同じ形（成功は ok・updated・created・skipped、失敗は ok・message・conflict_date?）。
     *
     * @return array{ok: bool, message?: string, updated?: int, created?: int, skipped?: list<string>, conflict_date?: string}
     */
    public function toArray(): array
    {
        if ($this->ok) {
            return ['ok' => true, 'updated' => $this->updated, 'created' => $this->created, 'skipped' => $this->skipped];
        }
        $result = ['ok' => false, 'message' => (string)$this->message];
        if ($this->conflictDate !== null) {
            $result['conflict_date'] = $this->conflictDate;
        }

        return $result;
    }
}
