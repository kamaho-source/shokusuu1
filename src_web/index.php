<?php
// LP（ランディングページ）
//
// ドメイン直下(/)で表示されるサービス紹介ページ。
// CakePHPアプリ(/kamaho-shokusu/)の外に置いた静的コンテンツで、
// CSS等のアセットはアプリの webroot(/kamaho-shokusu/...)を共用する。
// ログイン導線は /kamaho-shokusu/MUserInfo/login へ誘導する。
$loginUrl = '/kamaho-shokusu/MUserInfo/login';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>食数管理システム | 施設向け食数管理ICTサービス</title>
    <meta name="description" content="食数管理システムは、施設の食数の申告・承認・集計を支援・効率化するICTツールです。">
    <link rel="stylesheet" href="/kamaho-shokusu/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/kamaho-shokusu/css/pages/landing.css">
</head>
<body>

<div class="lp">
    <?php /* ---- LPヘッダー ---- */ ?>
    <header class="lp-header">
        <div class="container d-flex align-items-center justify-content-between py-2">
            <div class="lp-header-brand d-flex align-items-center gap-2">
                <i class="bi bi-calendar-check-fill" aria-hidden="true"></i>
                <span class="fw-bold">食数管理システム</span>
            </div>
            <a class="btn lp-cta-btn px-4" href="<?= $loginUrl ?>" aria-label="ログインページへ移動">
                <i class="bi bi-box-arrow-in-right me-1"></i>ログイン
            </a>
        </div>
    </header>

    <?php /* ---- ヒーローセクション ---- */ ?>
    <section class="lp-hero position-relative overflow-hidden">
        <div class="lp-deco lp-deco-top-left" aria-hidden="true"></div>
        <div class="lp-deco lp-deco-bottom-left" aria-hidden="true"></div>
        <div class="lp-deco lp-deco-right" aria-hidden="true"></div>
        <div class="container position-relative">
            <div class="row align-items-center g-5">
                <div class="col-12 col-lg-7">
                    <div class="d-flex align-items-center gap-3 flex-wrap mb-3">
                        <div>
                            <span class="lp-badge">施設向け</span>
                            <div class="lp-hero-label mt-2 fw-bold">
                                食数の申告・承認・集計<br>
                                まるごと管理ICTサービス
                            </div>
                        </div>
                        <div class="lp-stat-circle text-center" aria-hidden="true">
                            <span class="lp-stat-circle-label">申告は</span>
                            <span class="lp-stat-circle-value">ワンタップ</span>
                        </div>
                    </div>
                    <h1 class="lp-hero-title fw-bold mb-3">
                        食数管理が、<br>
                        <span class="lp-hero-title-sub">もっとカンタンに。</span>
                    </h1>
                    <a class="btn lp-cta-btn btn-lg px-5 my-3" href="<?= $loginUrl ?>" aria-label="ログインページへ移動">
                        ログインはこちらへ <i class="bi bi-arrow-right-circle ms-1"></i>
                    </a>
                    <p class="lp-hero-lead fw-bold mt-3 mb-1">
                        食数の申告・管理・承認／集計・レポートをまるっと管理
                    </p>
                    <p class="lp-hero-lead fw-bold mb-0">
                        紙やExcelでの取りまとめ作業のお悩みをマルっと解決！
                    </p>
                </div>
                <div class="col-12 col-lg-5 d-none d-md-block">
                    <?php /* 実際のアプリ画面（食数予約カレンダー） */ ?>
                    <div class="lp-mock shadow">
                        <div class="lp-mock-bar" aria-hidden="true">
                            <span></span><span></span><span></span>
                        </div>
                        <img class="lp-mock-img" src="/kamaho-shokusu/img/lp/calendar.png"
                             alt="食数予約カレンダー画面のスクリーンショット" loading="lazy">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php /* ---- ブランドステートメント ---- */ ?>
    <section class="lp-statement text-center py-5">
        <div class="container">
            <div class="lp-statement-icon mb-3" aria-hidden="true">
                <i class="bi bi-calendar-check-fill"></i>
            </div>
            <p class="lp-statement-text fw-bold mb-2">
                <span class="lp-brand-name">食数管理システム</span>は、施設の食数の
                申告・承認・集計を支援・効率化するICTツールです。
            </p>
            <p class="text-muted small mb-0">※ 週単位のカレンダー申告・段階承認・自動集計に対応しています。</p>
        </div>
    </section>

    <?php /* ---- 主な機能セクション ---- */ ?>
    <section class="lp-features py-5">
        <div class="container">
            <div class="lp-section-eyebrow text-center">FEATURE</div>
            <h2 class="text-center fw-bold mb-2">主な機能</h2>
            <p class="text-center text-muted mb-5">日々の食数管理に必要な機能をひとつのシステムで提供します。</p>
            <div class="row g-4">
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="card lp-feature-card h-100 border-0 shadow-sm text-center">
                        <div class="card-body">
                            <div class="lp-feature-icon mb-3" aria-hidden="true">
                                <i class="bi bi-calendar3"></i>
                            </div>
                            <h3 class="lp-feature-title fw-bold">カレンダーでかんたん申告</h3>
                            <p class="text-muted mb-0">週単位のカレンダーから「食べる・食べない」を選ぶだけ。まとめて一括申告にも対応しています。</p>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="card lp-feature-card h-100 border-0 shadow-sm text-center">
                        <div class="card-body">
                            <div class="lp-feature-icon mb-3" aria-hidden="true">
                                <i class="bi bi-check2-square"></i>
                            </div>
                            <h3 class="lp-feature-title fw-bold">承認フロー</h3>
                            <p class="text-muted mb-0">申告内容はブロック長・管理者が段階的に承認。確認状況もひと目で把握できます。</p>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="card lp-feature-card h-100 border-0 shadow-sm text-center">
                        <div class="card-body">
                            <div class="lp-feature-icon mb-3" aria-hidden="true">
                                <i class="bi bi-bar-chart"></i>
                            </div>
                            <h3 class="lp-feature-title fw-bold">集計・レポート</h3>
                            <p class="text-muted mb-0">食数の集計や食事控除表の出力を自動化。手作業での集計ミスを防ぎます。</p>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="card lp-feature-card h-100 border-0 shadow-sm text-center">
                        <div class="card-body">
                            <div class="lp-feature-icon mb-3" aria-hidden="true">
                                <i class="bi bi-bell"></i>
                            </div>
                            <h3 class="lp-feature-title fw-bold">お知らせ・通知</h3>
                            <p class="text-muted mb-0">運営からのお知らせや承認状況の変化を通知でお届け。申告漏れを防ぎます。</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php /* ---- 利用の流れセクション ---- */ ?>
    <section class="lp-steps py-5">
        <div class="container">
            <div class="lp-section-eyebrow text-center">FLOW</div>
            <h2 class="text-center fw-bold mb-5">利用の流れ</h2>
            <div class="row g-4 justify-content-center">
                <div class="col-12 col-md-4">
                    <div class="lp-step text-center">
                        <div class="lp-step-number mx-auto mb-3" aria-hidden="true">1</div>
                        <h3 class="lp-feature-title fw-bold">ログイン</h3>
                        <p class="text-muted mb-0">配布されたログインIDとパスワードでログインします。</p>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="lp-step text-center">
                        <div class="lp-step-number mx-auto mb-3" aria-hidden="true">2</div>
                        <h3 class="lp-feature-title fw-bold">食数を申告</h3>
                        <p class="text-muted mb-0">カレンダーから日付を選んで、食事の要否を申告します。</p>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="lp-step text-center">
                        <div class="lp-step-number mx-auto mb-3" aria-hidden="true">3</div>
                        <h3 class="lp-feature-title fw-bold">承認・集計</h3>
                        <p class="text-muted mb-0">承認された申告は自動で集計され、レポートに反映されます。</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php /* ---- フッターCTAセクション ---- */ ?>
    <section class="lp-footer-cta text-center py-5">
        <div class="container">
            <h2 class="fw-bold mb-3">さっそく始めましょう</h2>
            <p class="mb-4">
                アカウントをお持ちの方は、ログインしてご利用ください。<br>
                アカウントの発行やログインでお困りの場合は、施設の管理者までお問い合わせください。
            </p>
            <a class="btn lp-cta-btn lp-cta-btn-light btn-lg px-5" href="<?= $loginUrl ?>" aria-label="ログインページへ移動">
                ログインはこちらへ <i class="bi bi-arrow-right-circle ms-1"></i>
            </a>
        </div>
    </section>

    <?php /* ---- フッター ---- */ ?>
    <footer class="lp-footer text-center py-3">
        <small>&copy; <?= date('Y') ?> 食数管理システム</small>
    </footer>
</div>

</body>
</html>
