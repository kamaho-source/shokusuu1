<?php
declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * お知らせ（m_notice）。ダッシュボードに掲載期間のあいだ出す。
 */
final class Notice
{
    /**
     * @param string|null $startDate 掲載開始日（Y-m-d。null は制限なし）
     * @param string|null $endDate 掲載終了日（Y-m-d。null は制限なし）
     * @param int $importance 重要度（NoticeRule::IMPORTANCE_*）
     * @param int $type 種別（NoticeRule::TYPE_*）
     * @param string|null $createdAt 登録日時（Y-m-d H:i:s）
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $title,
        public readonly ?string $body,
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        public readonly int $importance,
        public readonly int $type,
        public readonly ?string $createdBy = null,
        public readonly ?string $createdAt = null,
    ) {}

    public function isHighImportance(): bool
    {
        return $this->importance === 1;
    }

    public function isReleaseNote(): bool
    {
        return $this->type === 1;
    }
}
