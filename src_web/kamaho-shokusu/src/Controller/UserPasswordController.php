<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\UserQueryInterface;
use App\Application\UseCase\ChangePassword\ChangePasswordInput;
use App\Application\UseCase\ChangePassword\ChangePasswordOutput;
use App\Application\UseCase\ChangePassword\ChangePasswordUseCase;
use App\Infrastructure\Persistence\Entity\MUserInfo;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Response;

/**
 * パスワードの変更（管理者が他の人の分・本人が自分の分）
 * （MUserInfoController から分けた。URL は /MUserInfo/admin_change_password などのまま。routes.php 参照）。
 *
 * @property \Authorization\Controller\Component\AuthorizationComponent $Authorization
 */
class UserPasswordController extends AppController
{

    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('default');
        $this->viewBuilder()->setOption('serialize', true);
        $this->viewBuilder()->setTemplatePath('MUserInfo');
    }

    public function adminChangePassword(ChangePasswordUseCase $changePassword, UserQueryInterface $userQuery): ?Response
    {
        $this->request->allowMethod(['get', 'post', 'put']);
        $resource = new MUserInfo();
        try {
            $this->Authorization->authorize($resource, 'adminChangePassword');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            return $this->redirect(['controller' => 'MUserInfo', 'action' => 'index']);
        }

        $users = $userQuery->passwordResetTargets();
        $selectedUserId = null;

        if ($this->request->is(['post', 'put'])) {
            $data   = $this->request->getData();
            $userId = $this->userIdParam($data['user_id'] ?? null);
            $result = $changePassword->execute(new ChangePasswordInput(
                $userId,
                (string)($data['new_password'] ?? ''),
                (string)($data['confirm_password'] ?? ''),
                $this->auditActor(),
                true,
            ));
            if ($result->status === ChangePasswordOutput::CHANGED) {
                $this->Flash->success(__('パスワードを変更しました。'));
                return $this->redirect(['controller' => 'MUserInfo', 'action' => 'index']);
            }
            if ($result->status === ChangePasswordOutput::REJECTED) {
                $this->Flash->error((string)$result->message);
                return $this->redirect(['action' => 'adminChangePassword']);
            }
            $this->Flash->error(__('パスワードの変更に失敗しました。'));
            $this->set(['users' => $users, 'selectedUserId' => $userId]);

            return null;
        }

        if ($this->request->is('get')) {
            // 編集画面の「パスワード変更」リンクから ?user_id= 付きで遷移してきた場合、対象ユーザーを事前選択する。
            // 存在しない・削除済みユーザーIDは無視し、通常の未選択状態にフォールバックする。
            $queryUserId = $this->userIdParam($this->request->getQuery('user_id'));
            if ($queryUserId !== null && isset($users[$queryUserId])) {
                $selectedUserId = $queryUserId;
            }
        }

        $this->set(compact('users', 'selectedUserId'));

        return null;
    }

    /**
     * 送られた利用者 ID（数字の文字列だけを受け取る。'01' や '1abc'・配列（?user_id[]=1）は選ばれていない扱い）。
     *
     * @param string|int|array<array-key, string|array<array-key, string>>|null $value
     */
    private function userIdParam(string|int|array|null $value): ?int
    {
        return is_scalar($value) && (string)(int)$value === (string)$value && (int)$value > 0 ? (int)$value : null;
    }

    public function generalPasswordReset(ChangePasswordUseCase $changePassword): ?Response
    {
        $this->request->allowMethod(['get', 'post', 'put', 'patch']);
        $identity = $this->request->getAttribute('identity');
        if (!$identity) {
            $this->Flash->error('ログインしてください。');
            return $this->redirect(['controller' => 'Auth', 'action' => 'login']);
        }

        $userId = $identity->getIdentifier() ?? $identity->get('i_id_user');
        // 認可（本人か）の判定にエンティティが要る（Policy の例外）
        $user   = $this->fetchTable('MUserInfo')->get($userId);

        try {
            $this->Authorization->authorize($user, 'generalPasswordReset');
        } catch (ForbiddenException $e) {
            $this->Flash->error('この操作は許可されていません。');
            return $this->redirect(['controller' => 'MUserInfo', 'action' => 'index']);
        }

        if ($this->request->is(['post', 'put', 'patch'])) {
            $data   = (array)$this->request->getData();
            $result = $changePassword->execute(new ChangePasswordInput(
                (int)$user->get('i_id_user'),
                (string)($data['new_password'] ?? ''),
                (string)($data['confirm_password'] ?? ''),
                $this->auditActor(),
                false,
            ));
            if ($result->status === ChangePasswordOutput::CHANGED) {
                $this->request->getSession()->renew();
                $this->Flash->success('パスワードを変更しました。');
                return $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index']);
            }
            if ($result->status === ChangePasswordOutput::REJECTED) {
                $this->Flash->error((string)$result->message);
                return $this->redirect($this->request->getRequestTarget());
            }
            $this->Flash->error('パスワードの変更に失敗しました。');
        }

        $this->set(compact('user'));
        return null;
    }
}
