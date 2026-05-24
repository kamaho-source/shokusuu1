<?php
/**
 * 食数予約のカレンダー表示とエクスポート機能を提供するテンプレート
 *
 * @var \App\View\AppView $this
 * @var array $mealDataArray 食数データの配列
 */
$this->assign('title', __('食事給与控除データエクスポート'));
?>
<!-- Example Form with Bootstrap -->
<div class="container mt-5">
    <h2>食事給与控除データエクスポート</h2>
    <form id="export-form">
        <!-- Year Selection -->
        <div class="mb-3">
            <label for="year-select" class="form-label">年度</label>
            <select class="form-select" id="year-select" name="year">
                <option selected disabled>年度を選択してください</option>
                <?php foreach ($yearList as $year): ?>
                    <option value="<?= h($year['i_fiscal_year']) ?>"><?= h($year['i_fiscal_year']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Month Selection -->
        <div class="mb-3">
            <label for="month-select" class="form-label">月</label>
            <select class="form-select" id="month-select" name="month">
                <option selected disabled>月を選択してください</option>
                <option value="1">1月</option>
                <option value="2">2月</option>
                <option value="3">3月</option>
                <option value="4">4月</option>
                <option value="5">5月</option>
                <option value="6">6月</option>
                <option value="7">7月</option>
                <option value="8">8月</option>
                <option value="9">9月</option>
                <option value="10">10月</option>
                <option value="11">11月</option>
                <option value="12">12月</option>
            </select>
        </div>

        <!-- Submit Buttons -->
        <div class="d-flex gap-2 mt-2">
            <button type="button" id="downloadExcelWithDeductions" class="btn btn-primary">
                承認済みエクスポート
            </button>
            <button type="button" id="downloadExcelPreview" class="btn btn-warning">
                未承認プレビューエクスポート
            </button>
        </div>
        <div class="form-text text-muted mt-1">
            ※ 未承認プレビューは管理者承認前のデータ（未承認・ブロック長承認済）を含みます。確定値ではありません。
        </div>
    </form>
</div>

<!-- 未承認プレビュー確認モーダル -->
<div id="preview-confirm-modal" class="preview-modal-backdrop" aria-hidden="true">
    <div class="preview-modal-card" role="dialog" aria-modal="true" aria-labelledby="preview-modal-title">
        <div class="preview-modal-header">
            <div class="preview-modal-icon">⚠</div>
            <h5 id="preview-modal-title" class="preview-modal-title">未承認データのエクスポート</h5>
        </div>
        <div class="preview-modal-body">
            これは<strong>未承認データのプレビューエクスポート</strong>です。<br>
            管理者による最終承認が完了していないデータを含むため、<strong>確定値ではありません。</strong><br><br>
            エクスポートを続けますか？
        </div>
        <div class="preview-modal-footer">
            <button type="button" id="preview-modal-cancel" class="btn btn-secondary">キャンセル</button>
            <button type="button" id="preview-modal-confirm" class="btn btn-warning">エクスポートする</button>
        </div>
    </div>
</div>

<style>
.preview-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.18);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1055;
    padding: 1rem;
    backdrop-filter: blur(4px);
}
.preview-modal-backdrop.is-open { display: flex; }
.preview-modal-card {
    width: min(100%, 400px);
    background: linear-gradient(180deg, #ffffff 0%, #fffdf5 100%);
    border: 1px solid rgba(251, 191, 36, 0.4);
    border-radius: 20px;
    box-shadow: 0 24px 80px rgba(15, 23, 42, 0.18);
    overflow: hidden;
    transform: translateY(8px) scale(0.98);
    opacity: 0;
    transition: transform .18s ease, opacity .18s ease;
}
.preview-modal-backdrop.is-open .preview-modal-card {
    transform: translateY(0) scale(1);
    opacity: 1;
}
.preview-modal-header {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: 1rem 1.2rem .5rem;
    border-bottom: none;
}
.preview-modal-icon {
    width: 42px; height: 42px;
    border-radius: 999px;
    background: #fef9c3;
    color: #b45309;
    font-size: 1.3rem;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.preview-modal-title { margin: 0; font-size: 1rem; font-weight: 700; color: #1e293b; }
.preview-modal-body {
    padding: .5rem 1.2rem 1rem;
    color: #475569;
    line-height: 1.8;
    font-size: .92rem;
}
.preview-modal-footer {
    display: flex;
    gap: .6rem;
    padding: 0 1.2rem 1rem;
}
.preview-modal-footer .btn {
    flex: 1;
    border-radius: 999px;
    font-weight: 600;
    padding: .6rem .9rem;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/exceljs/dist/exceljs.min.js"></script>

<!-- ★ 列幅自動調整ユーティリティ -->
<script>
    /**
     * 指定ワークシートの列幅を自動調整します。
     * ・半角文字  = 幅 1
     * ・全角文字  = 幅 2
     * ・数式セル  = 計算結果があれば結果を優先
     * @param {ExcelJS.Worksheet} worksheet
     */
    function autoFitColumns(worksheet) {
        worksheet.columns.forEach((column, colIdx) => {
            let max = 10; // 最低幅

            worksheet.eachRow({ includeEmpty: true }, (row) => {
                const cellValue = row.getCell(colIdx + 1).value;
                if (cellValue === undefined || cellValue === null) return;

                let text = '';
                if (typeof cellValue === 'object') {
                    if (cellValue.richText) {
                        text = cellValue.richText.map(rt => rt.text).join('');
                    } else if (cellValue.result !== undefined) {
                        text = String(cellValue.result);
                    } else if (cellValue.text !== undefined) {
                        text = String(cellValue.text);
                    } else if (cellValue.formula !== undefined) {
                        text = String(cellValue.formula);
                    } else {
                        text = String(cellValue);
                    }
                } else {
                    text = String(cellValue);
                }

                const displayWidth = Array.from(text).reduce((sum, ch) => {
                    return sum + (/[\u0020-\u007e]/.test(ch) ? 1 : 2); // 半角:1, 全角:2
                }, 0);

                if (displayWidth > max) max = displayWidth;
            });

            column.width = max + 2; // 余白を追加
        });
    }
</script>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        const exportWithDeductionsButton = document.getElementById("downloadExcelWithDeductions");
        const yearSelect = document.getElementById("year-select");
        const monthSelect = document.getElementById("month-select");

        if (exportWithDeductionsButton && yearSelect && monthSelect) {
            exportWithDeductionsButton.addEventListener("click", async function (event) {
                event.preventDefault(); // ボタンのデフォルト動作を防止

                try {
                    const selectedYear = yearSelect.value; // 選択された年度
                    const selectedMonth = monthSelect.value; // 選択された月

                    // 年度と月をチェック
                    if (!selectedYear) {
                        alert("年度を選択してください。");
                        return;
                    }
                    if (!selectedMonth) {
                        alert("月を選択してください。");
                        return;
                    }

                    console.info("選択された年度:", selectedYear, "選択された月:", selectedMonth);

                    // APIからデータを取得
                    const response = await fetch(
                        `/kamaho-shokusu/MMealPriceInfo/exportMealSummary?year=${selectedYear}&month=${selectedMonth}`
                    );
                    if (!response.ok) throw new Error(`APIエラー: ${response.status}`);

                    const raw = await response.json();
                    const payload = window.normalizeApiPayload ? window.normalizeApiPayload(raw) : raw;
                    const data = (raw && typeof raw === 'object' && raw.ok !== undefined)
                        ? (Array.isArray(raw.data?.rows) ? raw.data.rows : (Array.isArray(raw.data) ? raw.data : []))
                        : (Array.isArray(payload) ? payload : []);
                    console.info("取得したデータ（控除付き）:", data);

                    // データが空の場合の処理
                    if (!Array.isArray(data) || data.length === 0) {
                        console.warn("該当するデータがありません:", data);
                        alert("該当するデータがありませんでした。"); // エラー表示
                        return;
                    }

                    // Excel ワークブックを作成
                    const workbook = new ExcelJS.Workbook();
                    workbook.creator = "給与控除システム"; // 作成者情報
                    workbook.created = new Date();
                    workbook.modified = new Date();

                    // メインシート作成
                    const sheet = workbook.addWorksheet(`控除データ_${selectedYear}_${selectedMonth}`);

                    // シートのヘッダー行 (日本語のラベル)
                    const header = ["職員情報", "弁当", "朝食", "昼食", "夕食", "控除額合計"];
                    sheet.addRow(header);

                    // データを埋め込む
                    data.forEach((employeeData, index) => {
                        console.log(`Processing row #${index + 1}:`, employeeData);

                        // meal_countsが存在しない場合はデフォルト値として0を使用
                        const mealCounts = employeeData.meal_counts || { bento: 0, morning: 0, lunch: 0, dinner: 0 };

                        // total_priceが存在する場合は使用（使用しない場合は手計算にフォールバック）
                        const totalDeductions =
                            employeeData.total_price ||
                            (mealCounts.bento || 0) * (employeeData.meal_prices?.bento || 0) +
                            (mealCounts.morning || 0) * (employeeData.meal_prices?.morning || 0) +
                            (mealCounts.lunch || 0) * (employeeData.meal_prices?.lunch || 0) +
                            (mealCounts.dinner || 0) * (employeeData.meal_prices?.dinner || 0);

                        // Excelにデータ追加
                        sheet.addRow([
                            `${employeeData.staff_id || ""} ${employeeData.name || ""}`, // 職員ID + 名前
                            mealCounts.bento || 0, // 弁当回数
                            mealCounts.morning || 0, // 朝食回数
                            mealCounts.lunch || 0, // 昼食回数
                            mealCounts.dinner || 0, // 夕食回数
                            totalDeductions // 控除額合計
                        ]);
                    });

                    // ◆ 合計行を追加
                    sheet.addRow([
                        "合計", // 合計ラベル
                        { formula: `SUM(B2:B${data.length + 1})` }, // 弁当回数 合計
                        { formula: `SUM(C2:C${data.length + 1})` }, // 朝食回数 合計
                        { formula: `SUM(D2:D${data.length + 1})` }, // 昼食回数 合計
                        { formula: `SUM(E2:E${data.length + 1})` }, // 夕食回数 合計
                        { formula: `SUM(F2:F${data.length + 1})` }  // 控除額 合計
                    ]);

                    // 書式設定：ヘッダ行と集計行を太字にする
                    sheet.getRow(1).font = { bold: true }; // ヘッダー
                    sheet.getRow(sheet.lastRow.number).font = { bold: true }; // 合計行を太字にする

                    // ★ 列幅を自動調整
                    autoFitColumns(sheet);

                    // Excel ファイルを生成
                    const buffer = await workbook.xlsx.writeBuffer();
                    const blob = new Blob([buffer], {
                        type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                    });

                    // ダウンロード処理
                    const link = document.createElement("a");
                    link.href = URL.createObjectURL(blob);
                    link.download = `給与控除データ_${selectedYear}_${selectedMonth}.xlsx`; // ファイル名
                    document.body.appendChild(link);
                    link.click();
                    link.remove();

                    console.info("控除データのエクセルファイルが生成されました！");
                } catch (error) {
                    console.error("控除データのエクスポート中にエラー:", error);
                    alert("控除データのエクスポート中にエラーが発生しました。詳細はコンソールを確認してください。");
                }
            });
        } else {
            console.error("必要な要素が見つかりません。");
        }

        // ── 未承認プレビューエクスポート ──────────────────────────────
        const previewButton = document.getElementById("downloadExcelPreview");
        const previewModal  = document.getElementById("preview-confirm-modal");
        const modalCancel   = document.getElementById("preview-modal-cancel");
        const modalConfirm  = document.getElementById("preview-modal-confirm");

        function openPreviewModal() {
            return new Promise(function (resolve) {
                previewModal.classList.add("is-open");
                previewModal.setAttribute("aria-hidden", "false");

                function onConfirm() { cleanup(); resolve(true); }
                function onCancel()  { cleanup(); resolve(false); }
                function onBackdrop(e) { if (e.target === previewModal) { cleanup(); resolve(false); } }

                function cleanup() {
                    previewModal.classList.remove("is-open");
                    previewModal.setAttribute("aria-hidden", "true");
                    modalConfirm.removeEventListener("click", onConfirm);
                    modalCancel.removeEventListener("click", onCancel);
                    previewModal.removeEventListener("click", onBackdrop);
                }

                modalConfirm.addEventListener("click", onConfirm);
                modalCancel.addEventListener("click", onCancel);
                previewModal.addEventListener("click", onBackdrop);
            });
        }

        if (previewButton && yearSelect && monthSelect) {
            previewButton.addEventListener("click", async function () {
                const selectedYear  = yearSelect.value;
                const selectedMonth = monthSelect.value;

                if (!selectedYear)  { alert("年度を選択してください。"); return; }
                if (!selectedMonth) { alert("月を選択してください。"); return; }

                const confirmed = await openPreviewModal();
                if (!confirmed) return;

                try {
                    const response = await fetch(
                        `/kamaho-shokusu/MMealPriceInfo/exportMealSummaryPreview?year=${selectedYear}&month=${selectedMonth}`
                    );
                    if (!response.ok) throw new Error(`APIエラー: ${response.status}`);

                    const raw  = await response.json();
                    const data = (raw && typeof raw === "object" && raw.ok !== undefined)
                        ? (Array.isArray(raw.data?.rows) ? raw.data.rows : [])
                        : [];

                    if (data.length === 0) {
                        alert("未承認データが見つかりませんでした。");
                        return;
                    }

                    const mealPrices = raw.data?.meal_prices || { bento: 0, morning: 0, lunch: 0, dinner: 0 };

                    const workbook = new ExcelJS.Workbook();
                    workbook.creator = "給与控除システム（プレビュー）";
                    workbook.created  = new Date();
                    workbook.modified = new Date();

                    const sheet = workbook.addWorksheet(`未承認プレビュー_${selectedYear}_${selectedMonth}`);
                    // 列定義: A〜G の7列
                    sheet.columns = [
                        { key: "a", width: 22 },
                        { key: "b", width: 10 },
                        { key: "c", width: 10 },
                        { key: "d", width: 10 },
                        { key: "e", width: 10 },
                        { key: "f", width: 10 },
                        { key: "g", width: 14 },
                    ];

                    // ── 行1: 警告 ──────────────────────────
                    const warnRow = sheet.addRow([
                        "【警告】このデータは未承認のプレビューです。管理者承認が完了していないため確定値ではありません。使用の際は十分注意してください。"
                    ]);
                    sheet.mergeCells("A1:G1");
                    warnRow.getCell(1).font      = { bold: true, color: { argb: "FFCC0000" }, size: 12 };
                    warnRow.getCell(1).fill      = { type: "pattern", pattern: "solid", fgColor: { argb: "FFFFF2F2" } };
                    warnRow.getCell(1).alignment = { vertical: "middle", wrapText: true };
                    warnRow.height = 40;

                    // ── 行2: 単価情報 ──────────────────────────
                    const priceInfoRow = sheet.addRow([
                        "【単価情報】",
                        `弁当: ${mealPrices.bento}円`,
                        `朝食: ${mealPrices.morning}円`,
                        `昼食: ${mealPrices.lunch}円`,
                        `夕食: ${mealPrices.dinner}円`,
                        "", ""
                    ]);
                    priceInfoRow.eachCell(cell => {
                        cell.font = { bold: true, color: { argb: "FF1D4ED8" } };
                        cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFEFF6FF" } };
                    });

                    // ── 行3: ヘッダー ──────────────────────────
                    const headerRow = sheet.addRow([
                        "職員情報", "承認ステータス", "弁当(回)", "朝食(回)", "昼食(回)", "夕食(回)", "控除額合計"
                    ]);
                    headerRow.eachCell(cell => {
                        cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFFFC107" } };
                        cell.font = { bold: true };
                        cell.alignment = { horizontal: "center" };
                    });

                    // ── 行4〜: データ（職員×ステータスで1行ずつ） ──────────────────────────
                    const STATUS_LABELS = { 0: "未承認", 1: "ブロック長承認済" };
                    const STATUS_COLORS = {
                        0: "FFFFF3CD", // 薄い黄: 未承認
                        1: "FFE0F2FE", // 薄い青: BL承認済
                    };

                    data.forEach(row => {
                        const mc     = row.meal_counts || { bento: 0, morning: 0, lunch: 0, dinner: 0 };
                        const status = row.approval_status ?? 0;
                        const label  = STATUS_LABELS[status] ?? `status:${status}`;
                        const color  = STATUS_COLORS[status] ?? "FFFFFFFF";

                        const dataRow = sheet.addRow([
                            `${row.staff_id ?? ""} ${row.name ?? ""}`.trim(),
                            label,
                            mc.bento   || 0,
                            mc.morning || 0,
                            mc.lunch   || 0,
                            mc.dinner  || 0,
                            row.total_price || 0,
                        ]);
                        dataRow.eachCell(cell => {
                            cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: color } };
                        });
                        // 控除額列は通貨書式
                        dataRow.getCell(7).numFmt = "¥#,##0";
                    });

                    // ── 合計行 ──────────────────────────
                    // 行1=警告, 行2=単価情報, 行3=ヘッダー → データは4行目〜
                    const firstDataRow = 4;
                    const lastDataRow  = data.length + 3;
                    const totalRow = sheet.addRow([
                        "合計", "",
                        { formula: `SUM(C${firstDataRow}:C${lastDataRow})` },
                        { formula: `SUM(D${firstDataRow}:D${lastDataRow})` },
                        { formula: `SUM(E${firstDataRow}:E${lastDataRow})` },
                        { formula: `SUM(F${firstDataRow}:F${lastDataRow})` },
                        { formula: `SUM(G${firstDataRow}:G${lastDataRow})` },
                    ]);
                    totalRow.font = { bold: true };
                    totalRow.getCell(7).numFmt = "¥#,##0";

                    autoFitColumns(sheet);

                    const buffer = await workbook.xlsx.writeBuffer();
                    const blob   = new Blob([buffer], {
                        type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                    });
                    const link = document.createElement("a");
                    link.href     = URL.createObjectURL(blob);
                    link.download = `給与控除データ_未承認プレビュー_${selectedYear}_${selectedMonth}.xlsx`;
                    document.body.appendChild(link);
                    link.click();
                    link.remove();

                } catch (error) {
                    console.error("プレビューエクスポートエラー:", error);
                    alert("プレビューエクスポート中にエラーが発生しました。詳細はコンソールを確認してください。");
                }
            });
        }
    });
</script>
