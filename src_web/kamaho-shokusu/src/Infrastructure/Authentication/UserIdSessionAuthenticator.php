<?php
declare(strict_types=1);

namespace App\Infrastructure\Authentication;

use Authentication\Authenticator\PrimaryKeySessionAuthenticator;
use Authentication\Authenticator\ResultInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * セッションには利用者 ID（i_id_user）だけを保存し、リクエストのたびに DB から利用者を読み直す認証器。
 *
 * 以前は利用者の Entity をそのままセッションに保存していた。Entity のクラス名や置き場所を変えると、
 * 変える前にログインした人のセッションが読み戻せず、全画面（ログイン画面を含む）が 500 になる。
 * ID だけなら、クラスの移動や項目の増減に影響されない。
 *
 * 以前の形式のセッションも読めるようにし、読めたら ID だけの形に書き換える。
 * - Entity（今のクラス）や配列：i_id_user を取り出す
 * - 旧クラス名の Entity（クラスが無くなり __PHP_Incomplete_Class になったもの）：中の i_id_user を取り出す
 * 取り出せないものは未ログインとして扱い（セッションから消す）、例外にはしない。
 */
final class UserIdSessionAuthenticator extends PrimaryKeySessionAuthenticator
{
    public function authenticate(ServerRequestInterface $request): ResultInterface
    {
        $sessionKey = $this->getConfig('sessionKey');
        $session    = $request->getAttribute('session');
        $stored     = $session->read($sessionKey);

        if ($stored !== null && !is_int($stored)) {
            $userId = self::userIdFrom($stored, (string)$this->getConfig('idField'));
            if ($userId === null) {
                $session->delete($sessionKey);
            } else {
                $session->write($sessionKey, $userId);
            }
        }

        return parent::authenticate($request);
    }

    /**
     * セッションに入っている値から利用者 ID を取り出す。取り出せなければ null。
     */
    public static function userIdFrom(mixed $stored, string $idField): ?int
    {
        if ($stored instanceof \__PHP_Incomplete_Class) {
            // 復元できなかった Entity。保護プロパティ _fields は配列化すると "\0*\0_fields" というキーになる
            $props  = (array)$stored;
            $stored = $props["\0*\0_fields"] ?? [];
        }

        $value = null;
        if (is_array($stored) || $stored instanceof \ArrayAccess) {
            $value = $stored[$idField] ?? null;
        } elseif (is_int($stored) || is_string($stored)) {
            $value = $stored;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $id = (int)$value;

            return $id > 0 ? $id : null;
        }

        return null;
    }
}
