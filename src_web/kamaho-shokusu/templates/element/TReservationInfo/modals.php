<?php
use Cake\Core\Configure;

$pastDateUnavailableMessage = (string)Configure::read(
    'App.messages.pastDateUnavailable',
    '過去日の内容はこの画面では表示できません。修正が必要な場合は管理者にお問い合わせください。'
);
?>

<div class="modal fade" id="bentoLunchWarnModal" tabindex="-1" aria-labelledby="bentoLunchWarnTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="bentoLunchWarnTitle">弁当の変更について</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button></div>
            <div class="modal-body">本日は<strong>昼食の予約が登録されています</strong>。<br>お弁当を変更する前に、<u>昼食の予約を無効（取り消し）</u>にしてください。</div>
            <div class="modal-footer">
                <a href="<?= h($lunchChangeUrl) ?>" class="btn btn-primary">昼食の予約を変更する</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
            </div>
        </div></div>
</div>

<div class="modal fade" id="lunchBentoWarnModal" tabindex="-1" aria-labelledby="lunchBentoWarnTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="lunchBentoWarnTitle">昼食の変更について</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button></div>
            <div class="modal-body">本日は<strong>弁当の予約が登録されています</strong>。<br>昼食を変更する前に、<u>弁当の予約を無効（取り消し）</u>にしてください。</div>
            <div class="modal-footer">
                <a href="<?= h($bentoChangeUrl) ?>" class="btn btn-primary">弁当の予約を変更する</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
            </div>
        </div></div>
</div>

<div class="modal fade modal-warning" id="lateNoticeModal" tabindex="-1" aria-labelledby="lateNoticeTitle" aria-hidden="true" role="alertdialog" aria-modal="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lateNoticeTitle"><i class="bi bi-exclamation-triangle-fill"></i>警告：直前の変更・追加</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="とじる"></button>
            </div>
            <div class="modal-body">
                <div id="lateNoticeBody" class="alert alert-danger mb-3"></div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="lateAgreeCheck" aria-describedby="lateAgreeHelp">
                    <label class="form-check-label" for="lateAgreeCheck">
                        <strong>発注済みであること</strong>を理解しました（内容をよく確認します）
                    </label>
                    <div id="lateAgreeHelp" class="form-text">チェックすると「同意して進む」ボタンが有効になります。</div>
                </div>
            </div>
            <div class="modal-footer">
                <a id="lateProceed" href="#" class="btn btn-primary disabled" aria-disabled="true" tabindex="-1" role="button">同意して進む</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">同意しない（戻る）</button>
            </div>
        </div></div>
</div>

<div class="modal fade" id="quickDayModal" tabindex="-1" aria-labelledby="quickDayModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xxl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="quickDayModalLabel">食数予約の追加 <small class="fw-normal">(対象日: <span id="qd-picked-date"></span>)</small></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning py-2 px-3 small mb-3" role="alert">
                    <?= h($pastDateUnavailableMessage) ?>
                </div>
                <div id="qd-remote-wrap" class="bg-white rounded border">
                    <div id="qd-remote-loading" class="text-center">
                        <div class="spinner-border" role="status" aria-hidden="true"></div>
                        <div class="mt-2">読み込み中...</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
            </div>
        </div>
    </div>
</div>
