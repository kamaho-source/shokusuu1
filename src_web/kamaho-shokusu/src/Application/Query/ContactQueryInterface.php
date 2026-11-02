<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Entity\Contact;

/**
 * 問い合わせの一覧の読み取り。
 */
interface ContactQueryInterface
{
    /**
     * すべての問い合わせ（新しい順、返信付き）。管理者の一覧。
     *
     * @return list<Contact>
     */
    public function page(int $page, int $limit = 30): array;

    /**
     * 本人が送った問い合わせ（新しい順、返信付き）。
     *
     * @return list<Contact>
     */
    public function mine(int $userId, int $limit = 50): array;

    /** 返信付きの問い合わせ（管理者の詳細）。無ければ null。 */
    public function find(int $id): ?Contact;
}
