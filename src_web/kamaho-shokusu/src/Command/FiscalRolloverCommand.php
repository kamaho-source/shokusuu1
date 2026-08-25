<?php
namespace App\Command;

use App\Service\AuditLogService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Database\Expression\QueryExpression;
use Cake\I18n\DateTime;

class FiscalRolloverCommand extends Command
{
    const FISCAL_YEAR_MONTH = 4;
    const FISCAL_YEAR_DAY   = 1;

    // 実行済みガード用に t_audit_log へ記録する際のカテゴリ/アクション
    const AUDIT_CATEGORY = 'system';
    const AUDIT_ACTION   = 'fiscal_rollover';

    public static function defaultName(): string
    {
        return 'fiscal:rollover';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);

        return $parser
            ->addOption('date', [
                'help'  => '基準日 (YYYY-MM-DD)。未指定は今日(JST)。会計年度(4/1開始)判定に使用。',
                'short' => 'd',
            ])
            // 両形式をサポート（--dryRun / --dry-run / -r）
            ->addOption('dryRun', [
                'help'    => 'ドライラン（件数のみ表示しコミットしない）',
                'boolean' => true,
                'short'   => 'r',
            ])
            ->addOption('dry-run', [
                'help'    => '(エイリアス) ドライラン',
                'boolean' => true,
            ])
            // force も両対応
            ->addOption('force', [
                'help'    => '4/1以外や既実行でも強制実行',
                'boolean' => true,
                'short'   => 'f',
            ])
            ->addOption('force-run', [
                'help'    => '(エイリアス) 強制実行',
                'boolean' => true,
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $dryRun = (bool)($args->getOption('dryRun') ?? $args->getOption('dry-run') ?? false);
        $force  = (bool)($args->getOption('force')  ?? $args->getOption('force-run') ?? false);

        // 基準日（JST）
        $dateOpt = $args->getOption('date');
        $today   = $dateOpt
            ? new DateTime($dateOpt . ' 00:00:00', 'Asia/Tokyo')
            : DateTime::now('Asia/Tokyo');

        // 4/1判定（forceが無ければ当日4/1のみ実行）
        if (!$force) {
            if (!($today->month === self::FISCAL_YEAR_MONTH && $today->day === self::FISCAL_YEAR_DAY)) {
                $io->out('本コマンドは毎年4月1日に実行されます。--force で強制実行できます。');
                return self::CODE_SUCCESS;
            }
        }

        // 対象会計年度（4月始まり）
        $fiscalYear = $today->month >= self::FISCAL_YEAR_MONTH ? $today->year : $today->year - 1;

        // 実行済みガード：本年度分がすでに成功実行済みなら force なしでは再実行しない
        if (!$force) {
            $alreadyRun = $this->fetchTable('TAuditLog')->find()
                ->where([
                    'c_category'  => self::AUDIT_CATEGORY,
                    'c_action'    => self::AUDIT_ACTION,
                    'c_target_id' => (string)$fiscalYear,
                    'i_result'    => 1,
                ])
                ->first();

            if ($alreadyRun !== null) {
                $io->out(sprintf(
                    '%d年度分のロールオーバーは実行済みです（%s）。--force で再実行できます。',
                    $fiscalYear,
                    $alreadyRun->dt_create->format('Y-m-d H:i:s')
                ));
                return self::CODE_SUCCESS;
            }
        }

        $mUserInfo = $this->fetchTable('MUserInfo');
        $connection = $mUserInfo->getConnection();
        $connection->begin();

        try {
            $totalUpdated  = 0;
            $ranksAdjusted = 0;
            $now = DateTime::now();

            // 有効ユーザ条件：i_enable = 0（※ i_del_flag は条件に含めない）
            $activeWhere = ['i_enable' => 0, 'i_user_age IS NOT' => null];

            // 優先的に処理する年齢遷移: (現在の年齢 => 設定する i_user_rank)
            // 年齢の大きい方から処理する（降順）。11,12のように連番の境界があると、
            // 昇順ではupdateAllの直後の書き込みを同一トランザクション内で再度読んで
            // 二重更新してしまう（read-your-writes）ため、降順にして再ヒットを防ぐ。
            $transitions = [
                18 => 7, // 18 -> 19 で rank=7（高校生 → 成人など、施設ルールに合わせて）
                15 => 6, // 15 -> 16 で rank=6（中学生 → 高校生）
                12 => 5, // 12 -> 13 で rank=5（高学年 → 中学生）
                11 => 4, // 11 -> 12 で rank=4（中学年 → 高学年）
                9  => 3, // 9 -> 10 で rank=3（低学年 → 中学年）
                6  => 2, // 6 -> 7 で rank=2（年長 → 小学生(低学年)）
            ];

            // 1) 上記以外：年齢のみ +1
            // 先にこちらを実行する。個別境界（下記2）を先に処理すると、
            // 例えば 12->13 のように更新後の年齢が transitions のキーに含まれない
            // ケースで、この NOT IN 条件が同一トランザクション内の直前の書き込みを
            // 再度拾ってしまい（read-your-writes）二重加算になるため。
            $totalUpdated += $mUserInfo->updateAll(
                function (QueryExpression $exp) use ($now) {
                    return $exp
                        ->add('i_user_age = i_user_age + 1')
                        ->add(['dt_update' => $now]);
                },
                $activeWhere + ['i_user_age NOT IN' => array_keys($transitions)]
            );

            // 2) 個別境界：年齢+1 と rank 更新
            foreach ($transitions as $age => $rank) {
                $totalUpdated += $mUserInfo->updateAll(
                    function (QueryExpression $exp) use ($rank, $now) {
                        return $exp
                            ->add('i_user_age = i_user_age + 1')
                            ->add(['i_user_rank' => $rank, 'dt_update' => $now]);
                    },
                    $activeWhere + ['i_user_age' => $age]
                );
            }

            // 3) 新しい年齢が 3〜6 のユーザ：rank を 1 に統一
            $ranksAdjusted = $mUserInfo->updateAll(
                ['i_user_rank' => 1, 'dt_update' => $now],
                $activeWhere + ['i_user_age >=' => 3, 'i_user_age <=' => 6]
            );

            if ($dryRun) {
                $connection->rollback();
                $io->out(sprintf('[DRY-RUN] 年齢更新予定合計: %d、年齢区分調整予定: %d（コミットしていません）', $totalUpdated, $ranksAdjusted));
                return self::CODE_SUCCESS;
            }

            // 実行済みガード用に監査ログを記録（同一トランザクションでコミットされる）
            AuditLogService::record(
                self::AUDIT_CATEGORY,
                self::AUDIT_ACTION,
                'system',
                0,
                'm_user_info',
                (string)$fiscalYear,
                ['fiscalYear' => $fiscalYear, 'totalUpdated' => $totalUpdated, 'ranksAdjusted' => $ranksAdjusted]
            );

            $connection->commit();
            $io->out(sprintf('年齢更新合計: %d、年齢区分調整: %d', $totalUpdated, $ranksAdjusted));
            return self::CODE_SUCCESS;

        } catch (\Throwable $e) {
            if ($connection->inTransaction()) {
                $connection->rollback();
            }
            $io->err('エラー発生: ' . $e->getMessage());
            return self::CODE_ERROR;
        }
    }
}
