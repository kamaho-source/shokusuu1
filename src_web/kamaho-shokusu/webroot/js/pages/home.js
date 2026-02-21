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
    const btn = document.getElementById('daily-report-noeat');
    const card = document.getElementById('daily-report-card');
    const eatLink = document.getElementById('daily-report-eat');

    if (card && eatLink?.dataset?.date) {
        const hideKey = `daily_report_hide:${eatLink.dataset.date}`;
        if (sessionStorage.getItem(hideKey)) {
            card.remove();
        }
    }

    if (eatLink?.dataset?.date) {
        eatLink.addEventListener('click', () => {
            const hideKey = `daily_report_hide:${eatLink.dataset.date}`;
            sessionStorage.setItem(hideKey, '1');
        });
    }

    if (!btn) return;
    btn.addEventListener('click', async () => {
        const url = btn.dataset.url;
        if (!url) return;
        btn.disabled = true;
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrfToken"]')?.content || '' },
            });
            const data = await res.json();
            if (data && data.ok) {
                if (card) card.remove();
            } else {
                alert(data?.message || '処理に失敗しました。');
                btn.disabled = false;
            }
        } catch (e) {
            alert('通信に失敗しました。');
            btn.disabled = false;
        }
    });
})();
