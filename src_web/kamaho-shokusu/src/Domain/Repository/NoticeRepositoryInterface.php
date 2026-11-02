<?php
declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Notice;

/**
 * お知らせの保存先。
 */
interface NoticeRepositoryInterface
{
    public function find(int $id): ?Notice;

    /**
     * 新しく登録する。保存できなければ null（タイトルが空・長すぎるなど）。
     *
     * @return int|null 登録したお知らせの ID
     */
    public function add(Notice $notice, int $actorId, string $actorName): ?int;

    /** 更新する。保存できなければ false。 */
    public function update(Notice $notice, string $actorName): bool;

    /** 削除する。消せなければ false。 */
    public function delete(int $id): bool;
}
