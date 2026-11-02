<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\ContactQueryInterface;
use App\Domain\Entity\Contact;
use App\Infrastructure\Repository\CakeContactRepository;

/**
 * ContactQueryInterface の CakePHP 実装（t_contacts・t_contact_replies）。
 */
final class CakeContactQuery implements ContactQueryInterface
{
    public function page(int $page, int $limit = 30): array
    {
        return array_map(
            CakeContactRepository::toContact(...),
            CakeContactRepository::withReplies()->orderByDesc('created')->limit($limit)->page(max(1, $page))->all()->toList()
        );
    }

    public function mine(int $userId, int $limit = 50): array
    {
        return array_map(
            CakeContactRepository::toContact(...),
            CakeContactRepository::withReplies()->where(['TContacts.user_id' => $userId])->orderByDesc('created')->limit($limit)->all()->toList()
        );
    }

    public function find(int $id): ?Contact
    {
        return (new CakeContactRepository())->find($id);
    }
}
