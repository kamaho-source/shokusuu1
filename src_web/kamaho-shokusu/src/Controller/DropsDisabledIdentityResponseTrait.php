<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Event\EventInterface;

/**
 * /MUserInfo/... の URL で届く画面（MUserInfo・UserImport・UserPassword・UserRole）用。
 *
 * 分割前、MUserInfoController::beforeFilter() は AppController::beforeFilter() の戻り値（無効化された人の締め出し＝
 * ログイン画面への転送）を返していなかった。そのため転送は使われず、ログアウト済みになった人は認証ミドルウェアが
 * 「未ログイン」としてログイン画面へ転送した。この転送には ?redirect=（元の URL）が付き、ログイン後に元の画面へ戻れる。
 * 分割で戻り値を返すようになると ?redirect= が消えるため、分割前と同じに戻す。
 * 締め出し（ログアウト・Flash）自体は AppController::beforeFilter() で行われる。
 *
 * このトレイトを使うクラスの親は AppController であること。
 */
trait DropsDisabledIdentityResponseTrait
{
    public function beforeFilter(EventInterface $event)
    {
        // 戻り値を返さない（分割前と同じ）
        parent::beforeFilter($event);
    }
}
