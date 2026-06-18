/**
 * Toast通知モジュール
 * pageToast / notifyUser を提供する。
 * treservation_config.js の後にロードすること。
 */
(function () {
    function pageToast(message, type) {
        type = type || 'warning';
        try {
            var wrap = document.getElementById('toastWrap');
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = 'toastWrap';
                wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
                document.body.appendChild(wrap);
            }
            var toastEl = document.createElement('div');
            toastEl.className = 'toast align-items-center text-bg-' +
                (type === 'success' ? 'success' : (type === 'warning' ? 'warning' : 'danger')) + ' border-0';
            toastEl.role = 'alert';
            toastEl.ariaLive = 'assertive';
            toastEl.ariaAtomic = 'true';
            toastEl.innerHTML = '<div class="d-flex"><div class="toast-body">' + String(message) + '</div>' +
                '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
                '</div>';
            wrap.appendChild(toastEl);
            var instance = window.bootstrap && window.bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 3500 });
            if (instance) instance.show();
            toastEl.addEventListener('hidden.bs.toast', function () { toastEl.remove(); });
        } catch (e) {
            console.error('[pageToast]', e);
        }
    }

    window.pageToast = pageToast;
})();

function notifyUser(message, type) {
    var tone = type || 'warning';
    if (window.pageToast) {
        window.pageToast(message, tone);
        return;
    }
    alert(message);
}
