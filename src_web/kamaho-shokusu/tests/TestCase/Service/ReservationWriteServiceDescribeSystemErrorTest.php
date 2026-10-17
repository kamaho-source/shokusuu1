<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationWriteService;
use Cake\Database\Exception\QueryException;
use PHPUnit\Framework\TestCase;

/**
 * ReservationWriteService::describeSystemError のテスト
 *
 * 確認項目: 生の SQL・テーブル名を出さず、原因の種類だけを返すこと
 */
class ReservationWriteServiceDescribeSystemErrorTest extends TestCase
{
    private function pdo(string $message): \PDOException
    {
        return new \PDOException($message);
    }

    public function testDeadlockIsDescribedWithoutSql(): void
    {
        $e = new QueryException(
            'UPDATE t_individual_reservation_info SET i_change_flag = 1',
            $this->pdo('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock')
        );
        $msg = ReservationWriteService::describeSystemError($e);

        $this->assertStringContainsString('同時に更新', $msg);
        $this->assertStringContainsString('[エラーコード: DB-1213]', $msg);
        $this->assertStringNotContainsString('SQLSTATE', $msg);
        $this->assertStringNotContainsString('t_individual_reservation_info', $msg);
    }

    public function testDuplicateEntry(): void
    {
        $msg = ReservationWriteService::describeSystemError(
            $this->pdo("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '2026-10-12-1-9' for key 'PRIMARY'")
        );
        $this->assertStringEndsWith('[エラーコード: DB-1062]', $msg);
        $this->assertStringNotContainsString('PRIMARY', $msg);
    }

    public function testUnknownDatabaseError(): void
    {
        $msg = ReservationWriteService::describeSystemError(
            $this->pdo("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x' in 'field list'")
        );
        $this->assertSame('システムエラーのため。管理者にお問い合わせください [エラーコード: DB-1054]', $msg);
    }

    public function testConnectionErrorIsShownOnlyAsCode(): void
    {
        $msg = ReservationWriteService::describeSystemError(
            $this->pdo('SQLSTATE[HY000] [2002] Connection refused')
        );
        $this->assertStringNotContainsString('データベース', $msg);
        $this->assertSame('システムエラーのため。管理者にお問い合わせください [エラーコード: DB-2002]', $msg);
    }

    public function testNonDatabaseError(): void
    {
        $msg = ReservationWriteService::describeSystemError(new \RuntimeException('secret internal path /var/www'));
        $this->assertSame('システムエラーのため。管理者にお問い合わせください [エラーコード: SYS-0000]', $msg);
    }
}
