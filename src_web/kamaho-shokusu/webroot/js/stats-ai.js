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

    /** @type {{role: string, content: string}[]} 会話履歴 */
    const history = [];
    let busy = false;

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrfToken"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    function appendMessage(role, text) {
        const wrap = document.createElement('div');
        wrap.className = 'stats-ai-msg ' + role;
        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        bubble.textContent = text;
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

    async function ask(question) {
        if (busy || question === '') {
            return;
        }
        setBusy(true);
        appendMessage('user', question);
        history.push({ role: 'user', content: question });

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
                        bubble.textContent = answer;
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
})();
