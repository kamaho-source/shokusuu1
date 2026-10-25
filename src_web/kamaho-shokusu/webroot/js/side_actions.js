/**
 * 操作パネル（templates/element/side_actions.php）の動き。
 *  - 削除ボタン: ConfirmPopup で確認してからフォームを送信する（キャンセルなら送信しない）
 *  - スマートフォンの操作バー: 入力欄にフォーカスしている間は隠す（キーボードに重ならないように）
 */
(function () {
    'use strict';

    var FIELDS = 'input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]):not([type=hidden]),textarea,select';

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-sa-confirm]') : null;
        if (!button) {
            return;
        }
        event.preventDefault();

        var form = button.closest('form');
        if (!form) {
            return;
        }
        var message = button.getAttribute('data-sa-confirm');

        // 「その他」シートが開いているときは、閉じてから確認を出す（確認ダイアログが隠れないように）
        var sheet = button.closest('.offcanvas');
        var closed = Promise.resolve();
        if (sheet && sheet.classList.contains('show') && window.bootstrap) {
            closed = new Promise(function (resolve) {
                sheet.addEventListener('hidden.bs.offcanvas', resolve, { once: true });
                window.bootstrap.Offcanvas.getOrCreateInstance(sheet).hide();
            });
        }

        closed.then(function () {
            if (!window.ConfirmPopup) {
                return window.confirm(message);
            }
            return window.ConfirmPopup.show(message, { okLabel: '削除する', okColor: 'danger', type: 'danger' });
        }).then(function (ok) {
            if (ok) {
                form.submit();
            }
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        var bar = document.querySelector('.sa-bar');
        if (!bar) {
            return;
        }
        document.body.classList.add('has-sa-bar');

        document.addEventListener('focusin', function (event) {
            if (event.target.matches && event.target.matches(FIELDS)) {
                bar.classList.add('sa-bar--hidden');
            }
        });
        document.addEventListener('focusout', function () {
            // 別の入力欄へ移るときのちらつきを避けるため、移動先を見てから戻す
            setTimeout(function () {
                var active = document.activeElement;
                if (!(active && active.matches && active.matches(FIELDS))) {
                    bar.classList.remove('sa-bar--hidden');
                }
            }, 0);
        });
    });
})();
