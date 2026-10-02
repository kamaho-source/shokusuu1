<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ReservationVersionService;
use Cake\Http\Response;

/**
 * 予約データの更新有無を知らせる専用コントローラー。
 *
 * 画面を開いたままでも他の人の予約が反映されるよう、クライアントが
 * 短い間隔で問い合わせる。頻繁に呼ばれるため、DB は一切引かず
 * キャッシュ上の版数を返すだけに留めている。
 */
class ReservationSyncController extends ReservationBaseController
{
    /**
     * 予約データの現在の版数を返す。
     *
     * 予約が書き換わるたびに版数がひとつ進む。クライアントは前回取得した値と
     * 比べ、変わっていたときだけ本体のデータを取りに行く。
     *
     * @return Response|null
     */
    public function version(): ?Response
    {
        $this->request->allowMethod(['get']);

        // 一覧と同じ条件（ログイン済みであること）。返すのは整数ひとつのみ。
        if ($denied = $this->authorizeReservation('index', [], true)) {
            return $denied;
        }

        $version = ReservationVersionService::current();

        /*
         * セッションの書き込みを閉じてロックを解放する。
         *
         * PHP のファイルセッションはリクエスト中ずっとロックを保持するため、
         * 短い間隔で呼ばれるこの処理が、同じ利用者の画面遷移や保存を
         * 待たせてしまう。ここから先はセッションを触らないので先に閉じる。
         */
        $this->request->getSession()->close();

        return $this->apiResponseService->success($this->response, [
            'version' => $version,
        ]);
    }
}
