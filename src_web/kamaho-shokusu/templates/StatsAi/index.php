<?php
/**
 * 統計AIチャット画面（管理者専用）
 *
 * @var \App\View\AppView $this
 */
?>
<style>
    .stats-ai-shell { max-width: 860px; margin: 0 auto; }
    .stats-ai-messages {
        min-height: 320px;
        max-height: 60vh;
        overflow-y: auto;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 16px;
    }
    .stats-ai-msg { margin-bottom: 12px; display: flex; }
    .stats-ai-msg .bubble {
        max-width: 85%;
        padding: 10px 14px;
        border-radius: 12px;
        white-space: pre-wrap;
        word-break: break-word;
        font-size: .95rem;
    }
    .stats-ai-msg.user { justify-content: flex-end; }
    .stats-ai-msg.user .bubble { background: #2563eb; color: #fff; border-bottom-right-radius: 4px; }
    .stats-ai-msg.assistant .bubble { background: #f1f5f9; color: #1e293b; border-bottom-left-radius: 4px; }
    .stats-ai-msg.error .bubble { background: #fee2e2; color: #b91c1c; }
    /* Markdownレンダリング時の調整（AI回答のみ） */
    .stats-ai-msg.assistant .bubble.md { white-space: normal; }
    .bubble.md > :last-child { margin-bottom: 0; }
    .bubble.md p { margin: 0 0 8px; }
    .bubble.md h1, .bubble.md h2, .bubble.md h3, .bubble.md h4 {
        font-size: 1rem; font-weight: 700; margin: 10px 0 6px;
    }
    .bubble.md ul, .bubble.md ol { margin: 0 0 8px; padding-left: 1.4em; }
    .bubble.md li { margin-bottom: 2px; }
    .bubble.md table { border-collapse: collapse; margin: 8px 0; font-size: .85rem; }
    .bubble.md th, .bubble.md td { border: 1px solid #cbd5e1; padding: 4px 10px; text-align: left; }
    .bubble.md thead th { background: #e2e8f0; }
    .bubble.md code { background: #e2e8f0; border-radius: 4px; padding: 1px 5px; font-size: .85em; }
    .bubble.md hr { margin: 8px 0; }
    .stats-ai-suggest .btn { margin: 0 6px 6px 0; }
    .stats-ai-thinking { color: #64748b; font-size: .85rem; }
</style>

<div class="stats-ai-shell">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h1 class="fs-3 m-0">📊 統計AI</h1>
        <div class="d-flex align-items-center gap-2">
            <button type="button" id="stats-ai-clear" class="btn btn-sm btn-outline-secondary">🗑 会話をクリア</button>
            <span class="badge bg-secondary">管理者専用</span>
        </div>
    </div>
    <p class="text-muted small">
        食数・承認・利用状況の集計データをもとにAIが回答します。個人名は外部AIへ送信されず、IDトークンをこの画面上で氏名に変換して表示します。
    </p>

    <div id="stats-ai-messages" class="stats-ai-messages mb-3" aria-live="polite">
        <div class="stats-ai-msg assistant greeting">
            <div class="bubble">こんにちは。直近4週間と今後1週間の統計データを参照できます。食数の傾向・部屋別の集計・承認状況などについて質問してください。</div>
        </div>
    </div>

    <div class="stats-ai-suggest mb-2" id="stats-ai-suggest">
        <button type="button" class="btn btn-sm btn-outline-primary">今週の食数を食種別に教えて</button>
        <button type="button" class="btn btn-sm btn-outline-primary">部屋別の食数が多い順に教えて</button>
        <button type="button" class="btn btn-sm btn-outline-primary">食べる率と直前変更の傾向は？</button>
        <button type="button" class="btn btn-sm btn-outline-primary">承認待ちは何件ある？</button>
        <button type="button" class="btn btn-sm btn-outline-primary">食べない申告が多い利用者は？</button>
        <button type="button" class="btn btn-sm btn-outline-primary">部屋別の子供の使用率は？</button>
        <button type="button" class="btn btn-sm btn-outline-primary">大人（職員）の使用率を教えて</button>
        <button type="button" class="btn btn-sm btn-outline-primary">最も入力していない職員は？</button>
    </div>

    <form id="stats-ai-form" class="d-flex gap-2">
        <input type="text" id="stats-ai-input" class="form-control"
               placeholder="統計について質問を入力…" autocomplete="off" maxlength="500">
        <button type="submit" id="stats-ai-send" class="btn btn-primary flex-shrink-0">送信</button>
    </form>
</div>

<script>
    window.STATS_AI_STREAM_URL = <?= json_encode($this->Url->build(['controller' => 'StatsAi', 'action' => 'askStream']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    // AI回答内の [U:<ID>] トークンを氏名へ変換するためのマップ（氏名は外部AIへ送信されない）
    window.STATS_AI_USER_MAP = <?= json_encode($userMap ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?php /* AI回答のMarkdown表示用。DOMPurifyでサニタイズしてから描画する（XSS対策） */ ?>
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
<?= $this->Html->script('stats-ai') ?>
