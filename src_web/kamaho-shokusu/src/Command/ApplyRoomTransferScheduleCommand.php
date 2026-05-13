<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\RoomTransferScheduleService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\I18n\DateTime;

/**
 * 部屋異動予約適用バッチ
 *
 * 有効開始日が到来した予約中スケジュールを適用する。
 * 毎日0時頃にcronで実行することを想定。
 *
 * 使用例:
 *   bin/cake apply_room_transfer_schedule
 *   bin/cake apply_room_transfer_schedule --dry-run
 *   bin/cake apply_room_transfer_schedule --date 2026-04-01
 */
class ApplyRoomTransferScheduleCommand extends Command
{
    public static function defaultName(): string
    {
        return 'apply_room_transfer_schedule';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);

        return $parser
            ->addOption('date', [
                'help'  => '基準日 (YYYY-MM-DD)。未指定は今日(JST)。',
                'short' => 'd',
            ])
            ->addOption('dry-run', [
                'help'    => 'ドライラン（件数のみ表示しコミットしない）',
                'boolean' => true,
                'short'   => 'r',
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $dryRun  = (bool)$args->getOption('dry-run');
        $dateOpt = $args->getOption('date');

        $today = $dateOpt
            ? (new DateTime($dateOpt . ' 00:00:00', 'Asia/Tokyo'))->format('Y-m-d')
            : DateTime::now('Asia/Tokyo')->format('Y-m-d');

        if ($dryRun) {
            $io->out('[DRY-RUN] 基準日: ' . $today);
        } else {
            $io->out('部屋異動予約の適用を開始します。基準日: ' . $today);
        }

        $service = new RoomTransferScheduleService();

        try {
            $result = $service->applyPending($today, $dryRun);
        } catch (\Throwable $e) {
            $io->err('致命的エラー: ' . $e->getMessage());
            return self::CODE_ERROR;
        }

        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $io->out(sprintf('%s適用%s: %d件', $prefix, $dryRun ? '予定' : '完了', $result['applied']));

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $err) {
                $io->err('エラー: ' . $err);
            }
            return self::CODE_ERROR;
        }

        return self::CODE_SUCCESS;
    }
}
