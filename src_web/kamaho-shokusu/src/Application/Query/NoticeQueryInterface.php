<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Entity\Notice;

/**
 * お知らせの読み取り。
 */
interface NoticeQueryInterface
{
    /** 1件。無ければ null。 */
    public function find(int $id): ?Notice;

    /**
     * すべて（新しい順）。お知らせ管理の一覧。
     *
     * @return list<Notice>
     */
    public function all(): array;

    /**
     * 今日が掲載期間に入るもの（重要なものが先、それぞれ新しい順）。ダッシュボードに出す。
     *
     * @return list<Notice>
     */
    public function active(): array;

    /**
     * 掲載期間中で、モーダルでも表示するお知らせ（重要度の高い順、同じなら新しい順）。
     *
     * @return list<\App\Domain\Entity\Notice>
     */
    public function activeModals(): array;
}
