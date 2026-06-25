document.addEventListener('DOMContentLoaded', function() {
    const aiButton = document.getElementById('ai-assistant-fab');
    const aiPanel = document.getElementById('ai-assistant-panel');
    const aiPanelClose = document.getElementById('ai-panel-close');
    const aiForm = document.getElementById('ai-assistant-form');
    const aiQuestion = document.getElementById('ai-question');
    const aiChatBox = document.getElementById('ai-chat-box');
    const aiSubmitBtn = document.getElementById('ai-submit-btn');
    const aiLoadingFab = document.getElementById('ai-assistant-loading-fab');

    if (!aiButton || !aiPanel) return;

    aiButton.addEventListener('click', function() {
        aiPanel.classList.toggle('show');
        if (aiPanel.classList.contains('show')) {
            aiQuestion.focus();
            scrollToBottom();
        }
    });

    if (aiPanelClose) {
        aiPanelClose.addEventListener('click', function() {
            aiPanel.classList.remove('show');
        });
    }

    // パネル外クリックで閉じる（オプション）
    document.addEventListener('click', function(e) {
        if (!aiPanel.contains(e.target) && !aiButton.contains(e.target) && aiPanel.classList.contains('show')) {
            aiPanel.classList.remove('show');
        }
    });

    aiForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const question = aiQuestion.value.trim();
        if (!question || aiSubmitBtn.disabled) return;

        // ユーザーの質問を表示
        appendMessage('user', question);
        aiQuestion.value = '';
        
        // ローディング表示（IDではなく要素参照を直接保持する）
        const loadingEl = createLoadingMessage();
        aiChatBox.appendChild(loadingEl);
        aiChatBox.scrollTop = aiChatBox.scrollHeight;

        aiSubmitBtn.disabled = true;
        if (aiLoadingFab) aiLoadingFab.classList.remove('d-none');
        const robotIcon = aiButton ? aiButton.querySelector('i') : null;
        if (robotIcon) robotIcon.classList.add('d-none');

        // コンテキストの取得
        let context = `現在のページ: ${document.title}\nURL: ${window.location.href}`;
        
        // ページ内の主要な見出しや説明文があればコンテキストに含める
        const mainHeading = document.querySelector('h1, h2');
        if (mainHeading) {
            context += `\n画面見出し: ${mainHeading.innerText.trim()}`;
        }

        const description = document.querySelector('.description, .help-block');
        if (description) {
            context += `\n画面説明: ${description.innerText.trim().substring(0, 200)}`;
        }

        // ベースパスを取得（サブディレクトリ運用への対応）
        // meta[name="csrfToken"] の存在確認
        const csrfTokenMeta = document.querySelector('meta[name="csrfToken"]');
        const csrfToken = csrfTokenMeta ? csrfTokenMeta.content : '';

        // APIのURLを構築（PHP側で生成された URL があれば優先する）
        const askUrl = window.AI_ASSISTANT_ASK_URL || '/AiAssistant/ask';

        fetch(askUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: new URLSearchParams({
                'question': question,
                'context': context
            })
        })
        .then(async response => {
            const contentType = response.headers.get('content-type');
            if (!response.ok) {
                let errorMessage = 'エラーが発生しました';
                if (contentType && contentType.includes('application/json')) {
                    const errData = await response.json();
                    errorMessage = errData.message || errorMessage;
                } else {
                    errorMessage = `サーバーエラーが発生しました (Status: ${response.status})`;
                }
                throw new Error(errorMessage);
            }
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('サーバーから不正な応答（HTML）が返されました。URL設定を確認してください。');
            }
            return response.json();
        })
        .then(data => {
            loadingEl.remove();
            if (aiLoadingFab) aiLoadingFab.classList.add('d-none');
            if (robotIcon) robotIcon.classList.remove('d-none');
            
            // 回答内のURLをリンクに変換（安全にHTMLをエスケープした後にリンク化）
            const escapedAnswer = data.answer
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
            
            const urlRegex = /(https?:\/\/[^\s<]+|\[([^\]]+)\]\(([^\)]+)\))/g;
            const linkedAnswer = escapedAnswer.replace(urlRegex, function(match, url, label, path) {
                if (url && !label) {
                    // 通常のURL (http...)
                    return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a>';
                } else if (label && path) {
                    // Markdown形式のリンク [ラベル](パス)
                    // セキュリティのため、パスが http で始まるか / で始まるもののみ許可
                    if (path.startsWith('http') || path.startsWith('/')) {
                        return '<a href="' + path + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
                    }
                    return match;
                }
                return match;
            });
            
            const formattedAnswer = linkedAnswer.replace(/\n/g, '<br>');
            appendMessage('ai', formattedAnswer);
        })
        .catch(error => {
            loadingEl.remove();
            if (aiLoadingFab) aiLoadingFab.classList.add('d-none');
            if (robotIcon) robotIcon.classList.remove('d-none');
            appendMessage('ai', `<span class="text-danger">エラー: ${error.message}</span>`);
        })
        .finally(() => {
            aiSubmitBtn.disabled = false;
        });
    });

    function appendMessage(role, text) {
        const div = document.createElement('div');
        div.className = `mb-3 p-2 rounded ${role === 'user' ? 'bg-light text-end' : 'bg-info bg-opacity-10'}`;
        div.innerHTML = `<strong>${role === 'user' ? 'あなた' : 'AI助手'}:</strong><br>${text}`;
        aiChatBox.appendChild(div);
        aiChatBox.scrollTop = aiChatBox.scrollHeight;
    }

    function createLoadingMessage() {
        const div = document.createElement('div');
        div.className = 'mb-3 p-2 rounded bg-info bg-opacity-10';
        div.innerHTML = '<strong>AI助手:</strong><br><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div> 考え中...';
        return div;
    }

    function scrollToBottom() {
        aiChatBox.scrollTop = aiChatBox.scrollHeight;
    }
});