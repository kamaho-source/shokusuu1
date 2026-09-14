<?php
namespace App\Command;

use App\Service\AuditLogService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Database\Driver\Mysql;
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

        $mUserInfo  = $this->fetchTable('MUserInfo');
        $connection = $mUserInfo->getConnection();

        // 「実行済みか」のDBチェックだけでは、2プロセス（例: cronの多重発火）が
        // ほぼ同時にチェックを通過し、両方が更新を実行してしまうレースコンディションを
        // 防げない。MySQL の名前付きロックで会計年度ごとに排他制御する。
        // （テストで使用する sqlite には GET_LOCK が無いため、MySQL接続時のみ適用する）
        $driver       = $connection->getDriver();
        $useNamedLock = $driver instanceof Mysql;
        $lockName     = sprintf('fiscal_rollover_%d', $fiscalYear);

        if ($useNamedLock) {
            $locked = $connection->execute(
                'SELECT GET_LOCK(:name, :timeout) AS locked',
                ['name' => $lockName, 'timeout' => 5]
            )->fetch('assoc');

            if ((int)($locked['locked'] ?? 0) !== 1) {
                $io->out(sprintf(
                    '%d年度分のロールオーバーは他のプロセスが実行中の可能性があるため中断しました。',
                    $fiscalYear
                ));
                return self::CODE_SUCCESS;
            }
        }

        try {
            // 実行済みガード：本年度分がすでに成功実行済みなら force なしでは再実行しない
            // （ロック取得後に確認するため、同時実行時も一方だけが処理を進める）
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

            return $this->runRollover($mUserInfo, $connection, $fiscalYear, $dryRun, $io);
        } finally {
            if ($useNamedLock) {
                $connection->execute('SELECT RELEASE_LOCK(:name)', ['name' => $lockName]);
            }
        }
    }

    private function runRollover(\Cake\ORM\Table $mUserInfo, \Cake\Datasource\ConnectionInterface $connection, int $fiscalYear, bool $dryRun, ConsoleIo $io): int
    {
        $connection->begin();

        try {
            $now = DateTime::now();

            // 有効ユーザ条件：i_enable = 0（※ i_del_flag は条件に含めない）
            $activeWhere = ['i_enable' => 0, 'i_user_age IS NOT' => null];

            // 境界年齢の遷移: (現在の年齢 => 設定する i_user_rank)
            $transitions = [
                6  => 2, // 6 -> 7 で rank=2（年長 → 小学生(低学年)）
                9  => 3, // 9 -> 10 で rank=3（低学年 → 中学年）
                11 => 4, // 11 -> 12 で rank=4（中学年 → 高学年）
                12 => 5, // 12 -> 13 で rank=5（高学年 → 中学生）
                15 => 6, // 15 -> 16 で rank=6（中学生 → 高校生）
                18 => 7, // 18 -> 19 で rank=7（高校生 → 成人など、施設ルールに合わせて）
            ];

            // 1) 年齢+1 と rank 更新を「1つの UPDATE 文」で同時に行う。
            //    複数の UPDATE 文に分けて処理すると、後続の文が前の文の書き込み結果を
            //    同一トランザクション内で読んでしまい（read-your-writes）、境界の1つ手前の
            //    年齢（5,8,10,14,17歳）が「一般加算で6,9,11,15,18歳になった直後に、
            //    今度は境界更新にも一致してさらに+1される」二重加算が起きる。
            //    1文の UPDATE では各行が自分の更新前の値のみを参照するため、この問題が起きない。
            //    （MySQL は SET 句を左から順に評価するため、rank の CASE 式を
            //    年齢の加算より先に書き、加算前の i_user_age を参照させている）
            $rankCaseSql = 'i_user_rank = CASE i_user_age '
                . implode(' ', array_map(
                    static fn (int $age, int $rank): string => "WHEN {$age} THEN {$rank}",
                    array_keys($transitions),
                    array_values($transitions)
                ))
                . ' ELSE i_user_rank END';

            $totalUpdated = $mUserInfo->updateAll(
                function (QueryExpression $exp) use ($now, $rankCaseSql) {
                    return $exp
                        ->add($rankCaseSql)
                        ->add('i_user_age = i_user_age + 1')
                        ->add(['dt_update' => $now]);
                },
                $activeWhere
            );

            // 2) 新しい年齢が 3〜6 のユーザ：rank を 1 に統一
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

            // AuditLogService::record() は失敗を内部で握りつぶし呼び出し元へ伝播しないため、
            // 「実行済みマーカー」が実際に保存されたかをここで確認する。保存できていなければ
            // 年齢更新だけがコミットされて次回実行時に再度加算される二重実行の原因になるため、
            // ロールバックしてエラー扱いにする。
            $marker = $this->fetchTable('TAuditLog')->find()
                ->where([
                    'c_category'  => self::AUDIT_CATEGORY,
                    'c_action'    => self::AUDIT_ACTION,
                    'c_target_id' => (string)$fiscalYear,
                    'i_result'    => 1,
                ])
                ->first();
            if ($marker === null) {
                throw new \RuntimeException('実行済みマーカーの記録に失敗したため処理を中断しました。');
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
