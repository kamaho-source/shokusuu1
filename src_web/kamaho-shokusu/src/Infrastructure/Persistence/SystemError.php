<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Exception\PersistenceException;
use Cake\Log\Log;

/**
 * 予期しない例外（DB 障害など）を、画面・監査ログに出してよい形に変える。
 * 生のメッセージ（SQL 文・テーブル名など）は利用者に返さず、ログファイルにだけ残す。
 */
final class SystemError
{
    /**
     * 短い原因とエラーコード。DB 由来なら「DB-<MySQL エラー番号>」、それ以外は「SYS-0000」。
     *
     * 例: 「他の操作と同時に更新されたため。もう一度お試しください [エラーコード: DB-1213]」
     */
    public static function describe(\Throwable $e): string
    {
        $pdo = $e;
        while ($pdo !== null && !$pdo instanceof \PDOException) {
            $pdo = $pdo->getPrevious();
        }
        if ($pdo === null) {
            return 'システムエラーのため。管理者にお問い合わせください [エラーコード: SYS-0000]';
        }

        // MySQL のエラー番号。クエリ失敗は "SQLSTATE[23000]: Integrity constraint violation: 1062 ..."、
        // 接続失敗は "SQLSTATE[HY000] [2002] Connection refused" の形。
        $code = (int)($pdo->errorInfo[1] ?? 0);
        if ($code === 0 && preg_match('/SQLSTATE\[\w+\](?:\s*\[(\d+)\]|:[^:]*:\s*(\d+))/', $pdo->getMessage(), $m)) {
            $code = (int)(($m[1] ?? '') !== '' ? $m[1] : $m[2]);
        }

        $text = match ($code) {
            1213 => '他の操作と同時に更新されたため。もう一度お試しください',
            1205 => '他の操作の処理待ちで時間切れになったため。もう一度お試しください',
            1062 => '同じ予約がすでに登録されているため',
            1451, 1452 => '部屋または利用者のデータが見つからないため',
            // 接続断などの技術的な原因は利用者に説明せず、エラーコードで管理者が判別する。
            default => 'システムエラーのため。管理者にお問い合わせください',
        };

        return sprintf('%s [エラーコード: DB-%04d]', $text, $code);
    }

    /**
     * 予約の更新に失敗した例外にする（生のメッセージはログにだけ残す）。
     */
    public static function reservationUpdateFailed(\Throwable $e, string $context): PersistenceException
    {
        Log::error($context . 'で予期しない例外: ' . $e->getMessage());

        return new PersistenceException('予約の更新に失敗しました（' . self::describe($e) . '）');
    }
}
