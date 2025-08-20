// user-import.js
// インポート画面の挙動（DOM構築後に実行）
document.addEventListener('DOMContentLoaded', () => {
  // ====== 要素参照 ======
  const drop = document.getElementById('drop');
  const fileInput = document.getElementById('fileInput');
  const browseBtn = document.getElementById('browseBtn');
  const sendBtn = document.getElementById('sendBtn');
  const clearBtn = document.getElementById('clearBtn');
  const statusEl = document.getElementById('status');
  const fileInfo = document.getElementById('fileInfo');
  const countBadge = document.getElementById('countBadge');
  const bar = document.getElementById('bar');
  const previewTable = document.getElementById('previewTable')?.querySelector('tbody');
  const statusBadge = document.getElementById('statusBadge');
  const downloadTemplate = document.getElementById('downloadTemplate');
  const downloadErrors = document.getElementById('downloadErrors');

  // ====== 設定取得（メタ） ======
  const csrf = document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') || '';
  const importUrl = document.querySelector('meta[name="importJsonUrl"]')?.getAttribute('content') || '';
  const xlsxLocalSrc = document.querySelector('meta[name="xlsxLocalSrc"]')?.getAttribute('content') || '';

  // ====== 状態 ======
  /** @type {{login_id:string,name:string,role:string,staff_id?:string,password?:string,_row:number,age?:string,age_group?:string,i_user_gender?:string,gender?:string}[]} */
  let parsed = [];
  /** エラー集計（行番号 -> string[]） */
  let errorsAll = {};
  let loadedFileName = '';

  // ====== ユーティリティ ======
  function log(msg) { if (!statusEl) return; statusEl.textContent += msg + "\n"; statusEl.scrollTop = statusEl.scrollHeight; }
  function clearLog(){ if (!statusEl) return; statusEl.textContent = ''; }
  function setProgress(p){ if (!bar) return; bar.style.width = Math.max(0, Math.min(100, p)) + '%'; }
  function resetState(){
    parsed = [];
    errorsAll = {};
    loadedFileName = '';
    if (fileInfo) fileInfo.textContent = '';
    if (countBadge) countBadge.style.display = 'none';
    setProgress(0);
    if (previewTable) previewTable.innerHTML = '';
    if (sendBtn) sendBtn.disabled = true;
    if (clearBtn) clearBtn.disabled = true;
    if (statusBadge) statusBadge.style.display = 'none';
    if (downloadErrors) downloadErrors.disabled = true;
    clearLog();
  }

  // 見出し正規化
  function normalizeHeader(v){
    v = String(v ?? '').trim().toLowerCase();
    const dict = {
      login_id:     ['login_id','loginid','ログインid','ログインｉｄ','ログインＩＤ','ユーザid','ユーザーid','ユーザーｉｄ','c_login_account','ログインアカウント'],
      name:         ['name','氏名','名前','c_user_name','利用者名','ユーザー名'],
      role:         ['role','権限','ロール','役割'],
      staff_id:     ['staff_id','職員id','職員ｉｄ','職員ＩＤ','i_id_staff','職員番号','社員番号','従業員番号'],
      password:     ['password','パスワード','c_login_passwd'],
      age:          ['age','年齢','ねんれい'],
      age_group:    ['age_group','年代','年代選択','どの年代'],
      i_user_gender:['i_user_gender','性別','ジェンダー'],
      gender:       ['gender']
    };
    for (const key in dict){ if (dict[key].includes(v)) return key; }
    return v;
  }

  // クライアント側簡易検査
  function validateClientRow(r){
    const msgs = [];
    if (!r.login_id) msgs.push('login_id 空');
    if (!r.name) msgs.push('name 空');
    if (!r.role && !r.i_user_level) msgs.push('role または i_user_level が必要');
    const roleN = deriveLevel(r);
    if (roleN === null && (r.role || r.i_user_level)) msgs.push('role/i_user_level が不正');
    if (roleN === 0 && !String(r.staff_id ?? '').trim()) msgs.push('staff_id（職員ID）必須');
    // gender 簡易チェック
    if ((r.i_user_gender ?? r.gender) != null && String(r.i_user_gender ?? r.gender).trim() !== '') {
      const gNum = normalizeGender(r.i_user_gender ?? r.gender);
      if (gNum === null) msgs.push('gender が不正（男性/女性 または 1/2）');
    }
    return msgs;
  }

  // 役割正規化
  function normalizeRole(raw){
    if (raw == null) return null;
    let v = String(raw).trim();
    if (v === '') return null;
    if (typeof v.normalize === 'function') v = v.normalize();
    try { v = v.replace(/\u3000/g,' '); } catch(e){}
    v = v.toLowerCase();

    if (!isNaN(Number(v))) {
      const n = parseInt(v, 10);
      return [0,1,3].includes(n) ? n : null;
    }
    const map = {
      '職員':0,'スタッフ':0,'教職員':0,'staff':0,
      '児童':1,'子ども':1,'子供':1,'こども':1,'生徒':1,'利用者':1,'ユーザー':1,'child':1,'user':1,
      'その他':3,'外部':3,'ゲスト':3,'臨時':3,'other':3
    };
    if (map[v] !== undefined) return map[v];

    const contains = (s, arr) => arr.some(k => s.includes(k));
    if (contains(v, ['職員','スタッフ','教職員'])) return 0;
    if (contains(v, ['児童','子ども','子供','こども','生徒','利用者','ユーザー'])) return 1;
    if (contains(v, ['その他','外部','ゲスト','臨時'])) return 3;
    if (contains(v, ['staff'])) return 0;
    if (contains(v, ['child','user'])) return 1;
    if (contains(v, ['other'])) return 3;
    return null;
  }

  // gender の正規化（男性=1, 女性=2）
  function normalizeGender(raw) {
    if (raw == null) return null;
    let v = String(raw).trim();
    if (v === '') return null;
    if (!isNaN(Number(v))) {
      const n = parseInt(v, 10);
      if (n === 1 || n === 2) return n;
    }
    try { v = v.normalize().replace(/\u3000/g, ''); } catch(e){}
    v = v.toLowerCase();
    const maleWords   = ['男', '男性', 'だんせい', 'male', 'man', 'm'];
    const femaleWords = ['女', '女性', 'じょせい', 'female', 'woman', 'f'];
    if (maleWords.some(w => v.includes(w))) return 1;
    if (femaleWords.some(w => v.includes(w))) return 2;
    return null;
  }

  // 年代（age_group）の正規化（1..7）
  function normalizeAgeGroup(raw) {
    if (raw == null) return null;
    let v = String(raw).trim();
    if (v === '') return null;
    if (!isNaN(Number(v))) {
      const n = parseInt(v, 10);
      return (n >= 1 && n <= 7) ? n : null;
    }
    try { v = v.normalize().replace(/\u3000/g, ''); } catch(e){}
    const t = v.replace(/歳|才/g, '').toLowerCase();
    const map = new Map([
      ['3~5', 1], ['3-5', 1], ['3〜5', 1], ['3～5', 1], ['3~5才', 1], ['3~5歳', 1],
      ['低学年', 2],
      ['中学年', 3],
      ['高学年', 4],
      ['中学生', 5],
      ['高校生', 6],
      ['大人',   7], ['成人', 7]
    ]);
    for (const [k, code] of map.entries()) {
      if (t.includes(k)) return code;
    }
    return null;
  }

  // role から数値レベルを導出
  function deriveLevel(r){
    if (r == null) return null;
    if (r.role != null && String(r.role).trim() !== '') {
      const n = normalizeRole(r.role);
      if (n !== null) return n;
    }
    return null;
  }

  // プレビュー描画（先頭50行）
  function renderPreview(rows){
    if (!previewTable) return;
    previewTable.innerHTML = '';
    const limit = Math.min(50, rows.length);
    for (let i=0;i<limit;i++){
      const r = rows[i];
      const errs = validateClientRow(r);
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${r._row}</td>
        <td>${escapeHtml(r.login_id)}</td>
        <td>${escapeHtml(r.name)}</td>
        <td>${escapeHtml(r.role || '')}</td>
        <td>${escapeHtml(r.staff_id || '')}</td>
        <td>${escapeHtml(r.password || '')}</td>
        <td>${escapeHtml(r.age ?? '')}</td>
        <td>${escapeHtml(r.age_group ?? '')}</td>
        <td>${escapeHtml((r.i_user_gender ?? r.gender) || '')}</td>
        <td>${errs.length ? `<span class="badge-lite err">${errs.join(' / ')}</span>` : '<span class="badge-lite ok">OK</span>'}</td>
      `;
      previewTable.appendChild(tr);
    }
  }

  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  // ====== ファイル処理 ======
  async function parseFile(file){
    resetState();
    loadedFileName = file.name;
    if (fileInfo) fileInfo.textContent = `選択: ${file.name} (${(file.size/1024/1024).toFixed(2)}MB)`;
    log(`解析開始: ${file.name}`);

    if (!(window.XLSX && XLSX.read)) {
      const ok = await ensureXLSX();
      if (!ok) {
        log('ファイル解析用ライブラリ（XLSX）が読み込めていません。ネットワーク環境を確認して、ページを再読み込みしてください。');
        return;
      }
    }

    const buf = await file.arrayBuffer();
    const wb = XLSX.read(buf, { type: 'array' });
    const sheetName = wb.SheetNames[0];
    if (!sheetName){ log('シートが見つかりません'); return; }

    const ws = wb.Sheets[sheetName];
    let rows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
    if (!rows.length){ log('データが空です'); return; }

    const headerRow = rows.shift();
    const keys = headerRow.map(normalizeHeader);

    // 必須列チェック（role 必須）
    const required = ['login_id','name','role'];
    const missing = required.filter(r => !keys.includes(r));
    if (missing.length){
      log('必須見出しが不足: ' + missing.join(', '));
      return;
    }

    const indexOf = {};
    keys.forEach((k,i)=> { indexOf[k] = i; });

    // 2行目以降 -> オブジェクト配列
    const out = [];
    rows.forEach((arr, i) => {
      const rowNo = i + 2;
      const hasAny = arr.some(v => String(v ?? '').trim() !== '');
      if (!hasAny) return;

      const rec = {
        login_id: String(arr[indexOf['login_id']] ?? '').trim(),
        name:     String(arr[indexOf['name']] ?? '').trim(),
        role:     indexOf['role'] !== undefined ? String(arr[indexOf['role']] ?? '').trim() : '',
        _row:     rowNo
      };
      if (indexOf['staff_id'] !== undefined){
        rec.staff_id = String(arr[indexOf['staff_id']] ?? '').trim();
      }
      if (indexOf['password'] !== undefined){
        rec.password = String(arr[indexOf['password']] ?? '');
      }
      if (indexOf['age'] !== undefined){
        rec.age = String(arr[indexOf['age']] ?? '').trim();
      }
      if (indexOf['age_group'] !== undefined){
        rec.age_group = String(arr[indexOf['age_group']] ?? '').trim();
      }
      if (indexOf['i_user_gender'] !== undefined){
        rec.i_user_gender = String(arr[indexOf['i_user_gender']] ?? '').trim();
      } else if (indexOf['gender'] !== undefined){
        rec.gender = String(arr[indexOf['gender']] ?? '').trim();
      }
      out.push(rec);
    });

    parsed = out;
    if (countBadge) {
      countBadge.textContent = `レコード数: ${parsed.length}`;
      countBadge.style.display = 'inline-block';
    }
    renderPreview(parsed);
    if (sendBtn) sendBtn.disabled = parsed.length === 0;
    if (clearBtn) clearBtn.disabled = parsed.length === 0;

    const invalidCount = parsed.reduce((acc,r) => acc + (validateClientRow(r).length ? 1 : 0), 0);
    if (statusBadge) {
      statusBadge.style.display = 'inline-block';
      statusBadge.className = 'badge-lite ' + (invalidCount ? 'warn' : 'ok');
      statusBadge.textContent = invalidCount ? `要修正 ${invalidCount}件` : '準備OK';
    }
    log(`解析完了。レコード数: ${parsed.length}（要修正: ${invalidCount}）`);
  }

  // ====== 送信 ======
  async function postBatch(batch, idx, total){
    const res = await fetch(importUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
      body: JSON.stringify({ records: batch })
    });
    const data = await res.json().catch(()=> ({}));
    if (!res.ok) throw new Error((data && data.message) || res.statusText);
    log(`バッチ ${idx}/${total} 完了: 作成 ${data.summary?.created ?? 0}, スキップ ${data.summary?.skipped ?? 0}, 失敗 ${data.summary?.failed ?? 0}`);
    return data.summary || {processed:0,created:0,skipped:0,failed:0,errors:{}};
  }

  async function sendAll(){
    if (!parsed.length) return;
    if (browseBtn) browseBtn.disabled = true;
    if (fileInput) fileInput.disabled = true;
    if (sendBtn) sendBtn.disabled = true;
    if (clearBtn) clearBtn.disabled = true;
    setProgress(0);
    log('--- サーバ送信開始 ---');

    const BATCH = 500;
    const totalBatches = Math.ceil(parsed.length / BATCH);

    const total = { processed:0, created:0, skipped:0, failed:0, errors:{} };

    try {
      for (let i=0; i<totalBatches; i++){
        const start = i*BATCH;
        const end = Math.min((i+1)*BATCH, parsed.length);
        const batch = parsed.slice(start, end);

        // 送信前の簡易検査
        const ng = batch.filter(r => {
          const roleN = deriveLevel(r);
          return roleN === null || !r.login_id || !r.name || (roleN === 0 && !String(r.staff_id ?? '').trim());
        });
        if (ng.length){
          ng.forEach(r => {
            total.failed++;
            total.processed++;
            (total.errors[r._row] ||= []).push('クライアント検査: 必須/role(i_user_level)/staff_id不正');
          });
        }
        const okBatch = batch.filter(r => {
          const roleN = deriveLevel(r);
          return roleN !== null && r.login_id && r.name && !(roleN === 0 && !String(r.staff_id ?? '').trim());
        });

        // サーバ送信前の正規化上書き（数値に統一）
        okBatch.forEach(rec => {
          // gender/i_user_gender 数値化
          const gNum = normalizeGender(rec.i_user_gender ?? rec.gender);
          if (gNum !== null) {
            rec.i_user_gender = gNum;
          } else {
            delete rec.i_user_gender;
          }
          // age_group → 1..7
          if (rec.age_group != null && String(rec.age_group).trim() !== '') {
            const ag = normalizeAgeGroup(rec.age_group);
            if (ag !== null) {
              rec.age_group = ag;
              rec.i_user_rank = ag;
            } else {
              delete rec.age_group;
            }
          }
          // 別名カラムは送らない
          delete rec.gender;
        });

        if (okBatch.length){
          const s = await postBatch(okBatch, i+1, totalBatches);
          total.processed += s.processed || 0;
          total.created   += s.created   || 0;
          total.skipped   += s.skipped   || 0;
          total.failed    += s.failed    || 0;
          if (s.errors){
            for (const [row, msgs] of Object.entries(s.errors)){
              total.errors[row] = (total.errors[row] || []).concat(msgs || []);
            }
          }
        }

        setProgress(Math.round(((i+1)/totalBatches)*100));
      }

      // 集計表示
      log('\n--- 集計 ---');
      log(`処理行数: ${total.processed}`);
      log(`作成件数: ${total.created}`);
      log(`スキップ: ${total.skipped}`);
      log(`失敗件数: ${total.failed}`);

      if (Object.keys(total.errors).length){
        log('\n[行別エラー]');
        Object.entries(total.errors).forEach(([row, msgs]) => msgs.forEach(m => log(`Row ${row}: ${m}`)));
        errorsAll = total.errors;
        if (downloadErrors) downloadErrors.disabled = false;
        if (statusBadge){
          statusBadge.className = 'badge-lite warn';
          statusBadge.textContent = `完了（エラー ${Object.keys(total.errors).length}行）`;
          statusBadge.style.display = 'inline-block';
        }
      } else {
        if (statusBadge){
          statusBadge.className = 'badge-lite ok';
          statusBadge.textContent = '完了（エラーなし）';
          statusBadge.style.display = 'inline-block';
        }
      }
      log('\n完了しました。');
    } catch (e){
      log('送信エラー: ' + e.message);
      if (statusBadge){
        statusBadge.style.display = 'inline-block';
        statusBadge.className = 'badge-lite err';
        statusBadge.textContent = '送信エラー';
      }
    } finally {
      if (browseBtn) browseBtn.disabled = false;
      if (fileInput) fileInput.disabled = false;
      if (sendBtn) sendBtn.disabled = false;
      if (clearBtn) clearBtn.disabled = false;
    }
  }

  // ====== イベント ======
  if (drop) {
    ['dragenter','dragover'].forEach(evt => {
      drop.addEventListener(evt, e => { e.preventDefault(); e.stopPropagation(); drop.classList.add('dragover'); });
    });
    ['dragleave','drop'].forEach(evt => {
      drop.addEventListener(evt, e => { e.preventDefault(); e.stopPropagation(); drop.classList.remove('dragover'); });
    });
    drop.addEventListener('drop', e => {
      const files = e.dataTransfer.files;
      if (files && files[0]) parseFile(files[0]);
    });
  }

  if (browseBtn && fileInput) {
    browseBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
      if (fileInput.files && fileInput.files[0]) parseFile(fileInput.files[0]);
    });
  }

  if (sendBtn) sendBtn.addEventListener('click', sendAll);
  if (clearBtn) clearBtn.addEventListener('click', resetState);

  // ▼▼ テンプレートDL（標準: Excel / Alt 押下: CSV）▼▼
  if (downloadTemplate) {
    downloadTemplate.addEventListener('click', async (ev) => {
      const headers = ['login_id','name','role','staff_id','password','age','age_group','gender'];
      const samples = [
        ['u0001', '山田 太郎', '職員',  'S-001', '', 30, '大人',   '男性'],
        ['u0002', '佐藤 花子', '児童',  '',      '', 10, '低学年', '女性'],
        ['u0003', '鈴木 一郎', 'その他','',      '', 40, '大人',   '男性'],
        ['u0004', '田中 次郎', '職員',  'S-002', '', 35, '大人',   '男性'],
        ['u0005', '高橋 美咲', '児童',  '',      '', 12, '中学生', '女性']
      ];

      if (ev && ev.altKey) {
        downloadCsvTemplate(headers, samples);
        return;
      }

      const ok = await ensureXLSX();
      if (!ok) {
        log('テンプレート生成用ライブラリ（XLSX）が読み込めていません。ネットワーク環境を確認して、ページを再読み込みしてください。CSVへ切り替えます。');
        downloadCsvTemplate(headers, samples);
        return;
      }

      try {
        const wb = buildWorkbook(headers, samples);
        XLSX.writeFile(wb, 'user_import_template.xlsx', { compression: true });
        log('Excelテンプレートをダウンロードしました。');
      } catch (e) {
        log('Excelテンプレート作成に失敗: ' + e.message + ' / CSVへ切り替えます。');
        downloadCsvTemplate(headers, samples);
      }
    });
  }
}); // DOMContentLoaded

// ▼▼ XLSX フォールバックローダ（ローカル → 公式CDN → jsDelivr → unpkg）▼▼
const XLSX_SOURCES = [
  // ① ローカル（webroot/js に配置するとCDN不要で安定）: メタから取得
  document.querySelector('meta[name="xlsxLocalSrc"]')?.getAttribute('content') || '',
  // ② 公式CDN（SheetJS推奨）
  'https://cdn.sheetjs.com/xlsx-0.20.2/package/dist/xlsx.full.min.js',
  // ③ jsDelivr
  'https://cdn.jsdelivr.net/npm/xlsx@0.20.2/dist/xlsx.full.min.js',
  // ④ unpkg
  'https://unpkg.com/xlsx@0.20.2/dist/xlsx.full.min.js'
].filter(Boolean);

async function ensureXLSX() {
  if (window.XLSX && XLSX.utils && (XLSX.write || XLSX.writeFile)) return true;
  for (const src of XLSX_SOURCES) {
    try {
      await loadScript(src, 12000);
      if (window.XLSX && XLSX.utils && (XLSX.write || XLSX.writeFile)) return true;
    } catch (e) {
      // 次の候補にフォールバック
    }
  }
  return false;
}

function loadScript(src, timeout = 10000) {
  return new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = src;
    s.async = true;
    s.crossOrigin = 'anonymous';
    let done = false;
    const timer = setTimeout(() => {
      if (done) return;
      done = true;
      s.remove();
      reject(new Error('timeout: ' + src));
    }, timeout);
    s.onload = () => {
      if (done) return;
      done = true;
      clearTimeout(timer);
      resolve();
    };
    s.onerror = () => {
      if (done) return;
      done = true;
      clearTimeout(timer);
      reject(new Error('failed: ' + src));
    };
    document.head.appendChild(s);
  });
}

// ▼ CSVテンプレートDL（共通関数）
function downloadCsvTemplate(headers, samples){
  const rows = [headers, ...samples]
    .map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(','))
    .join('\r\n');
  const blob = new Blob([rows], {type:'text/csv;charset=utf-8;'});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = 'user_import_template.csv';
  document.body.appendChild(a); a.click(); a.remove();
  URL.revokeObjectURL(url);
  const statusEl = document.getElementById('status');
  if (statusEl) { statusEl.textContent += "CSVテンプレートをダウンロードしました。\n"; }
}

/**
 * SheetJS 公式手順準拠：ワークブック生成（users + spec + lists）
 * 入力規制（ドロップダウン）は lists シートの Named Range を参照します
 */
function buildWorkbook(headers, samples){
  const dataAoA = [headers, ...samples];
  const wsUsers = XLSX.utils.aoa_to_sheet(dataAoA);

  wsUsers['!cols'] = [
    { wch: 16 }, // login_id
    { wch: 18 }, // name
    { wch: 12 }, // role
    { wch: 14 }, // staff_id
    { wch: 18 }, // password
    { wch: 8  }, // age
    { wch: 12 }, // age_group
    { wch: 10 }, // gender
  ];
  headers.forEach((_, c) => {
    const ref = XLSX.utils.encode_cell({ r:0, c });
    wsUsers[ref] = wsUsers[ref] || {};
    wsUsers[ref].s = { font: { bold: true } };
  });
  wsUsers['!freeze'] = { xSplit: 0, ySplit: 1 };

  const specAoA = [
    ['取り込み仕様（かんたん版）'],
    ['1. ダウンロードしたテンプレートを開く（Excel推奨、CSVも可）'],
    ['2. 必須列', 'login_id / name / （role または i_user_level のいずれか）'],
    ['3. 役割の入力', 'role は 職員/児童/その他 ／ i_user_level は 0/1/3 のいずれか（どちらかを入力）'],
    ['4. 職員ID', 'i_user_level=0（職員）の場合は staff_id が必須'],
    ['5. password', '空なら自動生成（12桁）。入力時はその値で登録（サーバ側でハッシュ化）'],
    ['6. 性別', '男性/女性 または 1/2 のいずれか（推奨は 1/2）'],
    ['7. 年齢', '1〜80 から選択（直接入力も可）'],
    [],
    ['見出しの許容例（自動マッピング）'],
    ['login_id', 'login_id / ログインID / ユーザーID / c_login_account'],
    ['name',     'name / 氏名 / 名前 / c_user_name'],
    ['role',     'role / 権限 / ロール / 役割'],
    ['i_user_level','i_user_level / レベル / 権限数値 / level / ユーザレベル'],
    ['staff_id', 'staff_id / 職員ID / i_id_staff / 職員番号 / 社員番号 / 従業員番号'],
    ['password', 'password / パスワード / c_login_passwd'],
    ['age',      'age / 年齢 / ねんれい'],
    ['gender',   'gender / 性別 / i_user_gender'],
  ];
  const wsSpec = XLSX.utils.aoa_to_sheet(specAoA);
  wsSpec['!cols'] = [{ wch: 22 }, { wch: 72 }];

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, wsUsers, 'users');
  XLSX.utils.book_append_sheet(wb, wsSpec,  'spec');

  const wsLists = XLSX.utils.aoa_to_sheet([
    ['職員', '男性', '3~5才'],
    ['児童', '女性', '低学年'],
    ['その他', '',   '中学年'],
    ['',     '',   '高学年'],
    ['',     '',   '中学生'],
    ['',     '',   '高校生'],
    ['',     '',   '大人'],
  ]);
  XLSX.utils.book_append_sheet(wb, wsLists, 'lists');

  wb.Workbook = wb.Workbook || {};
  wb.Workbook.Names = [
    { Name: 'LevelList',    Ref: "lists!$A$1:$A$3" },
    { Name: 'GenderList',   Ref: "lists!$B$1:$B$2" },
    { Name: 'AgeGroupList', Ref: "lists!$C$1:$C$7" }
  ];
  wb.Workbook.Sheets = [
    { Hidden: 0 },
    { Hidden: 0 },
    { Hidden: 1 }
  ];

  try {
    const lastRow = 10000;
    const colIndex = (name) => headers.indexOf(name);
    const addDV = (addr, formulaList) => {
      const obj = { type: 'list', allowBlank: 1, showInputMessage: 1, showErrorMessage: 1, sqref: addr, formula1: formulaList };
      const dvKey = '!dataValidations';
      wsUsers[dvKey] = wsUsers[dvKey] || { dataValidation: [] };
      wsUsers[dvKey].dataValidation.push(obj);
    };
    // role
    if (colIndex('role') >= 0) {
      const c = colIndex('role');
      const addr = XLSX.utils.encode_range({s:{r:1,c}, e:{r:lastRow,c}});
      addDV(addr, '=LevelList');
    }
    // age
    if (colIndex('age') >= 0) {
      const c = colIndex('age');
      const addr = XLSX.utils.encode_range({s:{r:1,c}, e:{r:lastRow,c}});
      const ageListStr = '"' + Array.from({length:80}, (_,i)=> i+1).join(',') + '"';
      addDV(addr, ageListStr);
    }
    // age_group
    if (colIndex('age_group') >= 0) {
      const c = colIndex('age_group');
      const addr = XLSX.utils.encode_range({s:{r:1,c}, e:{r:lastRow,c}});
      addDV(addr, '=AgeGroupList');
    }
    // gender
    if (colIndex('gender') >= 0) {
      const c = colIndex('gender');
      const addr = XLSX.utils.encode_range({s:{r:1,c}, e:{r:lastRow,c}});
      addDV(addr, '=GenderList');
    }
    // i_user_gender
    if (colIndex('i_user_gender') >= 0) {
      const c = colIndex('i_user_gender');
      const addr = XLSX.utils.encode_range({s:{r:1,c}, e:{r:lastRow,c}});
      addDV(addr, '=GenderList');
    }
  } catch (e) {
    // 未対応環境ではスキップ
  }

  return wb;
}
