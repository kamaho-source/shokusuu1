<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\UseCase\RecordLogin\RecordLoginInput;
use App\Application\UseCase\RecordLogin\RecordLoginUseCase;
use App\Application\UseCase\RecordLoginFailure\RecordLoginFailureInput;
use App\Application\UseCase\RecordLoginFailure\RecordLoginFailureUseCase;
use App\Application\UseCase\RecordLogout\RecordLogoutInput;
use App\Application\UseCase\RecordLogout\RecordLogoutUseCase;
use App\Domain\ValueObject\AccountStatus;
use App\Domain\ValueObject\UserRole;
use Cake\Http\Response;

/**
 * ログイン・ログアウト（MUserInfoController から分けた。URL は /MUserInfo/login・/MUserInfo/logout のまま。routes.php 参照）。
 * 未ログインで login を開けるのは AppController::beforeFilter() の allowUnauthenticated(['login']) による。
 *
 * @property \Authentication\Controller\Component\AuthenticationComponent $Authentication
 * @property \Authorization\Controller\Component\AuthorizationComponent $Authorization
 */
class AuthController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('default');
        $this->viewBuilder()->setOption('serialize', true);
        $this->viewBuilder()->setTemplatePath('MUserInfo');

        // セッション切れや複数タブでフォームトークンが失効し「不正なアクセス」になるため、フォームトークンの検証から外す。
        // CSRF 保護は CsrfProtectionMiddleware がミドルウェア層で適用済み。
        $this->FormProtection->setConfig('unlockedActions', ['login', 'logout']);
    }

    public function login(RecordLoginUseCase $recordLogin, RecordLoginFailureUseCase $recordLoginFailure): ?Response
    {
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod(['get', 'post']);
        $result = $this->Authentication->getResult();

        if ($result !== null && $result->isValid()) {
            if (AccountStatus::isDisabled($this->Authentication->getIdentityData('i_del_flag'), $this->Authentication->getIdentityData('i_enable'))) {
                $this->Authentication->logout();
                $this->Flash->error(__('このアカウントは無効化されています。'));
                return $this->redirect(['action' => 'login']);
            }

            // セッション固定化攻撃対策：ログイン成功時にセッションIDを再生成する
            $this->request->getSession()->renew();

            $recordLogin->execute(new RecordLoginInput(
                (int)$this->Authentication->getIdentityData('i_id_user'),
                $this->nullableString($this->Authentication->getIdentityData('c_user_name')),
                $this->nullableString($this->Authentication->getIdentityData('c_login_account')),
                $this->getClientIp(),
            ));

            $defaultRedirect = UserRole::landsOnReservationCalendar(
                (int)$this->Authentication->getIdentityData('i_admin'),
                (int)$this->Authentication->getIdentityData('i_user_level'),
            )
                ? ['controller' => 'TReservationInfo', 'action' => 'index']
                : ['controller' => 'Pages', 'action' => 'display', 'home'];
            $redirectParam = $this->request->getQuery('redirect');

            return $this->redirect(is_string($redirectParam) && $this->isSafeRedirect($redirectParam) ? $redirectParam : $defaultRedirect);
        }

        if ($this->request->is('post')) {
            $status = $result !== null ? $result->getStatus() : 'Result is null';
            $this->log('Login failed. status=' . preg_replace('/[\r\n\t]/', ' ', $status), 'debug');
            $this->Flash->error(__('ユーザー名またはパスワードが正しくありません。'));
            $recordLoginFailure->execute(new RecordLoginFailureInput($this->dataString('c_login_account'), $this->getClientIp()));
        }

        return null;
    }

    public function logout(RecordLogoutUseCase $recordLogout): ?Response
    {
        $this->Authorization->skipAuthorization();
        $result = $this->Authentication->getResult();
        if ($result !== null && $result->isValid()) {
            $recordLogout->execute(new RecordLogoutInput(
                (int)$this->Authentication->getIdentityData('i_id_user'),
                $this->nullableString($this->Authentication->getIdentityData('c_user_name')),
                $this->nullableString($this->Authentication->getIdentityData('c_login_account')),
                $this->getClientIp(),
            ));
            $this->Authentication->logout();
            $this->Flash->success('正常にログアウトされました。');
        }

        return $this->redirect(['action' => 'login']);
    }

    /** アカウントの値（文字列の列）。無ければ null。 */
    private function nullableString(string|int|null $value): ?string
    {
        return $value === null ? null : (string)$value;
    }
}
