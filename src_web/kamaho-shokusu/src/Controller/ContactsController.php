<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\UseCase\SubmitContact\SubmitContactInput;
use App\Application\UseCase\ReplyToContact\ReplyToContactInput;
use App\Application\Query\ContactQueryInterface;
use App\Application\UseCase\AddContactReplyFromUser\AddContactReplyFromUserInput;
use App\Application\UseCase\AddContactReplyFromUser\AddContactReplyFromUserUseCase;
use App\Application\UseCase\ReplyToContact\ReplyToContactUseCase;
use App\Application\UseCase\SubmitContact\SubmitContactUseCase;
use App\Domain\Entity\Contact;
use App\Domain\Exception\NotFoundException as DomainNotFoundException;
use App\Infrastructure\Persistence\Entity\TContact;
use Cake\Http\Exception\NotFoundException;
use Authorization\Exception\ForbiddenException;
use Cake\Event\EventInterface;
use Cake\Http\Response;

class ContactsController extends AppController
{

    /**
     * @throws \Exception
     */
    public function beforeFilter(EventInterface $event): ?Response
    {
        $response = parent::beforeFilter($event);
        if ($response !== null) {
            return $response;
        }

        if ($this->request->getParam('action') === 'index') {
            $this->FormProtection->setConfig('unlockedFields', ['name', 'email', 'category', 'body']);
        }

        return null;
    }

    /**
     * フィードバック・お問い合わせフォーム（全ユーザー共通）
     */
    public function index(SubmitContactUseCase $submitContact, ContactQueryInterface $contactQuery): ?Response
    {
        $this->Authorization->authorize($this, 'index');

        $user = $this->Authentication->getIdentity();
        $categories = array_combine(Contact::CATEGORIES, Contact::CATEGORIES);
        $userId = (int)$user->get('i_id_user');

        // ログインユーザーの情報を初期値として渡す
        $defaultName  = $user->get('c_user_name') ?? '';
        $defaultEmail = ''; // メールカラムがあれば取得

        if ($this->request->is('post')) {
            $data = [];
            foreach (['category', 'name', 'email', 'body'] as $field) {
                $data[$field] = $this->dataString($field);
            }

            $result = $submitContact->execute(new SubmitContactInput($data, $userId));

            if ($result->saved) {
                $this->Flash->success('お問い合わせを送信しました。ありがとうございます。');
                return $this->redirect(['action' => 'index']);
            }

            $this->Flash->error('入力内容に誤りがあります。確認してください。');
            $entity = $result;
            $myContacts = $contactQuery->mine($userId);
            $this->set(compact('entity', 'categories', 'defaultName', 'defaultEmail', 'myContacts'));
            return null;
        }

        $myContacts = $contactQuery->mine($userId);
        $this->set(compact('categories', 'defaultName', 'defaultEmail', 'myContacts'));
        return null;
    }

    /**
     * 問い合わせ者本人：自分の問い合わせへ追加の返信を送信する
     */
    public function reply(AddContactReplyFromUserUseCase $addReply, ContactQueryInterface $contactQuery, int $id): ?Response
    {
        $this->request->allowMethod(['post']);

        $user = $this->Authentication->getIdentity();
        $userId = (int)$user->get('i_id_user');

        $contact = $contactQuery->find($id);
        if ($contact === null || $contact->userId !== $userId) {
            $this->Authorization->skipAuthorization();
            $this->Flash->error('指定されたお問い合わせが見つかりません。');
            return $this->redirect(['action' => 'index']);
        }

        try {
            // 本人の問い合わせか（Policy は TContact の user_id を見る）
            $this->Authorization->authorize(new TContact(['id' => $contact->id, 'user_id' => $contact->userId], ['guard' => false]), 'reply');
        } catch (ForbiddenException) {
            $this->Flash->error('あなたはこの操作を行う権限がありません。');
            return $this->redirect(['action' => 'index']);
        }

        $replyBody = $this->dataString('reply_body');
        if ($addReply->execute(new AddContactReplyFromUserInput($id, $userId, $replyBody))->saved) {
            $this->Flash->success('返信を送信しました。');
        } else {
            $this->Flash->error('返信の送信に失敗しました。入力内容を確認してください。');
        }

        return $this->redirect(['action' => 'index', '#' => 'contact-' . $id]);
    }

    /**
     * 管理者用：問い合わせ一覧
     */
    public function adminIndex(ContactQueryInterface $contactQuery): ?Response
    {
        $this->Authorization->authorize($this, 'adminIndex');

        // 0 以下・数字でない値は 1 ページ目（以前は 500 になっていた）
        $page     = max(1, (int)$this->queryString('page'));
        $contacts = $contactQuery->page($page);

        $this->set(compact('contacts', 'page'));
        return null;
    }

    /**
     * 管理者用：問い合わせ詳細・返信送信
     */
    public function adminDetail(ReplyToContactUseCase $replyToContact, ContactQueryInterface $contactQuery, int $id): ?Response
    {
        $this->Authorization->authorize($this, 'adminDetail');

        $contact = $contactQuery->find($id) ?? throw new NotFoundException('お問い合わせが見つかりません。');

        if ($this->request->is('post')) {
            $replyBody = $this->dataString('reply_body');
            try {
                $sent = $replyToContact->execute(new ReplyToContactInput($id, $replyBody))->sent;
            } catch (DomainNotFoundException $e) {
                throw new NotFoundException($e->getMessage(), null, $e);
            }

            if ($sent) {
                $this->Flash->success('返信を送信しました。');
                return $this->redirect(['action' => 'adminDetail', $id]);
            }

            $this->Flash->error('返信の送信に失敗しました。');
        }

        $this->set(compact('contact'));
        return null;
    }
}
