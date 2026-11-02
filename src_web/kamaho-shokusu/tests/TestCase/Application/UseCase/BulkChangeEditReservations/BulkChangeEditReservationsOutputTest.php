<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\BulkChangeEditReservations;

use App\Application\UseCase\BulkChangeEditReservations\BulkChangeEditReservationsOutput;
use PHPUnit\Framework\TestCase;

/**
 * toArray が、型付きにする前の戻り値の配列（キーの有無・順を含む）と同じであること。
 */
final class BulkChangeEditReservationsOutputTest extends TestCase
{
    public function testSucceeded(): void
    {
        $out = BulkChangeEditReservationsOutput::succeeded(2, 1, ['理由']);

        $this->assertTrue($out->ok);
        $this->assertNull($out->message);
        $this->assertSame(['ok' => true, 'updated' => 2, 'created' => 1, 'skipped' => ['理由']], $out->toArray());
    }

    public function testFailed(): void
    {
        $out = BulkChangeEditReservationsOutput::failed('他ユーザーの予約を更新する権限がありません。');

        $this->assertFalse($out->ok);
        $this->assertSame(['ok' => false, 'message' => '他ユーザーの予約を更新する権限がありません。'], $out->toArray());
    }

    public function testFailedWithConflictDate(): void
    {
        $out = BulkChangeEditReservationsOutput::failed('競合', '2026-11-10');

        $this->assertSame(['ok' => false, 'message' => '競合', 'conflict_date' => '2026-11-10'], $out->toArray());
    }
}
