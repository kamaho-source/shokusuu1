<?php
declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * 承認・差し戻しの対象の予約を特定するキー（利用者 × 日付 × 部屋 × 食事区分）。
 */
final class ApprovalKey
{
    /** 画面から受け取るキーの項目 */
    private const FIELDS = ['i_id_user', 'd_reservation_date', 'i_id_room', 'i_reservation_type'];

    public function __construct(
        public readonly int $userId,
        public readonly string $date,
        public readonly int $roomId,
        public readonly int $mealType,
    ) {}

    /**
     * 画面から受け取ったキーを読む。形が崩れていたら失敗にする（担当外のスキップと区別するため、黙って飛ばさない）。
     *
     * @param array<array-key, int|string|null> $key
     * @throws \InvalidArgumentException 項目が足りないとき
     */
    public static function fromArray(array $key): self
    {
        if (array_diff(self::FIELDS, array_keys($key)) !== []) {
            throw new \InvalidArgumentException('承認対象のキーが不正です。');
        }

        return new self(
            (int)$key['i_id_user'],
            (string)$key['d_reservation_date'],
            (int)$key['i_id_room'],
            (int)$key['i_reservation_type'],
        );
    }

    /**
     * @param array<array-key, array<array-key, int|string|null>|string|int|null> $keys 画面から受け取ったキーの並び
     * @return list<self>
     * @throws \InvalidArgumentException どれかが配列でない・項目が足りないとき
     */
    public static function listFrom(array $keys): array
    {
        $list = [];
        foreach ($keys as $key) {
            if (!is_array($key)) {
                throw new \InvalidArgumentException('承認対象のキーが不正です。');
            }
            $list[] = self::fromArray($key);
        }

        return $list;
    }

    /** 監査ログの対象ID（「利用者ID:日付」） */
    public function auditId(): string
    {
        return $this->userId . ':' . $this->date;
    }

    /**
     * 画面・DB と同じ項目名の配列。
     *
     * @return array{i_id_user: int, d_reservation_date: string, i_id_room: int, i_reservation_type: int}
     */
    public function toArray(): array
    {
        return [
            'i_id_user'          => $this->userId,
            'd_reservation_date' => $this->date,
            'i_id_room'          => $this->roomId,
            'i_reservation_type' => $this->mealType,
        ];
    }
}
