<?php
declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\ValueObject\UserRole;

/**
 * 利用者の権限（i_admin）を変えるときのルール。
 *
 * ブロック長・管理者・システム管理者は排他（1人1つまで）。
 */
final class UserRoleRule
{
    private const ROLE_LABELS = [
        UserRole::ADMIN        => '管理者',
        UserRole::BLOCK_LEADER => 'ブロック長',
        UserRole::SYSTEM_ADMIN => 'システム管理者',
    ];

    /**
     * 変えてよい権限の値か。システム管理者は、システム管理者専用の画面からだけ付けられる。
     */
    public static function isAssignable(int $role, bool $allowSystemAdmin): bool
    {
        $allowed = [UserRole::GENERAL, UserRole::ADMIN, UserRole::BLOCK_LEADER];
        if ($allowSystemAdmin) {
            $allowed[] = UserRole::SYSTEM_ADMIN;
        }

        return in_array($role, $allowed, true);
    }

    /**
     * 操作者が、対象の利用者の権限・パスワード・削除を扱ってよいか。システム管理者を扱えるのはシステム管理者だけ。
     *
     * @param int|null $actorRole  操作者の i_admin（操作者が見つからなければ null）
     * @param int|null $targetRole 対象の i_admin
     */
    public static function canManage(?int $actorRole, ?int $targetRole): bool
    {
        return !UserRole::isSystemAdmin((int)$targetRole) || UserRole::isSystemAdmin((int)$actorRole);
    }

    /**
     * 画面の確認なしに別の権限へ上書きしたり、別の権限を解除で消したりしないための確認。
     *
     * - 付与: 既に別の権限を持つ場合は $overwrite（画面で「本当に変更しますか？」に同意）が必要
     * - 解除: 解除対象（$offTargetRole）以外の権限を持つ場合は拒否（古い画面からの操作で他の権限を消さない）
     *
     * @param int  $currentRole   今の i_admin
     * @param int  $newRole       新しい権限（0 は解除）
     * @param int  $offTargetRole 解除の操作が対象とする権限（付与のときは $newRole と同じ）
     * @param bool $overwrite     別の権限からの変更を承認済みか
     * @return string|null 変えられない理由（問題なければ null）
     */
    public static function conflict(int $currentRole, int $newRole, int $offTargetRole, bool $overwrite): ?string
    {
        if ($newRole === $currentRole) {
            return null;
        }
        $currentLabel = self::ROLE_LABELS[$currentRole] ?? null;

        if ($newRole !== UserRole::GENERAL) {
            if ($currentLabel === null || $overwrite) {
                return null;
            }

            return sprintf(
                '現在は「%s」です。「%s」へ変更するには確認が必要です。画面を更新してやり直してください。',
                $currentLabel,
                self::ROLE_LABELS[$newRole] ?? '権限'
            );
        }

        if ($currentLabel === null || $currentRole === $offTargetRole) {
            return null;
        }

        return sprintf(
            '現在は「%s」のため、「%s」の解除は行えません。画面を更新してやり直してください。',
            $currentLabel,
            self::ROLE_LABELS[$offTargetRole] ?? '権限'
        );
    }
}
