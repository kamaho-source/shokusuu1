<?php
declare(strict_types=1);

namespace App\Controller;

use App\Domain\ValueObject\UserRole;
use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * システム管理者専用のテナント切り替えコントローラー。
 *
 * POST /tenant/switch で activeTenantId をセッションに保存し、元のページへリダイレクトする。
 * tenant_id=0 または空の場合は「全テナント」モード（コンテキストなし）に戻す。
 */
final class TenantSwitcherController extends AppController
{
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->FormProtection->setConfig('unlockedActions', ['switchTenant']);
    }

    /**
     * @throws \Cake\Http\Exception\MethodNotAllowedException
     */
    public function switchTenant(): ?Response
    {
        $this->request->allowMethod(['post']);
        $this->Authorization->skipAuthorization();

        $user = $this->Authentication->getIdentity();
        if ($user === null || !UserRole::isSystemAdmin((int)$user->get('i_admin'))) {
            $this->Flash->error('権限がありません。');
            return $this->redirect('/');
        }

        $tenantId = $this->request->getData('tenant_id');

        if ($tenantId === null || $tenantId === '' || (int)$tenantId === 0) {
            $this->request->getSession()->delete('SystemAdmin.activeTenantId');
        } else {
            $tenantId = (int)$tenantId;
            $tenantsTable = $this->fetchTable('Tenants');
            if (!$tenantsTable->exists(['id' => $tenantId])) {
                $this->Flash->error('指定されたテナントは存在しません。');
                return $this->redirect($this->referer('/'));
            }
            $this->request->getSession()->write('SystemAdmin.activeTenantId', $tenantId);
        }

        return $this->redirect($this->referer('/'));
    }
}
