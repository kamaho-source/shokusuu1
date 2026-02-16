<?php
/**
 * 新ホーム（ダッシュボード）
 *
 * @var \App\View\AppView $this
 */
use Cake\Core\Configure;

$this->assign('title', 'ダッシュボード');
$pastDateUnavailableMessage = (string)Configure::read(
    'App.messages.pastDateUnavailable',
    '過去日の内容はこの画面では表示できません。修正が必要な場合は管理者にお問い合わせください。'
);
$user = $this->request->getAttribute('identity');
$isLoggedIn = (bool)$user;
$isAdmin = ($user && (int)$user->get('i_admin') === 1);
$todayLabel = $dashboard['todayLabel'] ?? '';
$todayParam = $dashboard['todayParam'] ?? '';
$thisWeekMonday = $dashboard['thisWeekMonday'] ?? null;
$nextWeekMonday = $dashboard['nextWeekMonday'] ?? null;
$nextNextWeekMonday = $dashboard['nextNextWeekMonday'] ?? null;
$firstNormalWeekMonday = $dashboard['firstNormalWeekMonday'] ?? null;
$secondNormalWeekMonday = $dashboard['secondNormalWeekMonday'] ?? null;
$thirdNormalWeekMonday = $dashboard['thirdNormalWeekMonday'] ?? null;
$fmtWeekRange = $dashboard['fmtWeekRange'] ?? null;
?>

<?= $this->Html->css('pages/home.pc.css') ?>
<?= $this->Html->css('pages/home.mobile.css') ?>

<?php if (!$isLoggedIn): ?>
    <div class="p-4">
        <div class="alert alert-warning">利用するにはログインが必要です。</div>
        <a class="btn btn-primary" href="<?= $this->Url->build('/MUserInfo/login') ?>">ログイン</a>
    </div>
<?php else: ?>
    <div class="dash-shell mobile-sidebar-collapsed">
        <?= $this->element('Pages/home_sidebar', [
            'user' => $user,
            'isAdmin' => $isAdmin,
            'activeKey' => 'dashboard'
        ]) ?>

        <main class="dash-main">
            <div class="dash-header">
                <div class="dash-title">ダッシュボード</div>
                <div class="d-flex align-items-center gap-2">
                    <button class="mobile-menu-btn" id="mobile-menu-btn" type="button">メニュー</button>
                    <div class="date-pill"><?= h($todayLabel) ?></div>
                    <div class="bell">🔔</div>
                </div>
            </div>

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
                        <button class="btn-soft" id="daily-report-noeat" type="button"
                                data-url="<?= h($this->Url->build('/TReservationInfo/reportNoMeal')) ?>">
                            食べない
                        </button>
                        <a class="btn-teal" id="daily-report-eat" data-date="<?= h($todayParam) ?>"
                           href="<?= $this->Url->build('/TReservationInfo?date=' . $todayParam) ?>">食べる</a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="section-title">各種メニュー</div>
            <div class="card-grid">
                <a class="menu-card" href="<?= $this->Url->build('/TReservationInfo/view/' . $todayParam) ?>">
                    <div class="menu-icon" style="background:#eef2ff;color:#5b5fe0;">📄</div>
                    <div class="menu-title-text">食数状況確認</div>
                    <div class="menu-desc">当日のフロア別利用状況を確認する</div>
                </a>
                <button class="menu-card border-0" type="button" data-bs-toggle="modal" data-bs-target="#reservationChoiceModal">
                    <div class="menu-icon" style="background:#e9f7ef;color:#30a46c;">📅</div>
                    <div class="menu-title-text">食数予約</div>
                    <div class="menu-desc">将来の食事予定を一括登録する</div>
                </button>
            </div>

            <?php if ($isAdmin): ?>
                <div class="section-title">管理者機能</div>
                <div class="card-grid">
                    <a class="menu-card" href="<?= $this->Url->build('/MUserInfo') ?>">
                        <div class="menu-icon" style="background:#fff4e5;color:#d08c3d;">👥</div>
                        <div class="menu-title-text">ユーザ一覧</div>
                        <div class="menu-desc">職員・利用者の管理</div>
                    </a>
                    <a class="menu-card" href="<?= $this->Url->build('/MMealPriceInfo') ?>">
                        <div class="menu-icon" style="background:#e8f7ff;color:#2b7bb9;">💰</div>
                        <div class="menu-title-text">食数単価一覧</div>
                        <div class="menu-desc">単価マスタの確認</div>
                    </a>
                    <a class="menu-card" href="<?= $this->Url->build('/MMealPriceInfo/GetMealSummary') ?>">
                        <div class="menu-icon" style="background:#f1f5f9;color:#475569;">⬇️</div>
                        <div class="menu-title-text">食事控除表ダウンロード</div>
                        <div class="menu-desc">集計帳票の出力</div>
                    </a>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <div class="mobile-overlay" id="mobile-overlay"></div>

    <!-- 予約方法選択モーダル -->
    <div class="modal fade" id="reservationChoiceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">食数予約の方法を選択</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-0">
                    <div class="text-muted small mb-3">
                        目的に合わせて選んでください。<strong>通常予約</strong>は15日以上先、<strong>直前編集</strong>は今週以降の変更に使います。
                    </div>
                    <div class="alert alert-warning py-2 px-3 small mb-3" role="alert">
                        <?= h($pastDateUnavailableMessage) ?>
                    </div>
                    <div class="d-grid gap-3">
                        <a class="btn btn-primary btn-lg" href="<?= $this->Url->build('/TReservationInfo/bulk-add-form?date=' . $firstNormalWeekMonday->format('Y-m-d')) ?>">
                            通常予約（新規）
                        </a>
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
