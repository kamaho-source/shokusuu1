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

        <!-- Submit Button -->
        <button type="button" id="downloadExcelWithDeductions" class="btn btn-primary">エクスポート</button>
    </form>
</div>

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

                    const data = await response.json();
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
    });
</script>