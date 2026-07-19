<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Tenant\TenantContext;
use App\Application\Tenant\TenantContextHolder;
use App\Domain\ValueObject\UserRole;
use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * システム管理者専用のテナント管理画面コントローラー。
 *
 * - index: テナント一覧・選択画面
 * - enter: テナントに入る（セッションに activeTenantId を保存）
 * - exit:  全テナントモードに戻る（セッション削除）
 */
final class AdminTenantsController extends AppController
{
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->FormProtection->setConfig('unlockedActions', ['enter', 'exitTenant']);
    }

    private function requireSysAdmin(): ?Response
    {
        $user = $this->Authentication->getIdentity();
        if ($user === null || !UserRole::isSystemAdmin((int)$user->get('i_admin'))) {
            $this->Flash->error('この画面はシステム管理者専用です。');
            return $this->redirect('/');
        }
        return null;
    }

    /**
     * テナント一覧・選択画面。
     */
    public function index(): ?Response
    {
        if ($denied = $this->requireSysAdmin()) {
            return $denied;
        }
        $this->Authorization->skipAuthorization();

        $tenantsTable = $this->fetchTable('Tenants');
        $tenants = $tenantsTable->find()
            ->orderBy(['id' => 'ASC'])
            ->all()
            ->toArray();

        $activeTenantId = $this->request->getSession()->read('SystemAdmin.activeTenantId');

        $this->set(compact('tenants', 'activeTenantId'));
        return null;
    }

    /**
     * テナントを選択してダッシュボードへ遷移する。
     *
     * @param int $tenantId
     */
    public function enter(int $tenantId): ?Response
    {
        $this->request->allowMethod(['post']);
        if ($denied = $this->requireSysAdmin()) {
            return $denied;
        }
        $this->Authorization->skipAuthorization();

        $tenantsTable = $this->fetchTable('Tenants');
        $tenant = $tenantsTable->find()->where(['id' => $tenantId])->first();

        if ($tenant === null) {
            $this->Flash->error('指定されたテナントは存在しません。');
            return $this->redirect(['action' => 'index']);
        }

        $this->request->getSession()->write('SystemAdmin.activeTenantId', $tenantId);

        TenantContextHolder::set(new TenantContext(
            tenantId:     $tenant->id,
            tenantCode:   $tenant->tenant_code,
            tenantStatus: $tenant->status,
        ));

        $this->Flash->success(sprintf('「%s」のテナントに切り替えました。', $tenant->name));
        return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
    }

    /**
     * 全テナントモードに戻る。
     */
    public function exitTenant(): ?Response
    {
        $this->request->allowMethod(['post']);
        if ($denied = $this->requireSysAdmin()) {
            return $denied;
        }
        $this->Authorization->skipAuthorization();

        $this->request->getSession()->delete('SystemAdmin.activeTenantId');
        TenantContextHolder::clear();

        return $this->redirect(['action' => 'index']);
    }
}
