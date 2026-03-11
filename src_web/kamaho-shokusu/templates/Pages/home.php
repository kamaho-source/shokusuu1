<?php
/**
 * 新ホーム（ダッシュボード）テンプレート
 *
 * PagesController::dashboard() および display('home') から描画される。
 * ログイン状態・管理者権限・本日の食数報告状態に応じて表示内容を切り替える。
 *
 * 受け取るビュー変数:
 *   - $dashboard      : array  DashboardService::buildHomeContext() の返却値
 *                              (todayLabel, todayParam, *WeekMonday, fmtWeekRange など)
 *   - $hasTodayReport : bool   本日の食数報告が完了しているか
 *
 * @var \App\View\AppView $this
 */
use Cake\Core\Configure;

// ページタイトルをレイアウトに渡す
$this->assign('title', 'ダッシュボード');

// 過去日アクセス時のメッセージを設定ファイルから取得する。
// 設定がない場合はデフォルトメッセージを使う。
$pastDateUnavailableMessage = (string)Configure::read(
    'App.messages.pastDateUnavailable',
    '過去日の内容はこの画面では表示できません。修正が必要な場合は管理者にお問い合わせください。'
);

// 認証済みユーザーオブジェクトを取得する(未ログイン時は null)
$user      = $this->request->getAttribute('identity');
$isLoggedIn = (bool)$user;

// 管理者かどうかを判定する (i_admin === 1 の場合に管理者メニューを表示する)
$isAdmin = ($user && (int)$user->get('i_admin') === 1);

// DashboardService から渡されたダッシュボード用データを展開する
$todayLabel             = $dashboard['todayLabel']             ?? '';  // 例: 「2026年2月21日(土)」
$todayParam             = $dashboard['todayParam']             ?? '';  // 例: 「2026-02-21」(URLパラメータ用)
$thisWeekMonday         = $dashboard['thisWeekMonday']         ?? null; // 今週月曜日
$nextWeekMonday         = $dashboard['nextWeekMonday']         ?? null; // 来週月曜日
$nextNextWeekMonday     = $dashboard['nextNextWeekMonday']     ?? null; // 再来週月曜日
$firstNormalWeekMonday  = $dashboard['firstNormalWeekMonday']  ?? null; // 通常予約可能な最初の週の月曜日(今日+15日以降)
$secondNormalWeekMonday = $dashboard['secondNormalWeekMonday'] ?? null; // 通常予約2週目の月曜日
$thirdNormalWeekMonday  = $dashboard['thirdNormalWeekMonday']  ?? null; // 通常予約3週目の月曜日
$fmtWeekRange           = $dashboard['fmtWeekRange']           ?? null; // 「n/j(曜) 〜 n/j(曜)」形式に変換するクロージャ
?>

<?= $this->Html->css('pages/home.pc.css') ?>
<?= $this->Html->css('pages/home.mobile.css') ?>

<?php if (!$isLoggedIn): ?>
    <?php /* 未ログイン時: ログイン促進メッセージとログインボタンを表示する */ ?>
    <div class="p-4">
        <div class="alert alert-warning">利用するにはログインが必要です。</div>
        <a class="btn btn-primary" href="<?= $this->Url->build('/MUserInfo/login') ?>">ログイン</a>
    </div>
<?php else: ?>
    <?php /* ログイン済み: ダッシュボード本体を表示する */ ?>
    <div class="dash-shell mobile-sidebar-collapsed">
        <?php /* サイドバー要素を読み込む。activeKey='dashboard' でダッシュボードメニューをハイライトする */ ?>
        <?= $this->element('Pages/home_sidebar', [
            'user'      => $user,
            'isAdmin'   => $isAdmin,
            'activeKey' => 'dashboard'
        ]) ?>

        <main class="dash-main">
            <?php /* ---- ヘッダー ---- */ ?>
            <div class="dash-header">
                <div class="dash-title">ダッシュボード</div>
                <div class="d-flex align-items-center gap-2">
                    <?php /* モバイル向けサイドバー開閉ボタン */ ?>
                    <button class="mobile-menu-btn" id="mobile-menu-btn" type="button">メニュー</button>
                    <?php /* 今日の日付ラベル(例: 2026年2月21日(土)) */ ?>
                    <div class="date-pill"><?= h($todayLabel) ?></div>
                    <div class="bell">🔔</div>
                </div>
            </div>

            <?php /* ---- プロフィールカード ---- */ ?>
            <?php /* ログイン中ユーザーの氏名と職員IDを表示し、詳細ページへのリンクを設ける */ ?>
            <div class="alert-card" style="margin-top:12px;">
                <div class="alert-left">
                    <div class="alert-icon">👤</div>
                    <div>
                        <div class="alert-title">プロフィール</div>
                        <div class="alert-sub">
                            <?= h($user->get('c_user_name') ?? '') ?>
                            <?php if (!empty($user->get('i_id_staff'))): ?>
                                ／ 職員ID: <?= h($user->get('i_id_staff')) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="alert-actions">
                    <a class="btn-soft" href="<?= $this->Url->build('/MUserInfo/view/' . (int)$user->get('i_id_user')) ?>">詳細を見る</a>
                </div>
            </div>

            <?php /* ---- 本日の食数報告アラート ---- */ ?>
            <?php /* $hasTodayReport が false(未報告)の場合のみ表示する */ ?>
            <?php if (empty($hasTodayReport)): ?>
                <div class="alert-card" id="daily-report-card">
                    <div class="alert-left">
                        <div class="alert-icon">i</div>
                        <div>
                            <div class="alert-title">本日の食数報告が未完了です</div>
                            <div class="alert-sub"><?= h($user->get('c_user_name') ?? '') ?>さんの本日の食事利用について回答してください。</div>
                        </div>
                    </div>
                    <div class="alert-actions">
                        <?php /*
                            「食べない」ボタン: JS (home.js) が data-url を使って
                            /TReservationInfo/reportNoMeal へ非同期 POST する
                        */ ?>
                        <button class="btn-soft" id="daily-report-noeat" type="button"
                                data-url="<?= h($this->Url->build('/TReservationInfo/reportNoMeal')) ?>">
                            食べない
                        </button>
                        <button class="btn-soft" id="daily-report-noeat" type="button"
                                data-url="<?= h($this->Url->build('/TReservationInfo/reportNoMeal')) ?>">
                            食べない
                        </button>
                        <a class="btn-teal"
                           href="<?= h($this->Url->build('/TReservationInfo?date=' . $todayParam)) ?>">
                            食べる
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php /* ---- メインメニューカード ---- */ ?>
            <div class="section-title">各種メニュー</div>
            <div class="card-grid">
                <?php /* 食数状況確認: 当日の日付パラメータ付きで予約一覧画面へ遷移する */ ?>
                <a class="menu-card" href="<?= $this->Url->build('/TReservationInfo/view/' . $todayParam) ?>">
                    <div class="menu-icon" style="background:#eef2ff;color:#5b5fe0;">📄</div>
                    <div class="menu-title-text">食数状況確認</div>
                    <div class="menu-desc">当日のフロア別利用状況を確認する</div>
                </a>
                <?php /*
                    食数予約ボタン: クリックすると予約方法選択モーダル(#reservationChoiceModal)を開く。
                    モーダル内で「通常予約」か「直前編集」かを選択させる。
                */ ?>
                <button class="menu-card border-0" type="button" data-bs-toggle="modal" data-bs-target="#reservationChoiceModal">
                    <div class="menu-icon" style="background:#e9f7ef;color:#30a46c;">📅</div>
                    <div class="menu-title-text">食数予約</div>
                    <div class="menu-desc">将来の食事予定を一括登録する</div>
                </button>
            </div>

            <?php /* ---- 管理者専用メニュー ---- */ ?>
            <?php /* $isAdmin が true の場合のみ管理者向けメニューセクションを表示する */ ?>
            <?php if ($isAdmin): ?>
                <div class="section-title">管理者機能</div>
                <div class="card-grid">
                    <?php /* ユーザ一覧: MUserInfo の一覧画面へ遷移する */ ?>
                    <a class="menu-card" href="<?= $this->Url->build('/MUserInfo') ?>">
                        <div class="menu-icon" style="background:#fff4e5;color:#d08c3d;">👥</div>
                        <div class="menu-title-text">ユーザ一覧</div>
                        <div class="menu-desc">職員・利用者の管理</div>
                    </a>
                    <?php /* 食数単価一覧: MMealPriceInfo の一覧画面へ遷移する */ ?>
                    <a class="menu-card" href="<?= $this->Url->build('/MMealPriceInfo') ?>">
                        <div class="menu-icon" style="background:#e8f7ff;color:#2b7bb9;">💰</div>
                        <div class="menu-title-text">食数単価一覧</div>
                        <div class="menu-desc">単価マスタの確認</div>
                    </a>
                    <?php /* 食事控除表ダウンロード: 月次集計帳票を出力する */ ?>
                    <a class="menu-card" href="<?= $this->Url->build('/MMealPriceInfo/GetMealSummary') ?>">
                        <div class="menu-icon" style="background:#f1f5f9;color:#475569;">⬇️</div>
                        <div class="menu-title-text">食事控除表ダウンロード</div>
                        <div class="menu-desc">集計帳票の出力</div>
                    </a>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <?php /* モバイルサイドバー表示中にメインコンテンツを暗くするオーバーレイ */ ?>
    <div class="mobile-overlay" id="mobile-overlay"></div>

    <?php /* ==== 予約方法選択モーダル ==== */ ?>
    <?php /*
        食数予約ボタンを押したときに表示されるモーダル。
        「通常予約（新規）」と「直前編集（変更）」の2択を提示する。
        - 通常予約: 今日から15日以上先の週の月曜日($firstNormalWeekMonday)を開始日として
                   一括登録フォーム(/TReservationInfo/bulk-add-form)へ遷移する。
        - 直前編集: 今週月曜日($thisWeekMonday)を開始日として
                   一括変更編集フォーム(/TReservationInfo/bulk-change-edit-form)へ遷移する。
    */ ?>
    <div class="modal fade" id="reservationChoiceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">食数予約の方法を選択</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-0">
                    <?php /* 利用方法の説明テキスト */ ?>
                    <div class="text-muted small mb-3">
                        目的に合わせて選んでください。<strong>通常予約</strong>は15日以上先、<strong>直前編集</strong>は今週以降の変更に使います。
                    </div>
                    <?php /* 過去日アクセス不可の注意メッセージ(設定ファイルから取得) */ ?>
                    <div class="alert alert-warning py-2 px-3 small mb-3" role="alert">
                        <?= h($pastDateUnavailableMessage) ?>
                    </div>
                    <div class="d-grid gap-3">
                        <?php /*
                            通常予約（新規）ボタン:
                            $firstNormalWeekMonday は「今日+15日以降」で最初の月曜日。
                            この日付を開始日として一括登録フォームへ遷移する。
                        */ ?>
                        <a class="btn btn-primary btn-lg" href="<?= $this->Url->build('/TReservationInfo/bulk-add-form?date=' . $firstNormalWeekMonday->format('Y-m-d')) ?>">
                            通常予約（新規）
                        </a>
                        <?php /*
                            直前編集（変更）ボタン:
                            $thisWeekMonday は今週の月曜日。
                            今週〜2週後(14日以内)の予約を変更する一括編集フォームへ遷移する。
                        */ ?>
                        <a class="btn btn-outline-primary btn-lg" href="<?= $this->Url->build('/TReservationInfo/bulk-change-edit-form?date=' . $thisWeekMonday->format('Y-m-d')) ?>">
                            直前編集（変更）
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?= $this->Html->script('pages/home.js') ?>
<?php endif; ?>