<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\ApprovalController;
use App\Test\TestCase\Policy\TestRoomAccessService;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;

/**
 * ApprovalController::keysAreInMyBlocks の担当ブロック検証を確認する。
 *
 * 承認・差し戻しAPIは対象キーをリクエストから受け取るため、
 * ここで弾かないとブロック長が他ブロックの予約を承認・差し戻しできてしまう（IDOR）。
 */
class ApprovalControllerScopeTest extends TestCase
{
    /**
     * @param array<int, array<int>> $roomMap ユーザーID => 担当ブロックID一覧
     */
    private function callKeysAreInMyBlocks(array $keys, array $identity, array $roomMap): bool
    {
        $controller = new class (new ServerRequest()) extends ApprovalController {
            public function initialize(): void
            {
                // コンポーネント初期化は検証対象外のためスキップ
            }
        };

        $property = new \ReflectionProperty(ApprovalController::class, 'roomAccessService');
        $property->setValue($controller, new TestRoomAccessService($roomMap));

        $user = new class ($identity) {
            public function __construct(private array $data)
            {
            }

            public function get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }
        };

        $method = new \ReflectionMethod(ApprovalController::class, 'keysAreInMyBlocks');

        return (bool)$method->invoke($controller, $keys, $user);
    }

    private function key(int $roomId, int $userId = 5): array
    {
        return [
            'i_id_user'          => $userId,
            'd_reservation_date' => '2026-06-01',
            'i_id_room'          => $roomId,
            'i_reservation_type' => 1,
        ];
    }

    public function testBlockLeaderCanOperateOwnBlock(): void
    {
        $this->assertTrue($this->callKeysAreInMyBlocks(
            [$this->key(1), $this->key(2)],
            ['i_id_user' => 9, 'i_admin' => 2],
            [9 => [1, 2]]
        ));
    }

    public function testBlockLeaderCannotOperateOtherBlock(): void
    {
        $this->assertFalse(
            $this->callKeysAreInMyBlocks(
                [$this->key(1), $this->key(3)],
                ['i_id_user' => 9, 'i_admin' => 2],
                [9 => [1, 2]]
            ),
            '担当外ブロックの予約を承認・差し戻しできてはならない'
        );
    }

    public function testBlockLeaderWithoutBlocksIsRejected(): void
    {
        $this->assertFalse($this->callKeysAreInMyBlocks(
            [$this->key(1)],
            ['i_id_user' => 9, 'i_admin' => 2],
            []
        ));
    }

    public function testMissingRoomIdIsRejected(): void
    {
        $this->assertFalse(
            $this->callKeysAreInMyBlocks(
                [['i_id_user' => 5, 'd_reservation_date' => '2026-06-01', 'i_reservation_type' => 1]],
                ['i_id_user' => 9, 'i_admin' => 2],
                [9 => [1, 2]]
            ),
            'ブロックIDの無いキーは拒否すること（fail-closed）'
        );
    }

    public function testAdminIsExemptFromBlockScope(): void
    {
        $this->assertTrue(
            $this->callKeysAreInMyBlocks(
                [$this->key(7)],
                ['i_id_user' => 1, 'i_admin' => 1],
                []
            ),
            '管理者は全ブロックを操作できる'
        );
    }
}
