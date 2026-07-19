<?php
/**
 * @var \App\View\AppView $this
 * @var string $tenantName
 */
$this->assign('title', '申し込み完了');
$tenantName ??= '';
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-7 col-lg-5 text-center">
        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success shadow mb-4"
             style="width:72px;height:72px;">
            <i class="bi bi-check-lg text-white" style="font-size:2rem;"></i>
        </div>
        <h1 class="h4 fw-bold mb-2">お申し込みありがとうございます！</h1>
        <?php if ($tenantName): ?>
            <p class="text-muted">「<?= h($tenantName) ?>」のトライアルを開始しました。</p>
        <?php endif; ?>

        <div class="card border-0 shadow-sm text-start mt-4 mb-4">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-3">次のステップ</h2>
                <ol class="list-unstyled mb-0">
                    <li class="d-flex align-items-start gap-3 mb-3">
                        <div class="d-flex align-items-center justify-content-center rounded-circle bg-info text-white flex-shrink-0"
                             style="width:28px;height:28px;font-size:0.8rem;">1</div>
                        <div>
                            <div class="fw-semibold small">ウェルカムメールをご確認ください</div>
                            <div class="text-muted" style="font-size:0.8rem;">ご登録のメールアドレスにログイン情報をお送りしました。</div>
                        </div>
                    </li>
                    <li class="d-flex align-items-start gap-3 mb-3">
                        <div class="d-flex align-items-center justify-content-center rounded-circle bg-info text-white flex-shrink-0"
                             style="width:28px;height:28px;font-size:0.8rem;">2</div>
                        <div>
                            <div class="fw-semibold small">施設サブドメインにアクセスしてログイン</div>
                            <div class="text-muted" style="font-size:0.8rem;">例: <code>{テナントコード}.shokusu.jp</code> にアクセスしてください。</div>
                        </div>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <div class="d-flex align-items-center justify-content-center rounded-circle bg-info text-white flex-shrink-0"
                             style="width:28px;height:28px;font-size:0.8rem;">3</div>
                        <div>
                            <div class="fw-semibold small">30日間無料でご利用ください</div>
                            <div class="text-muted" style="font-size:0.8rem;">トライアル期間中にご不明な点があればお気軽にお問い合わせください。</div>
                        </div>
                    </li>
                </ol>
            </div>
        </div>

        <a href="<?= $this->Url->build(['controller' => 'MUserInfo', 'action' => 'login']) ?>"
           class="btn btn-info text-white shadow-sm">
            <i class="bi bi-box-arrow-in-right me-2"></i>ログインページへ
        </a>
    </div>
</div>
