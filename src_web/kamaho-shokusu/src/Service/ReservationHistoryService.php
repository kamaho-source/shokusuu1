<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\ValueObject\UserRole;
use Cake\ORM\TableRegistry;

/**
 * 予約履歴サービス（読み取り専用）
 *
 * 「いつ食べたか」の確認画面に必要な次の3つを返す。何も書き込まない。
 *   - mealRows()   : 日×食事（朝昼夜弁当）の予約と実食
 *   - logRows()    : 変更の経緯（監査ログから）
 *   - roomSummary(): 部屋別・日別の食べた人数
 *
 * 「食べた」の判定は ReservationDatePolicy::countedValue() に一本化する
 * （食数の報告・カレンダー・承認の集計と同じ基準）。
 */
class ReservationHistoryService
{
    /** 期間の最大日数 */
    public const MAX_DAYS = 93;

    /** i_reservation_type と表示名 */
    public const MEAL_LABELS = [1 => '朝', 2 => '昼', 3 => '夜', 4 => '弁当'];

    /** 経緯の取得件数の上限。ponytail: 対象者1人の監査ログ上限。超える場合は期間を絞ってから探す作りに変える */
    private const LOG_LIMIT = 2000;

    private ReservationDatePolicy $datePolicy;

    private RoomAccessService $roomAccess;

    public function __construct(?ReservationDatePolicy $datePolicy = null, ?RoomAccessService $roomAccess = null)
    {
        $this->datePolicy = $datePolicy ?? new ReservationDatePolicy();
        $this->roomAccess = $roomAccess ?? new RoomAccessService();
    }

    /**
     * 期間の入力を検証する。
     *
     * @param string $from  開始日（Y-m-d）
     * @param string $to    終了日（Y-m-d）
     * @param string $today 今日（Y-m-d）。履歴は昨日以前だけ
     * @return string|null 問題があればその理由、なければ null
     */
    public function validatePeriod(string $from, string $to, string $today): ?string
    {
        if (!$this->isDate($from) || !$this->isDate($to)) {
            return '日付の形式が正しくありません。';
        }
        if ($from > $to) {
            return '開始日が終了日より後になっています。';
        }
        if ($to >= $today) {
            return '終了日は昨日以前にしてください（当日以降は予約画面で確認します）。';
        }
        $days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
        if ($days > self::MAX_DAYS) {
            return sprintf('期間は最大%d日までです。', self::MAX_DAYS);
        }

        return null;
    }

    /**
     * 操作する人が、対象者の履歴を見てよいか。
     * 本人・管理者は可。職員は子ども（i_user_level=1）のみ。#835 の代理判定と同じ。
     *
     * @param array{id:int, admin:int, level:?int} $actor  見る人
     * @param array{id:int, admin:int, level:?int} $target 見られる人
     * @return bool
     */
    public function canView(array $actor, array $target): bool
    {
        if ($actor['id'] === $target['id']) {
            return true;
        }
        if (UserRole::isAdmin($actor['admin'])) {
            return true;
        }

        return $actor['level'] !== null
            && UserRole::isStaffLevel($actor['level'])
            && $target['level'] === 1;
    }

    /**
     * ユーザー1人の見る側の情報（権限判定用）を返す。退職・削除済みは null。
     *
     * @return array{id:int, admin:int, level:?int, name:string}|null
     */
    public function findUser(int $userId): ?array
    {
        $u = TableRegistry::getTableLocator()->get('MUserInfo')->find()
            ->select(['i_id_user', 'c_user_name', 'i_admin', 'i_user_level'])
            ->where(['i_id_user' => $userId, 'i_del_flag' => 0])
            ->first();

        return $u === null ? null : $this->toUser($u);
    }

    /**
     * 見る人が対象者として選べる人（id => 名前）。canView() をそのまま使う。
     *
     * @param array{id:int, admin:int, level:?int} $actor
     * @return array<int, string>
     */
    public function selectableTargets(array $actor): array
    {
        $users = TableRegistry::getTableLocator()->get('MUserInfo')->find()
            ->select(['i_id_user', 'c_user_name', 'i_admin', 'i_user_level'])
            ->where(['i_del_flag' => 0])
            ->orderBy(['i_disp_no' => 'ASC', 'i_id_user' => 'ASC']);
        $out = [];
        foreach ($users as $u) {
            $t = $this->toUser($u);
            if ($this->canView($actor, $t)) {
                $out[$t['id']] = $t['name'];
            }
        }

        return $out;
    }

    /**
     * 日×食事の予約と実食。
     *
     * @return array<string, array<int, array{reserved:bool, ate:bool}>> 日付 => 食事種別 => 状態（日付順）
     */
    public function mealRows(int $userId, string $from, string $to): array
    {
        $rows = TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()
            ->select(['d_reservation_date', 'i_reservation_type', 'eat_flag', 'i_change_flag'])
            ->where(['i_id_user' => $userId, 'd_reservation_date >=' => $from, 'd_reservation_date <=' => $to])
            ->all();
        $out = [];
        foreach ($rows as $r) {
            $date = $r->d_reservation_date->format('Y-m-d');
            $type = (int)$r->i_reservation_type;
            $reserved = (int)$r->eat_flag === 1;
            $ate = $this->datePolicy->countedValue($date, $r->eat_flag, $r->i_change_flag) === 1;
            // 部屋をまたいで同じ食事の行がある場合は、どれかが真なら真
            $prev = $out[$date][$type] ?? ['reserved' => false, 'ate' => false];
            $out[$date][$type] = ['reserved' => $prev['reserved'] || $reserved, 'ate' => $prev['ate'] || $ate];
        }
        ksort($out);

        return $out;
    }

    /**
     * 変更の経緯（新しい順）。監査ログのうち、対象者の予約・実食の操作だけを、表示に必要な項目に絞って返す。
     * 操作者の名前は $withActorName が真のときだけ含める（IP・失敗理由は含めない）。
     *
     * 対象者の特定:
     *   - actual_meal_save … c_target_id が「ユーザーID:日付:部屋:食事」
     *   - reservation_toggle … detail.user_id。無い古い記録は、操作者＝対象者の本人操作とみなす
     * 代理の予約登録（reservation_individual_save）などは対象者が記録されていないため含まれない。
     *
     * @return array<int, array{at:string, date:string, meal:int, kind:string, actor:string}>
     */
    public function logRows(int $userId, string $from, string $to, bool $withActorName = false): array
    {
        $logs = TableRegistry::getTableLocator()->get('TAuditLog')->find()
            ->where([
                'i_result' => 1,
                'OR' => [
                    ['c_action' => 'actual_meal_save', 'c_target_id LIKE' => $userId . ':%'],
                    ['c_action' => 'reservation_toggle', 'c_detail LIKE' => '%"user_id":' . $userId . ',%'],
                    ['c_action' => 'reservation_toggle', 'c_detail LIKE' => '%"user_id":' . $userId . '}%'],
                    ['c_action' => 'reservation_toggle', 'i_actor_user_id' => $userId, 'c_detail NOT LIKE' => '%"user_id":%'],
                ],
            ])
            ->orderBy(['dt_create' => 'DESC', 'i_id_audit' => 'DESC'])
            ->limit(self::LOG_LIMIT)
            ->all();

        $adminCache = [];
        $out = [];
        foreach ($logs as $log) {
            $detail = json_decode((string)$log->c_detail, true);
            if (!is_array($detail)) {
                continue;
            }
            if ($log->c_action === 'actual_meal_save') {
                $parts = explode(':', (string)$log->c_target_id);
                $date = $parts[1] ?? '';
                $meal = (int)($detail['meal_type'] ?? 0);
                $kind = !empty($detail['checked']) ? 'ate' : 'not_ate';
            } else {
                $date = (string)($detail['date'] ?? '');
                $meal = (int)($detail['meal'] ?? 0);
                $kind = (int)($detail['value'] ?? 0) === 1 ? 'reserve' : 'cancel';
            }
            if ($date < $from || $date > $to || !isset(self::MEAL_LABELS[$meal])) {
                continue;
            }
            $actorId = (int)$log->i_actor_user_id;
            $role = $actorId === $userId
                ? '本人'
                : ($this->isAdminUser($actorId, $adminCache) ? '管理者' : '代理');
            $out[] = [
                'at' => $log->dt_create->format('Y-m-d H:i'),
                'date' => $date,
                'meal' => $meal,
                'kind' => $kind,
                'actor' => $withActorName ? $role . '（' . $log->c_actor_user_name . '）' : $role,
            ];
        }

        return $out;
    }

    /**
     * 部屋別・日別の「食べた」人数。個人は出さない。
     *
     * @return array<string, array<int, int>> 日付 => 食事種別 => 人数（日付順）
     */
    public function roomSummary(int $roomId, string $from, string $to): array
    {
        $rows = TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()
            ->select(['d_reservation_date', 'i_reservation_type', 'eat_flag', 'i_change_flag'])
            ->where(['i_id_room' => $roomId, 'd_reservation_date >=' => $from, 'd_reservation_date <=' => $to])
            ->all();
        $out = [];
        foreach ($rows as $r) {
            $date = $r->d_reservation_date->format('Y-m-d');
            $type = (int)$r->i_reservation_type;
            $out[$date][$type] = ($out[$date][$type] ?? 0)
                + $this->datePolicy->countedValue($date, $r->eat_flag, $r->i_change_flag);
        }
        ksort($out);

        return $out;
    }

    /**
     * 集計を見られる部屋（id => 名前）。事務所ユーザーは有効な全部屋、それ以外は有効な所属部屋（#821 と同じ）。
     *
     * @return array<int, string>
     */
    public function summaryRooms(int $userId): array
    {
        if ($this->roomAccess->isOfficeUser($userId)) {
            return $this->roomAccess->getAllActiveRooms();
        }

        return $this->roomAccess->getAccessibleRooms(TableRegistry::getTableLocator()->get('MRoomInfo'), $userId);
    }

    /**
     * @param \Cake\Datasource\EntityInterface $u
     * @return array{id:int, admin:int, level:?int, name:string}
     */
    private function toUser($u): array
    {
        return [
            'id' => (int)$u->i_id_user,
            'admin' => (int)$u->i_admin,
            'level' => $u->i_user_level === null ? null : (int)$u->i_user_level,
            'name' => (string)$u->c_user_name,
        ];
    }

    /**
     * @param array<int, bool> $cache
     */
    private function isAdminUser(int $userId, array &$cache): bool
    {
        if ($userId <= 0) {
            return false;
        }
        if (!isset($cache[$userId])) {
            $u = $this->findUser($userId);
            $cache[$userId] = $u !== null && UserRole::isAdmin($u['admin']);
        }

        return $cache[$userId];
    }

    private function isDate(string $s): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);

        return $d !== false && $d->format('Y-m-d') === $s;
    }
}
