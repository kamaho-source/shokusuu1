<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\ValueObject\UserRole;
use Cake\ORM\TableRegistry;

/**
 * 予約履歴サービス（読み取り専用）
 *
 * 過去の予約の確認画面に必要な次のものを返す。何も書き込まない。
 *   - mealRows()   : 日×食事（朝昼夜弁当）の予約済み
 *   - roomSummary(): 部屋別・日別の予約済み人数
 *   - logRows()    : 変更の経緯（監査ログから）
 *
 * 実食機能はオフのため「食べた」ではなく「予約済み」かどうかを出す。判定は ReservationDatePolicy::countedValue()
 * （食数の報告・カレンダーと同じ基準）。過去日は直前編集の i_change_flag（NULL なら eat_flag）で決まる。
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
     * 見る人が対象者として選べる人（id => 名前）。先頭は本人。
     *   - 管理者・システム管理者 … 全員
     *   - ブロック長 … 自分が所属している部屋の大人と子ども
     *   - 職員 … 自分が所属している部屋の子ども（i_user_level=1）
     * 所属は有効な所属（active_flag=0）だけ。退職・削除済みの人は出さない。
     *
     * @param array{id:int, admin:int, level:?int, name:string} $actor
     * @return array<int, string>
     */
    public function selectableTargets(array $actor): array
    {
        $out = [$actor['id'] => $actor['name']];
        $locator = TableRegistry::getTableLocator();

        if (UserRole::isAdmin($actor['admin'])) {
            $users = $locator->get('MUserInfo')->find()
                ->select(['i_id_user', 'c_user_name'])
                ->where(['i_del_flag' => 0])
                ->orderBy(['i_disp_no' => 'ASC', 'i_id_user' => 'ASC'])
                ->enableHydration(false);
            foreach ($users as $u) {
                $out[(int)$u['i_id_user']] ??= (string)$u['c_user_name'];
            }

            return $out;
        }

        $isBlockLeader = UserRole::isBlockLeader($actor['admin']);
        $isStaff = $actor['level'] !== null && UserRole::isStaffLevel($actor['level']);
        if (!$isBlockLeader && !$isStaff) {
            return $out;
        }

        $groups = $locator->get('MUserGroup');
        $roomIds = $groups->find()
            ->select(['i_id_room'])
            ->where(['i_id_user' => $actor['id'], 'active_flag' => 0]);
        $members = $groups->find()
            ->enableAutoFields(false)
            ->select(['id' => 'MUserInfo.i_id_user', 'name' => 'MUserInfo.c_user_name', 'level' => 'MUserInfo.i_user_level'])
            ->innerJoin(['MUserInfo' => 'm_user_info'], ['MUserInfo.i_id_user = MUserGroup.i_id_user'])
            ->where([
                'MUserGroup.i_id_room IN' => $roomIds,
                'MUserGroup.active_flag' => 0,
                'MUserInfo.i_del_flag' => 0,
            ])
            ->orderBy(['MUserInfo.i_disp_no' => 'ASC', 'MUserInfo.i_id_user' => 'ASC'])
            ->enableHydration(false);
        foreach ($members as $m) {
            // ブロック長は大人も子どもも、職員は子どもだけ
            if (!$isBlockLeader && (int)($m['level'] ?? -1) !== 1) {
                continue;
            }
            $out[(int)$m['id']] ??= (string)$m['name'];
        }

        return $out;
    }

    /**
     * 日×食事の予約済み。予約済みの食事だけを返し、予約済みが1つも無い日は含めない。
     *
     * @return array<string, array<int, true>> 日付 => 食事種別 => true（日付順）
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
            // 部屋をまたいで同じ食事の行がある場合は、どれかが予約済みなら予約済み
            if ($this->datePolicy->countedValue($date, $r->eat_flag, $r->i_change_flag) === 1) {
                $out[$date][(int)$r->i_reservation_type] = true;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * 変更の経緯（新しい順）。監査ログのうち、対象者の予約の操作だけを、表示に必要な項目に絞って返す。
     * 実食機能はオフのため実食の操作（actual_meal_save）は含めない。
     * 操作者の名前は $withActorName が真のときだけ含める（IP・失敗理由は含めない）。
     *
     * 対象者の特定:
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
            $date = (string)($detail['date'] ?? '');
            $meal = (int)($detail['meal'] ?? 0);
            $kind = (int)($detail['value'] ?? 0) === 1 ? 'reserve' : 'cancel';
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
     * 部屋別・日別の予約済み人数。個人は出さない。予約済みが1人もいない日は含めない。
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
            if ($this->datePolicy->countedValue($date, $r->eat_flag, $r->i_change_flag) !== 1) {
                continue;
            }
            $type = (int)$r->i_reservation_type;
            $out[$date][$type] = ($out[$date][$type] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    /**
     * 変更の経緯の記録が始まった日（監査ログで最も古い日）。記録が1件も無ければ null。
     */
    public function logStartDate(): ?string
    {
        $row = TableRegistry::getTableLocator()->get('TAuditLog')->find()
            ->select(['first' => 'MIN(dt_create)'])
            ->enableHydration(false)
            ->first();
        $first = $row['first'] ?? null;

        return $first === null ? null : substr((string)$first, 0, 10);
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
