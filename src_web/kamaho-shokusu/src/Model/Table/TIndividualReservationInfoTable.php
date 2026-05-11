<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\Exception\PersistenceFailedException;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class TIndividualReservationInfoTable extends Table
{
    public const MEAL_BREAKFAST = 1;
    public const MEAL_LUNCH     = 2;
    public const MEAL_DINNER    = 3;
    public const MEAL_BENTO     = 4;

    /** 昼↔弁当の排他ペア [一方 => 他方] */
    private const MEAL_OPPONENTS = [
        self::MEAL_LUNCH  => self::MEAL_BENTO,
        self::MEAL_BENTO  => self::MEAL_LUNCH,
    ];

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
            if (!array_key_exists($type, self::MEAL_OPPONENTS)) {
                return true;
            }
            $date = $entity->d_reservation_date instanceof \DateTimeInterface
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
            $opponentType = self::MEAL_OPPONENTS[$type];

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
                if ((int)$r->i_reservation_type === self::MEAL_LUNCH)  $hasLunch = $hasLunch || ($eff === 1);
                if ((int)$r->i_reservation_type === self::MEAL_BENTO)  $hasBento = $hasBento || ($eff === 1);
            }

            // 今回エンティティの有効化も反映
            $thisEff = $isLastMinute
                ? (int)($entity->i_change_flag ?? $entity->eat_flag ?? 0)
                : (int)($entity->eat_flag ?? 0);

            if ($type === self::MEAL_LUNCH  && $thisEff === 1) $hasLunch = true;
            if ($type === self::MEAL_BENTO  && $thisEff === 1) $hasBento = true;

            return !($hasLunch && $hasBento);
        }, 'uniqueLunchBentoEffective', [
            'errorField' => 'i_reservation_type',
            'message' => '同じ日付で「昼」と「弁当」を同時に有効にはできません。'
        ]);

        return $rules;
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
     */
    public function toggleMeal(
        int $userId,
        int $roomId,
        string $date,
        int $meal,
        bool $on,
        string $actor,
        ?int $eatFlag = null,
        ?int $changeFlag = null,
        ?bool $useChangeFlag = null  // true=直前編集(i_change_flag), false=通常(eat_flag)
    ): array {
        $validMeals = [self::MEAL_BREAKFAST, self::MEAL_LUNCH, self::MEAL_DINNER, self::MEAL_BENTO];
        if (!in_array($meal, $validMeals, true)) {
            throw new \InvalidArgumentException('meal は 1(朝)/2(昼)/3(夜)/4(弁) のみ');
        }

        // useChangeFlag が未指定の場合のみフォールバック計算（後方互換）
        if ($useChangeFlag === null) {
            $today = Date::today();
            $d     = new Date($date);
            $useChangeFlag = ($d <= $today->addDays(14));
        }

        if ($eatFlag === null || $changeFlag === null) {
            $eatFlag    = $eatFlag    ?? ($on ? ($useChangeFlag ? 0 : 1) : 0);
            $changeFlag = $changeFlag ?? ($on ? 1 : 0);
        }

        $now = DateTime::now();

        return $this->getConnection()->transactional(function () use (
            $userId, $roomId, $date, $meal, $on, $actor, $now, $useChangeFlag, $eatFlag, $changeFlag
        ) {

            // 対象レコード取得（必要カラムのみ、autoFields無効化）
            $entity = $this->find()
                ->enableAutoFields(false)
                ->select([
                    'i_id_user','d_reservation_date','i_id_room','i_reservation_type',
                    'eat_flag','i_change_flag','i_version','dt_create','c_create_user','dt_update','c_update_user'
                ])
                ->where([
                    'i_id_user'          => $userId,
                    'd_reservation_date' => $date,
                    'i_id_room'          => $roomId,
                    'i_reservation_type' => $meal,
                ])
                ->first();

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

            // フラグの確定: ReservationDatePolicy の判定に従い書き込む列を決定する
            if ($useChangeFlag) {
                $entity->i_change_flag = (int)$changeFlag;                // 直前編集: i_change_flag を更新
                $entity->eat_flag      = (int)($entity->eat_flag ?? 0);   // eat_flag は既存値を保持
            } else {
                $entity->eat_flag      = (int)$eatFlag;                   // 通常予約: eat_flag を更新
                $entity->i_change_flag = (int)$changeFlag;                // i_change_flag も連動して更新
            }

            // 監査: 新規か更新かで分岐
            if ($isNew) {
                // 既に上で dt_create/c_create_user を設定済み。dt_update は触らない（NULLのまま）
                $this->saveOrFail($entity);
            } else {
                // 既存行: 更新情報のみ
                $expectedVersion = (int)($entity->i_version ?? 1);
                $nextVersion = $expectedVersion + 1;
                $affected = $this->updateAll([
                    'eat_flag'      => (int)$entity->eat_flag,
                    'i_change_flag' => (int)$entity->i_change_flag,
                    'dt_update'     => $now,
                    'c_update_user' => $actor,
                    'i_version'     => $nextVersion,
                ], [
                    'i_id_user'          => $userId,
                    'd_reservation_date' => $date,
                    'i_id_room'          => $roomId,
                    'i_reservation_type' => $meal,
                    'i_version'          => $expectedVersion,
                ]);
                if ($affected !== 1) {
                    $entity->setError('conflict', '予約が更新されています。画面を再読み込みしてください。');
                    throw new PersistenceFailedException($entity, 'Optimistic lock conflict.');
                }
                $entity->i_version = $nextVersion;
            }

            // 昼/弁の相互排他：ON にしたら相手は OFF
            if ($on && array_key_exists($meal, self::MEAL_OPPONENTS)) {
                $opponentMeal = self::MEAL_OPPONENTS[$meal];

                $opponent = $this->find()
                    ->enableAutoFields(false)
                    ->select([
                        'i_id_user','d_reservation_date','i_id_room','i_reservation_type',
                        'eat_flag','i_change_flag','i_version','dt_create','c_create_user','dt_update','c_update_user'
                    ])
                    ->where([
                        'i_id_user'          => $userId,
                        'd_reservation_date' => $date,
                        'i_id_room'          => $roomId,
                        'i_reservation_type' => $opponentMeal,
                    ])
                    ->first();

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

                if ($useChangeFlag) {
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
                    $oppExpectedVersion = (int)($opponent->i_version ?? 1);
                    $oppNextVersion = $oppExpectedVersion + 1;
                    $affected = $this->updateAll([
                        'eat_flag'      => (int)$opponent->eat_flag,
                        'i_change_flag' => (int)$opponent->i_change_flag,
                        'dt_update'     => $now,
                        'c_update_user' => $actor,
                        'i_version'     => $oppNextVersion,
                    ], [
                        'i_id_user'          => $userId,
                        'd_reservation_date' => $date,
                        'i_id_room'          => $roomId,
                        'i_reservation_type' => $opponentMeal,
                        'i_version'          => $oppExpectedVersion,
                    ]);
                    if ($affected !== 1) {
                        $opponent->setError('conflict', '予約が更新されています。画面を再読み込みしてください。');
                        throw new PersistenceFailedException($opponent, 'Optimistic lock conflict.');
                    }
                    $opponent->i_version = $oppNextVersion;
                }
            }

            // “有効値”詳細
            $details = $this->getDayDetailsEffective($userId, $roomId, $date, $useChangeFlag);

            $mealKeyMap = [
                self::MEAL_BREAKFAST => 'breakfast',
                self::MEAL_LUNCH     => 'lunch',
                self::MEAL_DINNER    => 'dinner',
                self::MEAL_BENTO     => 'bento',
            ];
            $mealKey = $mealKeyMap[$meal];

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
                'i_reservation_type IN' => [
                    self::MEAL_BREAKFAST,
                    self::MEAL_LUNCH,
                    self::MEAL_DINNER,
                    self::MEAL_BENTO,
                ],
            ])
            ->all();

        $details = ['breakfast' => false, 'lunch' => false, 'dinner' => false, 'bento' => false];

        $mealKeyMap = [
            self::MEAL_BREAKFAST => 'breakfast',
            self::MEAL_LUNCH     => 'lunch',
            self::MEAL_DINNER    => 'dinner',
            self::MEAL_BENTO     => 'bento',
        ];

        $effective = static function ($eatFlag, $chgFlag) use ($isLastMinute): int {
            if ($isLastMinute && $chgFlag !== null) return (int)$chgFlag;
            return (int)($eatFlag ?? 0);
        };

        foreach ($rows as $r) {
            $type = (int)$r->i_reservation_type;
            $key  = $mealKeyMap[$type] ?? null;
            if ($key !== null) {
                $details[$key] = $effective($r->eat_flag, $r->i_change_flag) === 1;
            }
        }
        return $details;
    }
}
