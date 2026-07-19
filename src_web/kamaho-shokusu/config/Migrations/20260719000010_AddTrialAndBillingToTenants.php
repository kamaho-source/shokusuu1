<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * tenantsテーブルにトライアル期限・Stripe・請求書送付先カラムを追加する。
 */
class AddTrialAndBillingToTenants extends AbstractMigration
{
    public function up(): void
    {
        $this->table('tenants')
            ->addColumn('trial_expires_at', 'datetime', [
                'null'    => true,
                'default' => null,
                'comment' => 'トライアル期限日時',
                'after'   => 'status',
            ])
            ->addColumn('stripe_customer_id', 'string', [
                'limit'   => 255,
                'null'    => true,
                'default' => null,
                'comment' => 'Stripe Customer ID (cus_xxx)',
                'after'   => 'trial_expires_at',
            ])
            ->addColumn('billing_contact_name', 'string', [
                'limit'   => 100,
                'null'    => true,
                'default' => null,
                'comment' => '請求書送付先担当者名',
                'after'   => 'stripe_customer_id',
            ])
            ->addColumn('billing_contact_email', 'string', [
                'limit'   => 255,
                'null'    => true,
                'default' => null,
                'comment' => '請求書送付先メールアドレス',
                'after'   => 'billing_contact_name',
            ])
            ->addColumn('billing_address', 'text', [
                'null'    => true,
                'default' => null,
                'comment' => '請求書送付先住所',
                'after'   => 'billing_contact_email',
            ])
            ->addIndex(['stripe_customer_id'])
            ->update();

        // 既存の trial ステータスのテナントに trial_expires_at をセット（登録日+30日）
        $this->execute("
            UPDATE tenants
            SET trial_expires_at = DATE_ADD(created_at, INTERVAL 30 DAY)
            WHERE status = 'trial' AND trial_expires_at IS NULL
        ");
    }

    public function down(): void
    {
        $this->table('tenants')
            ->removeColumn('trial_expires_at')
            ->removeColumn('stripe_customer_id')
            ->removeColumn('billing_contact_name')
            ->removeColumn('billing_contact_email')
            ->removeColumn('billing_address')
            ->update();
    }
}
