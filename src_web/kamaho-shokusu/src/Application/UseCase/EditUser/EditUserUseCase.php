<?php
declare(strict_types=1);

namespace App\Application\UseCase\EditUser;

use App\Application\Port\AuditLogInterface;
use App\Application\Port\TransactionManagerInterface;
use App\Domain\Entity\UserAccount;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\Repository\UserAccountRepositoryInterface;
use App\Domain\Service\UserProfileRule;
use App\Domain\ValueObject\UserProfileOptions;
use Psr\Clock\ClockInterface;

/**
 * 利用者の情報と所属部屋を編集する。誰が編集してよいか（認可）は呼び出し側で確かめる。
 *
 * - 年齢・権限・状態は誰も変えられない（送られても受け取らない）。
 * - 役職・職員IDは管理者が他のユーザーを編集するときだけ。年代は管理者だけ。性別・ログインID・所属部屋は管理者・本人。
 *   職員は自分の職員IDを変えられる。職員・ブロック長が児童を編集するときは氏名のみ。
 * - 削除済みの人の所属部屋は変えない（復元すると削除前の所属に戻る）。
 * - プロフィールと所属部屋は1つのトランザクションで保存し、どちらかが失敗したら両方取り消す。
 */
final class EditUserUseCase
{
    public function __construct(
        private readonly UserAccountRepositoryInterface $users,
        private readonly RoomMembershipRepositoryInterface $rooms,
        private readonly TransactionManagerInterface $transaction,
        private readonly AuditLogInterface $auditLog,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * 編集画面で選べる部屋。
     *
     * @return array<int, string> 部屋ID => 部屋名
     */
    public function roomChoices(): array
    {
        return $this->rooms->selectableRooms();
    }

    /**
     * @throws NotFoundException 対象の利用者が居ない場合
     * @throws \Throwable DB の失敗（取り消して、失敗を監査ログに残してから投げ直す）
     */
    public function execute(EditUserInput $input): EditUserOutput
    {
        $user = $this->users->find($input->userId);
        if ($user === null) {
            throw new NotFoundException('対象ユーザーが見つかりません。');
        }
        $posted = $input->posted;
        $data = [
            'c_login_account' => $input->canEditRooms ? ($posted['c_login_account'] ?? $user->loginAccount) : $user->loginAccount,
            'c_user_name'     => $posted['c_user_name'] ?? $user->name,
            'c_update_user'   => $input->actor->name,
            'dt_update'       => $this->clock->now()->format('Y-m-d H:i:s'),
        ];
        $fields = ['c_login_account', 'c_user_name', 'c_update_user', 'dt_update'];
        if ($input->canEditRooms) {
            $fields[] = 'i_user_gender';
            $data += array_intersect_key($posted, ['i_user_gender' => 1, 'i_id_staff' => 1]);
        }
        if ($input->actorIsAdmin && $input->canEditRooms) {
            $fields[] = 'i_user_rank';
            $data += array_intersect_key($posted, ['i_user_rank' => 1]);
        }
        if ($input->canChangeRole) {
            $fields = array_merge($fields, ['i_user_level', 'i_id_staff']);
            $data += array_intersect_key($posted, ['i_user_level' => 1]);
        }
        [$data, $error] = UserProfileRule::normalizeForEdit($data, $user->level);
        if (!$input->canChangeRole && $input->canEditRooms && $error === null && (int)$user->level === UserProfileOptions::STAFF_LEVEL) {
            $fields[] = 'i_id_staff';
            [$data, $error] = UserProfileRule::normalizeOwnStaffId($data);
        }
        if ($error !== null) {
            return EditUserOutput::rejected($error);
        }

        $roomIds = !$input->canEditRooms || $user->isDeleted()
            ? $this->rooms->activeRoomIds($user->id)
            : self::checkedRoomIds($posted);

        $this->transaction->begin();
        try {
            // 削除済みの人を更新すると、時刻で見分けていた「削除で外した所属」が分からなくなるため、先に印を付ける
            $this->users->freezeLeftByDeletion($user->id);
            $result = $this->users->updateProfile($user->id, $data, $fields);
            if (!$result->saved || !$this->users->replaceActiveRooms($user->id, $roomIds, $input->actor->name)) {
                $this->transaction->rollback();

                return EditUserOutput::saveFailed($result->fieldErrors, $roomIds);
            }
            $this->transaction->commit();
        } catch (\Throwable $e) {
            if ($this->transaction->inTransaction()) {
                $this->transaction->rollback();
            }
            $this->record($input, $user, ['error' => $e->getMessage()], false);
            throw $e;
        }

        $changes = self::diff($result->before, $result->after);
        $this->record($input, $user, ['user_name' => $result->after['c_user_name'] ?? null] + ($changes === [] ? [] : ['changes' => $changes]), true);

        return EditUserOutput::updated();
    }

    /**
     * 画面でチェックされた部屋（rooms[部屋ID] = '1'）。
     *
     * @param array<array-key, mixed> $posted
     * @return list<int>
     */
    private static function checkedRoomIds(array $posted): array
    {
        $roomIds = [];
        foreach ((array)($posted['rooms'] ?? []) as $roomId => $checked) {
            if ($checked === '1') {
                $roomIds[] = (int)$roomId;
            }
        }

        return $roomIds;
    }

    /**
     * 変わった項目だけを [項目 => [変更前, 変更後]] で返す。
     *
     * @param array<string, int|string|null> $before
     * @param array<string, int|string|null> $after
     * @return array<string, array{0: int|string|null, 1: int|string|null}>
     */
    private static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $value) {
            if ((string)($before[$field] ?? '') !== (string)($value ?? '')) {
                $changes[$field] = [$before[$field] ?? null, $value];
            }
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(EditUserInput $input, UserAccount $user, array $details, bool $succeeded): void
    {
        $this->auditLog->record('user', 'user_update', $input->actor, 'm_user_info', (string)$user->id, $details, $succeeded);
    }
}
