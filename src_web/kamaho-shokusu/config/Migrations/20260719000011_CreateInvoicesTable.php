<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * invoicesテーブルを新規作成する。
 * Stripe Invoice と1対1で紐付き、入金状況を管理する。
 */
class CreateInvoicesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('invoices', ['id' => true, 'primary_key' => ['id']])
            ->addColumn('tenant_id', 'integer', [
                'null'    => false,
                'comment' => '対象テナントID',
            ])
            ->addColumn('stripe_invoice_id', 'string', [
                'limit'   => 255,
                'null'    => true,
                'default' => null,
                'comment' => 'Stripe Invoice ID (in_xxx)',
            ])
            ->addColumn('invoice_number', 'string', [
                'limit'   => 50,
                'null'    => false,
                'comment' => '請求書番号 (INV-YYYY-NNNN)',
            ])
            ->addColumn('billing_period_start', 'date', [
                'null'    => true,
                'default' => null,
                'comment' => '請求期間開始日',
            ])
            ->addColumn('billing_period_end', 'date', [
                'null'    => true,
                'default' => null,
                'comment' => '請求期間終了日',
            ])
            ->addColumn('amount', 'integer', [
                'null'    => false,
                'default' => 0,
                'comment' => '請求金額（税抜、円）',
            ])
            ->addColumn('tax_amount', 'integer', [
                'null'    => false,
                'default' => 0,
                'comment' => '消費税額（円）',
            ])
            ->addColumn('status', 'string', [
                'limit'   => 20,
                'null'    => false,
                'default' => 'unpaid',
                'comment' => 'unpaid / paid / cancelled',
            ])
            ->addColumn('issued_at', 'datetime', [
                'null'    => true,
                'default' => null,
                'comment' => '発行日時',
            ])
            ->addColumn('due_at', 'datetime', [
                'null'    => true,
                'default' => null,
                'comment' => '支払期限日時',
            ])
            ->addColumn('paid_at', 'datetime', [
                'null'    => true,
                'default' => null,
                'comment' => '入金確認日時（Webhookで自動更新）',
            ])
            ->addColumn('notes', 'text', [
                'null'    => true,
                'default' => null,
                'comment' => '備考',
            ])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['tenant_id'])
            ->addIndex(['stripe_invoice_id'], ['unique' => true, 'name' => 'uq_stripe_invoice_id'])
            ->addIndex(['invoice_number'], ['unique' => true, 'name' => 'uq_invoice_number'])
            ->addIndex(['status'])
            ->create();
    }

    public function down(): void
    {
        $this->table('invoices')->drop()->save();
    }
}
