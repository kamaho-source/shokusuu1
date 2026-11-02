<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkImportUsers;

use App\Application\Port\AuditLogInterface;
use App\Application\Port\TransactionManagerInterface;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\Repository\UserAccountRepositoryInterface;
use App\Domain\Service\UserImportRule;
use App\Domain\ValueObject\UserProfileOptions;
use Psr\Clock\ClockInterface;

/**
 * 利用者を一括で登録する。行ごとに確かめ、登録できない行は理由を残して飛ばす。
 *
 * - 全体を1つのトランザクションで行い、DB の失敗があれば全部取り消して投げ直す。
 * - 管理者以外は、児童だけを、自分が所属している部屋にだけ登録できる。
 * - 終わったら件数を監査ログに残す（1件でも失敗があれば失敗として残す）。
 */
final class BulkImportUsersUseCase
{
    public function __construct(
        private readonly UserAccountRepositoryInterface $users,
        private readonly RoomMembershipRepositoryInterface $rooms,
        private readonly TransactionManagerInterface $transaction,
        private readonly AuditLogInterface $auditLog,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws \Throwable DB の失敗（取り消してから投げ直す）
     */
    public function execute(BulkImportUsersInput $input): BulkImportUsersOutput
    {
        $childOnly = !$input->actorIsAdmin;
        // 登録先は管理者も含めて自分が所属している部屋だけ（#821。登録画面と同じ）
        $allowedRoomIds = $this->rooms->activeRoomIds($input->actor->id);
        $createdBy = $input->actor->name;
        $nextDispNo = $this->users->nextDisplayNo();
        $created = $skipped = $failed = $processed = 0;
        $errors = [];

        $this->transaction->begin();
        try {
            foreach ($input->records as $rec) {
                $processed++;
                $rowNo     = (int)($rec['_row'] ?? 0);
                $loginId   = trim((string)($rec['login_id'] ?? ''));
                $name      = trim((string)($rec['name'] ?? ''));
                $roleRaw   = (string)($rec['role'] ?? '');
                $password  = (string)($rec['password'] ?? '');
                $staffId   = trim((string)($rec['staff_id'] ?? ''));
                $ageRaw    = (string)($rec['age'] ?? '');
                $roomNames = array_values(array_filter([trim((string)($rec['room_name1'] ?? '')), trim((string)($rec['room_name2'] ?? ''))], fn($n) => $n !== ''));

                if ($loginId === '' || $name === '' || $roleRaw === '') {
                    $failed++;
                    $errors[$rowNo][] = '必須項目（login_id, name, role）のいずれかが空です。';
                    continue;
                }
                if ($this->users->loginAccountExists($loginId)) {
                    $skipped++;
                    $errors[$rowNo][] = 'c_login_account が既に存在します。';
                    continue;
                }
                $level = UserImportRule::normalizeRole($roleRaw);
                $error = $this->rowError($level, $childOnly, $allowedRoomIds, $roomNames, $staffId);
                if ($error !== null) {
                    $failed++;
                    $errors[$rowNo][] = $error;
                    continue;
                }

                $data = [
                    'c_login_account' => $loginId,
                    'c_user_name'     => $name,
                    'i_user_level'    => $level,
                    'c_login_passwd'  => $password === '' ? bin2hex(random_bytes(6)) : $password,
                    'i_del_flag'      => 0,
                    'i_enable'        => 0,
                    'i_disp_no'       => $nextDispNo++,
                    'dt_create'       => $this->clock->now()->format('Y-m-d H:i:s'),
                    'c_create_user'   => $createdBy,
                ];
                if ($ageRaw !== '' && (int)$ageRaw > 0) {
                    $data['i_user_age'] = (int)$ageRaw;
                }
                $gender = UserImportRule::normalizeGender((string)($rec['i_user_gender'] ?? ($rec['gender'] ?? '')));
                if ($gender !== null) {
                    $data['i_user_gender'] = $gender;
                }
                $ageGroupRaw = (string)($rec['age_group'] ?? '');
                $ageGroup = $ageGroupRaw !== '' ? UserImportRule::normalizeAgeGroup($ageGroupRaw) : null;
                if ($ageGroup !== null) {
                    $data['i_user_rank'] = $ageGroup;
                }
                if ($level === UserProfileOptions::STAFF_LEVEL) {
                    $data['i_id_staff'] = $staffId;
                }

                $result = $this->users->importUser($data);
                if ($result->fieldErrors !== []) {
                    $failed++;
                    foreach ($result->fieldErrors as $field => $messages) {
                        foreach ($messages as $message) {
                            $errors[$rowNo][] = "{$field}: {$message}";
                        }
                    }
                    continue;
                }
                if (!$result->isCreated()) {
                    $failed++;
                    $errors[$rowNo][] = '保存に失敗しました。';
                    continue;
                }
                $created++;
                foreach ($roomNames as $roomName) {
                    $roomId = $this->rooms->roomIdByName($roomName, $allowedRoomIds);
                    if ($roomId === null) {
                        $errors[$rowNo][] = "部屋名 '{$roomName}' が見つかりません";
                        continue;
                    }
                    // 所属の保存の失敗は、従来どおり行の結果に含めない
                    $this->users->joinRoom((int)$result->userId, $roomId, $createdBy);
                }
            }
            $this->transaction->commit();
        } catch (\Throwable $e) {
            if ($this->transaction->inTransaction()) {
                $this->transaction->rollback();
            }
            throw $e;
        }

        $this->auditLog->record(
            'user',
            'user_bulk_import',
            $input->actor,
            'm_user_info',
            null,
            ['processed' => $processed, 'created' => $created, 'skipped' => $skipped, 'failed' => $failed],
            $failed === 0,
            $failed === 0 ? null : sprintf('一括登録で%d件が失敗しました（%d件成功・%d件スキップ）', $failed, $created, $skipped),
        );

        return new BulkImportUsersOutput($processed, $created, $skipped, $failed, $errors);
    }

    /**
     * 役職・登録先の部屋・職員IDの誤り。問題なければ null。確かめる順番は従来どおり。
     *
     * @param list<int> $allowedRoomIds 登録できる部屋（操作する人の所属部屋。管理者も同じ）
     * @param list<string> $roomNames
     */
    private function rowError(?int $level, bool $childOnly, array $allowedRoomIds, array $roomNames, string $staffId): ?string
    {
        if ($level === null) {
            return 'role の値が不正です（職員/児童/その他 または 0/1/3 を指定してください）。';
        }
        if ($childOnly && $level !== 1) {
            return '管理者以外は児童（role=児童）のみ登録できます。';
        }
        // 部屋の指定が必須なのは管理者以外（管理者は部屋なしで登録できる。登録画面と同じ）
        if ($childOnly && $allowedRoomIds === []) {
            return '所属している部屋がないため登録できません。';
        }
        if ($childOnly && $roomNames === []) {
            return '登録先の部屋（room_name1）が必要です。';
        }
        // 部屋名は重複し得るため、所属部屋の中から名前を解決する。見つからなければ拒否する
        foreach ($roomNames as $roomName) {
            if ($this->rooms->roomIdByName($roomName, $allowedRoomIds) === null) {
                return '自分が所属していない部屋、または存在しない部屋には登録できません。';
            }
        }
        if ($level === UserProfileOptions::STAFF_LEVEL && $staffId === '') {
            return 'role=職員 のため staff_id が必須です。';
        }

        return null;
    }
}
