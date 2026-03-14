<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     0.2.9
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Controller;

use App\Service\DashboardService;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\View\Exception\MissingTemplateException;

/**
 * PagesController
 *
 * 静的コンテンツ(templates/Pages/ 以下のビューファイル)を表示するコントローラー。
 * CakePHP のデフォルト実装を拡張し、ダッシュボード専用アクションを追加している。
 *
 * 主な役割:
 *   - dashboard() アクション: 新ホーム(ダッシュボード)画面を表示する
 *   - display()   アクション: URL パスに対応するテンプレートを汎用的に表示する
 *   - buildDashboardViewVars(): ダッシュボードに必要なビュー変数を組み立てる(共通処理)
 *
 * 認証ポリシー:
 *   - display / dashboard は未ログインでもアクセス可能(allowUnauthenticated)。
 *     テンプレート側でログイン状態を判定して表示内容を切り替える。
 *   - 認可チェックは両アクションで skipAuthorization() しており、
 *     ロールベースの制限はテンプレート側の $isAdmin フラグで制御する。
 *
 * @link https://book.cakephp.org/4/en/controllers/pages-controller.html
 */
class PagesController extends AppController
{
    /**
     * 各アクション実行前に呼ばれるフィルター処理。
     *
     * display と dashboard アクションを「未認証でもアクセス可能」に設定する。
     * これにより未ログインユーザーがトップページへアクセスした場合でも
     * 認証ミドルウェアによるリダイレクトが発生せず、
     * テンプレート側でログイン促進メッセージを表示できる。
     *
     * @param EventInterface $event CakePHP イベントオブジェクト
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['display', 'dashboard']);
    }

    /**
     * ダッシュボード画面を表示するアクション。
     *
     * URL: / (ルートURL) または /pages/dashboard
     *
     * 処理の流れ:
     *   1. 認可チェックをスキップする(ロールによるアクセス制限なし)
     *   2. buildDashboardViewVars() でビュー変数を組み立てる
     *   3. templates/Pages/dashboard.php を描画して返す
     *
     * @return \Cake\Http\Response|null
     */
    public function dashboard(): ?Response
    {
        // 認可ポリシーの適用をスキップする(誰でもアクセス可能)
        $this->Authorization->skipAuthorization();

        // ダッシュボード用ビュー変数($dashboard, $hasTodayReport)をセットする
        $this->set($this->buildDashboardViewVars());

        // templates/Pages/dashboard.php を描画する
        return $this->render('dashboard');
    }

    /**
     * URL パスに対応する静的テンプレートを汎用的に表示するアクション。
     *
     * URL パスのセグメントをそのままテンプレートのパスとして使用する。
     * 例: /pages/home → templates/Pages/home.php を描画する
     *
     * セキュリティ対策:
     *   - パス内に「..」や「.」が含まれる場合は ForbiddenException をスローして
     *     ディレクトリトラバーサル攻撃を防ぐ。
     *
     * ダッシュボード統合:
     *   - $page が 'home' または 'dashboard' の場合は buildDashboardViewVars() を
     *     呼び出してダッシュボード用ビュー変数も合わせてセットする。
     *
     * エラーハンドリング:
     *   - 対応するテンプレートが存在しない場合、デバッグモードでは
     *     MissingTemplateException をそのままスローして詳細を表示する。
     *     本番環境では NotFoundException に変換して 404 画面を表示する。
     *
     * @param string ...$path URL から取得したパスセグメントの可変長引数
     * @return \Cake\Http\Response|null
     * @throws \Cake\Http\Exception\ForbiddenException ディレクトリトラバーサル試行時
     * @throws \Cake\View\Exception\MissingTemplateException デバッグ時にテンプレート未発見
     * @throws \Cake\Http\Exception\NotFoundException 本番時にテンプレート未発見
     */
    public function display(string ...$path): ?Response
    {
        // 認可ポリシーの適用をスキップする
        $this->Authorization->skipAuthorization();

        // パスが空の場合はトップページへリダイレクトする
        if (!$path) {
            return $this->redirect('/');
        }

        // ディレクトリトラバーサル攻撃対策:
        // 「..」(親ディレクトリ参照)や「.」(カレントディレクトリ)が
        // パスに含まれている場合は 403 エラーとする
        if (in_array('..', $path, true) || in_array('.', $path, true)) {
            throw new ForbiddenException();
        }

        // パスの第1・第2セグメントをそれぞれ $page・$subpage に格納する
        // 例: /pages/report/detail → $page='report', $subpage='detail'
        $page = $subpage = null;

        if (!empty($path[0])) {
            $page = $path[0];
        }
        if (!empty($path[1])) {
            $subpage = $path[1];
        }

        // $page と $subpage をビュー変数の基本セットとして用意する
        $viewVars = compact('page', 'subpage');

        // 'home' または 'dashboard' ページの場合は、ダッシュボード用の
        // 追加ビュー変数($dashboard, $hasTodayReport)をマージする
        if ($page === 'home' || $page === 'dashboard') {
            $viewVars += $this->buildDashboardViewVars();
        }

        $this->set($viewVars);

        try {
            // URL パスをスラッシュで結合してテンプレートパスとして渡す
            // 例: ['home'] → 'home' → templates/Pages/home.php を描画
            return $this->render(implode('/', $path));
        } catch (MissingTemplateException $exception) {
            // デバッグモードではそのまま例外を投げて詳細を開発者に見せる
            if (Configure::read('debug')) {
                throw $exception;
            }
            // 本番環境では 404 Not Found に変換してユーザーに適切なエラーを返す
            throw new NotFoundException();
        }
    }

    /**
     * ダッシュボード画面で使用するビュー変数を組み立てて返す。
     *
     * dashboard() と display() の両アクションから呼ばれる共通処理。
     * DashboardService に実際のデータ生成を委譲している。
     *
     * 返却する配列のキー:
     *   - hasTodayReport : bool  ログイン中ユーザーが本日の食数報告を済ませているか
     *                           (未ログイン時は常に false)
     *   - dashboard      : array DashboardService::buildHomeContext() の返却値
     *                           (今日の日付ラベル・各週の月曜日・フォーマット関数など)
     *
     * @return array<string, mixed>
     */
    private function buildDashboardViewVars(): array
    {
        // 認証済みユーザーオブジェクトを取得する(未ログイン時は null)
        $user = $this->Authentication->getIdentity();

        $dashboardService = new DashboardService();

        if ($user) {
            // ログイン済みの場合: ユーザーIDを取得してサービスを呼び出す
            $userId = (int)$user->get('i_id_user');

            // 当日の食数報告が完了しているかを DB + キャッシュで判定する
            // 所属部屋のいずれかに本日の予約が存在すれば「報告済み」と判定する
            $hasTodayReport = $dashboardService->hasTodayReport(
                $userId,
                $this->fetchTable('TIndividualReservationInfo'),
                $this->fetchTable('MUserGroup')
            );

            // ダッシュボード画面の日付データ・週データを組み立てる
            $dashboard = $dashboardService->buildHomeContext($user);
        } else {
            // 未ログインの場合: 報告済みフラグは false 固定にし、
            // ダッシュボードコンテキストはユーザー情報なしで生成する
            $hasTodayReport = false;
            $dashboard      = $dashboardService->buildHomeContext(null);
        }

        return compact('hasTodayReport', 'dashboard');
    }
}
