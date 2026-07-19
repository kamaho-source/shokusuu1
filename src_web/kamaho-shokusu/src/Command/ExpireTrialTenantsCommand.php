<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

/**
 * 日次バッチ: トライアル期限切れテナントを suspended に移行する。
 *
 * 実行例:
 *   bin/cake expire_trial_tenants
 *   bin/cake expire_trial_tenants --dry-run
 */
class ExpireTrialTenantsCommand extends Command
{
    public static function defaultName(): string
    {
        return 'expire_trial_tenants';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription('トライアル期限が切れたテナントを suspended に一括移行する。')
            ->addOption('dry-run', [
                'help'    => 'ドライラン（実際には更新しない）',
                'boolean' => true,
                'short'   => 'r',
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $now    = DateTime::now('Asia/Tokyo');
        $dryRun = (bool)$args->getOption('dry-run');

        $io->info("実行日時: {$now->format('Y-m-d H:i:s')} (JST)");
        $io->info($dryRun ? '[DRY RUN] 実際の更新は行いません。' : '期限切れテナントを suspended に移行します。');

        $tenantsTable = TableRegistry::getTableLocator()->get('Tenants');

        $targets = $tenantsTable->find()
            ->where([
                'status'             => 'trial',
                'trial_expires_at <' => $now->format('Y-m-d H:i:s'),
            ])
            ->all();

        $count = 0;
        foreach ($targets as $tenant) {
            $io->out("  → [{$tenant->id}] {$tenant->name} (trial_expires_at: {$tenant->trial_expires_at})");

            if (!$dryRun) {
                $tenant = $tenantsTable->patchEntity($tenant, [
                    'status'     => 'suspended',
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);
                $tenantsTable->save($tenant);
            }
            $count++;
        }

        $io->success($dryRun
            ? "DRY RUN完了: 対象 {$count} 件（実際には更新していません）"
            : "完了: {$count} 件を suspended に移行しました。"
        );

        return self::CODE_SUCCESS;
    }
}
