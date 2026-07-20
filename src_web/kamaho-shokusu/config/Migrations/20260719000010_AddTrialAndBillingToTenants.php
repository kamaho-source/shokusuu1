<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * tenantsテーブルにトライアル期限・請求書送付先カラムを追加する。
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
            ->addColumn('billing_contact_name', 'string', [
                'limit'   => 100,
                'null'    => true,
                'default' => null,
                'comment' => '請求書送付先担当者名',
                'after'   => 'trial_expires_at',
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
            ->removeColumn('billing_contact_name')
            ->removeColumn('billing_contact_email')
            ->removeColumn('billing_address')
            ->update();
    }
}
