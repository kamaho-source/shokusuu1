<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Table\TContactsTable;
use App\Service\ContactService;
use Authorization\Exception\ForbiddenException;
use Cake\Event\EventInterface;
use Cake\Http\Response;

class ContactsController extends AppController
{
    private ContactService $contactService;

    public function initialize(): void
    {
        parent::initialize();
        $this->contactService = new ContactService();
    }

    /**
     * @throws \Exception
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        if ($this->request->getParam('action') === 'index') {
            $this->FormProtection->setConfig('unlockedFields', ['name', 'email', 'category', 'body']);
        }
    }

    /**
     * フィードバック・お問い合わせフォーム（全ユーザー共通）
     */
    public function index(): ?Response
    {
        $this->Authorization->authorize($this, 'index');

        $user = $this->Authentication->getIdentity();
        $categories = TContactsTable::CATEGORIES;
        $userId = (int)$user->get('i_id_user');

        // ログインユーザーの情報を初期値として渡す
        $defaultName  = $user->get('c_user_name') ?? '';
        $defaultEmail = ''; // メールカラムがあれば取得

        if ($this->request->is('post')) {
            $data = (array)$this->request->getData();

            $result = $this->contactService->submit($data, $userId);

            if ($result['success']) {
                $this->Flash->success('お問い合わせを送信しました。ありがとうございます。');
                return $this->redirect(['action' => 'index']);
            }

            $this->Flash->error('入力内容に誤りがあります。確認してください。');
            $entity = $result['entity'];
            $myContacts = $this->contactService->getMyList($userId);
            $this->set(compact('entity', 'categories', 'defaultName', 'defaultEmail', 'myContacts'));
            return null;
        }

        $myContacts = $this->contactService->getMyList($userId);
        $this->set(compact('categories', 'defaultName', 'defaultEmail', 'myContacts'));
        return null;
    }

    /**
     * 問い合わせ者本人：自分の問い合わせへ追加の返信を送信する
     */
    public function reply(int $id): ?Response
    {
        $this->request->allowMethod(['post']);

        $user = $this->Authentication->getIdentity();
        $userId = (int)$user->get('i_id_user');

        try {
            $contact = $this->contactService->getDetailForUser($id, $userId);
        } catch (\Cake\Datasource\Exception\RecordNotFoundException) {
            $this->Flash->error('指定されたお問い合わせが見つかりません。');
            return $this->redirect(['action' => 'index']);
        }

        try {
            $this->Authorization->authorize($contact, 'reply');
        } catch (ForbiddenException) {
            $this->Flash->error('あなたはこの操作を行う権限がありません。');
            return $this->redirect(['action' => 'index']);
        }

        $replyBody = (string)($this->request->getData('reply_body') ?? '');
        $result = $this->contactService->addUserReply($id, $userId, $replyBody);

        if ($result['success']) {
            $this->Flash->success('返信を送信しました。');
        } else {
            $this->Flash->error('返信の送信に失敗しました。入力内容を確認してください。');
        }

        return $this->redirect(['action' => 'index', '#' => 'contact-' . $id]);
    }

    /**
     * 管理者用：問い合わせ一覧
     */
    public function adminIndex(): ?Response
    {
        $this->Authorization->authorize($this, 'adminIndex');

        $page    = (int)($this->request->getQuery('page') ?? 1);
        $contacts = $this->contactService->getList($page);

        $this->set(compact('contacts', 'page'));
        return null;
    }

    /**
     * 管理者用：問い合わせ詳細・返信送信
     */
    public function adminDetail(int $id): ?Response
    {
        $this->Authorization->authorize($this, 'adminDetail');

        $contact = $this->contactService->getDetail($id);

        if ($this->request->is('post')) {
            $replyBody = (string)($this->request->getData('reply_body') ?? '');
            $result = $this->contactService->sendReply($id, $replyBody);

            if ($result['success']) {
                $this->Flash->success('返信を送信しました。');
                return $this->redirect(['action' => 'adminDetail', $id]);
            }

            $this->Flash->error('返信の送信に失敗しました。');
        }

        $this->set(compact('contact'));
        return null;
    }
}
