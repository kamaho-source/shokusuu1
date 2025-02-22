<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>食数予約</title>
</head>
<body>

<div class="container">
    <h1>食数予約</h1>

    <?php if ($user->get('i_admin') === 1): // i_adminが1の場合 ?>
    <div style="margin-bottom: 15px;">
        <label for="monthSelect">エクスポートする月を選択:</label>
        <select id="monthSelect">
            <?php for ($month = 1; $month <= 12; $month++) : ?>
                <option value="<?= date('Y-') . str_pad($month, 2, '0', STR_PAD_LEFT) ?>" <?= $month == date('n') ? 'selected' : '' ?>>
                    <?= $month ?>月
                </option>
            <?php endfor; ?>
        </select>
    </div>

        <button class="btn btn-success float-lg-right mb-3" id="downloadExcel" style="margin-bottom: 10px;">食数予定表をダウンロード</button>
        <button class="btn btn-success float-lg-right mb-3" id="downloadExcelRank" style="margin-bottom: 10px;">実施食数表をダウンロード</button>
    <?php endif; ?>

    <div id="calendar"></div>
</div>

<!-- Include jQuery and Bootstrap JS -->
<?= $this->Html->script('jquery-3.5.1.slim.min.js') ?>

<!-- Include FullCalendar JS -->
<?= $this->Html->script('index.global.min.js') ?>

<!-- Include japanese-holidays.js -->
<?= $this->Html->script('japanese-holidays.min.js') ?>

<!-- Include ExcelJS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');

        // PHPで生成された既存のイベント（食数予約）
        var existingEvents = [
            <?php if (!empty($mealDataArray)) : ?>
            <?php foreach ($mealDataArray as $date => $meals): ?>
            <?php
            $mealTypes = [
                '1' => '朝',
                '2' => '昼',
                '3' => '夜',
                '4' => '弁当'
            ];
            ?>
            <?php foreach ($mealTypes as $mealType => $mealName): ?>
            <?php if (isset($meals[$mealType]) && $meals[$mealType] > 0): ?>
            {
                title: '<?= $mealName ?>: <?= $meals[$mealType] ?>人',
                start: '<?= $date ?>',
                allDay: true,
                displayOrder: <?= $mealType ?> // 順序を指定
            },
            <?php endif; ?>
            <?php endforeach; ?>
            <?php endforeach; ?>
            <?php endif; ?>
        ];

        // カレンダーの初期化とイベント統合
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            locale: 'ja',
            events: function(fetchInfo, successCallback, failureCallback) {
                var startYear = fetchInfo.start.getFullYear();
                var endYear = fetchInfo.end.getFullYear();

                // 祝日イベントを生成
                var holidayEvents = [];
                for (var year = startYear; year <= endYear; year++) {
                    const holidays = JapaneseHolidays.getHolidaysOf(year);
                    if (holidays && Array.isArray(holidays)) {
                        holidays.forEach(function(holiday) {
                            holidayEvents.push({
                                title: holiday.name,
                                start: `${year}-${String(holiday.month).padStart(2, '0')}-${String(holiday.date).padStart(2, '0')}`,
                                allDay: true,
                                backgroundColor: 'red',
                                borderColor: 'red',
                                textColor: 'white',
                                displayOrder: 0,
                            });
                        });
                    }
                }

                // 食数予約データと祝日データを統合
                successCallback(existingEvents.concat(holidayEvents));
            },
            eventOrder: 'displayOrder', // 順序で並べ替え
            dateClick: function(info) {
                let date = new Date(info.dateStr);
                let isMonday = date.getDay() === 1;
                if (isMonday && confirm("週の一括予約を行いますか？")) {
                    window.location.href = '<?= $this->Url->build('/TReservationInfo/bulkAddForm') ?>?date=' + info.dateStr;
                } else {
                    window.location.href = '<?= $this->Url->build('/TReservationInfo/view') ?>?date=' + info.dateStr;
                }
            }
        });

        calendar.render();

        // 「Excelをダウンロード」処理
        const excelButton = document.getElementById("downloadExcel");
        const monthSelect = document.getElementById("monthSelect");

        if (excelButton) {
            excelButton.addEventListener("click", async function () {
                try {
                    const selectedMonth = monthSelect.value; // 選択された月
                    console.info("選択された月:", selectedMonth);

                    const response = await fetch(`/kamaho-shokusu/TReservationInfo/exportJson?month=${selectedMonth}`);
                    if (!response.ok) throw new Error(`APIエラー: ${response.status}`);
                    const data = await response.json();
                    console.info("取得したデータ:", data);

                    const workbook = new ExcelJS.Workbook();
                    const sheet = workbook.addWorksheet("食数予約");

                    // ヘッダー行を作成
                    sheet.addRow(["部屋名", "日付", "朝食", "昼食", "夕食", "弁当"]);

                    // データ行を追加
                    if (data.rooms && Object.keys(data.rooms).length > 0) {
                        Object.keys(data.rooms).forEach(roomName => {
                            const roomData = data.rooms[roomName];
                            Object.keys(roomData).forEach(date => {
                                const meals = roomData[date];
                                sheet.addRow([
                                    roomName,
                                    date,
                                    meals['朝'] || 0,
                                    meals['昼'] || 0,
                                    meals['夜'] || 0,
                                    meals['弁当'] || 0
                                ]);
                            });
                        });
                    } else {
                        console.warn("データが空です！");
                    }

                    // Excelファイルを生成
                    const buffer = await workbook.xlsx.writeBuffer();
                    const blob = new Blob([buffer], {
                        type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                    });

                    // ダウンロード処理
                    const link = document.createElement("a");
                    link.href = window.URL.createObjectURL(blob);
                    link.download = `食数予約_${selectedMonth}.xlsx`;
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                } catch (error) {
                    console.error("エクスポート中にエラー発生:", error);
                    alert("エクスポート中にエラーが発生しました。管理者に連絡してください。");
                }
            });
        }
        const rankExportButton = document.getElementById("downloadExcelRank");
        if (rankExportButton) {
            rankExportButton.addEventListener("click", async function () {
                try {
                    const selectedMonth = monthSelect.value; // 選択された月
                    console.info("選択された月（ランク別エクセル）:", selectedMonth);

                    // ランク・性別ごとのデータを取得
                    const response = await fetch(`/kamaho-shokusu/TReservationInfo/exportJsonrank?month=${selectedMonth}`);
                    if (!response.ok) throw new Error(`APIエラー: ${response.status}`);

                    const data = await response.json();
                    console.info("取得したランク別のデータ（性別込み）:", data);

                    // データが空の場合の処理
                    if (!Array.isArray(data)) {
                        console.error("データ形式が不正です:", data);
                        alert(data.message || "エクスポートするデータが見つかりませんでした。");
                        return;
                    }

                    // 日本語表記を変換する関数
                    function translateMealType(breakfast, lunch, dinner, bento) {
                        const meals = [];
                        if (breakfast > 0) meals.push(`朝食 (${breakfast})`);
                        if (lunch > 0) meals.push(`昼食 (${lunch})`);
                        if (dinner > 0) meals.push(`夕食 (${dinner})`);
                        if (bento > 0) meals.push(`弁当 (${bento})`);
                        return meals.join('、') || "該当なし"; // データがない場合は「該当なし」
                    }

                    // Excel ワークブックを作成
                    const workbook = new ExcelJS.Workbook();
                    workbook.creator = "予約システム"; // 作成者情報
                    workbook.created = new Date();
                    workbook.modified = new Date();

                    // メインシート作成
                    const sheet = workbook.addWorksheet(`ランク別データ`);

                    // シートのヘッダー行 (日本語のラベル)
                    const header = ["ランク", "性別", "日付", "朝", "昼", "夜", "弁当", "合計人数"];
                    sheet.addRow(header);

                    // データを埋め込む
                    data.forEach(rankData => {
                        sheet.addRow([
                            rankData.rank_name,                // ランク名
                            rankData.gender,                   // 性別（男子/女子）
                            rankData.reservation_date,         // 日付
                            rankData.breakfast || 0,           // 朝食人数
                            rankData.lunch || 0,               // 昼食人数
                            rankData.dinner || 0,              // 夕食人数
                            rankData.bento || 0,               // 弁当
                            rankData.total_eaters || 0         // 合計人数
                        ]);
                    });

                    // 書式設定
                    sheet.getRow(1).font = { bold: true }; // ヘッダーを太字で表示

                    // **列幅を自動調整**
                    sheet.columns.forEach(column => {
                        let maxLength = 0;
                        column.eachCell({ includeEmpty: true }, cell => {
                            if (cell.value) {
                                const cellLength = cell.value.toString().length;
                                if (cellLength > maxLength) {
                                    maxLength = cellLength;
                                }
                            }
                        });
                        column.width = maxLength + 2; // 余白を持たせるため +2
                    });

                    // Excel ファイルを生成
                    const buffer = await workbook.xlsx.writeBuffer();
                    const blob = new Blob([buffer], {
                        type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                    });

                    // ダウンロード処理
                    const link = document.createElement("a");
                    link.href = URL.createObjectURL(blob);
                    link.download = `実施食数表_${selectedMonth}.xlsx`; // ファイル名
                    document.body.appendChild(link);
                    link.click();
                    link.remove();

                    console.info("ランク別データのエクセルファイルが生成されました！");
                } catch (error) {
                    console.error("ランク別データのエクスポート中にエラー:", error);
                    alert("ランク別データのエクセルエクスポート中にエラーが発生しました。");
                }
            });
        }
    });
</script>
</body>
</html>
