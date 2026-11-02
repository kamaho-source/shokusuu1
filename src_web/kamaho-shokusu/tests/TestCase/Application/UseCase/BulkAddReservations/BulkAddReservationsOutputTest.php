<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\BulkAddReservations;

use App\Application\UseCase\BulkAddReservations\BulkAddReservationsOutput;
use PHPUnit\Framework\TestCase;

/**
 * toArray が、型付きにする前の戻り値の配列（キーの有無・順を含む）と同じであること。
 */
final class BulkAddReservationsOutputTest extends TestCase
{
    public function testSucceeded(): void
    {
        $out = BulkAddReservationsOutput::succeeded('すべての予約が正常に登録されました。', './');

        $this->assertTrue($out->ok);
        $this->assertSame(['ok' => true, 'message' => 'すべての予約が正常に登録されました。', 'redirect_url' => './'], $out->toArray());
    }

    public function testFailed(): void
    {
        $out = BulkAddReservationsOutput::failed('予約タイプが選択されていません。');

        $this->assertFalse($out->ok);
        $this->assertNull($out->conflictDate);
        $this->assertSame(['ok' => false, 'message' => '予約タイプが選択されていません。'], $out->toArray());
    }

    public function testFailedWithConflictDate(): void
    {
        $out = BulkAddReservationsOutput::failed('競合', '2026-11-10');

        $this->assertSame(['ok' => false, 'message' => '競合', 'conflict_date' => '2026-11-10'], $out->toArray());
    }
}
