<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\ApprovalQueryInterface;
use App\Application\Query\RoomQueryInterface;
use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\ApprovalStatus;
use Cake\Chronos\ChronosDate;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\Paging\Exception\PageOutOfBoundsException;
use Cake\Datasource\Paging\NumericPaginator;
use Cake\Http\Exception\NotFoundException;
use Cake\I18n\Date;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;
use Psr\Clock\ClockInterface;

/**
 * ApprovalQueryInterface の CakePHP 実装（t_individual_reservation_info・t_approval_log）。
 */
final class CakeApprovalQuery implements ApprovalQueryInterface
{
    /** 一覧に出す大人（職員、または i_user_level=7） */
    private const ADULT_CONDITION = [
        'OR' => [
            ['MUserInfo.i_id_staff IS NOT' => null],
            ['MUserInfo.i_user_level' => 7],
        ],
    ];

    private const LIST_ORDER = [
        'TIndividualReservationInfo.d_reservation_date' => 'ASC',
        'MRoomInfo.i_disp_no'                          => 'ASC',
        'MUserInfo.i_disp_no'                          => 'ASC',
        'TIndividualReservationInfo.i_reservation_type' => 'ASC',
    ];

    /** 集計の食事区分 => 列 */
    private const SUMMARY_COLUMNS = [1 => 'breakfast', 2 => 'lunch', 3 => 'dinner', 4 => 'bento'];

    public function __construct(
        private readonly RoomQueryInterface $rooms,
        private readonly ClockInterface $clock,
    ) {}

    public function getBlockLeaderList(
        int $userId,
        ?int $filterRoomId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $filterStatus = null,
    ): array {
        $roomIds = $this->rooms->getUserRoomIds($userId);
        if ($roomIds === []) {
            return [];
        }
        if ($filterRoomId !== null && in_array($filterRoomId, $roomIds, true)) {
            $roomIds = [$filterRoomId];
        }

        $query = $this->reservations(['MUserInfo', 'MRoomInfo'], $dateFrom, $dateTo)
            ->where([
                'TIndividualReservationInfo.i_id_room IN' => $roomIds,
                'TIndividualReservationInfo.i_approval_status' => $filterStatus ?? ApprovalStatus::PENDING,
            ])
            ->where(self::ADULT_CONDITION)
            ->orderBy(self::LIST_ORDER);

        return $this->entities($query);
    }

    public function getAdminList(
        ?int $filterRoomId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $filterStatus = null,
    ): array {
        $query = $this->reservations(['MUserInfo', 'MRoomInfo'], $dateFrom, $dateTo);
        if ($filterRoomId !== null) {
            $query->where(['TIndividualReservationInfo.i_id_room' => $filterRoomId]);
        }
        if ($filterStatus !== null) {
            $query->where(['TIndividualReservationInfo.i_approval_status' => $filterStatus]);
        }
        $query->where(self::ADULT_CONDITION)->orderBy(self::LIST_ORDER);

        return $this->entities($query);
    }

    public function getAdminSummary(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $rows = $this->entities(
            $this->reservations(['MRoomInfo'], $dateFrom, $dateTo)
                ->where(['TIndividualReservationInfo.i_approval_status' => ApprovalStatus::ADMIN])
        );
        $today = new ChronosDate($this->clock->now());

        $summary = [];
        foreach ($rows as $row) {
            $date = $row->get('d_reservation_date');
            $reservationDate = $date instanceof Date ? $date->format('Y-m-d') : (string)$date;
            $roomName = (string)($row->get('m_room_info')?->get('c_room_name') ?? '不明');
            $groupKey = $reservationDate . '|' . $roomName;
            $summary[$groupKey] ??= [
                'reservation_date' => $reservationDate,
                'room_name' => $roomName,
                'breakfast' => 0,
                'lunch' => 0,
                'dinner' => 0,
                'bento' => 0,
            ];
            // 直前期間の変更（i_change_flag）も、食数の報告・カレンダーと同じ基準で数える
            $eat = $row->get('eat_flag');
            $change = $row->get('i_change_flag');
            if (ReservationDateRule::countedValue(new ChronosDate($reservationDate), $eat === null ? null : (int)$eat, $change === null ? null : (int)$change, $today) !== 1) {
                continue;
            }
            $column = self::SUMMARY_COLUMNS[(int)$row->get('i_reservation_type')] ?? null;
            if ($column !== null) {
                $summary[$groupKey][$column]++;
            }
        }

        uasort($summary, static function (array $a, array $b): int {
            if ($a['reservation_date'] === $b['reservation_date']) {
                return strcmp($a['room_name'], $b['room_name']);
            }

            return strcmp($a['reservation_date'], $b['reservation_date']);
        });

        return array_values($summary);
    }

    public function countBlockLeaderPending(int $userId, ?string $dateFrom = null, ?string $dateTo = null): int
    {
        $roomIds = $this->rooms->getUserRoomIds($userId);
        if ($roomIds === []) {
            return 0;
        }

        return $this->reservations(['MUserInfo'], $dateFrom, $dateTo)
            ->where([
                'TIndividualReservationInfo.i_id_room IN' => $roomIds,
                'TIndividualReservationInfo.i_approval_status' => ApprovalStatus::PENDING,
            ])
            ->where(self::ADULT_CONDITION)
            ->count();
    }

    public function countAdminPending(?string $dateFrom = null, ?string $dateTo = null): int
    {
        return $this->reservations(['MUserInfo'], $dateFrom, $dateTo)
            ->where(['TIndividualReservationInfo.i_approval_status' => ApprovalStatus::BLOCK_LEADER])
            ->where(self::ADULT_CONDITION)
            ->count();
    }

    public function approvalLogs(int $viewerId, bool $isAdmin, array $queryParams): iterable
    {
        $query = TableRegistry::getTableLocator()->get('TApprovalLog')->find()
            ->contain(['MUserInfo', 'MRoomInfo', 'Approvers']);
        if (!$isAdmin) {
            $query->where([
                'OR' => [
                    'TApprovalLog.i_id_room IN' => $this->rooms->getUserRoomIds($viewerId),
                    'TApprovalLog.i_approver_id' => $viewerId,
                ],
            ]);
        }
        $query->orderBy(['TApprovalLog.dt_create' => 'DESC']);

        try {
            return (new NumericPaginator())->paginate($query, $queryParams, ['limit' => 50]);
        } catch (PageOutOfBoundsException $e) {
            // Controller::paginate() と同じく 404 にする
            throw new NotFoundException(null, null, $e);
        }
    }

    /**
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface> $query
     * @return list<\Cake\Datasource\EntityInterface>
     */
    private function entities(SelectQuery $query): array
    {
        $rows = [];
        foreach ($query->all() as $row) {
            if ($row instanceof EntityInterface) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $contain
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface>
     */
    private function reservations(array $contain, ?string $dateFrom, ?string $dateTo): SelectQuery
    {
        $query = TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()->contain($contain);
        if ($dateFrom !== null) {
            $query->where(['TIndividualReservationInfo.d_reservation_date >=' => $dateFrom]);
        }
        if ($dateTo !== null) {
            $query->where(['TIndividualReservationInfo.d_reservation_date <=' => $dateTo]);
        }

        return $query;
    }
}
