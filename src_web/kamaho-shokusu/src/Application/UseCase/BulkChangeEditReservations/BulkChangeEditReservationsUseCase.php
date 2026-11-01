<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkChangeEditReservations;

use App\Application\Port\ReservationDatePolicyInterface;
use App\Application\Port\TransactionManagerInterface;
use App\Application\UseCase\BulkReservation\BulkReservationSupport;
use App\Domain\Entity\MealReservation;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\ReservationValidationException;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\MealType;
use App\Domain\ValueObject\UserRole;
use App\Domain\ValueObject\UserRoleInfo;
use Cake\Chronos\ChronosDate;
use Psr\Log\LoggerInterface;

/**
 * 直前一括編集（部屋 × 日付 × 利用者 × 食事）を保存する。
 *
 * 画面は変わっていない行も送るので、数える値が変わる行だけを書く。
 * 次の行は送信全体を止めず、skipped に理由を積んで飛ばす（ok=true のまま返す）。
 *   - 管理者以外が、過去日の行を変えようとした
 *   - 管理者以外が、職員の直前期間の予約を取り消そうとした
 * 職員の直前期間の昼食⇔弁当の入れ替え、権限のない変更、競合、承認済み、昼食と弁当の同時予約は ok=false で返す。
 */
final class BulkChangeEditReservationsUseCase
{
    private const SAVE_ERROR_MESSAGE = '直前編集の保存中にエラーが発生しました。';

    private const NO_PERMISSION_MESSAGE = '他ユーザーの予約を更新する権限がありません。';

    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly RoomMembershipRepositoryInterface $rooms,
        private readonly UserRepositoryInterface $users,
        private readonly TransactionManagerInterface $transaction,
        private readonly ReservationDatePolicyInterface $datePolicy,
        private readonly BulkReservationSupport $support,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{ok: bool, message?: string, updated?: int, created?: int, skipped?: list<string>, conflict_date?: string}
     * @throws \App\Domain\Exception\PersistenceException 部屋の読み取りに失敗した場合
     */
    public function execute(BulkChangeEditReservationsInput $input): array
    {
        $dayUsers    = $input->dayUsers;
        $roomId      = $input->roomId;
        $loginUserId = $input->loginUserId;
        $isAdmin     = $input->isAdmin;

        if (!$roomId || empty($dayUsers)) {
            return [
                'ok' => false,
                'message' => '部屋または予約内容が指定されていません。',
            ];
        }

        // ログインユーザーを特定できない場合は権限判定できないため拒否する（fail-closed）
        if ($loginUserId <= 0) {
            return [
                'ok'      => false,
                'message' => 'ログインユーザーを特定できないため処理を中断しました。',
            ];
        }

        // 存在しない・削除済みの部屋には、管理者でも書き込めない（管理者以外は有効な所属の判定で弾かれる）
        if ($isAdmin && !$this->rooms->isRoomAvailable($roomId)) {
            return [
                'ok'      => false,
                'message' => '選択された部屋情報が不正です。画面を再読み込みして再実行してください。',
            ];
        }

        // 過ぎた日（実績）は、管理者以外は書き換えさせない（数える値が i_change_flag のため、食数・控除表が変わる）。
        // 画面は変わっていない行も送り、保存しなかった過去日の選択が残ることもある。過去日は画面で直せないので、
        // 送信全体は止めず、実際に変わる行だけを理由付きで飛ばす
        $this->transaction->begin();
        try {
            $conflict = $this->support->conflictingSnapshotDate($roomId, $dayUsers, $input->snapshots);
            if ($conflict !== null) {
                $this->transaction->rollback();
                return [
                    'ok' => false,
                    'message' => '他のユーザーが同じ日付の予約を更新しました。最新の状態を読み込み直してください。',
                    'conflict_date' => $conflict,
                ];
            }

            $result = $this->applyChanges($input, ChronosDate::today('Asia/Tokyo'));
            if ($result['ok'] === false) {
                $this->transaction->rollback();
                return $result;
            }

            $this->transaction->commit();
            $this->support->forgetCachesForDates($roomId, array_keys($dayUsers), $dayUsers);

            return $result;
        } catch (ConflictException $e) {
            // 承認済み・別の部屋の予約と重なる・昼食と弁当の同時予約（有効にする更新）
            $this->transaction->rollback();
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        } catch (ReservationValidationException $e) {
            // 新しい行の検証ルール（昼食と弁当の同時予約など）に当たった。理由を返す
            $this->transaction->rollback();
            $this->logger->error('bulkChangeEditSubmit validation error: ' . $e->getMessage());
            return [
                'ok' => false,
                'message' => $e->lunchBentoMessage ?? self::SAVE_ERROR_MESSAGE,
            ];
        } catch (\RuntimeException $e) {
            $this->transaction->rollback();
            if ($e->getMessage() === 'optimistic_conflict') {
                return [
                    'ok' => false,
                    'message' => '他の操作と競合しました。画面を再読み込みして再実行してください。',
                ];
            }
            $this->logger->error('bulkChangeEditSubmit runtime error: ' . $e->getMessage());
            return [
                'ok' => false,
                'message' => self::SAVE_ERROR_MESSAGE,
            ];
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->logger->error('bulkChangeEditSubmit error: ' . $e->getMessage());
            return [
                'ok' => false,
                'message' => self::SAVE_ERROR_MESSAGE,
            ];
        }
    }

    /**
     * 変わる行を書く。止める理由があれば ok=false の結果を返す（書いた分は呼び出し側が取り消す）。
     *
     * @return array{ok: bool, message?: string, updated?: int, created?: int, skipped?: list<string>}
     * @throws ConflictException 承認済み・別の部屋の予約と重なる場合
     * @throws ReservationValidationException 新しい行が検証ルールに当たった場合
     * @throws \RuntimeException 楽観的ロック競合（optimistic_conflict）・読み取りの失敗
     */
    private function applyChanges(BulkChangeEditReservationsInput $input, ChronosDate $today): array
    {
        $dayUsers    = $input->dayUsers;
        $roomId      = $input->roomId;
        $loginName   = $input->loginName;
        $loginUserId = $input->loginUserId;
        $isAdmin     = $input->isAdmin;

        $updated = 0;
        $created = 0;
        $skipped = [];

        $dates   = array_keys($dayUsers);
        $userIds = [];
        foreach ($dayUsers as $users) {
            if (!is_array($users)) {
                continue;
            }
            foreach ($users as $userIdRaw => $_) {
                $userIds[(int)$userIdRaw] = true;
            }
        }
        $userIds = array_keys($userIds);

        // 見つかった利用者だけ。区分が未設定（NULL）の利用者は 0 として扱う（従来どおり）
        $userLevels = array_map(static fn(UserRoleInfo $info): int => (int)$info->userLevel, $this->users->findRoleInfos($userIds));

        $isLoginStaff      = UserRole::isStaffLevel($input->loginUserLevel);
        $blockLeaderInRoom = false;
        if ($input->isBlockLeader) {
            $blockLeaderInRoom = $this->rooms->isActiveMember($loginUserId, $roomId);
        }
        $existingMap = [];
        foreach ($this->reservations->findByUsersAndDates($dates, $userIds, MealType::values(), $roomId) as $row) {
            $existingMap[$row->date][$row->userId][$row->mealType] = $row;
        }

        $newRows = [];

        foreach ($dayUsers as $date => $users) {
            if (!is_array($users)) {
                continue;
            }
            foreach ($users as $userIdRaw => $meals) {
                $userId = (int)$userIdRaw;
                if (!is_array($meals)) {
                    continue;
                }
                $targetUserLevel = $userLevels[$userId] ?? null;

                // 取消を先に行う（昼食⇔弁当の入れ替えで、取り消す前の相手と「同時に有効」と判定されないようにする）
                uasort($meals, static fn($a, $b): int => ((int)$a === 1) <=> ((int)$b === 1));
                foreach ($meals as $mealTypeRaw => $checked) {
                    $mealType = (int)$mealTypeRaw;
                    if (MealType::tryFrom($mealType) === null) {
                        continue;
                    }
                    $changeFlag = (int)$checked;

                    $existing = $existingMap[$date][$userId][$mealType] ?? null;

                    if ($existing !== null) {
                        // 職員の直前取消は認めない（toggle・change-room と同じく、管理者は例外）
                        if (!$isAdmin && ReservationDateRule::isLastMinuteWindow(new ChronosDate((string)$date), $today)
                            && $targetUserLevel !== null && UserRole::isStaffLevel($targetUserLevel) && $changeFlag === 0
                        ) {
                            // 昼食⇔弁当の入れ替えは取消を伴うので、理由を返して止める
                            $opponent = MealType::counterpartValue($mealType);
                            if ($opponent !== null && (int)($meals[$opponent] ?? 0) === 1 && $existing->isEffective($today)) {
                                return [
                                    'ok'      => false,
                                    'message' => "利用者ID {$userId} は職員のため、直前期間はお昼とお弁当を入れ替えられません（予約の取消になるため）。",
                                ];
                            }
                            // 画面は変わっていない行も送るので、実際に取り消そうとした（今は食べる）ときだけ理由を返す
                            if ($existing->countedValue($today) === 1) {
                                $skipped[] = "{$date} の利用者ID {$userId} は職員のため、直前編集でのキャンセルはできません。";
                            }
                            continue;
                        }
                        // 15日以降は eat_flag で数えるので、eat_flag もそろえる（直前期間・過去日は i_change_flag だけを変える）
                        $isFar = !ReservationDateRule::usesChangeFlag(new ChronosDate((string)$date), $today);
                        // 数える値が変わる行だけを書く（i_change_flag が NULL の古い行を、変わっていないのに書き換えない）
                        if ($existing->countedValue($today) === $changeFlag) {
                            continue;
                        }
                        // 画面は部屋内全ユーザーの既存予約を送信するため、
                        // 権限チェックは値が実際に変わる行に限定する
                        // 取消（チェックOFF）は、対象が退出済みでも行えるよう所属を要求しない
                        if (!$this->support->canEditTargetUser($userId, $loginUserId, $isAdmin, $isLoginStaff, (int)($targetUserLevel ?? 0), $blockLeaderInRoom, $roomId, $changeFlag === 0)) {
                            return [
                                'ok'      => false,
                                'message' => self::NO_PERMISSION_MESSAGE,
                            ];
                        }
                        // 権限の判定のあとで見る（権限のない操作を「過去日」として ok にしない）
                        if (!$isAdmin && $this->datePolicy->isPastDate((string)$date)) {
                            $skipped[] = "{$date} は過去日のため、利用者ID {$userId} の予約は変更できません。";
                            continue;
                        }
                        if (!$this->reservations->updateWithVersion($existing, $changeFlag, $isFar ? $changeFlag : null, $loginName)) {
                            throw new \RuntimeException('optimistic_conflict');
                        }
                        $updated++;
                        continue;
                    }

                    if ($changeFlag === 0) {
                        continue;
                    }
                    if (!$this->support->canEditTargetUser($userId, $loginUserId, $isAdmin, $isLoginStaff, (int)($targetUserLevel ?? 0), $blockLeaderInRoom, $roomId)) {
                        return [
                            'ok'      => false,
                            'message' => self::NO_PERMISSION_MESSAGE,
                        ];
                    }
                    // 権限の判定のあとで見る（権限のない操作を「過去日」として ok にしない）
                    if (!$isAdmin && $this->datePolicy->isPastDate((string)$date)) {
                        $skipped[] = "{$date} は過去日のため、利用者ID {$userId} の予約は変更できません。";
                        continue;
                    }
                    $newRows[] = new MealReservation(
                        userId: $userId,
                        date: (string)$date,
                        roomId: $roomId,
                        mealType: $mealType,
                        // 15日以降は eat_flag で数えるので 1 にする（直前期間・過去日は当初予定なしの 0）
                        eatFlag: ReservationDateRule::usesChangeFlag(new ChronosDate((string)$date), $today) ? 0 : 1,
                        changeFlag: 1,
                    );
                    $created++;
                }
            }
        }

        if ($newRows !== []) {
            $this->reservations->addMany($newRows, $loginName);
        }

        return [
            'ok' => true,
            'updated' => $updated,
            'created' => $created,
            'skipped' => array_values(array_unique($skipped)),
        ];
    }
}
