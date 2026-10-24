// 部屋情報の所属メンバーの並び替え（#871）。並べ替えは区分(tbody)の中だけ。保存で一括送信する
(() => {
    const table = document.getElementById('member-order-table');
    const saveBtn = document.getElementById('member-order-save');
    if (!table || !saveBtn) return;

    const undoBtn = document.getElementById('member-order-undo');
    const dirtyBadge = document.getElementById('member-order-dirty');
    const dragToggle = document.getElementById('member-order-drag');
    const message = document.getElementById('member-order-message');
    const csrfToken = document.querySelector('meta[name="csrfToken"]')?.content ?? '';
    const groups = [...table.tBodies];
    const rowsOf = (tbody) => [...tbody.querySelectorAll('tr[data-user-id]')];
    const ids = () => groups.flatMap((g) => rowsOf(g).map((r) => Number(r.dataset.userId)));

    let saved = ids();
    let saving = false;

    const showMessage = (text, ok) => {
        message.textContent = text;
        message.className = `alert ${ok ? 'alert-success' : 'alert-danger'}`;
    };

    // 番号を振り直し、保存済みの並びと違う人数を出す
    const refresh = () => {
        groups.forEach((g) => rowsOf(g).forEach((r, i) => {
            const input = r.querySelector('.member-order-input');
            input.value = input.defaultValue = String(i + 1);
        }));
        const now = ids();
        const dirty = now.filter((id, i) => id !== saved[i]).length;
        dirtyBadge.hidden = dirty === 0;
        dirtyBadge.textContent = `未保存 ${dirty}人`;
        undoBtn.disabled = saveBtn.disabled = dirty === 0 || saving;
    };

    const isDirty = () => ids().some((id, i) => id !== saved[i]);

    // 行を区分内の pos 番目（1始まり）へ移す
    const moveTo = (row, pos) => {
        const list = rowsOf(row.parentElement).filter((r) => r !== row);
        const index = Math.min(Math.max(pos, 1), list.length + 1) - 1;
        const ref = list[index];
        row.parentElement.insertBefore(row, ref ?? null);
    };

    table.addEventListener('keydown', (e) => {
        const input = e.target.closest('.member-order-input');
        if (!input || e.key !== 'Enter') return;
        e.preventDefault();
        const row = input.closest('tr');
        const pos = parseInt(input.value, 10);
        if (Number.isNaN(pos)) {
            input.value = input.defaultValue;
            return;
        }
        moveTo(row, pos);
        const rows = rowsOf(row.parentElement);
        const next = rows[rows.indexOf(row) + 1] ?? rows[0];
        refresh();
        next.querySelector('.member-order-input').focus();
        next.querySelector('.member-order-input').select();
    });
    table.addEventListener('change', (e) => {
        const input = e.target.closest('.member-order-input');
        if (!input || input.value === input.defaultValue) return;
        const pos = parseInt(input.value, 10);
        if (!Number.isNaN(pos)) moveTo(input.closest('tr'), pos);
        refresh();
    });

    // 年齢順（同じ年齢は今の並びのまま、年齢が無い人は末尾）
    table.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-age-sort]');
        if (!btn) return;
        const tbody = btn.closest('tbody');
        const dir = btn.dataset.ageSort === 'asc' ? 1 : -1;
        const rows = rowsOf(tbody);
        rows.map((r, i) => ({ r, i, age: r.dataset.age === '' ? null : Number(r.dataset.age) }))
            .sort((a, b) => {
                if (a.age === null || b.age === null) return (a.age === null) - (b.age === null) || a.i - b.i;
                return (a.age - b.age) * dir || a.i - b.i;
            })
            .forEach(({ r }) => tbody.appendChild(r));
        refresh();
    });

    // ドラッグ（オプション）。区分をまたぐ移動はしない
    let dragging = null;
    dragToggle?.addEventListener('change', () => {
        groups.forEach((g) => rowsOf(g).forEach((r) => {
            r.draggable = dragToggle.checked;
            r.querySelector('.member-order-grip').hidden = !dragToggle.checked;
            r.style.cursor = dragToggle.checked ? 'grab' : '';
        }));
    });
    table.addEventListener('dragstart', (e) => {
        // ドラッグ OFF のときや、入力欄の文字・名前の選択をドラッグしたときは行を動かさない
        if (!dragToggle?.checked || !(e.target instanceof HTMLTableRowElement)) return;
        dragging = e.target;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', dragging.dataset.userId); // Firefox は setData が無いとドラッグが始まらない
    });
    table.addEventListener('dragover', (e) => {
        const over = e.target.closest?.('tr[data-user-id]');
        if (!dragging || !over || over === dragging || over.parentElement !== dragging.parentElement) return;
        e.preventDefault();
        const { top, height } = over.getBoundingClientRect();
        over.parentElement.insertBefore(dragging, e.clientY > top + height / 2 ? over.nextSibling : over);
    });
    table.addEventListener('dragend', () => {
        if (!dragging) return;
        dragging = null;
        refresh();
    });

    undoBtn.addEventListener('click', () => {
        const pos = new Map(saved.map((id, i) => [id, i]));
        groups.forEach((g) => rowsOf(g)
            .sort((a, b) => pos.get(Number(a.dataset.userId)) - pos.get(Number(b.dataset.userId)))
            .forEach((r) => g.appendChild(r)));
        refresh();
    });

    saveBtn.addEventListener('click', async () => {
        // 通信中に並びを変えても、送った並びだけを保存済みにする（後から動かした分は未保存のまま）
        const sent = ids();
        saving = true;
        refresh();
        try {
            const res = await fetch(saveBtn.dataset.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ user_ids: sent }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) {
                showMessage(data.error || `保存できませんでした（HTTP ${res.status}）。`, false);
                return;
            }
            saved = sent;
            showMessage('並び順を保存しました。食数予約の各画面にも反映されます。', true);
        } catch (err) {
            showMessage('通信エラーのため保存できませんでした。', false);
        } finally {
            saving = false;
            refresh();
        }
    });

    window.addEventListener('beforeunload', (e) => {
        if (!isDirty()) return;
        e.preventDefault();
        e.returnValue = '';
    });
})();
