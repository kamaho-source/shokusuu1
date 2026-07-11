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

    /**
     * Undo ボタン付きトースト（予約取り消し後の誤操作回復用）。
     * onUndo が呼ばれた場合はトーストを即座に閉じる。
     */
    function pageToastUndo(message, onUndo, delay) {
        delay = delay || 5000;
        try {
            var wrap = document.getElementById('toastWrap');
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = 'toastWrap';
                wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
                document.body.appendChild(wrap);
            }
            var toastEl = document.createElement('div');
            toastEl.className = 'toast align-items-center text-bg-warning border-0';
            toastEl.role = 'alert';
            var row = document.createElement('div');
            row.className = 'd-flex align-items-center';
            var bodyDiv = document.createElement('div');
            bodyDiv.className = 'toast-body';
            bodyDiv.textContent = String(message);
            var undoBtnEl = document.createElement('button');
            undoBtnEl.type = 'button';
            undoBtnEl.className = 'btn btn-sm btn-light ms-2 me-1 flex-shrink-0 page-toast-undo-btn';
            undoBtnEl.textContent = '元に戻す';
            var closeBtnEl = document.createElement('button');
            closeBtnEl.type = 'button';
            closeBtnEl.className = 'btn-close me-2 ms-1';
            closeBtnEl.setAttribute('data-bs-dismiss', 'toast');
            closeBtnEl.setAttribute('aria-label', 'Close');
            row.appendChild(bodyDiv);
            row.appendChild(undoBtnEl);
            row.appendChild(closeBtnEl);
            toastEl.appendChild(row);
            wrap.appendChild(toastEl);
            var instance = window.bootstrap && window.bootstrap.Toast.getOrCreateInstance(toastEl, { delay: delay });
            undoBtnEl.addEventListener('click', function () {
                if (instance) instance.hide();
                if (typeof onUndo === 'function') onUndo();
            });
            if (instance) instance.show();
            toastEl.addEventListener('hidden.bs.toast', function () { toastEl.remove(); });
        } catch (e) {
            console.error('[pageToastUndo]', e);
        }
    }

    window.pageToastUndo = pageToastUndo;
})();

function notifyUser(message, type) {
    var tone = type || 'warning';
    if (window.pageToast) {
        window.pageToast(message, tone);
        return;
    }
    alert(message);
}
