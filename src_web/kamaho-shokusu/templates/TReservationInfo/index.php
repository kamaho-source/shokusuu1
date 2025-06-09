<?php
// 追加: $user を取得
$user = $this->request->getAttribute('identity');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>食数予約</title>
    <style>
        #calendar {
            max-width: 130%;
            margin: 0 auto;
        }
        @media screen and (max-width: 768px) {
            .fc-toolbar button { font-size: 12px; }
            .fc-toolbar-title { font-size: 14px; }
            #calendar { font-size: 12px; }
        }
        @media screen and (min-width: 769px) and (max-width: 1024px) {
            .fc-toolbar button { font-size: 14px; }
            .fc-toolbar-title { font-size: 16px; }
            #calendar { font-size: 14px; }
        }
        @media screen and (min-width: 1025px) {
            #calendar { font-size: 16px; }
        }
    </style>
</head>
<body>

<div class="container">
    <h1>食数予約</h1>

    <?php if ($user && $user->get('i_admin') === 1): // i_adminが1の場合 ?>
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

<?= $this->Html->script('jquery-3.5.1.slim.min.js') ?>
<?= $this->Html->script('index.global.min.js') ?>
<?= $this->Html->script('japanese-holidays.min.js') ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var defaultDate = new Date();
        defaultDate.setMonth(defaultDate.getMonth() + 1);

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
                displayOrder: <?= $mealType ?>
            },
            <?php endif; ?>
            <?php endforeach; ?>
            <?php endforeach; ?>
            <?php endif; ?>
        ];

        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialDate: defaultDate,
            initialView: 'dayGridMonth',
            locale: 'ja',
            height: 'auto',
            contentHeight: 'auto',
            expandRows: true,
            aspectRatio: 1.35,
            customButtons: {
                nextMonth: {
                    text: '次月',
                    click: function() {
                        calendar.next();
                    }
                }
            },
            headerToolbar: {
                right: 'prev,today,nextMonth,next',
                center: '',
            },
            buttonText: {
                today: '今日',
                month: '月',
                week: '週',
                day: '日',
                list: 'リスト'
            },
            events: function(fetchInfo, successCallback, failureCallback) {
                var startYear = fetchInfo.start.getFullYear();
                var endYear = fetchInfo.end.getFullYear();

                var holidayEvents = [];
                for (var year = startYear; year <= endYear; year++) {
                    // 修正: yearが数値かどうかをチェック
                    if (typeof year === "number" && !isNaN(year)) {
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
                }
                successCallback(existingEvents.concat(holidayEvents));
            },
            eventOrder: 'displayOrder',
            dateClick: function(info) {
                let date = new Date(info.dateStr);
                let isMonday = date.getDay() === 1;

                // PHPからユーザー権限を埋め込む
                const isAdmin = <?= $user && $user->get('i_admin') === 1 ? 'true' : 'false' ?>;
                const isLevel0 = <?= $user && $user->get('i_user_level') == 0 ? 'true' : 'false' ?>;

                if (isMonday && (isAdmin || isLevel0)) {
                    if (confirm("週の一括予約を行いますか？")) {
                        window.location.href = '<?= $this->Url->build('/TReservationInfo/bulkAddForm') ?>?date=' + info.dateStr;
                        return;
                    }
                }

                // 一括予約が許可されないユーザー、または月曜日以外は通常予約へ
                window.location.href = '<?= $this->Url->build('/TReservationInfo/view') ?>?date=' + info.dateStr;
            }

        });

        calendar.render();

        const excelButton = document.getElementById("downloadExcel");
        const monthSelect = document.getElementById("monthSelect");
        if (excelButton) {
            excelButton.addEventListener("click", async function () {
                try {
                    const selectedMonth = monthSelect.value;
                    console.info("選択された月:", selectedMonth);

                    const response = await fetch(`/kamaho-shokusu/TReservationInfo/exportJson?month=${selectedMonth}`);
                    if (!response.ok) throw new Error(`APIエラー: ${response.status}`);
                    const data = await response.json();
                    console.info("取得したデータ:", data);

                    const workbook = new ExcelJS.Workbook();
                    const sheet = workbook.addWorksheet("食数予約");

                    sheet.addRow(["部屋名", "日付", "朝食", "昼食", "夕食", "弁当"]);
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

                    const buffer = await workbook.xlsx.writeBuffer();
                    const blob = new Blob([buffer], {
                        type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                    });
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
                    const selectedMonth = monthSelect.value;
                    console.info("選択された月（ランク別エクセル）:", selectedMonth);

                    const response = await fetch(`/kamaho-shokusu/TReservationInfo/exportJsonrank?month=${selectedMonth}`);
                    if (!response.ok) throw new Error(`APIエラー: ${response.status}`);
                    const data = await response.json();
                    console.info("取得したランク別のデータ（性別込み）:", data);

                    if (!Array.isArray(data)) {
                        console.error("データ形式が不正です:", data);
                        alert(data.message || "エクスポートするデータが見つかりませんでした。");
                        return;
                    }

                    function translateMealType(breakfast, lunch, dinner, bento) {
                        const meals = [];
                        if (breakfast > 0) meals.push(`朝食 (${breakfast})`);
                        if (lunch > 0) meals.push(`昼食 (${lunch})`);
                        if (dinner > 0) meals.push(`夕食 (${dinner})`);
                        if (bento > 0) meals.push(`弁当 (${bento})`);
                        return meals.join('、') || "該当なし";
                    }

                    const workbook = new ExcelJS.Workbook();
                    workbook.creator = "予約システム";
                    workbook.created = new Date();
                    workbook.modified = new Date();

                    const sheet = workbook.addWorksheet(`ランク別データ`);
                    const header = ["ランク", "性別", "日付", "朝", "昼", "夜", "弁当", "合計人数"];
                    sheet.addRow(header);

                    data.forEach(rankData => {
                        sheet.addRow([
                            rankData.rank_name,
                            rankData.gender,
                            rankData.reservation_date,
                            rankData.breakfast || 0,
                            rankData.lunch || 0,
                            rankData.dinner || 0,
                            rankData.bento || 0,
                            rankData.total_eaters || 0
                        ]);
                    });

                    sheet.getRow(1).font = { bold: true };

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
                        column.width = maxLength + 2;
                    });

                    const buffer = await workbook.xlsx.writeBuffer();
                    const blob = new Blob([buffer], {
                        type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                    });
                    const link = document.createElement("a");
                    link.href = URL.createObjectURL(blob);
                    link.download = `実施食数表_${selectedMonth}.xlsx`;
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