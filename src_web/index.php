<?php
// LP（ランディングページ）
//
// ドメイン直下(/)で表示されるサービス紹介ページ。
// CakePHPアプリ(/kamaho-shokusu/)の外に置いた静的コンテンツで、
// CSS等のアセットはアプリの webroot(/kamaho-shokusu/...)を共用する。
// ログイン導線は /kamaho-shokusu/MUserInfo/login へ誘導する。
//
// 掲載画像は管理画面（/kamaho-shokusu/LpImage）から登録されたものを
// m_lp_image テーブルから取得して表示する（DB未接続時は既定画像で表示継続）。
$loginUrl   = '/kamaho-shokusu/MUserInfo/login';
$contactUrl = '/kamaho-shokusu/Contacts';

/**
 * m_lp_image テーブルからLPに表示する画像（i_display=1）を取得する。
 *
 * アプリのDB設定（config/app_local.php）を再利用して接続する。
 * DB未接続・テーブル未作成などの場合は空配列を返し、LPの表示自体は継続する。
 *
 * @return array<int, array{c_title: string, c_section: string, c_file_path: string}>
 */
function lpFetchImages(): array
{
    if (!function_exists('env')) {
        // app_local.php 内で使用される CakePHP の env() 互換関数
        function env(string $key, $default = null)
        {
            $value = getenv($key);

            return $value !== false ? $value : $default;
        }
    }

    try {
        $config = include __DIR__ . '/kamaho-shokusu/config/app_local.php';
        $ds = $config['Datasources']['default'] ?? null;
        if (!is_array($ds)) {
            return [];
        }

        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $ds['host'] ?? 'localhost', $ds['database'] ?? ''),
            (string)($ds['username'] ?? ''),
            (string)($ds['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
        );
        $rows = $pdo->query(
            'SELECT c_title, c_section, c_file_path FROM m_lp_image WHERE i_display = 1 ORDER BY i_sort ASC, i_id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable) {
        return [];
    }
}

$lpImages      = lpFetchImages();
$heroImages    = array_values(array_filter($lpImages, fn(array $r): bool => $r['c_section'] === 'hero'));
$galleryImages = array_values(array_filter($lpImages, fn(array $r): bool => $r['c_section'] === 'gallery'));

// ヒーロー画像: 管理画面で登録があればそれを、なければ既定のカレンダー画面を使う
$heroImagePath = '/kamaho-shokusu/' . ($heroImages[0]['c_file_path'] ?? 'img/lp/calendar.png');
$heroImageAlt  = $heroImages[0]['c_title'] ?? '食数予約カレンダー画面のスクリーンショット';
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
            <div class="d-flex align-items-center gap-3">
                <a class="lp-header-link d-none d-sm-inline" href="#contact">お問い合わせ</a>
                <a class="btn lp-cta-btn px-4" href="<?= $loginUrl ?>" aria-label="ログインページへ移動">
                    <i class="bi bi-box-arrow-in-right me-1"></i>ログイン
                </a>
            </div>
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
                    <?php /* 実際のアプリ画面（管理画面から差し替え可能） */ ?>
                    <div class="lp-mock shadow">
                        <div class="lp-mock-bar" aria-hidden="true">
                            <span></span><span></span><span></span>
                        </div>
                        <img class="lp-mock-img" src="<?= htmlspecialchars($heroImagePath, ENT_QUOTES) ?>"
                             alt="<?= htmlspecialchars($heroImageAlt, ENT_QUOTES) ?>">
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

    <?php /* ---- 具体事例セクション ---- */ ?>
    <section class="lp-cases py-5">
        <div class="container">
            <div class="lp-section-eyebrow text-center">CASE</div>
            <h2 class="text-center fw-bold mb-2">導入でこう変わる</h2>
            <p class="text-center text-muted mb-5">紙やExcelで行っていた作業が、こんなふうに変わります。</p>
            <div class="row g-4">
                <div class="col-12 col-lg-4">
                    <div class="card lp-case-card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <h3 class="lp-feature-title fw-bold mb-3">
                                <i class="bi bi-clipboard-check me-2 text-primary" aria-hidden="true"></i>毎日の食数取りまとめ
                            </h3>
                            <div class="lp-case-before">
                                <span class="lp-case-label lp-case-label-before">Before</span>
                                <p class="mb-0">紙の申告用紙を各部屋から回収し、担当者が1件ずつ手作業で集計。</p>
                            </div>
                            <div class="lp-case-arrow text-center" aria-hidden="true"><i class="bi bi-arrow-down"></i></div>
                            <div class="lp-case-after">
                                <span class="lp-case-label lp-case-label-after">After</span>
                                <p class="mb-0">各自がスマホ・PCからワンタップで申告。集計はリアルタイムに自動反映。</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <div class="card lp-case-card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <h3 class="lp-feature-title fw-bold mb-3">
                                <i class="bi bi-people me-2 text-success" aria-hidden="true"></i>申告内容の確認・承認
                            </h3>
                            <div class="lp-case-before">
                                <span class="lp-case-label lp-case-label-before">Before</span>
                                <p class="mb-0">口頭やメモでの確認のため、聞き漏れ・記入漏れが発生しがち。</p>
                            </div>
                            <div class="lp-case-arrow text-center" aria-hidden="true"><i class="bi bi-arrow-down"></i></div>
                            <div class="lp-case-after">
                                <span class="lp-case-label lp-case-label-after">After</span>
                                <p class="mb-0">ブロック長・管理者による段階承認フローで、漏れなくダブルチェック。</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <div class="card lp-case-card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <h3 class="lp-feature-title fw-bold mb-3">
                                <i class="bi bi-file-earmark-spreadsheet me-2 text-warning" aria-hidden="true"></i>月次の集計・控除計算
                            </h3>
                            <div class="lp-case-before">
                                <span class="lp-case-label lp-case-label-before">Before</span>
                                <p class="mb-0">月末にExcelへ転記して集計。計算ミスの確認にも時間がかかる。</p>
                            </div>
                            <div class="lp-case-arrow text-center" aria-hidden="true"><i class="bi bi-arrow-down"></i></div>
                            <div class="lp-case-after">
                                <span class="lp-case-label lp-case-label-after">After</span>
                                <p class="mb-0">食事控除表をワンクリックで出力。転記も再計算も不要に。</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php if ($galleryImages !== []): ?>
    <?php /* ---- 画面イメージ（管理画面から登録された画像を表示） ---- */ ?>
    <section class="lp-gallery py-5">
        <div class="container">
            <div class="lp-section-eyebrow text-center">SCREENSHOT</div>
            <h2 class="text-center fw-bold mb-5">画面イメージ</h2>
            <div class="row g-4 justify-content-center">
                <?php foreach ($galleryImages as $galleryImage): ?>
                    <div class="col-12 col-md-6 col-lg-4">
                        <figure class="lp-gallery-item mb-0">
                            <img class="lp-gallery-img shadow-sm"
                                 src="/kamaho-shokusu/<?= htmlspecialchars($galleryImage['c_file_path'], ENT_QUOTES) ?>"
                                 alt="<?= htmlspecialchars($galleryImage['c_title'], ENT_QUOTES) ?>" loading="lazy">
                            <figcaption class="text-center text-muted small mt-2"><?= htmlspecialchars($galleryImage['c_title'], ENT_QUOTES) ?></figcaption>
                        </figure>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

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

    <?php /* ---- お問い合わせセクション ---- */ ?>
    <section class="lp-contact py-5" id="contact">
        <div class="container text-center">
            <div class="lp-section-eyebrow">CONTACT</div>
            <h2 class="fw-bold mb-2">お問い合わせ</h2>
            <p class="text-muted mb-4">
                サービスに関するご質問・ご意見・不具合のご報告は、お問い合わせフォームからお送りください。<br>
                ログインしていなくてもご利用いただけます。
            </p>
            <a class="btn lp-cta-btn btn-lg px-5" href="<?= $contactUrl ?>" aria-label="お問い合わせフォームへ移動">
                <i class="bi bi-envelope me-2"></i>お問い合わせフォームへ
            </a>
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
