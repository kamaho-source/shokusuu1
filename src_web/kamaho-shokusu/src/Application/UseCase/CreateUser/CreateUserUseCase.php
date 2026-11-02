<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateUser;

use App\Application\Port\AuditLogInterface;
use App\Domain\Repository\UserAccountRepositoryInterface;
use App\Domain\Service\UserProfileRule;
use App\Domain\ValueObject\UserProfileOptions;
use Psr\Clock\ClockInterface;

/**
 * 利用者を新しく登録する。誰が登録してよいか（認可）は呼び出し側で確かめる。
 *
 * - 管理者以外（職員・ブロック長）は、児童だけを登録でき、部屋の選択は必須。
 * - 登録先の部屋は、管理者・システム管理者も含めて自分が所属している部屋だけ（#821）。
 * - 権限・状態系の項目（i_admin など）は送られた値から受け取らない。
 */
final class CreateUserUseCase
{
    public function __construct(
        private readonly UserAccountRepositoryInterface $users,
        private readonly AuditLogInterface $auditLog,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws \Throwable DB の失敗（変換せずにそのまま投げる）
     */
    public function execute(CreateUserInput $input): CreateUserOutput
    {
        $data  = $input->data;
        $level = $input->childOnly ? UserProfileRule::CHILD_LEVEL : (int)($data['role'] ?? 0);
        $roomIds = [];
        foreach (array_filter((array)($data['MUserGroup'] ?? []), 'is_array') as $group) {
            $roomIds[] = (int)($group['i_id_room'] ?? 0);
        }

        $error = UserProfileRule::creationError($input->childOnly, $data, $level)
            ?? UserProfileRule::roomsError($input->allowedRoomIds, $roomIds, $input->childOnly);
        if ($error !== null) {
            return CreateUserOutput::rejected($error);
        }
        if ($this->users->loginAccountExists((string)($data['c_login_account'] ?? ''))) {
            return CreateUserOutput::rejected('このログインIDは既に使用されています。他のIDをお試しください。');
        }

        $attributes = [
            'i_del_flag'    => 0,
            'dt_create'     => $this->clock->now()->format('Y-m-d H:i:s'),
            'i_enable'      => 0,
            'i_disp_no'     => $this->users->nextDisplayNo(),
            'i_user_age'    => (int)($data['age'] ?? 0),
            'i_user_level'  => $level,
            'i_user_gender' => (int)($data['i_user_gender'] ?? 0),
            'i_user_rank'   => (int)($data['age_group'] ?? 0),
        ];
        if ($level === UserProfileOptions::STAFF_LEVEL) {
            $attributes['i_id_staff'] = $data['staff_id'] ?? null;
        }

        $result = $this->users->create(
            $attributes,
            ['c_create_user' => $input->actor->name] + $data,
            array_values(array_filter($roomIds)),
            $input->actor->name,
        );
        if ($result->fieldErrors !== []) {
            return CreateUserOutput::invalidFields($result->fieldErrors);
        }
        if (!$result->isCreated()) {
            $this->auditLog->record(
                'user', 'user_create', $input->actor, 'm_user_info', null, ['error' => 'save failed'], false,
                'ユーザーの新規作成の保存に失敗しました（入力値エラーまたはDBエラー）'
            );

            return CreateUserOutput::saveFailed();
        }

        $this->auditLog->record(
            'user', 'user_create', $input->actor, 'm_user_info', (string)$result->userId,
            ['user_name' => $data['c_user_name'] ?? null, 'login_account' => $data['c_login_account'] ?? null], true
        );

        return CreateUserOutput::created();
    }
}
