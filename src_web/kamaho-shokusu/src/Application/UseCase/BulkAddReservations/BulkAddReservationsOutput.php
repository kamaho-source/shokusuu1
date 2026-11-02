<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkAddReservations;

/**
 * 週次一括登録の結果。
 */
final class BulkAddReservationsOutput
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
        /** 成功時の遷移先（失敗時は null） */
        public readonly ?string $redirectUrl = null,
        /** 他の操作と競合した日付（競合以外は null） */
        public readonly ?string $conflictDate = null,
    ) {}

    /** 登録した。$message に結果（スキップ・入れ替えの内訳を含む） */
    public static function succeeded(string $message, string $redirectUrl): self
    {
        return new self(true, $message, $redirectUrl);
    }

    /** 登録しなかった。$message に理由、競合なら $conflictDate も */
    public static function failed(string $message, ?string $conflictDate = null): self
    {
        return new self(false, $message, null, $conflictDate);
    }

    /**
     * 従来の戻り値と同じ形（null のキーは含めない）。
     *
     * @return array{ok: bool, message: string, redirect_url?: string, conflict_date?: string}
     */
    public function toArray(): array
    {
        $result = ['ok' => $this->ok, 'message' => $this->message];
        if ($this->redirectUrl !== null) {
            $result['redirect_url'] = $this->redirectUrl;
        }
        if ($this->conflictDate !== null) {
            $result['conflict_date'] = $this->conflictDate;
        }

        return $result;
    }
}
