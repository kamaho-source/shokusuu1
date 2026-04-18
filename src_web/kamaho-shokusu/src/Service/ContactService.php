<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Table\TContactsTable;
use Cake\Mailer\Mailer;
use Cake\ORM\TableRegistry;

class ContactService
{
    private TContactsTable $contacts;

    public function __construct()
    {
        /** @var TContactsTable $table */
        $table = TableRegistry::getTableLocator()->get('TContacts');
        $this->contacts = $table;
    }

    /**
     * 問い合わせを保存してメールを送信する。
     *
     * @param array $data フォームデータ
     * @param int|null $userId ログインユーザーID
     * @return array{success: bool, entity: \App\Model\Entity\TContact}
     */
    public function submit(array $data, ?int $userId): array
    {
        $entity = $this->contacts->newEntity([
            'category' => $data['category'] ?? '',
            'name'     => trim($data['name'] ?? ''),
            'email'    => trim($data['email'] ?? ''),
            'body'     => trim($data['body'] ?? ''),
            'user_id'  => $userId,
        ]);

        if ($entity->getErrors()) {
            return ['success' => false, 'entity' => $entity];
        }

        if (!$this->contacts->save($entity)) {
            return ['success' => false, 'entity' => $entity];
        }

        // 管理者への通知メール（失敗しても保存成功として扱う）
        try {
            $this->sendAdminNotification($entity);
        } catch (\Throwable $e) {
            // メール送信失敗はログのみ
        }

        return ['success' => true, 'entity' => $entity];
    }

    /**
     * 問い合わせ一覧を取得する（管理者用）。
     */
    public function getList(int $page = 1, int $limit = 30): array
    {
        return $this->contacts->find()
            ->orderByDesc('created')
            ->limit($limit)
            ->page($page)
            ->toArray();
    }

    /**
     * 管理者へ通知メールを送信する。
     */
    private function sendAdminNotification(\App\Model\Entity\TContact $entity): void
    {
        $adminEmail = \Cake\Core\Configure::read('App.adminEmail', 'admin@localhost');

        $mailer = new Mailer('default');
        $mailer
            ->setTo($adminEmail)
            ->setSubject('[食数管理システム] 新しいお問い合わせ：' . $entity->category)
            ->setEmailFormat('text')
            ->deliver(implode("\n", [
                '新しいお問い合わせが届きました。',
                '',
                'カテゴリ：' . $entity->category,
                'お名前：'  . $entity->name,
                'メール：'  . $entity->email,
                '',
                '--- 内容 ---',
                $entity->body,
            ]));
    }
}
