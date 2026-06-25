<?php
declare(strict_types=1);

namespace App\Application\AI;

/**
 * AI助手がシステムプロンプトを組み立てる際に使用するロール識別子。
 *
 * Domain の UserRole（i_admin 値）と 1:1 に対応するが、
 * AI プロンプト生成という Application 層の関心事として分離する。
 */
final class AiUserRole
{
    /** 利用者本人（i_user_level = 1） */
    public const CHILD = 'child';

    /** 一般職員（i_admin = 0, i_user_level = 0） */
    public const GENERAL = 'general';

    /** ブロック長（i_admin = 2） */
    public const BLOCK_LEADER = 'block_leader';

    /** 管理者・システム管理者（i_admin = 1 または 3） */
    public const ADMIN = 'admin';

    private function __construct() {}
}
