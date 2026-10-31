// 部屋情報の所属メンバーの並び替え（#871）。区分（職員・児童・その他）をまたいで並べられる。
// 「順番」の数字は何人分でも入れておけて、行はすぐには動かさない。［保存］のときにまとめてその順に並べ、
// 行の hidden user_ids[] を並んだ順のまま、部屋情報のフォームで送る
(() => {
    const table = document.getElementById('member-order-table');
    const form = table?.closest('form');
    if (!table || !form) return;

    const undoBtn = document.getElementById('member-order-undo');
    const dirtyBadge = document.getElementById('member-order-dirty');
    const dragToggle = document.getElementById('member-order-drag');
    const tbody = table.tBodies[0];
    const rows = () => [...tbody.querySelectorAll('tr[data-user-id]')];
    const inputOf = (r) => r.querySelector('.member-order-input');
    const ids = () => rows().map((r) => Number(r.dataset.userId));

    const saved = ids();
    let submitting = false;

    // 数字を書き換えたが、まだ並べていない行
    const isEdited = (r) => inputOf(r).value !== inputOf(r).defaultValue;

    // 今の並びで順番・行番号を振り直し、保存済みの並びから動いた行・数字を書き換えた行に「変更」を付ける
    const refresh = () => {
        const pos = new Map(saved.map((id, i) => [id, i]));
        let dirty = 0;
        rows().forEach((r, i) => {
            const input = inputOf(r);
            const edited = isEdited(r);
            if (!edited) input.value = String(i + 1);
            input.defaultValue = String(i + 1);
            input.classList.toggle('chg', edited);
            r.querySelector('.rh').textContent = String(i + 1);
            const changed = edited || pos.get(Number(r.dataset.userId)) !== i;
            r.querySelector('.chgmark').textContent = changed ? '変更' : '';
            dirty += changed ? 1 : 0;
        });
        dirtyBadge.textContent = dirty ? `● 未保存 ${dirty}人` : '保存済み';
        dirtyBadge.classList.toggle('dirty', dirty > 0);
        if (undoBtn) undoBtn.disabled = dirty === 0;
    };

    // 入っている数字の順に並べる。同じ数字なら書き換えた行が先、それ以外は今の並びのまま。数字が空・不正なら今の位置
    const applyNumbers = () => {
        rows()
            .map((r, i) => {
                const n = parseInt(inputOf(r).value, 10);
                return { r, i, n: Number.isNaN(n) ? i + 1 : n, edited: isEdited(r) };
            })
            .sort((a, b) => a.n - b.n || b.edited - a.edited || a.i - b.i)
            .forEach(({ r }) => {
                inputOf(r).value = inputOf(r).defaultValue;
                tbody.appendChild(r);
            });
        refresh();
    };

    const isDirty = () => rows().some(isEdited) || ids().some((id, i) => id !== saved[i]);

    // Enter は送信せず、次の行の順番へ（Excel のように続けて打てる）
    table.addEventListener('keydown', (e) => {
        const input = e.target.closest('.member-order-input');
        if (!input || e.key !== 'Enter') return;
        e.preventDefault();
        const list = rows();
        const next = list[list.indexOf(input.closest('tr')) + 1] ?? list[0];
        inputOf(next).focus();
        inputOf(next).select();
    });
    table.addEventListener('input', (e) => {
        if (e.target.closest('.member-order-input')) refresh();
    });

    // 年齢順（同じ年齢は今の並びのまま、年齢が無い人は末尾）。
    // 「子どもだけ」は子どもの行だけを並べ替え、子どもがいた位置に入れ直す（職員・その他の位置は動かさない）
    table.closest('.xl-wrap').addEventListener('click', (e) => {
        const btn = e.target.closest('[data-age-sort]');
        if (!btn) return;
        applyNumbers();
        const dir = btn.dataset.ageSort === 'asc' ? 1 : -1;
        const isTarget = btn.dataset.ageScope === 'child' ? (r) => r.dataset.child === '1' : () => true;
        const all = rows();
        const sorted = all.filter(isTarget)
            .map((r, i) => ({ r, i, age: r.dataset.age === '' ? null : Number(r.dataset.age) }))
            .sort((a, b) => {
                if (a.age === null || b.age === null) return (a.age === null) - (b.age === null) || a.i - b.i;
                return (a.age - b.age) * dir || a.i - b.i;
            })
            .map(({ r }) => r);
        all.map((r) => (isTarget(r) ? sorted.shift() : r)).forEach((r) => tbody.appendChild(r));
        refresh();
    });

    // ドラッグ（オプション）。ドラッグ中は行を動かさず、入る位置に線を出す。離したときに初めて移す
    let dragging = null;
    let drop = null; // { row, after }
    const clearDrop = () => {
        table.querySelectorAll('.drop-before, .drop-after').forEach((r) => r.classList.remove('drop-before', 'drop-after'));
        drop = null;
    };
    dragToggle?.addEventListener('change', () => {
        table.classList.toggle('drag-on', dragToggle.checked);
        rows().forEach((r) => { r.draggable = dragToggle.checked; });
    });
    table.addEventListener('dragstart', (e) => {
        // ドラッグ OFF のときや、入力欄の文字・名前の選択をドラッグしたときは行を動かさない
        if (!dragToggle?.checked || !(e.target instanceof HTMLTableRowElement)) return;
        applyNumbers(); // 書きかけの数字を先に反映してから動かす
        dragging = e.target;
        dragging.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', dragging.dataset.userId); // Firefox は setData が無いとドラッグが始まらない
    });
    table.addEventListener('dragover', (e) => {
        if (!dragging) return;
        const tr = e.target.closest?.('tr[data-user-id]');
        if (!tr) {
            clearDrop();
            return;
        }
        e.preventDefault();
        const list = rows();
        const { top, height } = tr.getBoundingClientRect();
        const target = { row: tr, after: e.clientY > top + height / 2 };
        // 今の位置と変わらないところには線を出さない
        const others = list.filter((r) => r !== dragging);
        const at = tr === dragging ? list.indexOf(dragging) : others.indexOf(tr) + (target.after ? 1 : 0);
        clearDrop();
        if (at === list.indexOf(dragging)) return;
        drop = target;
        tr.classList.add(target.after ? 'drop-after' : 'drop-before');
    });
    table.addEventListener('drop', (e) => {
        if (!dragging) return;
        e.preventDefault();
        if (drop) tbody.insertBefore(dragging, drop.after ? drop.row.nextSibling : drop.row);
    });
    table.addEventListener('dragend', () => {
        if (!dragging) return;
        clearDrop();
        dragging.classList.remove('dragging');
        dragging = null;
        refresh();
    });

    undoBtn?.addEventListener('click', () => {
        const pos = new Map(saved.map((id, i) => [id, i]));
        rows().forEach((r) => { inputOf(r).value = inputOf(r).defaultValue; });
        rows()
            .sort((a, b) => pos.get(Number(a.dataset.userId)) - pos.get(Number(b.dataset.userId)))
            .forEach((r) => tbody.appendChild(r));
        refresh();
    });

    // ［保存］を押すまで送信しない（部屋名などの入力欄で Enter を押しても送らない）
    form.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && e.target instanceof HTMLInputElement) e.preventDefault();
    });
    // 送信内容は submit の後に組み立てられるので、ここで並べれば user_ids[] がその順で送られる
    form.addEventListener('submit', () => {
        applyNumbers();
        submitting = true;
    });

    window.addEventListener('beforeunload', (e) => {
        if (submitting || !isDirty()) return;
        e.preventDefault();
        e.returnValue = '';
    });
})();
