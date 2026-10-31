<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkToggleMeals;

use App\Application\Port\ReservationCacheInterface;
use App\Application\UseCase\ToggleMeal\ToggleMealInput;
use App\Application\UseCase\ToggleMeal\ToggleMealUseCase;
use App\Domain\Exception\DomainException;

/**
 * 複数の予約トグルを1回でまとめて処理する（エクセル食数予約の一括保存）。
 *
 * - 各 item を ToggleMealUseCase で順に処理し、成否を item ごとに返す。1件の失敗（競合・権限など）は他の件に影響しない。
 * - 取消（value=0）を先に処理する。部屋を移す操作で「新しい部屋を ON」が先に来ると二重予約として拒否されるため。
 *   結果は送られた順番（キー）のまま返す。
 * - エクセル食数予約専用のため、管理者・システム管理者は所属外の部屋も操作できる（adminAnyRoom）。
 * - キャッシュの破棄は件数分ではなく、影響した日付ごとに最後へまとめて1回だけ行う。
 */
final class BulkToggleMealsUseCase
{
    public function __construct(
        private readonly ToggleMealUseCase $toggleMeal,
        private readonly ReservationCacheInterface $cache,
    ) {}

    /**
     * @param array<int, array{roomId?:int,i_id_room?:int,userId?:int,date?:string,meal?:int,value?:int}> $items
     * @return array<int, array{ok:bool,value?:bool,details?:array<mixed>,message?:string}> item と同じ順序の結果
     */
    public function execute(array $items, int $loginUserId, string $loginUserName): array
    {
        $results = [];
        /** @var array<string, list<int>> $usersByDate */
        $usersByDate = [];

        $ordered = array_values($items);
        uksort($ordered, static function (int $a, int $b) use ($ordered): int {
            $va = (int)($ordered[$a]['value'] ?? 1) === 0 ? 0 : 1;
            $vb = (int)($ordered[$b]['value'] ?? 1) === 0 ? 0 : 1;

            return [$va, $a] <=> [$vb, $b];
        });

        foreach ($ordered as $i => $item) {
            $itemUserId = (int)($item['userId'] ?? 0);
            $input = new ToggleMealInput(
                roomId: (int)($item['roomId'] ?? $item['i_id_room'] ?? 0),
                date: (string)($item['date'] ?? ''),
                meal: isset($item['meal']) ? (int)$item['meal'] : null,
                value: isset($item['value']) ? (int)$item['value'] : null,
                // userId が省略・0 のときは操作者本人（単件のトグルと同じ）
                targetUserId: $itemUserId > 0 ? $itemUserId : $loginUserId,
                loginUserId: $loginUserId,
                loginUserName: $loginUserName,
                adminAnyRoom: true,
                invalidateCache: false,
            );

            try {
                $out = $this->toggleMeal->execute($input);
                $results[$i] = ['ok' => true, 'value' => $out->value, 'details' => $out->details];
                $usersByDate[$out->date][] = $out->userId;
            } catch (DomainException $e) {
                $results[$i] = ['ok' => false, 'message' => $e->getMessage()];
            }
        }

        foreach ($usersByDate as $date => $userIds) {
            $this->cache->forgetDate((string)$date, $userIds);
        }
        ksort($results);

        return $results;
    }
}
