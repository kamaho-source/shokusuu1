<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Tenant\TenantContext;
use App\Application\Tenant\TenantContextHolder;
use App\Domain\ValueObject\PlanCode;
use App\Domain\ValueObject\UserRole;
use Authorization\Exception\ForbiddenException;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Cake\I18n\DateTime;

/**
 * テナント管理コントローラー（システム管理者専用）
 *
 * - index:        テナント選択画面（カード一覧、enter/exit）
 * - trials:       トライアルユーザー管理一覧（統計・検索・テーブル）
 * - enter:        テナントに入る（セッションに activeTenantId を保存）
 * - exitTenant:   全テナントモードに戻る（セッション削除）
 * - add:          テナント手動追加
 * - updateStatus: ステータス変更
 */
final class AdminTenantsController extends AppController
{
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->FormProtection->setConfig('unlockedActions', ['enter', 'exitTenant', 'updateStatus', 'updatePlan']);
    }

    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('default');
    }

    /**
     * テナント選択画面（カード一覧）
     */
    public function index(): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'index');
        } catch (ForbiddenException) {
            $this->Flash->error('この機能はシステム管理者のみ利用できます。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $tenantsTable   = $this->fetchTable('Tenants');
        $tenants        = $tenantsTable->find()->order(['name' => 'ASC'])->all();
        $activeTenantId = $this->request->getSession()->read('SystemAdmin.activeTenantId');

        $this->set(compact('tenants', 'activeTenantId'));
        return null;
    }

    /**
     * トライアルユーザー管理一覧
     */
    public function trials(): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'trials');
        } catch (ForbiddenException) {
            $this->Flash->error('この機能はシステム管理者のみ利用できます。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $tenantsTable = $this->fetchTable('Tenants');
        $q            = $this->request->getQueryParams();

        $now       = DateTime::now('Asia/Tokyo');
        $threshold = $now->modify('+7 days');

        $totalTrial = $tenantsTable->find()->where(['status' => 'trial'])->count();
        $nearExpiry = $tenantsTable->find()->where([
            'status'              => 'trial',
            'trial_expires_at >=' => $now->format('Y-m-d H:i:s'),
            'trial_expires_at <'  => $threshold->format('Y-m-d H:i:s'),
        ])->count();
        $expired = $tenantsTable->find()->where([
            'status'             => 'trial',
            'trial_expires_at <' => $now->format('Y-m-d H:i:s'),
        ])->count();
        $active = $tenantsTable->find()->where(['status' => 'active'])->count();

        $query = $tenantsTable->find();

        if (!empty($q['q'])) {
            $kw = '%' . $q['q'] . '%';
            $query->where(function ($exp) use ($kw) {
                return $exp->or([
                    'Tenants.name LIKE'                  => $kw,
                    'Tenants.billing_contact_name LIKE'  => $kw,
                    'Tenants.billing_contact_email LIKE' => $kw,
                ]);
            });
        }

        if (!empty($q['status'])) {
            switch ($q['status']) {
                case 'trial':
                    $query->where([
                        'status' => 'trial',
                        'OR'     => [
                            'trial_expires_at IS'  => null,
                            'trial_expires_at >='  => $threshold->format('Y-m-d H:i:s'),
                        ],
                    ]);
                    break;
                case 'near_expiry':
                    $query->where([
                        'status'              => 'trial',
                        'trial_expires_at >=' => $now->format('Y-m-d H:i:s'),
                        'trial_expires_at <'  => $threshold->format('Y-m-d H:i:s'),
                    ]);
                    break;
                case 'expired':
                    $query->where([
                        'status'             => 'trial',
                        'trial_expires_at <' => $now->format('Y-m-d H:i:s'),
                    ]);
                    break;
                case 'active':
                    $query->where(['status' => 'active']);
                    break;
                case 'suspended':
                    $query->where(['status' => 'suspended']);
                    break;
            }
        }

        if (!empty($q['expire_from'])) {
            $query->where(['trial_expires_at >=' => $q['expire_from'] . ' 00:00:00']);
        }
        if (!empty($q['expire_to'])) {
            $query->where(['trial_expires_at <=' => $q['expire_to'] . ' 23:59:59']);
        }

        $query->order(['Tenants.created_at' => 'DESC']);
        $tenants = $this->paginate($query, ['limit' => 20, 'maxLimit' => 100]);

        $tenantIds       = array_map(fn($t) => $t->id, iterator_to_array($tenants));
        $userStats       = [];
        $reservationStat = [];
        $lastLoginStat   = [];

        if (!empty($tenantIds)) {
            $userTable = $this->fetchTable('MUserInfo');
            $uQuery    = $userTable->find();
            $rows      = $uQuery
                ->select([
                    'tenant_id',
                    'cnt'     => $uQuery->func()->count('i_id_user'),
                    'last_dt' => $uQuery->func()->max('dt_update'),
                ])
                ->where(['i_del_flag' => 0, 'tenant_id IN' => $tenantIds])
                ->group(['tenant_id'])
                ->enableHydration(false)
                ->all();
            foreach ($rows as $r) {
                $userStats[(int)$r['tenant_id']]     = (int)$r['cnt'];
                $lastLoginStat[(int)$r['tenant_id']] = $r['last_dt'];
            }

            $rsvTable = $this->fetchTable('TReservationInfo');
            $rQuery   = $rsvTable->find();
            $rsvRows  = $rQuery
                ->select(['tenant_id', 'cnt' => $rQuery->func()->count('*')])
                ->where(['tenant_id IN' => $tenantIds])
                ->group(['tenant_id'])
                ->enableHydration(false)
                ->all();
            foreach ($rsvRows as $r) {
                $reservationStat[(int)$r['tenant_id']] = (int)$r['cnt'];
            }
        }

        $activeTenantId = $this->request->getSession()->read('SystemAdmin.activeTenantId');

        $this->set(compact(
            'tenants',
            'q',
            'totalTrial',
            'nearExpiry',
            'expired',
            'active',
            'userStats',
            'reservationStat',
            'lastLoginStat',
            'now',
            'activeTenantId',
        ));
        return null;
    }

    /**
     * テナントを選択してダッシュボードへ遷移する。
     */
    public function enter(int $tenantId): ?Response
    {
        $this->request->allowMethod(['post']);

        $user = $this->Authentication->getIdentity();
        if ($user === null || !UserRole::isSystemAdmin((int)$user->get('i_admin'))) {
            $this->Flash->error('この画面はシステム管理者専用です。');
            return $this->redirect('/');
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

        $user = $this->Authentication->getIdentity();
        if ($user === null || !UserRole::isSystemAdmin((int)$user->get('i_admin'))) {
            $this->Flash->error('この画面はシステム管理者専用です。');
            return $this->redirect('/');
        }
        $this->Authorization->skipAuthorization();

        $this->request->getSession()->delete('SystemAdmin.activeTenantId');
        TenantContextHolder::clear();

        return $this->redirect(['action' => 'index']);
    }

    /**
     * テナント手動追加フォーム（システム管理者用）
     */
    public function add(): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'add');
        } catch (ForbiddenException) {
            $this->Flash->error('この機能はシステム管理者のみ利用できます。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $tenantsTable    = $this->fetchTable('Tenants');
        $facilitiesTable = $this->fetchTable('Facilities');
        $userTable       = $this->fetchTable('MUserInfo');

        if ($this->request->is('post')) {
            $data   = $this->request->getData();
            $errors = [];

            if (empty($data['name'])) {
                $errors[] = '法人名は必須です。';
            }
            if (empty($data['tenant_code']) || !preg_match('/^[a-z0-9\-]{3,30}$/', (string)$data['tenant_code'])) {
                $errors[] = 'テナントコードは半角英小文字・数字・ハイフン（3〜30文字）で入力してください。';
            }
            if (empty($data['facility_name'])) {
                $errors[] = '施設名は必須です。';
            }
            if (empty($data['contact_name'])) {
                $errors[] = '担当者名は必須です。';
            }
            if (empty($data['contact_email']) || !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'メールアドレスを正しく入力してください。';
            }
            if (empty($data['login_account'])) {
                $errors[] = '管理者ログインIDは必須です。';
            }
            if (empty($data['login_password']) || strlen((string)$data['login_password']) < 8) {
                $errors[] = 'パスワードは8文字以上で入力してください。';
            }

            if (empty($errors) && $tenantsTable->exists(['tenant_code' => $data['tenant_code']])) {
                $errors[] = 'そのテナントコードはすでに使用されています。';
            }

            if (empty($errors)) {
                $now          = DateTime::now('Asia/Tokyo');
                $trialExpires = $now->modify('+30 days');

                $tenant = $tenantsTable->newEntity([
                    'tenant_code'           => $data['tenant_code'],
                    'name'                  => $data['name'],
                    'status'                => 'trial',
                    'trial_expires_at'      => $trialExpires->format('Y-m-d H:i:s'),
                    'billing_contact_name'  => $data['contact_name'],
                    'billing_contact_email' => $data['contact_email'],
                    'billing_address'       => $data['billing_address'] ?? null,
                    'created_at'            => $now->format('Y-m-d H:i:s'),
                    'updated_at'            => $now->format('Y-m-d H:i:s'),
                ]);

                if ($tenantsTable->save($tenant)) {
                    $tenantId = $tenant->id;

                    $facility = $facilitiesTable->newEntity([
                        'tenant_id'     => $tenantId,
                        'facility_code' => 'facility-01',
                        'name'          => $data['facility_name'],
                        'timezone'      => 'Asia/Tokyo',
                        'is_active'     => 1,
                        'created_at'    => $now->format('Y-m-d H:i:s'),
                        'updated_at'    => $now->format('Y-m-d H:i:s'),
                    ]);
                    $facilitiesTable->save($facility);

                    $hashedPassword = password_hash((string)$data['login_password'], PASSWORD_BCRYPT);
                    $adminUser      = $userTable->newEntity([
                        'tenant_id'       => $tenantId,
                        'facility_id'     => $facility->id ?? null,
                        'c_login_account' => $data['login_account'],
                        'c_login_passwd'  => $hashedPassword,
                        'c_user_name'     => $data['contact_name'],
                        'i_admin'         => 4,
                        'i_user_level'    => 0,
                        'i_enable'        => 1,
                        'i_del_flag'      => 0,
                        'dt_create'       => $now->format('Y-m-d H:i:s'),
                        'dt_update'       => $now->format('Y-m-d H:i:s'),
                    ]);
                    $userTable->save($adminUser);

                    $this->Flash->success("テナント「{$data['name']}」を登録しました（トライアル30日）。");
                    return $this->redirect(['action' => 'index']);
                }
                $errors[] = 'テナントの保存に失敗しました。';
            }

            $this->set('errors', $errors);
            $this->set('data', $data);
        }

        return null;
    }

    /**
     * テナントステータスを変更する
     */
    public function updateStatus(int $tenantId): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'updateStatus');
        } catch (ForbiddenException) {
            $this->Flash->error('この機能はシステム管理者のみ利用できます。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $this->request->allowMethod(['post']);
        $status = (string)$this->request->getData('status');

        if (!in_array($status, ['active', 'suspended', 'terminated', 'trial'], true)) {
            $this->Flash->error('不正なステータスです。');
            return $this->redirect(['action' => 'index']);
        }

        $tenantsTable = $this->fetchTable('Tenants');
        $tenant       = $tenantsTable->get($tenantId);
        $now          = DateTime::now('Asia/Tokyo');

        $patchData = ['status' => $status, 'updated_at' => $now->format('Y-m-d H:i:s')];
        if ($status === 'active' && $tenant->contract_started_at === null) {
            $patchData['contract_started_at'] = $now->format('Y-m-d H:i:s');
        }

        $tenant = $tenantsTable->patchEntity($tenant, $patchData);
        $tenantsTable->save($tenant);

        $this->Flash->success("テナント「{$tenant->name}」のステータスを「{$status}」に変更しました。");
        return $this->redirect(['action' => 'index']);
    }

    /**
     * テナントのプランコードを変更する（POST）
     */
    public function updatePlan(int $tenantId): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'updateStatus');
        } catch (ForbiddenException) {
            $this->Flash->error('この機能はシステム管理者のみ利用できます。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $this->request->allowMethod(['post']);
        $planCode = (string)$this->request->getData('plan_code');

        $validCodes = array_map(fn(PlanCode $p) => $p->value, PlanCode::cases());
        if (!in_array($planCode, $validCodes, true)) {
            $this->Flash->error('不正なプランコードです。');
            return $this->redirect(['action' => 'trials']);
        }

        $tenantsTable = $this->fetchTable('Tenants');
        $tenant       = $tenantsTable->get($tenantId);
        $now          = DateTime::now('Asia/Tokyo');

        $tenant = $tenantsTable->patchEntity($tenant, [
            'plan_code'  => $planCode,
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
        $tenantsTable->save($tenant);

        $label = PlanCode::from($planCode)->label();
        $this->Flash->success("テナント「{$tenant->name}」のプランを「{$label}」に変更しました。");
        return $this->redirect(['action' => 'trials']);
    }
}
