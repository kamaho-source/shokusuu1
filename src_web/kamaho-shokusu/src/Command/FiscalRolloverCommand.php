<?php
namespace App\Command;

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
            $transitions = [
                6  => 2, // 6 -> 7 で rank=2（年長 → 小学生(低学年)）
                9  => 3, // 9 -> 10 で rank=3（低学年 → 中学年）
                11 => 4, // 11 -> 12 で rank=4（中学年 → 高学年）
                12 => 5, // 12 -> 13 で rank=5（高学年 → 中学生）
                15 => 6, // 15 -> 16 で rank=6（中学生 → 高校生）
                18 => 7, // 18 -> 19 で rank=7（高校生 → 成人など、施設ルールに合わせて）
            ];

            // 1) 個別境界：年齢+1 と rank 更新
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

            // 2) 上記以外：年齢のみ +1
            $totalUpdated += $mUserInfo->updateAll(
                function (QueryExpression $exp) use ($now) {
                    return $exp
                        ->add('i_user_age = i_user_age + 1')
                        ->add(['dt_update' => $now]);
                },
                $activeWhere + ['i_user_age NOT IN' => array_keys($transitions)]
            );

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
