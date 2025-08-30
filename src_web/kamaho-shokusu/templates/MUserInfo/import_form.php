<?php
/**
 * @var \App\View\AppView $this
 */
$this->assign('title', $title ?? 'ユーザー一括登録（ドラッグ＆ドロップ）');
$csrfToken = $this->request->getAttribute('csrfToken');

// 外部CSS読込（webroot/css/user-import.css）
echo $this->Html->css('user-import', ['block' => true]);
?>
<div class="row">
    <!-- 左側メニュー -->
    <aside class="col-md-3" aria-label="<?= __('サイドメニュー') ?>">
        <div class="list-group">
            <h2 class="list-group-item list-group-item-action active h5 mb-0"><?= __('操作') ?></h2>
            <?= $this->Html->link(
                    __('ユーザー情報一覧'),
                    ['action' => 'index'],
                    ['class' => 'list-group-item list-group-item-action']
            ) ?>
        </div>
    </aside>

    <!-- 右側メイン -->
    <div class="col-md-9">
        <div class="card">
            <div class="card-header">
                <h1 class="h4 mb-0"><?= h($this->fetch('title')) ?></h1>
            </div>

            <div class="card-body" id="user-import-root">
                <!-- JS が読む設定値（現状維持） -->
                <meta name="csrfToken" content="<?= h($csrfToken) ?>">
                <meta name="importJsonUrl" content="<?= h($this->Url->build(['controller'=>'MUserInfo','action'=>'importJson'])) ?>">
                <meta name="xlsxLocalSrc" content="<?= h($this->Url->assetUrl('js/xlsx.full.min.js')) ?>">

                <!-- CDN（失敗時は ensureXLSX() がフォールバック再読み込み） -->
                <script src="https://cdn.jsdelivr.net/npm/xlsx@0.20.2/dist/xlsx.full.min.js" defer></script>
                <?= $this->Html->script('user-import.js', ['defer' => true]) ?>

                <!-- 上段：ドラッグ＆ドロップと操作 -->
                <section class="mb-3" aria-labelledby="import-area-title">
                    <div id="drop" class="dropzone" role="region" aria-labelledby="import-area-title">
                        <p id="import-area-title" class="fs-6 mb-1">
                            ここに <strong>Excel / CSV</strong> をドラッグ＆ドロップ
                        </p>
                        <p class="muted mb-2">対応: <code>.xlsx</code> <code>.xls</code> <code>.csv</code>（解析はブラウザ内で実行）</p>

                        <div class="d-flex justify-content-center align-items-center flex-wrap gap-2 mt-2">
                            <button id="browseBtn" type="button" class="btn-outline" aria-label="<?= __('ファイルを選択') ?>">ファイルを選択</button>
                            <input id="fileInput" type="file" accept=".xlsx,.xls,.csv" class="d-none" aria-hidden="true">
                            <button id="downloadTemplate" type="button" class="btn-outline" aria-label="<?= __('テンプレートをダウンロード') ?>">テンプレートをダウンロード</button>
                            <span class="muted">※ Excel が使えない環境では自動で CSV を配布します</span>
                        </div>
                    </div>

                    <div class="d-flex align-items-center flex-wrap gap-2 mt-2">
                        <div id="fileInfo" class="muted"></div>
                        <span class="badge-lite" id="countBadge" style="display:none"></span>
                        <span class="badge-lite" id="statusBadge" style="display:none"></span>
                        <button id="downloadErrors" type="button" class="btn-outline" disabled style="display:none">エラー出力</button>
                    </div>

                    <div class="mt-2">
                        <div class="progress-lite" aria-label="<?= __('取り込み進捗') ?>">
                            <div class="bar" id="bar"></div>
                        </div>
                        <div class="d-flex align-items-center flex-wrap gap-2 mt-2">
                            <button id="sendBtn" type="button" class="btn-outline primary" disabled>サーバに送信（500件ずつ）</button>
                            <button id="clearBtn" type="button" class="btn-outline" disabled>リセット</button>
                            <span class="muted">※ 既存の <code>c_login_account</code> はスキップされます。</span>
                        </div>
                    </div>
                </section>

                <!-- 初心者向けガイド -->
                <section class="mb-3" aria-labelledby="guide-title">
                    <h2 id="guide-title" class="h5 mb-2">取り込みガイド（はじめての方向け）</h2>
                    <ol class="mb-2 ps-3">
                        <li><strong>テンプレートをダウンロード</strong>し、Excel で開きます（Excel が無ければ CSV でも OK）。</li>
                        <li>各列を入力します（下表を参考）。<strong>空欄の行は無視</strong>されます。</li>
                        <li>保存して、このページに<strong>ドラッグ＆ドロップ</strong>します。</li>
                        <li>プレビューで <span class="badge-lite ok">OK</span> が多いことを確認し、<strong>サーバに送信</strong>を押します。</li>
                    </ol>

                    <div class="row gy-1">
                        <div class="col-12 col-lg-10">
                            <dl class="row mb-0">
                                <dt class="col-sm-4"><code>login_id</code>（必須）</dt>
                                <dd class="col-sm-8">ログイン ID（例：<code>u0001</code>）。同じ ID が既にある場合はスキップされます。</dd>

                                <dt class="col-sm-4"><code>name</code>（必須）</dt>
                                <dd class="col-sm-8">氏名（例：<code>山田 太郎</code>）</dd>

                                <dt class="col-sm-4"><code>role</code>（必須）</dt>
                                <dd class="col-sm-8">
                                    次のいずれか：数字 <code>0</code>=職員 / <code>1</code>=児童 / <code>3</code>=その他、
                                    日本語 <code>職員</code> / <code>児童</code> / <code>その他</code>（表記ゆれ一部許容）、
                                    英語 <code>staff</code> / <code>child</code> / <code>other</code>
                                </dd>

                                <dt class="col-sm-4"><code>staff_id</code></dt>
                                <dd class="col-sm-8"><strong>role=職員（または 0 / staff）なら必須</strong>。職員番号など（例：<code>S-001</code>）</dd>

                                <dt class="col-sm-4"><code>password</code></dt>
                                <dd class="col-sm-8">未入力なら自動生成（12 桁）。入力時はその値で登録（サーバ側でハッシュ化）</dd>
                            </dl>
                        </div>
                    </div>

                    <details class="mt-2">
                        <summary>よくあるエラー</summary>
                        <ul class="mb-0 ps-3">
                            <li><strong>role が不正</strong>：上の表にある値以外は不可です。</li>
                            <li><strong>職員なのに staff_id が空</strong>：<code>role=職員/0/staff</code> のときは必須です。</li>
                            <li><strong>重複する login_id</strong>：既に同じ ID があると取り込まれません。</li>
                        </ul>
                    </details>
                </section>

                <!-- プレビュー -->
                <section class="mb-3" aria-labelledby="preview-title">
                    <div class="d-flex justify-content-between align-items-end">
                        <h2 id="preview-title" class="h6 mb-0">プレビュー（先頭 50 行）</h2>
                        <p class="muted mb-0" id="headerNote">見出しは自動マッピングされます。</p>
                    </div>

                    <div id="previewWrap" class="mt-2">
                        <table class="preview table" id="previewTable">
                            <caption class="visually-hidden">取り込み内容の先頭 50 行のプレビュー</caption>
                            <thead>
                            <tr>
                                <th scope="col">行</th>
                                <th scope="col">ログインID</th>
                                <th scope="col">氏名</th>
                                <th scope="col">役割</th>
                                <th scope="col">職員番号</th>
                                <th scope="col">パスワード</th>
                                <th scope="col">年齢</th>
                                <th scope="col">年齢区分</th>
                                <th scope="col">性別</th>
                                <th scope="col">部屋名1</th>
                                <th scope="col">部屋名2</th>
                                <th scope="col">部屋名3</th>
                                <th scope="col">検査</th>
                            </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </section>

                <!-- ログ -->
                <section aria-labelledby="log-title">
                    <h2 id="log-title" class="h6">ログ</h2>
                    <div id="status" class="log" aria-live="polite" aria-atomic="false"></div>
                </section>
            </div>
        </div>
    </div>
</div>
