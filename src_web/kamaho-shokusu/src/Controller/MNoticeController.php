<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\NoticeQueryInterface;
use App\Application\UseCase\CreateNotice\CreateNoticeInput;
use App\Application\UseCase\CreateNotice\CreateNoticeUseCase;
use App\Application\UseCase\DeleteNotice\DeleteNoticeUseCase;
use App\Application\UseCase\UpdateNotice\UpdateNoticeInput;
use App\Application\UseCase\UpdateNotice\UpdateNoticeUseCase;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\NotFoundException as DomainNotFoundException;
use App\Domain\ValueObject\UserRole;
use App\Infrastructure\Persistence\Entity\MNotice;
use Authorization\Exception\ForbiddenException;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;

/**
 * お知らせ管理（管理者・システム管理者）。登録・更新・削除は UseCase、一覧は NoticeQueryInterface。
 */
class MNoticeController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('default');
    }

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->FormProtection->setConfig('unlockedFields', ['i_importance', 'i_type', 'd_start', 'd_end']);
    }

    /**
     * 管理者でなければ Response（お知らせ一覧またはダッシュボードへ戻す）。
     */
    private function denyUnlessAdmin(string $action, array $redirect): ?Response
    {
        try {
            $this->Authorization->authorize(new MNotice(), $action);

            return null;
        } catch (ForbiddenException) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));

            return $this->redirect($redirect);
        }
    }

    private function isSystemAdmin(): bool
    {
        return (int)($this->request->getAttribute('identity')?->get('i_admin') ?? 0) === UserRole::SYSTEM_ADMIN;
    }

    /**
     * 送信された値（タイトル・本文・掲載期間・重要度・種別）。
     *
     * @return array{0: string, 1: string|null, 2: string|null, 3: string|null, 4: int, 5: int}
     */
    private function noticeFields(): array
    {
        $body = $this->dataString('c_body');

        return [
            $this->dataString('c_title'),
            $body !== '' ? $body : null,
            $this->dataString('d_start') ?: null,
            $this->dataString('d_end') ?: null,
            (int)$this->dataString('i_importance'),
            (int)$this->dataString('i_type'),
        ];
    }

    public function index(NoticeQueryInterface $noticeQuery): ?Response
    {
        if ($denied = $this->denyUnlessAdmin('index', ['controller' => 'Pages', 'action' => 'dashboard'])) {
            return $denied;
        }

        $this->set('notices', $noticeQuery->all());

        return null;
    }

    public function add(CreateNoticeUseCase $createNotice): ?Response
    {
        $this->request->allowMethod(['get', 'post']);
        if ($denied = $this->denyUnlessAdmin('add', ['action' => 'index'])) {
            return $denied;
        }

        $isSysAdmin = $this->isSystemAdmin();
        if ($this->request->is('post')) {
            [$title, $body, $start, $end, $importance, $type] = $this->noticeFields();
            try {
                $createNotice->execute(new CreateNoticeInput($title, $body, $start, $end, $importance, $type, $isSysAdmin, $this->auditActor()));
                $this->Flash->success(__('お知らせを登録しました。'));

                return $this->redirect(['action' => 'index']);
            } catch (InvalidInputException $e) {
                $this->Flash->error(__($e->getMessage()));
            }
        }

        $this->set(compact('isSysAdmin'));

        return null;
    }

    public function edit(UpdateNoticeUseCase $updateNotice, NoticeQueryInterface $noticeQuery, int $id): ?Response
    {
        $this->request->allowMethod(['get', 'post']);
        if ($denied = $this->denyUnlessAdmin('edit', ['action' => 'index'])) {
            return $denied;
        }

        $isSysAdmin = $this->isSystemAdmin();
        try {
            if (!$this->request->is('post')) {
                $notice = $noticeQuery->find($id) ?? throw new DomainNotFoundException('お知らせが見つかりません。');
                $this->set(compact('notice', 'isSysAdmin'));

                return null;
            }

            [$title, $body, $start, $end, $importance, $type] = $this->noticeFields();
            $output = $updateNotice->execute(new UpdateNoticeInput($id, $title, $body, $start, $end, $importance, $type, $isSysAdmin, $this->auditActor()));
        } catch (DomainNotFoundException $e) {
            throw new NotFoundException($e->getMessage(), null, $e);
        }
        if ($output->succeeded) {
            $this->Flash->success(__('お知らせを更新しました。'));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error(__((string)$output->error));
        $notice = $output->notice;
        $this->set(compact('notice', 'isSysAdmin'));

        return null;
    }

    public function delete(DeleteNoticeUseCase $deleteNotice, int $id): Response
    {
        $this->request->allowMethod(['post']);
        if ($denied = $this->denyUnlessAdmin('delete', ['action' => 'index'])) {
            return $denied;
        }

        try {
            $deleted = $deleteNotice->execute($id, $this->auditActor());
        } catch (DomainNotFoundException $e) {
            throw new NotFoundException($e->getMessage(), null, $e);
        }
        if ($deleted) {
            $this->Flash->success(__('お知らせを削除しました。'));
        } else {
            $this->Flash->error(__('お知らせの削除に失敗しました。'));
        }

        return $this->redirect(['action' => 'index']);
    }
}
