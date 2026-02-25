<?php
/**
 * ダッシュボード用サイドバー要素
 *
 * dashboard.php テンプレートから $this->element() で読み込まれる部分テンプレート。
 * 画面左側のナビゲーションサイドバーを描画する。
 *
 * 受け取る変数:
 *   - $user      : 認証済みユーザーオブジェクト (c_user_name, i_id_staff を使用)
 *   - $isAdmin   : bool  管理者かどうか。true の場合に管理者メニューを表示する
 *   - $activeKey : string 現在アクティブなメニュー項目を示すキー
 *                  例: 'dashboard', 'reservation', 'users', 'prices', 'summary'
 *                  対応するメニュー項目に 'active' クラスが付与される
 *
 * @var \App\View\AppView $this
 * @var mixed $user
 * @var bool $isAdmin
 * @var string $activeKey
 */

// $activeKey が渡されなかった場合のデフォルト値を空文字にする
$activeKey = $activeKey ?? '';
?>
<aside class="dash-sidebar">
    <?php /* ---- ブランドロゴエリア ---- */ ?>
    <div class="brand">
        <div class="brand-icon">🍴</div>
        食数管理システム
    </div>

    <?php /* ---- プロフィールカード ---- */ ?>
    <?php /*
        ログイン中ユーザーの情報をサイドバー上部に表示する。
        - avatar: ユーザー名の先頭1文字をアイコン代わりに表示する(mb_substr で多バイト文字対応)
        - STAFF ID: i_id_staff が未設定の場合は '---' を表示する
        - c_user_name: ユーザーの氏名
    */ ?>
    <div class="profile-card">
        <div class="avatar"><?= h(mb_substr($user->get('c_user_name'), 0, 1)) ?></div>
        <div>
            <div class="profile-meta">STAFF ID: <?= h($user->get('i_id_staff') ?? '---') ?></div>
            <div class="profile-name"><?= h($user->get('c_user_name') ?? '') ?></div>
        </div>
    </div>

    <?php /* ---- メインメニュー ---- */ ?>
    <div class="menu-title">メインメニュー</div>

    <?php /*
        各メニュー項目は $activeKey と一致する場合に 'active' クラスを付与し、
        現在地をハイライト表示する。
    */ ?>

    <?php /* ダッシュボード: ルートURL(/) へ遷移する */ ?>
    <a class="menu-item <?= $activeKey === 'dashboard' ? 'active' : '' ?>" href="<?= $this->Url->build('/') ?>">ダッシュボード</a>

    <?php /* 食数確認・予約: 予約一覧画面へ遷移する */ ?>
    <a class="menu-item <?= $activeKey === 'reservation' ? 'active' : '' ?>" href="<?= $this->Url->build('/TReservationInfo') ?>">食数確認・予約</a>

    <?php /* ログアウト: 認証セッションを破棄してログイン画面へリダイレクトする */ ?>
    <a class="menu-item" href="<?= $this->Url->build('/MUserInfo/logout') ?>">ログアウト</a>

    <?php /* ---- 管理者メニュー ---- */ ?>
    <?php /* $isAdmin が true のユーザーにのみ表示する */ ?>
    <?php if ($isAdmin): ?>
        <div class="menu-title">管理者メニュー</div>

        <?php /* ユーザ一覧: MUserInfo の一覧画面へ遷移する */ ?>
        <a class="menu-item <?= $activeKey === 'users' ? 'active' : '' ?>" href="<?= $this->Url->build('/MUserInfo') ?>">ユーザ一覧</a>

        <?php /* 食数単価一覧: MMealPriceInfo の一覧画面へ遷移する */ ?>
        <a class="menu-item <?= $activeKey === 'prices' ? 'active' : '' ?>" href="<?= $this->Url->build('/MMealPriceInfo') ?>">食数単価一覧</a>

        <?php /* 食事控除表DL: 月次集計帳票をダウンロードする */ ?>
        <a class="menu-item <?= $activeKey === 'summary' ? 'active' : '' ?>" href="<?= $this->Url->build('/MMealPriceInfo/GetMealSummary') ?>">食事控除表DL</a>
    <?php endif; ?>

    <?php /* ---- サイドバー下部: 旧ホーム導線 ---- */ ?>
    <?php /*
        従来のホーム画面(TReservationInfo の一覧画面)へ遷移するリンクを下部に配置する。
        新ダッシュボードに慣れていないユーザーが旧画面へ戻れるよう残している。
    */ ?>
    <div class="sidebar-bottom">
        <a class="legacy-home-btn" href="<?= $this->Url->build('/TReservationInfo') ?>">
            <span class="legacy-home-icon">H</span>
            従来のホームを表示
            <span class="legacy-home-arrow">→</span>
        </a>
    </div>
</aside>
