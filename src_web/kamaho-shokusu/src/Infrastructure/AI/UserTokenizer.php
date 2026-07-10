<?php
declare(strict_types=1);

namespace App\Infrastructure\AI;

use Cake\Core\Configure;

/**
 * 利用者IDを外部AIへ渡すための仮名トークンへ変換する。
 *
 * 内部の連番主キー（i_id_user）をそのまま外部へ出さないため、
 * SECURITY_SALT を鍵とした HMAC-SHA256 でハッシュ化する。
 * 利用者数が少なくても、鍵を知らない外部からは元IDを逆算できない。
 *
 * 同一IDは常に同一トークンになる（決定論的）ため、
 * 画面側の「トークン→氏名」変換と一貫して対応づけできる。
 */
final class UserTokenizer
{
    /** トークンに使うハッシュの文字数（衝突しにくく、かつ短く保つ） */
    private const TOKEN_LENGTH = 12;

    private string $salt;

    /**
     * @param string|null $salt 省略時は Security.salt を使用する
     * @throws \RuntimeException Security.salt が未設定または空の場合
     */
    public function __construct(?string $salt = null)
    {
        $resolved = $salt ?? (string)Configure::read('Security.salt');
        if ($resolved === '') {
            throw new \RuntimeException('UserTokenizer: Security.salt が設定されていません。');
        }
        $this->salt = $resolved;
    }

    /**
     * 利用者IDを仮名トークン（16進文字列）へ変換する。
     *
     * @param int $userId 利用者ID（i_id_user）
     * @return string 仮名トークン
     */
    public function tokenize(int $userId): string
    {
        return substr(hash_hmac('sha256', (string)$userId, $this->salt), 0, self::TOKEN_LENGTH);
    }
}
