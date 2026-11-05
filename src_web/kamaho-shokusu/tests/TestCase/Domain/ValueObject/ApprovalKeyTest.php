<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\ValueObject;

use App\Domain\ValueObject\ApprovalKey;
use PHPUnit\Framework\TestCase;

/**
 * 承認対象のキー（ApprovalKey）。
 */
class ApprovalKeyTest extends TestCase
{
    private const VALID = ['i_id_user' => '2', 'd_reservation_date' => '2024-09-08', 'i_id_room' => '1', 'i_reservation_type' => '3'];

    public function testFromArray_convertsTypes(): void
    {
        $key = ApprovalKey::fromArray(self::VALID);

        $this->assertSame([2, '2024-09-08', 1, 3], [$key->userId, $key->date, $key->roomId, $key->mealType]);
    }

    /** @dataProvider brokenKeys */
    public function testFromArray_rejectsBrokenKeys(array $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApprovalKey::fromArray($key);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function brokenKeys(): array
    {
        $missing = self::VALID;
        unset($missing['i_id_room']);

        return [
            '項目が足りない' => [$missing],
            // 配列は (string) で 'Array' になり別のキーとして扱われてしまう
            '日付が配列'     => [['d_reservation_date' => ['x']] + self::VALID],
            '部屋が配列'     => [['i_id_room' => [1]] + self::VALID],
        ];
    }

    public function testListFrom_rejectsNonArrayElement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApprovalKey::listFrom([self::VALID, 'not-a-key']);
    }
}
