document.addEventListener('DOMContentLoaded', function() {
    const aiButton      = document.getElementById('ai-assistant-fab');
    const aiPanel       = document.getElementById('ai-assistant-panel');
    const aiPanelClose  = document.getElementById('ai-panel-close');
    const aiForm        = document.getElementById('ai-assistant-form');
    const aiQuestion    = document.getElementById('ai-question');
    const aiChatBox     = document.getElementById('ai-chat-box');
    const aiSubmitBtn   = document.getElementById('ai-submit-btn');
    const aiLoadingFab  = document.getElementById('ai-assistant-loading-fab');

    if (!aiButton || !aiPanel) return;

    // 会話履歴（マルチターン）
    let conversationMessages = [];
    let suggestionsLoaded    = false;

    const csrfToken    = (document.querySelector('meta[name="csrfToken"]') || {}).content || '';
    const askStreamUrl = window.AI_ASSISTANT_STREAM_URL || '/AiAssistant/askStream';
    const suggestUrl   = window.AI_ASSISTANT_SUGGEST_URL || '/AiAssistant/suggestions';
    const feedbackUrl  = window.AI_ASSISTANT_FEEDBACK_URL || '/AiAssistant/feedback';
    const appBase      = (window.AI_ASSISTANT_BASE_URL || '').replace(/\/$/, '');

    // ── パネル開閉 ─────────────────────────────────────────────
    aiButton.addEventListener('click', function() {
        aiPanel.classList.toggle('show');
        if (aiPanel.classList.contains('show')) {
            aiQuestion.focus();
            scrollToBottom();
            if (!suggestionsLoaded) loadSuggestions();
        }
    });

    if (aiPanelClose) {
        aiPanelClose.addEventListener('click', () => aiPanel.classList.remove('show'));
    }

    document.addEventListener('click', function(e) {
        if (aiPanel.classList.contains('show')
            && !aiPanel.contains(e.target)
            && !aiButton.contains(e.target)) {
            aiPanel.classList.remove('show');
        }
    });

    // ── サジェスト質問の読み込み ──────────────────────────────
    function loadSuggestions() {
        suggestionsLoaded = true;
        fetch(suggestUrl, {
            headers: { 'X-CSRF-Token': csrfToken, 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.ok ? r.json() : null)
        .then(data => {
            if (!data || !Array.isArray(data.suggestions) || data.suggestions.length === 0) return;
            renderSuggestions(data.suggestions);
        })
        .catch(() => {/* サジェスト取得失敗はサイレント */});
    }

    function renderSuggestions(suggestions) {
        const existing = document.getElementById('ai-suggestions');
        if (existing) existing.remove();

        const wrapper = document.createElement('div');
        wrapper.id        = 'ai-suggestions';
        wrapper.className = 'd-flex flex-wrap gap-1 px-2 pb-2';

        suggestions.forEach(text => {
            const btn = document.createElement('button');
            btn.type      = 'button';
            btn.className = 'btn btn-sm btn-outline-secondary';
            btn.style.fontSize = '0.75rem';
            btn.textContent = text;
            btn.addEventListener('click', () => {
                aiQuestion.value = text;
                aiQuestion.focus();
            });
            wrapper.appendChild(btn);
        });

        aiForm.parentNode.insertBefore(wrapper, aiForm);
    }

    // ── フォーム送信（ストリーミング + マルチターン） ─────────
    aiForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const question = aiQuestion.value.trim();
        if (!question || aiSubmitBtn.disabled) return;

        aiQuestion.value = '';

        const isFirstTurn = conversationMessages.length === 0;
        const userContent = buildUserContent(question, isFirstTurn);

        conversationMessages.push({ role: 'user', content: userContent });

        appendUserMessage(question);

        const aiMsgEl = createAiMessageElement();
        aiChatBox.appendChild(aiMsgEl);
        scrollToBottom();

        setLoadingState(true);

        let fullAnswer = '';

        fetch(askStreamUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ messages: conversationMessages }),
        })
        .then(response => {
            if (!response.ok || !response.body) {
                return response.text().then(t => { throw new Error('サーバーエラー: ' + response.status); });
            }
            setLoadingState(false);

            const reader  = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer    = '';

            const contentEl = aiMsgEl.querySelector('.ai-msg-content');

            function readChunk() {
                reader.read().then(({ done, value }) => {
                    if (done) {
                        conversationMessages.push({ role: 'assistant', content: fullAnswer });
                        showFeedbackButtons(aiMsgEl, question.length, fullAnswer.length);
                        scrollToBottom();
                        return;
                    }

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop();

                    for (const line of lines) {
                        if (!line.startsWith('data: ')) continue;
                        const payload = line.slice(6).trim();
                        if (payload === '[DONE]') continue;

                        let parsed;
                        try { parsed = JSON.parse(payload); } catch { continue; }

                        if (parsed.error) {
                            contentEl.innerHTML = '<span class="text-danger">エラー: ' + escHtml(parsed.error) + '</span>';
                            scrollToBottom();
                            return;
                        }
                        if (parsed.content) {
                            fullAnswer += parsed.content;
                            contentEl.innerHTML = formatAnswer(fullAnswer);
                            scrollToBottom();
                        }
                    }

                    readChunk();
                });
            }
            readChunk();
        })
        .catch(err => {
            setLoadingState(false);
            const contentEl = aiMsgEl.querySelector('.ai-msg-content');
            contentEl.innerHTML = '<span class="text-danger">エラー: ' + escHtml(err.message) + '</span>';
            conversationMessages.pop(); // 失敗した質問を履歴から除去
            scrollToBottom();
        });
    });

    // ── フィードバック ──────────────────────────────────────────
    function showFeedbackButtons(msgEl, questionLen, answerLen) {
        const fbDiv = document.createElement('div');
        fbDiv.className = 'd-flex gap-1 mt-1';
        fbDiv.innerHTML = '<small class="text-muted me-1">役に立ちましたか？</small>'
            + '<button type="button" class="btn btn-sm btn-outline-success py-0 px-1" data-rating="good">👍</button>'
            + '<button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" data-rating="bad">👎</button>';

        fbDiv.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', function() {
                sendFeedback(this.dataset.rating, questionLen, answerLen);
                fbDiv.innerHTML = '<small class="text-muted">フィードバックありがとうございます</small>';
            });
        });

        msgEl.appendChild(fbDiv);
    }

    function sendFeedback(rating, questionLen, answerLen) {
        fetch(feedbackUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ rating, question_length: questionLen, answer_length: answerLen }),
        }).catch(() => {/* フィードバック失敗はサイレント */});
    }

    // ── 会話リセット ────────────────────────────────────────────
    const resetBtn = document.getElementById('ai-reset-btn');
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            conversationMessages = [];
            aiChatBox.innerHTML  = '';
            suggestionsLoaded    = false;
            loadSuggestions();
        });
    }

    // ── ユーティリティ ──────────────────────────────────────────
    function buildUserContent(question, isFirstTurn) {
        if (!isFirstTurn) return question;

        let ctx = '現在のページ: ' + document.title + '\nURL: ' + window.location.href;
        const heading = document.querySelector('h1, h2');
        if (heading) ctx += '\n画面見出し: ' + heading.innerText.trim().substring(0, 100);
        return '【現在の画面コンテキスト】\n' + ctx + '\n\n【ユーザーの質問】\n' + question;
    }

    function appendUserMessage(text) {
        const div = document.createElement('div');
        div.className = 'mb-3 p-2 rounded bg-light text-end';
        div.innerHTML = '<strong>あなた:</strong><br>' + escHtml(text);
        aiChatBox.appendChild(div);
        scrollToBottom();
    }

    function createAiMessageElement() {
        const div = document.createElement('div');
        div.className = 'mb-3 p-2 rounded bg-info bg-opacity-10';
        div.innerHTML = '<strong>AI助手:</strong><br>'
            + '<span class="ai-msg-content">'
            + '<span class="spinner-border spinner-border-sm me-1" role="status"></span>考え中...'
            + '</span>';
        return div;
    }

    function formatAnswer(text) {
        const escaped = escHtml(text);
        const linked  = escaped.replace(
            /(https?:\/\/[^\s<]+|\[([^\]]+)\]\(([^)]+)\))/g,
            function(match, url, label, path) {
                if (url && !label) {
                    return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a>';
                }
                if (label && path) {
                    const href = path.startsWith('/') ? appBase + path : path;
                    return '<a href="' + href + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
                }
                return match;
            }
        );
        return linked.replace(/\n/g, '<br>');
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setLoadingState(loading) {
        aiSubmitBtn.disabled = loading;
        const robotIcon = aiButton ? aiButton.querySelector('i') : null;
        if (aiLoadingFab) aiLoadingFab.classList.toggle('d-none', !loading);
        if (robotIcon)    robotIcon.classList.toggle('d-none', loading);
    }

    function scrollToBottom() {
        aiChatBox.scrollTop = aiChatBox.scrollHeight;
    }
});
