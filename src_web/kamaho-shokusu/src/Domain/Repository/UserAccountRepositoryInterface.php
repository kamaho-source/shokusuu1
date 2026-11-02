<?php
declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\UserAccount;
use App\Domain\Entity\UserCreationResult;
use App\Domain\Entity\UserUpdateResult;

/**
 * 管理画面で利用者の権限などを読み書きする。
 *
 * DB の失敗（接続断など）は変換せずにそのまま投げる。
 */
interface UserAccountRepositoryInterface
{
    /** そのログインIDの利用者が居るか（削除済みも含める）。 */
    public function loginAccountExists(string $loginAccount): bool;

    /** 新しく登録する利用者の表示順（今の最大 + 1。誰も居なければ 1）。 */
    public function nextDisplayNo(): int;

    /**
     * 利用者を新しく保存し、指定した部屋に所属させる。
     *
     * 入力（ログインID・パスワード・氏名・作成者）はテーブルの入力チェックを通し、誤りがあれば保存せずに返す。
     * 所属の保存は、従来どおり利用者の保存のあとに行い、失敗しても利用者の保存は取り消さない。
     *
     * @param array<string, mixed> $attributes 入力チェックを通さずに入れる値（表示順・役職・年齢・性別・年代・職員ID・状態）
     * @param array<array-key, mixed> $input 入力チェックを通す値（送られた値。使うのは c_login_account・c_login_passwd・c_user_name・c_create_user だけ）
     * @param list<int> $roomIds 所属させる部屋
     */
    public function create(array $attributes, array $input, array $roomIds, string $createdBy): UserCreationResult;

    /** 利用者。居なければ null（削除済みも含めて探す）。 */
    public function find(int $userId): ?UserAccount;

    /**
     * 権限（i_admin）を変える。保存できなければ false（利用者が居ない場合も false）。
     */
    public function updateRole(int $userId, int $role, string $updatedBy): bool;

    /**
     * レポートの閲覧権（i_report_access）を変える。保存できなければ false。
     */
    public function updateReportAccess(int $userId, int $reportAccess, string $updatedBy): bool;

    /**
     * 削除済みにする（i_del_flag=1・ログイン不可）。保存できなければ false。
     *
     * @param string|null $at 更新日時（'Y-m-d H:i:s'）。省略なら今。削除で外す所属と同じ時刻にそろえるときに渡す
     */
    public function markDeleted(int $userId, string $updatedBy, ?string $at = null): bool;

    /**
     * 削除を取り消す（i_del_flag=0・ログイン可）。保存できなければ false。
     */
    public function markRestored(int $userId, string $updatedBy): bool;

    /**
     * 今所属している部屋（active_flag=0）を、すべて退出済み（1）にする。1行ずつ保存し、失敗しても続ける（従来どおり）。
     */
    public function leaveAllRooms(int $userId, string $updatedBy): void;

    /**
     * 利用者の削除のために、今所属している部屋（active_flag=0）をすべて退出済みにし、「削除で外した」印を付ける。
     * 1行でも保存できなければ false（そこで止める。取り消しは呼び出し側のトランザクションで行う）。
     *
     * @param string $at 更新日時（'Y-m-d H:i:s'）。利用者を削除済みにする時刻と同じにする
     */
    public function leaveAllRoomsForDeletion(int $userId, string $updatedBy, string $at): bool;

    /**
     * 削除で外した所属の部屋 ID（DeletedMembershipRule で見分ける）。復元・「削除前の所属」の表示に使う。
     *
     * @return list<int>
     */
    public function roomsLeftByDeletion(int $userId): array;

    /**
     * 削除済みで印の無い人（印の列を足す前に削除された人）の「削除で外した所属」に、時刻で見分けて印を付ける。
     * 利用者の行を更新すると時刻で見分けられなくなるため、削除済みの人を更新する前に呼ぶ。印がある人・削除されていない人には何もしない。
     */
    public function freezeLeftByDeletion(int $userId): void;

    /**
     * 指定した部屋の所属を所属中に戻し、「削除で外した」印を消す。1行でも保存できなければ false（そこで止める）。
     *
     * @param list<int> $roomIds
     */
    public function rejoinRooms(int $userId, array $roomIds, string $updatedBy): bool;

    /**
     * 部屋に所属させる（新しい行を作る）。保存できなければ false。
     */
    public function joinRoom(int $userId, int $roomId, string $createdBy): bool;

    /**
     * 編集画面の値でプロフィールを保存する。$fields に挙げた項目だけを変え、テーブルの入力チェック・ルールを通す。
     * 保存できたときは、保存前後のプロフィール（ログインID・氏名・性別・年代・役職・職員ID）を返す。
     *
     * @param array<string, mixed> $data
     * @param list<string> $fields 変えてよい項目
     */
    public function updateProfile(int $userId, array $data, array $fields): UserUpdateResult;

    /**
     * 今所属している部屋（active_flag=0）を、指定した部屋に差し替える（行を消して作り直す）。保存できなければ false。
     * 部屋異動で外れた旧所属（active_flag=1）は残す。ただし指定した部屋の旧所属の行は消して所属中で作り直す。
     *
     * @param list<int> $roomIds
     */
    public function replaceActiveRooms(int $userId, array $roomIds, string $updatedBy): bool;

    /**
     * 削除されていない利用者（i_del_flag=0）の氏名。パスワードを変える相手を選ぶのに使う。
     *
     * @return array<int, string> 利用者ID => 氏名
     */
    public function activeUserNames(): array;

    /**
     * パスワードを変える（保存時にハッシュ化される）。保存できなければ false（利用者が居ない場合も false）。
     */
    public function changePassword(int $userId, string $plainPassword): bool;

    /**
     * 一括登録の1行を保存する。すべての値をテーブルの入力チェックに通し、誤りがあれば保存せずに返す。
     * 所属部屋は保存しない（joinRoom で行う）。
     *
     * @param array<string, mixed> $data
     */
    public function importUser(array $data): UserCreationResult;
}
