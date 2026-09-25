(() => {
    const shell = document.querySelector('.dash-shell');
    const btn = document.getElementById('mobile-menu-btn');
    const overlay = document.getElementById('mobile-overlay');
    if (!shell || !btn) return;

    const closeMenu = () => {
        shell.classList.add('mobile-sidebar-collapsed');
        shell.classList.remove('mobile-sidebar-open');
    };
    const openMenu = () => {
        shell.classList.remove('mobile-sidebar-collapsed');
        shell.classList.add('mobile-sidebar-open');
    };

    closeMenu();
    btn.addEventListener('click', () => {
        if (shell.classList.contains('mobile-sidebar-collapsed')) {
            openMenu();
        } else {
            closeMenu();
        }
    });
    if (overlay) overlay.addEventListener('click', closeMenu);
})();

(() => {
    const noeatBtn = document.getElementById('daily-report-noeat');
    const card     = document.getElementById('daily-report-card');

    if (!noeatBtn || !card) return;

    const escapeHtml = (v) => String(v ?? '').replace(/[&<>"']/g, (m) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[m]));

    /**
     * 登録結果をカードに残す。
     *
     * 以前はカードを消していたが、保育中に注意がそれる場面では
     * 「押せたのか」「何を登録したのか」が分からなくなる。
     * 何を登録したかと、間違えたときの戻り方を画面に残す。
     */
    const showResult = (message) => {
        const editUrl = card.dataset.editUrl || '';
        card.classList.add('is-done');
        card.innerHTML =
            '<div class="alert-left">' +
                '<div class="alert-icon" aria-hidden="true"><i class="bi bi-check-circle-fill"></i></div>' +
                '<div>' +
                    '<div class="alert-title">本日分を「食べない」で登録しました</div>' +
                    '<div class="alert-sub">' + escapeHtml(message || '変更する場合は「修正する」から操作してください。') + '</div>' +
                '</div>' +
            '</div>' +
            (editUrl
                ? '<div class="alert-actions"><a class="btn-soft" href="' + escapeHtml(editUrl) + '">修正する</a></div>'
                : '');
        card.setAttribute('role', 'status');
    };

    /**
     * 失敗はダイアログで流さず、カード内に残す。
     * 通信失敗時は登録できたか分からないため、状態を確認してから
     * 操作し直せるよう導線を添える。
     */
    const showError = (message, { canRetry = true, suggestCheck = false } = {}) => {
        let box = card.querySelector('.daily-report-error');
        if (!box) {
            box = document.createElement('div');
            box.className = 'alert-sub daily-report-error text-danger mt-2';
            box.setAttribute('role', 'alert');
            const left = card.querySelector('.alert-left > div:last-child') || card;
            left.appendChild(box);
        }
        const editUrl = card.dataset.editUrl || '';
        box.innerHTML = escapeHtml(message) +
            (suggestCheck && editUrl
                ? ' <a href="' + escapeHtml(editUrl) + '">登録状態を確認する</a>'
                : '');
        noeatBtn.disabled = !canRetry;
    };

    noeatBtn.addEventListener('click', async () => {
        const url = noeatBtn.dataset.url;
        if (!url) return;

        if (window.ConfirmPopup) {
            const ok = await window.ConfirmPopup.show('本日の食事を「食べない」として登録してよろしいですか？');
            if (!ok) return;
        }

        const errorBox = card.querySelector('.daily-report-error');
        if (errorBox) errorBox.remove();

        noeatBtn.disabled = true;
        const originalLabel = noeatBtn.textContent;
        noeatBtn.textContent = '登録中...';

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrfToken"]')?.content || '' },
            });
            const data = await res.json();
            if (data && data.ok) {
                showResult(data.message);
                return;
            }
            noeatBtn.textContent = originalLabel;
            showError(data?.message || '処理に失敗しました。もう一度お試しください。');
        } catch (e) {
            noeatBtn.textContent = originalLabel;
            // 送信できたかどうか分からないため、二重登録を避けて状態確認を促す
            showError('通信に失敗しました。登録できているか確認してください。', { suggestCheck: true });
        }
    });
})();
