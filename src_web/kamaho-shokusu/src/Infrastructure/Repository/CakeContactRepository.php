<?php
declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\Contact;
use App\Domain\Entity\ContactReply;
use App\Domain\Repository\ContactRepositoryInterface;
use App\Infrastructure\Persistence\Entity\TContact;
use Cake\Database\Exception\QueryException;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * ContactRepositoryInterface の CakePHP 実装（t_contacts・t_contact_replies）。入力の検証はテーブルの検証ルール。
 */
final class CakeContactRepository implements ContactRepositoryInterface
{
    private static function contacts(): Table
    {
        return TableRegistry::getTableLocator()->get('TContacts');
    }

    private static function replies(): Table
    {
        return TableRegistry::getTableLocator()->get('TContactReplies');
    }

    /** 返信（古い順）付きで問い合わせを探す（CakeContactQuery と共通）。 */
    public static function withReplies(): SelectQuery
    {
        return self::contacts()->find()->contain(['TContactReplies' => fn($q) => $q->orderByAsc('created')]);
    }

    /** 行を問い合わせにする（CakeContactQuery と共通）。 */
    public static function toContact(TContact $row): Contact
    {
        $replies = [];
        foreach ($row->t_contact_replies ?? [] as $r) {
            $replies[] = new ContactReply(
                (string)$r->body,
                $r->author_type,
                $r->sent_at === null ? null : new \DateTimeImmutable($r->sent_at->format('Y-m-d H:i:s')),
            );
        }

        return new Contact(
            (int)$row->id,
            (string)$row->category,
            (string)$row->name,
            (string)$row->email,
            (string)$row->body,
            $row->user_id === null ? null : (int)$row->user_id,
            $row->reply_token === null || $row->reply_token === '' ? null : (string)$row->reply_token,
            $row->created === null ? null : new \DateTimeImmutable($row->created->format('Y-m-d H:i:s')),
            $replies,
        );
    }

    public function add(string $category, string $name, string $email, string $body, ?int $userId, string $replyToken): array
    {
        $table = self::contacts();
        $entity = $table->newEntity(['category' => $category, 'name' => $name, 'email' => $email, 'body' => $body, 'user_id' => $userId]);
        if ($entity->getErrors()) {
            return ['id' => null, 'errors' => $entity->getErrors()];
        }
        // reply_token はフォーム入力から受け取らない（_accessible に含めていないためマスアサインでは上書きできない）
        $entity->set('reply_token', $replyToken);
        if (!$table->save($entity)) {
            return ['id' => null, 'errors' => $entity->getErrors() ?: ['save' => ['save' => '保存に失敗しました。']]];
        }

        return ['id' => (int)$entity->get('id'), 'errors' => []];
    }

    public function find(int $id): ?Contact
    {
        $row = self::withReplies()->where(['TContacts.id' => $id])->first();

        return $row === null ? null : self::toContact($row);
    }

    public function findForUser(int $id, int $userId): ?Contact
    {
        $row = self::withReplies()->where(['TContacts.id' => $id, 'TContacts.user_id' => $userId])->first();

        return $row === null ? null : self::toContact($row);
    }

    public function findByToken(string $token): ?Contact
    {
        $row = self::withReplies()->where(['reply_token' => $token])->first();

        return $row === null ? null : self::toContact($row);
    }

    public function replyExists(string $externalMessageId): bool
    {
        return self::replies()->exists(['external_message_id' => $externalMessageId]);
    }

    public function addReply(int $contactId, string $body, string $authorType, ?string $externalMessageId = null): array
    {
        $table = self::replies();
        $data = ['contact_id' => $contactId, 'body' => $body, 'author_type' => $authorType, 'sent_at' => new DateTime()];
        if ($externalMessageId !== null) {
            $data['external_message_id'] = $externalMessageId;
        }
        $reply = $table->newEntity($data);
        if ($reply->getErrors()) {
            return ['saved' => false, 'duplicate' => false, 'errors' => $reply->getErrors()];
        }

        try {
            $saved = $table->save($reply);
        } catch (QueryException $e) {
            // 一意性の確認のあと・保存の前に別のプロセスが同じメールを保存したときは、DB の一意制約の例外になる。保存済みなら重複として扱う
            if ($externalMessageId !== null && $this->replyExists($externalMessageId)) {
                return ['saved' => false, 'duplicate' => true, 'errors' => []];
            }
            throw $e;
        }
        if (!$saved) {
            $errors = $reply->getErrors();
            if (isset($errors['external_message_id'])) {
                return ['saved' => false, 'duplicate' => true, 'errors' => []];
            }

            return ['saved' => false, 'duplicate' => false, 'errors' => $errors ?: ['save' => ['save' => '保存に失敗しました。']]];
        }

        return ['saved' => true, 'duplicate' => false, 'errors' => []];
    }
}
