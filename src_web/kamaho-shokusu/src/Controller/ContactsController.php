<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ContactService;
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
     * フィードバック・お問い合わせフォーム（全ユーザー共通）
     */
    public function index(): ?Response
    {
        $this->Authorization->skipAuthorization();

        $user = $this->Authentication->getIdentity();
        if ($user === null) {
            return $this->redirect('/MUserInfo/login');
        }

        $categories = TContactsTable::CATEGORIES;

        // ログインユーザーの情報を初期値として渡す
        $defaultName  = $user->get('c_user_name') ?? '';
        $defaultEmail = ''; // メールカラムがあれば取得

        if ($this->request->is('post')) {
            $data = (array)$this->request->getData();
            $userId = (int)$user->get('i_id_user');

            $result = $this->contactService->submit($data, $userId);

            if ($result['success']) {
                $this->Flash->success('お問い合わせを送信しました。ありがとうございます。');
                return $this->redirect(['action' => 'index']);
            }

            $this->Flash->error('入力内容に誤りがあります。確認してください。');
            $entity = $result['entity'];
            $this->set(compact('entity', 'categories', 'defaultName', 'defaultEmail'));
            return null;
        }

        $this->set(compact('categories', 'defaultName', 'defaultEmail'));
        return null;
    }

    /**
     * 管理者用：問い合わせ一覧
     */
    public function adminIndex(): ?Response
    {
        $this->Authorization->skipAuthorization();

        $user = $this->Authentication->getIdentity();
        if ($user === null) {
            return $this->redirect('/MUserInfo/login');
        }

        // 管理者のみアクセス可能
        if ((int)$user->get('i_admin') !== 1) {
            $this->Flash->error('管理者のみアクセスできます。');
            return $this->redirect('/');
        }

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
        $this->Authorization->skipAuthorization();

        $user = $this->Authentication->getIdentity();
        if ($user === null) {
            return $this->redirect('/MUserInfo/login');
        }

        if ((int)$user->get('i_admin') !== 1) {
            $this->Flash->error('管理者のみアクセスできます。');
            return $this->redirect('/');
        }

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
