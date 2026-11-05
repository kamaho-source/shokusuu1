<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\ValueObject;

use App\Domain\ValueObject\ApprovalKey;
use App\Domain\ValueObject\ApprovalStatus;
use PHPUnit\Framework\TestCase;

class ApprovalStatusTest extends TestCase
{
    public function testValuesAreUnchanged(): void
    {
        $this->assertSame([0, 1, 2, 3], [ApprovalStatus::PENDING, ApprovalStatus::BLOCK_LEADER, ApprovalStatus::ADMIN, ApprovalStatus::REJECTED]);
        $this->assertSame([1, 2], ApprovalStatus::APPROVED);
    }

    public function testIsApproved(): void
    {
        $this->assertTrue(ApprovalStatus::isApproved(1));
        $this->assertTrue(ApprovalStatus::isApproved(2));
        $this->assertFalse(ApprovalStatus::isApproved(0));
        $this->assertFalse(ApprovalStatus::isApproved(3));
        $this->assertFalse(ApprovalStatus::isApproved(null), 'NULL は未承認');
    }

    public function testTransitions(): void
    {
        $this->assertSame([0], ApprovalStatus::allowedFrom(ApprovalStatus::BLOCK_LEADER));
        $this->assertSame([0, 1], ApprovalStatus::allowedFrom(ApprovalStatus::ADMIN), '管理者は未承認から直接最終承認できる');
        $this->assertSame([0, 1], ApprovalStatus::allowedFrom(ApprovalStatus::REJECTED));
        $this->assertSame([], ApprovalStatus::allowedFrom(ApprovalStatus::PENDING));
        $this->assertFalse(ApprovalStatus::canTransition(ApprovalStatus::ADMIN, ApprovalStatus::REJECTED), '最終承認済みは差し戻せない');
        $this->assertFalse(ApprovalStatus::canTransition(ApprovalStatus::ADMIN, ApprovalStatus::BLOCK_LEADER));
        $this->assertTrue(ApprovalStatus::canTransition(ApprovalStatus::BLOCK_LEADER, ApprovalStatus::ADMIN));
    }

    public function testLabels(): void
    {
        $this->assertSame('未承認', ApprovalStatus::label(0));
        $this->assertSame('ブロック長承認済', ApprovalStatus::label(1));
        $this->assertSame('管理者承認済', ApprovalStatus::label(2));
        $this->assertSame('差し戻し', ApprovalStatus::label(3));
        $this->assertNull(ApprovalStatus::label(9));
    }

    public function testApprovalKeyReadsScreenKeys(): void
    {
        $keys = ApprovalKey::listFrom([['i_id_user' => '2', 'd_reservation_date' => '2024-09-07', 'i_id_room' => '1', 'i_reservation_type' => 3]]);

        $this->assertEquals([new ApprovalKey(2, '2024-09-07', 1, 3)], $keys);
        $this->assertSame('2:2024-09-07', $keys[0]->auditId());
        $this->assertSame(['i_id_user' => 2, 'd_reservation_date' => '2024-09-07', 'i_id_room' => 1, 'i_reservation_type' => 3], $keys[0]->toArray());
    }

    /**
     * @return array<string, array{array<array-key, array<array-key, int|string|null>|string|int|null>}>
     */
    public static function malformedKeysProvider(): array
    {
        return [
            '項目が足りない' => [[['i_id_user' => 1, 'd_reservation_date' => '2024-09-07', 'i_id_room' => 1]]],
            '配列でない'     => [['x']],
        ];
    }

    /**
     * @dataProvider malformedKeysProvider
     * @param array<array-key, array<array-key, int|string|null>|string|int|null> $keys
     */
    public function testApprovalKeyRejectsMalformedKeys(array $keys): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApprovalKey::listFrom($keys);
    }
}
