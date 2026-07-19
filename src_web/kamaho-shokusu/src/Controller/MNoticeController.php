<?php
declare(strict_types=1);

namespace App\Controller;

use Authorization\Exception\ForbiddenException;
use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * お知らせ管理コントローラー
 *
 * 管理者（i_admin=1 または i_admin=3）専用。
 * お知らせ（m_notice）の一覧・追加・編集・削除を提供する。
 */
class MNoticeController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('default');
    }

    /**
     * FormProtection::startup() より前に実行されるため、ここで unlockedFields を設定する。
     * i_importance・i_type はプレーン HTML の radio で生成するため Form ヘルパーが追跡しない。
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->FormProtection->setConfig('unlockedFields', ['i_importance', 'i_type', 'd_start', 'd_end']);
    }

    /**
     * お知らせ一覧
     */
    public function index(): ?Response
    {
        $table    = $this->fetchTable('MNotice');
        $resource = $table->newEmptyEntity();

        try {
            $this->Authorization->authorize($resource, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $notices = $table->find()
            ->orderByDesc('dt_create')
            ->all();

        $this->set(compact('notices'));
        return null;
    }

    /**
     * お知らせ追加フォーム・保存
     */
    public function add(): ?Response
    {
        $this->request->allowMethod(['get', 'post']);

        $table    = $this->fetchTable('MNotice');
        $resource = $table->newEmptyEntity();

        try {
            $this->Authorization->authorize($resource, 'add');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            return $this->redirect(['action' => 'index']);
        }

        $loginUser  = $this->request->getAttribute('identity');
        $isSysAdmin = (int)($loginUser?->get('i_admin') ?? 0) === 3;

        if ($this->request->is('post')) {
            $data       = $this->request->getData();
            $importance = (int)($data['i_importance'] ?? 0);
            $rawType    = (int)($data['i_type'] ?? 0);
            $dStart     = ($data['d_start'] ?? '') !== '' ? (string)$data['d_start'] : null;
            $dEnd       = ($data['d_end']   ?? '') !== '' ? (string)$data['d_end']   : null;

            if (!in_array($importance, [0, 1], true)) {
                $this->Flash->error(__('重要度の値が不正です。'));
                $this->set(compact('resource', 'isSysAdmin'));
                return null;
            }
            if (!in_array($rawType, [0, 1], true)) {
                $this->Flash->error(__('種別の値が不正です。'));
                $this->set(compact('resource', 'isSysAdmin'));
                return null;
            }
            if ($dStart !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dStart)) {
                $this->Flash->error(__('開始日の形式が不正です。'));
                $this->set(compact('resource', 'isSysAdmin'));
                return null;
            }
            if ($dEnd !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dEnd)) {
                $this->Flash->error(__('終了日の形式が不正です。'));
                $this->set(compact('resource', 'isSysAdmin'));
                return null;
            }

            $notice = $table->newEmptyEntity();
            $notice = $table->patchEntity($notice, [
                'c_title'      => trim((string)($data['c_title'] ?? '')),
                'c_body'       => ($data['c_body'] ?? '') !== '' ? $data['c_body'] : null,
                'd_start'      => $dStart,
                'd_end'        => $dEnd,
                'i_importance' => $importance,
                'i_type'       => $isSysAdmin ? $rawType : 0,
            ]);

            $notice->i_id_user_created = (int)($loginUser?->get('i_id_user') ?? 0);
            $notice->c_create_user     = $loginUser?->get('c_user_name') ?? 'system';
            $notice->c_update_user     = $loginUser?->get('c_user_name') ?? 'system';

            if ($table->save($notice)) {
                \App\Service\AuditLogService::record(
                    'notice',
                    'notice_add',
                    $loginUser?->get('c_user_name') ?? 'system',
                    (int)($loginUser?->get('i_id_user') ?? 0),
                    'm_notice',
                    (string)$notice->i_id_notice,
                    ['title' => $notice->c_title],
                    $this->getClientIp(),
                    1,
                    (string)($loginUser?->get('c_login_account') ?? '')
                );
                $this->Flash->success(__('お知らせを登録しました。'));
                return $this->redirect(['action' => 'index']);
            }

            $this->Flash->error(__('お知らせの登録に失敗しました。入力内容を確認してください。'));
        }

        $this->set(compact('resource', 'isSysAdmin'));
        return null;
    }

    /**
     * お知らせ編集フォーム・保存
     *
     * @param int $id お知らせID
     */
    public function edit(int $id): ?Response
    {
        $this->request->allowMethod(['get', 'post']);

        $table    = $this->fetchTable('MNotice');
        $resource = $table->newEmptyEntity();

        try {
            $this->Authorization->authorize($resource, 'edit');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            return $this->redirect(['action' => 'index']);
        }

        $notice = $table->get($id);

        $loginUser  = $this->request->getAttribute('identity');
        $isSysAdmin = (int)($loginUser?->get('i_admin') ?? 0) === 3;

        if ($this->request->is('post')) {
            $data       = $this->request->getData();
            $importance = (int)($data['i_importance'] ?? 0);
            $rawType    = (int)($data['i_type'] ?? 0);
            $dStart     = ($data['d_start'] ?? '') !== '' ? (string)$data['d_start'] : null;
            $dEnd       = ($data['d_end']   ?? '') !== '' ? (string)$data['d_end']   : null;

            if (!in_array($importance, [0, 1], true)) {
                $this->Flash->error(__('重要度の値が不正です。'));
                $this->set(compact('notice', 'isSysAdmin'));
                return null;
            }
            if (!in_array($rawType, [0, 1], true)) {
                $this->Flash->error(__('種別の値が不正です。'));
                $this->set(compact('notice', 'isSysAdmin'));
                return null;
            }
            if ($dStart !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dStart)) {
                $this->Flash->error(__('開始日の形式が不正です。'));
                $this->set(compact('notice', 'isSysAdmin'));
                return null;
            }
            if ($dEnd !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dEnd)) {
                $this->Flash->error(__('終了日の形式が不正です。'));
                $this->set(compact('notice', 'isSysAdmin'));
                return null;
            }

            $notice = $table->patchEntity($notice, [
                'c_title'      => trim((string)($data['c_title'] ?? '')),
                'c_body'       => ($data['c_body'] ?? '') !== '' ? $data['c_body'] : null,
                'd_start'      => $dStart,
                'd_end'        => $dEnd,
                'i_importance' => $importance,
                'i_type'       => $isSysAdmin ? $rawType : (int)$notice->i_type,
            ]);

            $notice->c_update_user = $loginUser?->get('c_user_name') ?? 'system';

            if ($table->save($notice)) {
                \App\Service\AuditLogService::record(
                    'notice',
                    'notice_edit',
                    $loginUser?->get('c_user_name') ?? 'system',
                    (int)($loginUser?->get('i_id_user') ?? 0),
                    'm_notice',
                    (string)$notice->i_id_notice,
                    ['title' => $notice->c_title],
                    $this->getClientIp(),
                    1,
                    (string)($loginUser?->get('c_login_account') ?? '')
                );
                $this->Flash->success(__('お知らせを更新しました。'));
                return $this->redirect(['action' => 'index']);
            }

            $this->Flash->error(__('お知らせの更新に失敗しました。入力内容を確認してください。'));
        }

        $this->set(compact('notice', 'isSysAdmin'));
        return null;
    }

    /**
     * お知らせ削除
     *
     * @param int $id お知らせID
     */
    public function delete(int $id): Response
    {
        $this->request->allowMethod(['post']);

        $table    = $this->fetchTable('MNotice');
        $resource = $table->newEmptyEntity();

        try {
            $this->Authorization->authorize($resource, 'delete');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            return $this->redirect(['action' => 'index']);
        }

        $notice     = $table->get($id);
        $loginUser  = $this->request->getAttribute('identity');
        $noticeId    = $notice->i_id_notice;
        $noticeTitle = $notice->c_title;

        if ($table->delete($notice)) {
            \App\Service\AuditLogService::record(
                'notice',
                'notice_delete',
                $loginUser?->get('c_user_name') ?? 'system',
                (int)($loginUser?->get('i_id_user') ?? 0),
                'm_notice',
                (string)$noticeId,
                ['title' => $noticeTitle],
                $this->getClientIp(),
                1,
                (string)($loginUser?->get('c_login_account') ?? '')
            );
            $this->Flash->success(__('お知らせを削除しました。'));
        } else {
            $this->Flash->error(__('お知らせの削除に失敗しました。'));
        }

        return $this->redirect(['action' => 'index']);
    }
}
