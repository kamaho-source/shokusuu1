// 部屋情報の所属メンバーの並び替え（#871）。並べ替えは区分(tbody)の中だけ。
// 行の hidden user_ids[] が画面の並び順のまま、部屋情報のフォームの［保存］で送られる
(() => {
    const table = document.getElementById('member-order-table');
    const form = table?.closest('form');
    if (!table || !form) return;

    const undoBtn = document.getElementById('member-order-undo');
    const dirtyBadge = document.getElementById('member-order-dirty');
    const dragToggle = document.getElementById('member-order-drag');
    const groups = [...table.tBodies];
    const rowsOf = (tbody) => [...tbody.querySelectorAll('tr[data-user-id]')];
    const ids = () => groups.flatMap((g) => rowsOf(g).map((r) => Number(r.dataset.userId)));

    const saved = ids();
    let submitting = false;
    let lastMoved = null; // 最後に動かした行（順番欄を黄色にする）

    // 順番・行番号を振り直し、保存済みの並びから動いた行に「変更」を付ける
    const refresh = () => {
        const pos = new Map(saved.map((id, i) => [id, i]));
        let row = 0;
        let dirty = 0;
        groups.forEach((g) => rowsOf(g).forEach((r, i) => {
            const input = r.querySelector('.member-order-input');
            input.value = input.defaultValue = String(i + 1);
            input.classList.toggle('chg', r === lastMoved);
            r.querySelector('.rh').textContent = String(row + 1);
            const changed = pos.get(Number(r.dataset.userId)) !== row;
            r.querySelector('.chgmark').textContent = changed ? '変更' : '';
            dirty += changed ? 1 : 0;
            row++;
        }));
        dirtyBadge.textContent = dirty ? `● 未保存 ${dirty}人` : '保存済み';
        dirtyBadge.classList.toggle('dirty', dirty > 0);
        if (undoBtn) undoBtn.disabled = dirty === 0;
    };

    const isDirty = () => ids().some((id, i) => id !== saved[i]);

    // 行を区分内の pos 番目（1始まり）へ移す
    const moveTo = (row, pos) => {
        lastMoved = row;
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
        lastMoved = null;
        refresh();
    });

    // ドラッグ（オプション）。区分をまたぐ移動はしない。
    // ドラッグ中は行を動かさず、入る位置に線を出す。離したときに初めて移す
    let dragging = null;
    let drop = null; // { row, after }
    const clearDrop = () => {
        table.querySelectorAll('.drop-before, .drop-after').forEach((r) => r.classList.remove('drop-before', 'drop-after'));
        drop = null;
    };
    dragToggle?.addEventListener('change', () => {
        table.classList.toggle('drag-on', dragToggle.checked);
        groups.forEach((g) => rowsOf(g).forEach((r) => { r.draggable = dragToggle.checked; }));
    });
    table.addEventListener('dragstart', (e) => {
        // ドラッグ OFF のときや、入力欄の文字・名前の選択をドラッグしたときは行を動かさない
        if (!dragToggle?.checked || !(e.target instanceof HTMLTableRowElement)) return;
        dragging = e.target;
        dragging.classList.add('dragging');
        dragging.parentElement.classList.add('drop-zone');
        table.classList.add('drag-active');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', dragging.dataset.userId); // Firefox は setData が無いとドラッグが始まらない
    });
    table.addEventListener('dragover', (e) => {
        if (!dragging) return;
        const tr = e.target.closest?.('tr');
        if (!tr || tr.parentElement !== dragging.parentElement) {
            clearDrop();
            return;
        }
        e.preventDefault();
        const rows = rowsOf(tr.parentElement);
        // 区分の見出し行の上は「先頭へ」
        const target = tr.matches('[data-user-id]') ? { row: tr, after: (() => {
            const { top, height } = tr.getBoundingClientRect();
            return e.clientY > top + height / 2;
        })() } : { row: rows[0], after: false };
        // 今の位置と変わらないところには線を出さない
        const list = rows.filter((r) => r !== dragging);
        const at = target.row === dragging ? rows.indexOf(dragging) : list.indexOf(target.row) + (target.after ? 1 : 0);
        clearDrop();
        if (at === rows.indexOf(dragging)) return;
        drop = target;
        target.row.classList.add(target.after ? 'drop-after' : 'drop-before');
    });
    table.addEventListener('drop', (e) => {
        if (!dragging) return;
        e.preventDefault();
        if (drop) {
            drop.row.parentElement.insertBefore(dragging, drop.after ? drop.row.nextSibling : drop.row);
            lastMoved = dragging;
        }
    });
    table.addEventListener('dragend', () => {
        if (!dragging) return;
        clearDrop();
        dragging.classList.remove('dragging');
        dragging.parentElement.classList.remove('drop-zone');
        table.classList.remove('drag-active');
        dragging = null;
        refresh();
    });

    undoBtn?.addEventListener('click', () => {
        const pos = new Map(saved.map((id, i) => [id, i]));
        groups.forEach((g) => rowsOf(g)
            .sort((a, b) => pos.get(Number(a.dataset.userId)) - pos.get(Number(b.dataset.userId)))
            .forEach((r) => g.appendChild(r)));
        lastMoved = null;
        refresh();
    });

    // ［保存］を押すまで送信しない（部屋名などの入力欄で Enter を押しても送らない）
    form.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && e.target instanceof HTMLInputElement) e.preventDefault();
    });
    form.addEventListener('submit', () => { submitting = true; });

    window.addEventListener('beforeunload', (e) => {
        if (submitting || !isDirty()) return;
        e.preventDefault();
        e.returnValue = '';
    });
})();
