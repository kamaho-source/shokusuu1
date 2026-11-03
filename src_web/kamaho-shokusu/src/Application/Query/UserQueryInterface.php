<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Entity\UserAccount;

/**
 * 利用者の管理画面で使う読み取り（認可より先に 404 を返すための存在確認・画面の選択肢）。
 */
interface UserQueryInterface
{
    /** 利用者（削除済みも含む）。居なければ null。 */
    public function find(int $userId): ?UserAccount;

    /** 利用者が居るか（削除済みも含む）。 */
    public function exists(int $userId): bool;

    /** このログインIDの利用者が居るか（削除済みも含む。登録時の重複チェック＝CreateUserUseCase と同じ判定）。 */
    public function loginAccountExists(string $loginAccount): bool;

    /** このログインIDの利用者（削除済みも含む）。居なければ null。ログイン失敗の監査ログで、操作者を名前で残すために使う。 */
    public function findByLoginAccount(string $loginAccount): ?UserAccount;

    /** 削除されていない利用者が居るか。 */
    public function isActive(int $userId): bool;

    /**
     * 操作する人が選べる部屋（自分が所属している、削除されていない部屋）。管理者・システム管理者も同じ（#821）。
     *
     * @return array<int, string> 部屋ID => 部屋名
     */
    public function selectableRoomsFor(int $actorId): array;

    /**
     * 管理者がパスワードを変える相手として選べる人（削除されていない人）。
     *
     * @return array<int, string> 利用者ID => 氏名
     */
    public function passwordResetTargets(): array;
}
