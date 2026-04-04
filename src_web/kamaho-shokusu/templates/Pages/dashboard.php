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

// 最新のDB状態から渡された権限フラグを使う
$roleFlags = $dashboard['roleFlags'] ?? ['isAdmin' => false, 'isBlockLeader' => false];
$isAdmin = (bool)($roleFlags['isAdmin'] ?? false);
$isBlockLeader = (bool)($roleFlags['isBlockLeader'] ?? false);
$canProxyActualMeal = $isAdmin || $isBlockLeader;

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
$approvalCounts         = $dashboard['approvalCounts']         ?? ['blockLeader' => 0, 'admin' => 0];
$blockLeaderPendingCount = $isAdmin ? 0 : (int)($approvalCounts['blockLeader'] ?? 0);
$adminPendingCount       = (int)($approvalCounts['admin'] ?? 0);
?>

<?= $this->Html->css('pages/home.pc.css') ?>
<?= $this->Html->css('pages/home.mobile.css') ?>

<style>
    .choice-modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.34);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        z-index: 1200;
        backdrop-filter: blur(6px);
    }
    .choice-modal-backdrop.is-open { display: flex; }
    .choice-modal-card {
        width: min(100%, 420px);
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        border: 1px solid rgba(148, 163, 184, 0.28);
        border-radius: 24px;
        box-shadow: 0 30px 90px rgba(15, 23, 42, 0.24);
        padding: 22px;
    }
    .choice-modal-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 14px;
    }
    .choice-modal-title {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 700;
        color: #0f172a;
    }
    .choice-modal-subtitle {
        margin-top: 6px;
        color: #64748b;
        font-size: .87rem;
        line-height: 1.6;
    }
    .choice-modal-options {
        display: grid;
        gap: 12px;
        margin-top: 16px;
    }
    .choice-modal-option {
        display: block;
        text-decoration: none;
        border: 1px solid #dbe4ee;
        border-radius: 18px;
        padding: 16px;
        color: #0f172a;
        background: #fff;
        transition: transform .14s ease, border-color .14s ease, box-shadow .14s ease;
    }
    .choice-modal-option:hover {
        transform: translateY(-1px);
        border-color: #93c5fd;
        box-shadow: 0 16px 40px rgba(59, 130, 246, 0.12);
        color: #0f172a;
    }
    .choice-modal-option-title {
        display: block;
        font-weight: 700;
        font-size: .96rem;
        margin-bottom: 4px;
    }
    .choice-modal-option-desc {
        display: block;
        color: #64748b;
        font-size: .82rem;
        line-height: 1.55;
    }
</style>

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
                    <a class="bell text-decoration-none position-relative" href="<?= $this->Url->build('/Notifications') ?>" aria-label="通知一覧">
                        🔔
                        <?php if (!empty($notificationUnreadCount)): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?= h($notificationUnreadCount > 99 ? '99+' : (string)$notificationUnreadCount) ?>
                            </span>
                        <?php endif; ?>
                    </a>
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
                        <a class="btn-teal"
                           href="<?= h($this->Url->build('/TReservationInfo/bulk-change-edit-form?date=' . $todayParam)) ?>">
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
                <button class="menu-card border-0 text-start" type="button" id="actual-meal-choice-trigger">
                    <div class="menu-icon" style="background:#fef3c7;color:#d97706;">✅</div>
                    <div class="menu-title-text">実食入力</div>
                    <div class="menu-desc">自分の実食を入力する</div>
                </button>
                <?php /* ブロック長用承認一覧: ブロック長または管理者に表示する */ ?>
                <?php if ($isBlockLeader || $isAdmin): ?>
                <a class="menu-card" href="<?= $this->Url->build('/Approval/blockLeaderIndex') ?>">
                    <div class="menu-icon" style="background:#ede9fe;color:#7c3aed;">📋</div>
                    <div class="menu-title-text">
                        承認一覧
                        <?php if ($blockLeaderPendingCount > 0): ?>
                            <span class="badge rounded-pill text-bg-danger ms-2"><?= h($blockLeaderPendingCount > 99 ? '99+' : (string)$blockLeaderPendingCount) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="menu-desc">職員入力の承認・差し戻しを行う</div>
                </a>
                <?php endif; ?>
                <?php if ($isBlockLeader): ?>
                <a class="menu-card" href="<?= $this->Url->build('/TReservationInfo/actual-meal-management') ?>">
                    <div class="menu-icon" style="background:#fff7ed;color:#c2410c;">📝</div>
                    <div class="menu-title-text">実食確認</div>
                    <div class="menu-desc">担当部屋の実食を代理入力する</div>
                </a>
                <?php endif; ?>
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
                    <?php /* 管理者用最終承認・集計: 管理者のみ表示する */ ?>
                    <a class="menu-card" href="<?= $this->Url->build('/Approval/adminIndex') ?>">
                        <div class="menu-icon" style="background:#ecfdf5;color:#059669;">✔️</div>
                        <div class="menu-title-text">
                            承認管理
                            <?php if ($adminPendingCount > 0): ?>
                                <span class="badge rounded-pill text-bg-danger ms-2"><?= h($adminPendingCount > 99 ? '99+' : (string)$adminPendingCount) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="menu-desc">全ブロックの承認・食数反映</div>
                    </a>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <?php /* モバイルサイドバー表示中にメインコンテンツを暗くするオーバーレイ */ ?>
    <div class="mobile-overlay" id="mobile-overlay"></div>

    <div id="actual-meal-choice-modal" class="choice-modal-backdrop" aria-hidden="true">
        <div class="choice-modal-card" role="dialog" aria-modal="true" aria-labelledby="actual-meal-choice-title">
            <div class="choice-modal-head">
                <div>
                    <h5 id="actual-meal-choice-title" class="choice-modal-title">実食入力の対象を選択</h5>
                    <div class="choice-modal-subtitle">入力対象に応じて画面を選択してください。</div>
                </div>
                <button type="button" class="btn-close" id="actual-meal-choice-close" aria-label="閉じる"></button>
            </div>
            <div class="choice-modal-options">
                <a class="choice-modal-option" href="<?= $this->Url->build('/TReservationInfo/my-actual-meal') ?>">
                    <span class="choice-modal-option-title">自分の分を入力</span>
                    <span class="choice-modal-option-desc">自分の実食を週単位で確認して保存します。</span>
                </a>
                <?php if ($canProxyActualMeal): ?>
                <a class="choice-modal-option" href="<?= $this->Url->build('/TReservationInfo/actual-meal-management') ?>">
                    <span class="choice-modal-option-title">他人の分も入力</span>
                    <span class="choice-modal-option-desc"><?= $isAdmin ? '全部屋の利用者を選んで代理入力します。' : '担当部屋の利用者を選んで代理入力します。'; ?></span>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

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
    <script>
        (() => {
            const trigger = document.getElementById('actual-meal-choice-trigger');
            const modal = document.getElementById('actual-meal-choice-modal');
            const closeBtn = document.getElementById('actual-meal-choice-close');
            if (!trigger || !modal || !closeBtn) {
                return;
            }

            const openModal = () => {
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
            };
            const closeModal = () => {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
            };

            trigger.addEventListener('click', openModal);
            closeBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeModal();
                }
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                    closeModal();
                }
            });
        })();
    </script>
<?php endif; ?>
