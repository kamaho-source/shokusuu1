<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Event\EventInterface;
use Cake\Http\Response;
use Cake\I18n\Date;

/**
 * 外部システム（発注管理システム）向けに、部屋別・日別・食事区分別の予約食数を返す。
 *
 * ログインセッションを持たないサーバー間の呼び出しのため、認証・認可は行わず、
 * 環境変数 MEAL_COUNTS_API_TOKEN との照合を認可の代替とする（WebhooksController と同じ方式）。
 * 読み取り専用の GET のみなので CSRF / FormProtection の除外は不要。
 */
class MealCountsApiController extends ReservationBaseController
{
    private const MAX_DAYS = 62;

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['index']);
    }

    /**
     * GET /api/meal-counts?from=Y-m-d&to=Y-m-d
     * 返り値: {rooms: [{room_id, room_name}], items: [{date, room_id, room_name, meal_type, count}]}
     * Authorization: Bearer <MEAL_COUNTS_API_TOKEN>
     *
     * 食数は ReservationDatePolicy::countedValue() で数えるので、画面の集計と一致する。
     */
    public function index(): Response
    {
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod(['get']);

        $secret = (string)env('MEAL_COUNTS_API_TOKEN', '');
        $given = (string)preg_replace('/^Bearer\s+/i', '', $this->request->getHeaderLine('Authorization'));
        if ($secret === '' || !hash_equals($secret, $given)) {
            return $this->apiResponseService->error($this->response, 'Unauthorized', 401);
        }

        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$this->request->getQuery('from'));
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$this->request->getQuery('to'));
        if (!$from || !$to || $from > $to || $from->diff($to)->days > self::MAX_DAYS) {
            return $this->apiResponseService->error(
                $this->response,
                'from/to（Y-m-d、' . self::MAX_DAYS . '日以内）を指定してください。',
                400
            );
        }

        $table = $this->TIndividualReservationInfo;
        $alias = $table->getAlias();
        $rows = $table->find()
            ->enableAutoFields(false)
            ->select([
                'date'        => $alias . '.d_reservation_date',
                'room_id'     => $alias . '.i_id_room',
                'room_name'   => 'MRoomInfo.c_room_name',
                'meal_type'   => $alias . '.i_reservation_type',
                'eat_flag'    => $alias . '.eat_flag',
                'change_flag' => $alias . '.i_change_flag',
                'n'           => $table->find()->func()->count('*'),
            ])
            ->innerJoinWith('MRoomInfo')
            ->where([
                $alias . '.d_reservation_date >=' => $from->format('Y-m-d'),
                $alias . '.d_reservation_date <=' => $to->format('Y-m-d'),
                $alias . '.i_reservation_type IN' => [1, 2, 3, 4],
            ])
            ->groupBy([
                $alias . '.d_reservation_date',
                $alias . '.i_id_room',
                'MRoomInfo.c_room_name',
                $alias . '.i_reservation_type',
                $alias . '.eat_flag',
                $alias . '.i_change_flag',
            ])
            ->enableHydration(false)
            ->toArray();

        $items = [];
        foreach ($rows as $row) {
            $date = $row['date'] instanceof Date ? $row['date'] : new Date((string)$row['date']);
            if ($this->datePolicy->countedValue($date, $row['eat_flag'], $row['change_flag']) !== 1) {
                continue;
            }
            $key = $date->format('Y-m-d') . '|' . $row['room_id'] . '|' . $row['meal_type'];
            $items[$key] ??= [
                'date'      => $date->format('Y-m-d'),
                'room_id'   => (int)$row['room_id'],
                'room_name' => (string)$row['room_name'],
                'meal_type' => (int)$row['meal_type'],
                'count'     => 0,
            ];
            $items[$key]['count'] += (int)$row['n'];
        }
        ksort($items);

        // 予約が0件の部屋も対応付けできるよう、部屋一覧も返す
        $rooms = $this->MRoomInfo->find()
            ->select(['room_id' => 'i_id_room', 'room_name' => 'c_room_name'])
            ->where(['i_del_flg' => 0])
            ->orderBy(['i_disp_no' => 'ASC', 'i_id_room' => 'ASC'])
            ->enableHydration(false)
            ->toArray();

        return $this->apiResponseService->success($this->response, [
            'rooms' => array_map(fn($r) => ['room_id' => (int)$r['room_id'], 'room_name' => (string)$r['room_name']], $rooms),
            'items' => array_values($items),
        ]);
    }
}
