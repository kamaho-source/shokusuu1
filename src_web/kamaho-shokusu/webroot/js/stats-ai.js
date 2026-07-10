/**
 * 統計AIチャット画面のフロントエンド。
 * SSE ストリーミング（POST + ReadableStream）でAI回答を逐次表示する。
 */
(function () {
    'use strict';

    const messagesEl = document.getElementById('stats-ai-messages');
    const formEl     = document.getElementById('stats-ai-form');
    const inputEl    = document.getElementById('stats-ai-input');
    const sendEl     = document.getElementById('stats-ai-send');
    const suggestEl  = document.getElementById('stats-ai-suggest');

    if (!messagesEl || !formEl || !inputEl || !sendEl) {
        return;
    }

    /** @type {{role: string, content: string}[]} 会話履歴（AIとの往復はIDトークンのまま保持する） */
    const history = [];
    let busy = false;

    /**
     * 会話履歴の保存先。sessionStorage のためリロードしても消えず、
     * タブを閉じると破棄される（共用PCに会話を残さない）。
     */
    const STORAGE_KEY = 'statsAiHistory';
    /** 保存する最大メッセージ数（サーバー側の送信上限より余裕をもたせる） */
    const STORAGE_LIMIT = 40;

    function saveHistory() {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(history.slice(-STORAGE_LIMIT)));
        } catch (e) {
            /* 容量超過等は無視（保存できなくても会話は継続できる） */
        }
    }

    function restoreHistory() {
        let stored;
        try {
            stored = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '[]');
        } catch (e) {
            stored = [];
        }
        if (!Array.isArray(stored)) {
            return;
        }
        for (const msg of stored) {
            if (!msg || typeof msg.content !== 'string') {
                continue;
            }
            if (msg.role !== 'user' && msg.role !== 'assistant') {
                continue;
            }
            history.push({ role: msg.role, content: msg.content });
            appendMessage(msg.role, resolveUserTokens(msg.content));
        }
    }

    function clearHistory() {
        try {
            sessionStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            /* noop */
        }
        history.length = 0;
        messagesEl.querySelectorAll('.stats-ai-msg:not(.greeting)').forEach(function (el) {
            el.remove();
        });
        inputEl.focus();
    }

    /** ハッシュトークン→氏名マップ。AI回答の [U:<ハッシュ>] を表示時のみ氏名へ変換する */
    const userMap = window.STATS_AI_USER_MAP || {};
    /** ハッシュトークン→部屋名マップ。AI回答の [R:<ハッシュ>] を表示時のみ部屋名へ変換する */
    const roomMap = window.STATS_AI_ROOM_MAP || {};

    function resolveUserTokens(text) {
        // [U:<ハッシュ>] → 氏名
        text = text.replace(/\[U:([0-9a-f]+)\]/g, function (match, token) {
            return Object.prototype.hasOwnProperty.call(userMap, token) ? userMap[token] : match;
        });
        // [R:<ハッシュ>] → 部屋名
        text = text.replace(/\[R:([0-9a-f]+)\]/g, function (match, token) {
            return Object.prototype.hasOwnProperty.call(roomMap, token) ? roomMap[token] : match;
        });
        return text;
    }

    /** 質問文に含まれる既知の氏名・部屋名をハッシュトークンへ変換し、外部AIへ送らない */
    function maskUserNames(text) {
        let masked = text;
        // 長い文字列を先に置換することで、部分一致による置換崩れを防ぐ
        const userEntries = Object.keys(userMap)
            .map(function (token) { return { token: token, name: userMap[token], prefix: 'U' }; })
            .filter(function (e) { return !!e.name; });
        const roomEntries = Object.keys(roomMap)
            .map(function (token) { return { token: token, name: roomMap[token], prefix: 'R' }; })
            .filter(function (e) { return !!e.name; });
        const allEntries = userEntries.concat(roomEntries)
            .sort(function (a, b) { return b.name.length - a.name.length; });
        for (const entry of allEntries) {
            if (masked.indexOf(entry.name) !== -1) {
                masked = masked.split(entry.name).join('[' + entry.prefix + ':' + entry.token + ']');
            }
        }
        return masked;
    }

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrfToken"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    /**
     * AI回答をMarkdownとして描画する。
     * 外部AIの出力は信頼できないため、必ずDOMPurifyでサニタイズしてから挿入する。
     * ライブラリ未読込時はプレーンテキスト表示にフォールバックする。
     */
    function renderAssistant(bubble, text) {
        if (window.marked && window.DOMPurify) {
            bubble.classList.add('md');
            bubble.innerHTML = window.DOMPurify.sanitize(window.marked.parse(text));
            return;
        }
        bubble.textContent = text;
    }

    function appendMessage(role, text) {
        const wrap = document.createElement('div');
        wrap.className = 'stats-ai-msg ' + role;
        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        if (role === 'assistant' && text !== '') {
            renderAssistant(bubble, text);
        } else {
            bubble.textContent = text;
        }
        wrap.appendChild(bubble);
        messagesEl.appendChild(wrap);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        return bubble;
    }

    function setBusy(state) {
        busy = state;
        sendEl.disabled = state;
        inputEl.disabled = state;
        sendEl.textContent = state ? '回答中…' : '送信';
    }

    async function ask(rawQuestion) {
        if (busy || rawQuestion === '') {
            return;
        }
        setBusy(true);
        // 表示は入力どおり、AIへはIDトークンへマスクした質問を送る
        const question = maskUserNames(rawQuestion);
        appendMessage('user', rawQuestion);
        history.push({ role: 'user', content: question });
        saveHistory();

        const bubble = appendMessage('assistant', '');
        bubble.classList.add('stats-ai-thinking');
        bubble.textContent = '統計データを確認しています…';

        let answer = '';
        let errored = false;

        try {
            const res = await fetch(window.STATS_AI_STREAM_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken()
                },
                body: JSON.stringify({ messages: history })
            });

            if (!res.ok || !res.body) {
                throw new Error('HTTP ' + res.status);
            }

            const reader  = res.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            for (;;) {
                const { done, value } = await reader.read();
                if (done) {
                    break;
                }
                buffer += decoder.decode(value, { stream: true });

                const parts = buffer.split('\n\n');
                buffer = parts.pop() || '';
                for (const part of parts) {
                    const line = part.trim();
                    if (!line.startsWith('data: ')) {
                        continue;
                    }
                    const payload = line.slice(6);
                    if (payload === '[DONE]') {
                        continue;
                    }
                    let data;
                    try {
                        data = JSON.parse(payload);
                    } catch (e) {
                        continue;
                    }
                    if (data.error) {
                        errored = true;
                        bubble.classList.remove('stats-ai-thinking');
                        bubble.parentElement.classList.add('error');
                        bubble.textContent = data.error;
                        continue;
                    }
                    if (typeof data.content === 'string') {
                        if (answer === '') {
                            bubble.classList.remove('stats-ai-thinking');
                            bubble.textContent = '';
                        }
                        answer += data.content;
                        renderAssistant(bubble, resolveUserTokens(answer));
                        messagesEl.scrollTop = messagesEl.scrollHeight;
                    }
                }
            }
        } catch (e) {
            errored = true;
            bubble.classList.remove('stats-ai-thinking');
            bubble.parentElement.classList.add('error');
            bubble.textContent = '通信エラーが発生しました。時間をおいてお試しください。';
        }

        if (!errored && answer !== '') {
            history.push({ role: 'assistant', content: answer });
            saveHistory();
        }
        if (!errored && answer === '') {
            bubble.classList.remove('stats-ai-thinking');
            bubble.parentElement.classList.add('error');
            bubble.textContent = '回答を取得できませんでした。';
        }
        setBusy(false);
        inputEl.focus();
    }

    formEl.addEventListener('submit', function (e) {
        e.preventDefault();
        const question = inputEl.value.trim();
        inputEl.value = '';
        ask(question);
    });

    if (suggestEl) {
        suggestEl.addEventListener('click', function (e) {
            const btn = e.target.closest('button');
            if (btn) {
                ask(btn.textContent.trim());
            }
        });
    }

    const clearEl = document.getElementById('stats-ai-clear');
    if (clearEl) {
        clearEl.addEventListener('click', function () {
            if (!busy) {
                clearHistory();
            }
        });
    }

    // リロード時に前回までの会話を復元する
    restoreHistory();
})();
