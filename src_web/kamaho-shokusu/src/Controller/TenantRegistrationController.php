<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Stripe\NullStripeService;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Cake\I18n\DateTime;
use Cake\Log\Log;

/**
 * テナント公開セルフ登録コントローラー（認証不要）
 *
 * URL: /tenant/register
 */
final class TenantRegistrationController extends AppController
{
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['register', 'complete']);
        $this->Authorization->skipAuthorization();
        $this->FormProtection->setConfig('unlockedActions', ['register']);
    }

    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('public');
    }

    /**
     * 公開セルフ登録フォーム
     */
    public function register(): ?Response
    {
        $errors = [];
        $data   = [];

        if ($this->request->is('post')) {
            $data   = $this->request->getData();
            $errors = $this->validateRegistration($data);

            if (empty($errors)) {
                $errors = $this->processRegistration($data);

                if (empty($errors)) {
                    return $this->redirect(['action' => 'complete', '?' => ['name' => $data['name']]]);
                }
            }
        }

        $this->set(compact('errors', 'data'));
        return null;
    }

    /**
     * 登録完了画面
     */
    public function complete(): ?Response
    {
        $tenantName = (string)$this->request->getQuery('name');
        $this->set('tenantName', $tenantName);
        return null;
    }

    /**
     * 入力バリデーション
     *
     * @return string[] エラーメッセージの配列（空なら正常）
     */
    private function validateRegistration(array $data): array
    {
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
        $password = (string)($data['login_password'] ?? '');
        if (strlen($password) < 8) {
            $errors[] = 'パスワードは8文字以上で入力してください。';
        }
        if ($password !== (string)($data['login_password_confirm'] ?? '')) {
            $errors[] = 'パスワードと確認用パスワードが一致しません。';
        }
        if (!empty($data['tenant_code'])) {
            $tenantsTable = $this->fetchTable('Tenants');
            if ($tenantsTable->exists(['tenant_code' => $data['tenant_code']])) {
                $errors[] = 'そのテナントコードはすでに使用されています。別のコードを入力してください。';
            }
        }

        return $errors;
    }

    /**
     * テナント・施設・管理者ユーザーを作成する。
     *
     * @return string[] エラーメッセージ（空なら成功）
     */
    private function processRegistration(array $data): array
    {
        $tenantsTable    = $this->fetchTable('Tenants');
        $facilitiesTable = $this->fetchTable('Facilities');
        $userTable       = $this->fetchTable('MUserInfo');

        $now          = DateTime::now('Asia/Tokyo');
        $trialExpires = $now->modify('+30 days');

        $tenant = $tenantsTable->newEntity([
            'tenant_code'           => $data['tenant_code'],
            'name'                  => $data['name'],
            'status'                => 'trial',
            'trial_expires_at'      => $trialExpires->format('Y-m-d H:i:s'),
            'billing_contact_name'  => $data['contact_name'],
            'billing_contact_email' => $data['contact_email'],
            'created_at'            => $now->format('Y-m-d H:i:s'),
            'updated_at'            => $now->format('Y-m-d H:i:s'),
        ]);

        if (!$tenantsTable->save($tenant)) {
            return ['テナント情報の保存に失敗しました。しばらく経ってから再度お試しください。'];
        }

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

        // Stripe カスタマー作成（未設定時は NullStripeService がスキップ）
        $stripe           = new NullStripeService();
        $stripeCustomerId = $stripe->createCustomer((string)$data['name'], (string)$data['contact_email']);
        if ($stripeCustomerId !== null) {
            $tenant = $tenantsTable->patchEntity($tenant, [
                'stripe_customer_id' => $stripeCustomerId,
                'updated_at'         => $now->format('Y-m-d H:i:s'),
            ]);
            $tenantsTable->save($tenant);
        }

        Log::info("[TenantRegistration] 新規テナント登録: id={$tenantId}, code={$data['tenant_code']}, name={$data['name']}");

        return [];
    }
}
