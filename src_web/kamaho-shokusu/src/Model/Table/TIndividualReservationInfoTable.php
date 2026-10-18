<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Exception\ApprovedReservationException;
use App\Exception\DuplicateMealReservationException;
use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\Exception\PersistenceFailedException;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class TIndividualReservationInfoTable extends Table
{
    /**
     * 「承認済み」とみなす i_approval_status の値（1=ブロック長承認, 2=管理者承認済み）。
     * この状態のレコードは予約内容を変更できない。
     */
    public const APPROVED_STATUSES = [1, 2];

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('t_individual_reservation_info');
        $this->setDisplayField('i_id_user');
        // 複合主キー（id カラムは存在しない）
        $this->setPrimaryKey(['i_id_user', 'd_reservation_date', 'i_id_room', 'i_reservation_type']);

        $this->belongsTo('MRoomInfo', ['foreignKey' => 'i_id_room', 'joinType' => 'INNER']);
        $this->belongsTo('MUserInfo', ['foreignKey' => 'i_id_user', 'joinType' => 'INNER']);
        $this->belongsTo('MUserGroup', ['foreignKey' => ['i_id_user', 'i_id_room'], 'joinType' => 'INNER']);
    }

    /**
     * 保存前の処理。
     *
     * - 同じ利用者・日・食事が、他の部屋ですでに有効なら拒否する（二重予約の防止）。
     *   実食の記録など、意図して対象外にする保存は $options['checkDuplicateRoom'] = false を渡す。
     * - マルチテナント環境の DB では tenant_id / facility_id が NOT NULL のため、新規行に既定値を補完する。
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event
     * @param \Cake\Datasource\EntityInterface $entity
     * @param \ArrayObject<string, mixed> $options
     * @throws \App\Exception\DuplicateMealReservationException 他の部屋で同じ食事が有効な場合
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        // 実食の記録は「実際に食べた部屋」を残すため、予約の二重チェックの対象外にできる
        if (($options['checkDuplicateRoom'] ?? true) !== false) {
            $this->assertNotEffectiveInOtherRoom(
                (int)$entity->i_id_user,
                $this->toDateString($entity->d_reservation_date),
                (int)$entity->i_reservation_type,
                (int)$entity->i_id_room,
                [(int)($entity->getOriginal('i_id_room') ?? $entity->i_id_room)],
                $entity->eat_flag,
                $entity->i_change_flag,
            );
        }

        if (!$entity->isNew()) {
            return;
        }

        foreach (['tenant_id' => 1, 'facility_id' => 1] as $column => $default) {
            if ($entity->get($column) !== null) {
                continue;
            }
            if ($this->getSchema()->getColumn($column) === null) {
                continue;
            }
            $entity->set($column, $default);
        }
    }

    public function validationDefault(Validator $validator): Validator
    {
        // 必須キー
        $validator
            ->integer('i_id_user')->requirePresence('i_id_user', 'create')->notEmptyString('i_id_user')
            ->date('d_reservation_date')->requirePresence('d_reservation_date', 'create')->notEmptyDate('d_reservation_date')
            ->integer('i_id_room')->requirePresence('i_id_room', 'create')->notEmptyString('i_id_room')
            ->integer('i_reservation_type')->requirePresence('i_reservation_type', 'create')->notEmptyString('i_reservation_type');

        // フラグ
        $validator
            ->integer('eat_flag')->allowEmptyString('eat_flag')
            ->integer('i_change_flag')->allowEmptyString('i_change_flag')
            ->integer('i_approval_status')->inList('i_approval_status', [0, 1, 2, 3])->allowEmptyString('i_approval_status')
            ->integer('i_version')->greaterThanOrEqual('i_version', 1)->allowEmptyString('i_version');

        // 監査
        $validator
            ->dateTime('dt_create')->allowEmptyDateTime('dt_create')
            ->scalar('c_create_user')->maxLength('c_create_user', 50)->allowEmptyString('c_create_user')
            ->dateTime('dt_update')->allowEmptyDateTime('dt_update')
            ->scalar('c_update_user')->maxLength('c_update_user', 50)->allowEmptyString('c_update_user');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        // 昼(2)⇔弁(4)の排他（その日の“有効値”で判定）
        $rules->add(function (EntityInterface $entity): bool {
            $type = (int)$entity->i_reservation_type;
            if (!in_array($type, [2, 4], true)) {
                return true;
            }
            $date = $entity->d_reservation_date instanceof Date
                ? Date::parseDate($entity->d_reservation_date->format('Y-m-d'), 'yyyy-MM-dd')
                : new Date((string)$entity->d_reservation_date);

            $today = Date::today();
            $isLastMinute = ($date >= $today && $date <= $today->addDays(14));

            $effective = static function ($row) use ($isLastMinute): int {
                if ($isLastMinute && $row->i_change_flag !== null) {
                    return (int)$row->i_change_flag;
                }
                return (int)($row->eat_flag ?? 0);
            };

            // 相手タイプ
            $opponentType = $type === 2 ? 4 : 2;

            // 必要カラムのみ取得
            $rows = $this->find()
                ->enableAutoFields(false)
                ->select(['i_reservation_type', 'eat_flag', 'i_change_flag', 'i_id_room'])
                ->where([
                    'i_id_user'             => $entity->i_id_user,
                    'd_reservation_date'    => $entity->d_reservation_date,
                    'i_reservation_type IN' => [$type, $opponentType],
                ])
                ->all();

            $hasLunch = false; $hasBento = false;
            foreach ($rows as $r) {
                $eff = $effective($r);
                if ((int)$r->i_reservation_type === 2) $hasLunch = $hasLunch || ($eff === 1);
                if ((int)$r->i_reservation_type === 4) $hasBento = $hasBento || ($eff === 1);
            }

            // 今回エンティティの有効化も反映
            $thisEff = $isLastMinute
                ? (int)($entity->i_change_flag ?? $entity->eat_flag ?? 0)
                : (int)($entity->eat_flag ?? 0);

            if ($type === 2 && $thisEff === 1) $hasLunch = true;
            if ($type === 4 && $thisEff === 1) $hasBento = true;

            return !($hasLunch && $hasBento);
        }, 'uniqueLunchBentoEffective', [
            'errorField' => 'i_reservation_type',
            'message' => '同じ日付で「昼」と「弁当」を同時に有効にはできません。'
        ]);

        return $rules;
    }

    /**
     * 予約行（d_reservation_date・eat_flag・i_change_flag を持つ）が有効かどうか。
     *
     * @param object $row 予約行（エンティティまたは select の結果）
     * @return bool
     */
    public static function isEffectiveRow(object $row): bool
    {
        $date = $row->d_reservation_date instanceof Date
            ? $row->d_reservation_date->format('Y-m-d')
            : (string)$row->d_reservation_date;

        return self::effectiveValue($date, $row->eat_flag ?? null, $row->i_change_flag ?? null) === 1;
    }

    /**
     * 同じ利用者・日付・食事で、有効な予約がある部屋IDを返す。
     *
     * 有効値は、直前期間（当日〜14日先）なら i_change_flag、それ以外は eat_flag（集計と同じ基準）。
     *
     * @return list<int>
     */
    public function findEffectiveRoomIds(int $userId, string $date, int $mealType): array
    {
        $rows = $this->find()
            ->enableAutoFields(false)
            ->select(['i_id_room', 'eat_flag', 'i_change_flag'])
            ->where([
                'i_id_user'          => $userId,
                'd_reservation_date' => $date,
                'i_reservation_type' => $mealType,
            ])
            ->all();

        $roomIds = [];
        foreach ($rows as $r) {
            if (self::effectiveValue($date, $r->eat_flag, $r->i_change_flag) === 1) {
                $roomIds[] = (int)$r->i_id_room;
            }
        }

        return $roomIds;
    }

    /**
     * 保存・更新後の行が有効になるとき、同じ食事が他の部屋で有効なら拒否する。
     *
     * @param list<int> $selfRoomIds 自分自身の行として除外する部屋（部屋を書き換える更新では元の部屋）
     * @throws \App\Exception\DuplicateMealReservationException 他の部屋で有効な予約がある場合
     */
    private function assertNotEffectiveInOtherRoom(
        int $userId,
        string $date,
        int $mealType,
        int $roomId,
        array $selfRoomIds,
        int|string|null $eatFlag,
        int|string|null $changeFlag
    ): void {
        $eat = $eatFlag === null ? null : (int)$eatFlag;
        $chg = $changeFlag === null ? null : (int)$changeFlag;
        if (self::effectiveValue($date, $eat, $chg) !== 1) {
            return;
        }
        $others = array_diff($this->findEffectiveRoomIds($userId, $date, $mealType), [$roomId], $selfRoomIds);
        if ($others === []) {
            return;
        }

        // 一括処理でも原因が分かるよう、誰の・いつの・どの食事かを文言に入れる（失敗時だけ名前を引く）
        $user = $this->MUserInfo->find()->select(['c_user_name'])->where(['i_id_user' => $userId])->first();
        $who = $user !== null ? (string)$user->c_user_name : ('利用者ID ' . $userId);
        $mealLabel = [1 => '朝食', 2 => '昼食', 3 => '夕食', 4 => '弁当'][$mealType] ?? '食事';
        throw new DuplicateMealReservationException(
            "{$who}さんの {$date} の{$mealLabel}は、別の部屋ですでに予約されています。画面を再読み込みしてください。"
        );
    }

    /**
     * 直前期間（当日〜14日先）かどうか。
     *
     * @param string $date YYYY-MM-DD
     * @return bool
     */
    public static function isLastMinute(string $date): bool
    {
        $d = new Date($date);
        $today = Date::today();

        return $d >= $today && $d <= $today->addDays(14);
    }

    /**
     * 予約行の有効値（1=食べる）を返す。直前期間（当日〜14日先）は i_change_flag、それ以外は eat_flag。
     *
     * ponytail: 有効値の基準は Table に置いている。Domain の値オブジェクトへ移すのは、判定を使う箇所がさらに増えたとき
     *
     * @param string $date YYYY-MM-DD
     * @param int|string|null $eatFlag
     * @param int|string|null $changeFlag
     * @return int 1=有効 / 0=無効
     */
    public static function effectiveValue(string $date, int|string|null $eatFlag, int|string|null $changeFlag): int
    {
        $eatFlag = $eatFlag === null ? null : (int)$eatFlag;
        $changeFlag = $changeFlag === null ? null : (int)$changeFlag;
        if (self::isLastMinute($date) && $changeFlag !== null) {
            return $changeFlag;
        }

        return (int)($eatFlag ?? 0);
    }

    private function toDateString(Date|string|null $date): string
    {
        return $date instanceof Date ? $date->format('Y-m-d') : (string)$date;
    }

    /**
     * 承認済みレコードかどうかを返す。
     *
     * @param int    $userId
     * @param string $date     YYYY-MM-DD
     * @param int    $roomId
     * @param int    $mealType 1=朝,2=昼,3=夜,4=弁
     */
    public function isApproved(int $userId, string $date, int $roomId, int $mealType): bool
    {
        return $this->exists([
            'i_id_user'          => $userId,
            'd_reservation_date' => $date,
            'i_id_room'          => $roomId,
            'i_reservation_type' => $mealType,
            'i_approval_status IN' => self::APPROVED_STATUSES,
        ]);
    }

    /**
     * 承認済みレコードであれば例外を投げる（予約変更の共通ガード）。
     *
     * @param int    $userId
     * @param string $date     YYYY-MM-DD
     * @param int    $roomId
     * @param int    $mealType 1=朝,2=昼,3=夜,4=弁
     * @param string|null $message 例外メッセージ（省略時は既定文言）
     * @throws \App\Exception\ApprovedReservationException 承認済みの場合
     */
    public function assertNotApproved(int $userId, string $date, int $roomId, int $mealType, ?string $message = null): void
    {
        if (!$this->isApproved($userId, $date, $roomId, $mealType)) {
            return;
        }

        throw $message === null
            ? new ApprovedReservationException()
            : new ApprovedReservationException($message);
    }

    /**
     * 楽観的ロック + 承認済み保護つきで予約1行を更新する。
     *
     * 予約行を書き換える経路はすべてこのメソッドを通すこと。
     * 承認済み保護を一箇所に集約し、経路ごとの実装漏れを防ぐ。
     *
     * @param object $row 更新対象行（複合主キー列と i_version を保持していること）
     * @param array{eat_flag?: int, i_change_flag?: int, i_id_room?: int, c_update_user?: string, dt_update?: \Cake\I18n\DateTime} $updateFields 更新する列と値
     * @return bool true=更新成功 / false=楽観的ロック競合
     * @throws \App\Exception\ApprovedReservationException 承認済み行を更新しようとした場合
     * @throws \App\Exception\DuplicateMealReservationException 更新で有効になる行が、他の部屋の有効な予約と重なる場合
     */
    public function updateRowWithVersion(object $row, array $updateFields): bool
    {
        $userId   = (int)$row->i_id_user;
        $roomId   = (int)$row->i_id_room;
        $mealType = (int)$row->i_reservation_type;
        $date     = $row->d_reservation_date instanceof Date
            ? $row->d_reservation_date->format('Y-m-d')
            : (string)$row->d_reservation_date;

        // 無効→有効になる更新と、部屋を書き換える更新だけを確かめる（有効なままの更新は、既存の二重予約があっても止めない）
        $roomChanged = isset($updateFields['i_id_room']) && (int)$updateFields['i_id_room'] !== $roomId;
        // toggleMeal などはエンティティに新しい値を入れてから呼ぶため、更新前の値（original）で「元は無効だったか」を見る
        $wasEffective = $row instanceof EntityInterface
            ? self::effectiveValue($date, $row->getOriginal('eat_flag'), $row->getOriginal('i_change_flag')) === 1
            : self::isEffectiveRow($row);
        if ($roomChanged || !$wasEffective) {
            $this->assertNotEffectiveInOtherRoom(
                $userId,
                $date,
                $mealType,
                (int)($updateFields['i_id_room'] ?? $roomId),
                [$roomId],
                $updateFields['eat_flag'] ?? $row->eat_flag ?? null,
                $updateFields['i_change_flag'] ?? $row->i_change_flag ?? null,
            );
        }

        $expectedVersion = (int)($row->i_version ?? 1);
        $set = $updateFields;
        $set['i_version'] = $expectedVersion + 1;

        $affected = $this->updateAll($set, [
            'i_id_user'          => $userId,
            'd_reservation_date' => $date,
            'i_id_room'          => $roomId,
            'i_reservation_type' => $mealType,
            'i_version'          => $expectedVersion,
            // 承認済み行は更新対象から除外する（i_approval_status が NULL の行は未承認扱い）
            'OR' => [
                'i_approval_status IS'     => null,
                'i_approval_status NOT IN' => self::APPROVED_STATUSES,
            ],
        ]);

        if ($affected === 1) {
            return true;
        }

        // 更新できなかった理由が「承認済み」なのか「競合」なのかを切り分ける
        $this->assertNotApproved($userId, $date, $roomId, $mealType);

        return false;
    }

    /**
     * トグル（直前: i_change_flag のみ / 通常: eat_flag のみ）
     *
     * @param int    $userId
     * @param int    $roomId
     * @param string $date   YYYY-MM-DD
     * @param int    $meal   1=朝,2=昼,3=夜,4=弁
     * @param bool   $on     true=ON / false=OFF
     * @param string $actor
     * @param int|null $eatFlag 上書き用（コントローラから明示指定）
     * @param int|null $changeFlag 上書き用（コントローラから明示指定）
     * @return array{ value: bool, details: array{breakfast:bool,lunch:bool,dinner:bool,bento:bool} }
     * @throws \InvalidArgumentException $meal が 1〜4 以外の場合
     * @throws \App\Exception\ApprovedReservationException 対象または相互排他の相手が承認済みの場合
     * @throws \App\Exception\DuplicateMealReservationException 他の部屋で同じ食事が有効な場合
     * @throws \Cake\ORM\Exception\PersistenceFailedException 新規保存失敗、または楽観的ロック競合の場合
     */
    public function toggleMeal(
        int $userId,
        int $roomId,
        string $date,
        int $meal,
        bool $on,
        string $actor,
        ?int $eatFlag = null,
        ?int $changeFlag = null
    ): array {
        if (!in_array($meal, [1, 2, 3, 4], true)) {
            throw new \InvalidArgumentException('meal は 1(朝)/2(昼)/3(夜)/4(弁) のみ');
        }

        $today = Date::today();
        $d     = new Date($date);
        $isLastMinute = ($d >= $today && $d <= $today->addDays(14));

        // コントローラから明示があればそれを、無ければ規定ロジックで
        if ($eatFlag === null || $changeFlag === null) {
            $eat  = $on ? ($isLastMinute ? 0 : 1) : 0;
            $chg  = $on ? 1 : 0;
            $eatFlag    = $eatFlag    ?? $eat;
            $changeFlag = $changeFlag ?? $chg;
        }

        $now = DateTime::now();

        return $this->getConnection()->transactional(function () use (
            $userId, $roomId, $date, $meal, $on, $actor, $now, $isLastMinute, $eatFlag, $changeFlag
        ) {

            // 対象レコード取得（必要カラムのみ、autoFields無効化）
            $entity = $this->find()
                ->enableAutoFields(false)
                ->select([
                    'i_id_user','d_reservation_date','i_id_room','i_reservation_type',
                    'eat_flag','i_change_flag','i_version','dt_create','c_create_user','dt_update','c_update_user',
                    'i_approval_status'
                ])
                ->where([
                    'i_id_user'          => $userId,
                    'd_reservation_date' => $date,
                    'i_id_room'          => $roomId,
                    'i_reservation_type' => $meal,
                ])
                ->first();

            if ($entity && in_array((int)($entity->i_approval_status ?? 0), self::APPROVED_STATUSES, true)) {
                throw new ApprovedReservationException();
            }

            $isNew = false;
            if (!$entity) {
                $entity = $this->newEmptyEntity();
                $entity->i_id_user          = $userId;
                $entity->d_reservation_date = $date;
                $entity->i_id_room          = $roomId;
                $entity->i_reservation_type = $meal;
                // 新規: 作成情報のみ設定（更新情報は設定しない）
                $entity->dt_create     = $now;
                $entity->c_create_user = $actor;
                $entity->i_version     = 1;
                $isNew = true;
            }

            // フラグの確定（NULL を残さない）
            if ($isLastMinute) {
                $entity->i_change_flag = (int)$changeFlag;                // 0/1
                $entity->eat_flag      = (int)($entity->eat_flag ?? 0);   // 既存NULLを0に
            } else {
                $entity->eat_flag      = (int)$eatFlag;                   // 0/1
                $entity->i_change_flag = (int)$changeFlag;                // 通常予約でも1にする
            }

            // 監査: 新規か更新かで分岐
            if ($isNew) {
                // 既に上で dt_create/c_create_user を設定済み。dt_update は触らない（NULLのまま）
                $this->saveOrFail($entity);
            } else {
                // 既存行: 更新情報のみ
                $nextVersion = (int)($entity->i_version ?? 1) + 1;
                $ok = $this->updateRowWithVersion($entity, [
                    'eat_flag'      => (int)$entity->eat_flag,
                    'i_change_flag' => (int)$entity->i_change_flag,
                    'dt_update'     => $now,
                    'c_update_user' => $actor,
                ]);
                if (!$ok) {
                    $entity->setError('conflict', '予約が更新されています。画面を再読み込みしてください。');
                    throw new PersistenceFailedException($entity, 'Optimistic lock conflict.');
                }
                $entity->i_version = $nextVersion;
            }

            // 昼/弁の相互排他：ON にしたら相手は OFF
            if ($on && in_array($meal, [2, 4], true)) {
                $opponentMeal = ($meal === 2) ? 4 : 2;

                $opponent = $this->find()
                    ->enableAutoFields(false)
                    ->select([
                        'i_id_user','d_reservation_date','i_id_room','i_reservation_type',
                        'eat_flag','i_change_flag','i_version','dt_create','c_create_user','dt_update','c_update_user',
                        'i_approval_status'
                    ])
                    ->where([
                        'i_id_user'          => $userId,
                        'd_reservation_date' => $date,
                        'i_id_room'          => $roomId,
                        'i_reservation_type' => $opponentMeal,
                    ])
                    ->first();

                // 相互排他で相手を強制OFFにするため、相手が承認済みなら変更を拒否する
                if ($opponent && in_array((int)($opponent->i_approval_status ?? 0), self::APPROVED_STATUSES, true)) {
                    throw new ApprovedReservationException(
                        $opponentMeal === 2
                            ? '同じ日の昼食予約が承認済みのため変更できません。'
                            : '同じ日の弁当予約が承認済みのため変更できません。'
                    );
                }

                $oppIsNew = false;
                if (!$opponent) {
                    $opponent = $this->newEmptyEntity();
                    $opponent->i_id_user          = $userId;
                    $opponent->d_reservation_date = $date;
                    $opponent->i_id_room          = $roomId;
                    $opponent->i_reservation_type = $opponentMeal;
                    // 新規: 作成情報のみ
                    $opponent->dt_create     = $now;
                    $opponent->c_create_user = $actor;
                    $opponent->i_version     = 1;
                    $oppIsNew = true;
                }

                if ($isLastMinute) {
                    $opponent->i_change_flag = 0;
                    $opponent->eat_flag      = (int)($opponent->eat_flag ?? 0);
                } else {
                    $opponent->eat_flag      = 0;
                    $opponent->i_change_flag = 0;
                }

                // 監査: 新規か更新かで分岐
                if ($oppIsNew) {
                    // dt_update は触らない
                    $this->saveOrFail($opponent);
                } else {
                    $oppNextVersion = (int)($opponent->i_version ?? 1) + 1;
                    $oppOk = $this->updateRowWithVersion($opponent, [
                        'eat_flag'      => (int)$opponent->eat_flag,
                        'i_change_flag' => (int)$opponent->i_change_flag,
                        'dt_update'     => $now,
                        'c_update_user' => $actor,
                    ]);
                    if (!$oppOk) {
                        $opponent->setError('conflict', '予約が更新されています。画面を再読み込みしてください。');
                        throw new PersistenceFailedException($opponent, 'Optimistic lock conflict.');
                    }
                    $opponent->i_version = $oppNextVersion;
                }
            }

            // “有効値”詳細
            $details = $this->getDayDetailsEffective($userId, $roomId, $date, $isLastMinute);

            $map = [1 => 'breakfast', 2 => 'lunch', 3 => 'dinner', 4 => 'bento'];
            $mealKey = $map[$meal];

            return [
                'value'   => (bool)$details[$mealKey],
                'details' => $details,
            ];
        });
    }

    /**
     * ある日の4食の“有効値”を返す（直前は i_change_flag 優先、通常は eat_flag）
     *
     * @param bool|null $isLastMinute null の場合は日付から自動判定
     * @return array{breakfast:bool,lunch:bool,dinner:bool,bento:bool}
     */
    public function getDayDetailsEffective(int $userId, int $roomId, string $date, ?bool $isLastMinute = null): array
    {
        if ($isLastMinute === null) {
            $today = Date::today();
            $d     = new Date($date);
            $isLastMinute = ($d >= $today && $d <= $today->addDays(14));
        }

        $rows = $this->find()
            ->enableAutoFields(false)
            ->select(['i_reservation_type', 'eat_flag', 'i_change_flag'])
            ->where([
                'i_id_user'             => $userId,
                'd_reservation_date'    => $date,
                'i_id_room'             => $roomId,
                'i_reservation_type IN' => [1,2,3,4],
            ])
            ->all();

        $details = ['breakfast'=>false,'lunch'=>false,'dinner'=>false,'bento'=>false];

        $effective = static function ($eatFlag, $chgFlag) use ($isLastMinute): int {
            if ($isLastMinute && $chgFlag !== null) return (int)$chgFlag;
            return (int)($eatFlag ?? 0);
        };

        foreach ($rows as $r) {
            $val = $effective($r->eat_flag, $r->i_change_flag) === 1;
            switch ((int)$r->i_reservation_type) {
                case 1: $details['breakfast'] = $val; break;
                case 2: $details['lunch']     = $val; break;
                case 3: $details['dinner']    = $val; break;
                case 4: $details['bento']     = $val; break;
            }
        }
        return $details;
    }
}