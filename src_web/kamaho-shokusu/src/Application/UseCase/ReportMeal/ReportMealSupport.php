<?php
declare(strict_types=1);

namespace App\Application\UseCase\ReportMeal;

use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\PersistenceException;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * 今日の「食べない」報告の決まり（代表部屋・メッセージ・DB 失敗の扱い）。
 */
final class ReportMealSupport
{
    public const SAVE_FAILED_MESSAGE = '報告の保存に失敗しました。';

    public const CONFLICT_MESSAGE = '他の画面で予約が変更されました。画面を再読み込みしてから、もう一度報告してください。';

    /**
     * 代表部屋（所属中で削除されていない部屋のうち、ID の小さい部屋）。報告の行を作る部屋。
     *
     * @throws InvalidInputException 所属部屋が無い
     */
    public static function primaryRoomId(RoomMembershipRepositoryInterface $rooms, int $userId): int
    {
        $roomIds = array_keys($rooms->selectableRooms($rooms->activeRoomIds($userId)));
        if ($roomIds === []) {
            throw new InvalidInputException('所属部屋が設定されていません。管理者にお問い合わせください。', 400);
        }

        return (int)min($roomIds);
    }

    /**
     * DB の失敗。理由（SQL など）は利用者に返さず、ログに残して 500 にする。途中までの保存は取り消し済み。
     */
    public static function dbFailure(LoggerInterface $logger, string $action, int $userId, PersistenceException $e): PersistenceException
    {
        $logger->error(sprintf('%s DB error (user %d): %s', $action, $userId, $e->getMessage()));

        return new PersistenceException('報告の保存に失敗しました（システムエラー）。管理者にお問い合わせください。');
    }

    private function __construct() {}
}
