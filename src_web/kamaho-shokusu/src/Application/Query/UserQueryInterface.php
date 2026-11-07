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
     * 利用者の一覧・詳細に出す、所属している部屋名。退出済み(active_flag=1)と削除済み部屋は含めない（編集画面でチェックが付く部屋と同じ基準）。
     * 削除済みの利用者は、削除の直前に所属していた部屋（削除で外した所属）を出す。復元で所属中に戻る部屋と同じ集合
     * （部屋異動で外れた昔の部屋は出さない。見分け方は DeletedMembershipRule）。
     *
     * @param list<int> $userIds
     * @return array<int, list<string>> 利用者ID => 部屋名（渡した順。居ない利用者は空）
     */
    public function activeRoomNames(array $userIds): array;

    /**
     * 編集画面で選択済みにする部屋（所属中の所属の部屋。active_flag が NULL のものも所属中とみなす。削除済みの部屋も含む）。
     *
     * @return list<int>
     */
    public function currentRoomIds(int $userId): array;

    /**
     * 操作する人が選べる部屋（自分が所属している、削除されていない部屋）。管理者・システム管理者も同じ（#821）。
     *
     * @return array<int, string> 部屋ID => 部屋名
     */
    public function selectableRoomsFor(int $actorId): array;

    /**
     * 管理者がパスワードを変える相手として選べる人（削除されていない人）。
     *
     * @param bool $includeSystemAdmins システム管理者も出すか（操作者がシステム管理者のときだけ true）
     * @return array<int, string> 利用者ID => 氏名
     */
    public function passwordResetTargets(bool $includeSystemAdmins): array;
}
