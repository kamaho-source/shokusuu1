<?php
$this->assign('title', '食数予約');
$user = $this->request->getAttribute('identity');

/**
 * コントローラ側で
 *   $myReservationDates = ['2025-07-15', '2025-07-20', ...];
 * を渡している想定。渡されていない場合は空配列で初期化しておく。
 */
$myReservationDates = $myReservationDates ?? [];

/**
 * 予約詳細（朝・昼・夜・弁当の予約有無）
 * 未定義だと notice になるため空配列で初期化
 */
$myReservationDetails = $myReservationDetails ?? [];

/**
 * 予約集計用データ（朝・昼・夜・弁当別件数）
 * 未定義だと notice になるため空配列で初期化
 */
$mealDataArray = $mealDataArray ?? [];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>食数予約</title>
    <!-- CSRFトークンを埋め込み -->
    <meta name="csrfToken" content="<?= h($this->request->getAttribute('csrfToken')) ?>">
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

    <?php if ($user && $user->get('i_admin') === 1): ?>
        <div style="margin-bottom: 15px;">
            <label for="fromDate">期間開始日:</label>
            <input type="date" id="fromDate"
                   value="<?= date('Y-m-01') ?>">

            <label for="toDate" style="margin-left:10px;">期間終了日:</label>
            <input type="date" id="toDate"
                   value="<?= date('Y-m-t') ?>">
        </div>

        <button class="btn btn-success float-lg-right mb-3"
                id="downloadExcel" style="margin-bottom: 10px;">
            食数予定表をダウンロード
        </button>
        <button class="btn btn-success float-lg-right mb-3"
                id="downloadExcelRank" style="margin-bottom: 10px;">
            実施食数表をダウンロード
        </button>
        <button class="btn btn-success float-lg-right mb-3" id="downloadExcelExport" style="margin-bottom: 10px;">
            実施食数表(部屋)をダウンロード
        </button>
    <?php endif; ?>

    <div id="calendar"></div>
</div>

<?= $this->Html->script('jquery-3.5.1.slim.min.js') ?>
<?= $this->Html->script('index.global.min.js') ?>
<?= $this->Html->script('japanese-holidays.min.js') ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        /* ===== CSRF トークン取得 ===== */
        const csrfToken = document
            .querySelector('meta[name="csrfToken"]')
            ?.getAttribute('content') ?? '';

        /* ===== HTML 要素取得 ===== */
        const calendarEl    = document.getElementById('calendar');
        const fromDateInput = document.getElementById('fromDate');
        const toDateInput   = document.getElementById('toDate');

        /* ===== 2週間後をデフォルト表示 ===== */
        const defaultDate = (() => {
            const d = new Date();
            d.setDate(d.getDate() + 14);
            return d;
        })();

        /* ===== PHP から渡された予約済み日付 ===== */
        const reservedDates = [
            <?php foreach ($myReservationDates as $reservedDate): ?>
            '<?= h($reservedDate) ?>',
            <?php endforeach; ?>
        ];
        <?php
        /* ========== 予約済イベント（詳細付き） ========== */
        $icon = static function ($v) {
            // ※ 値が null のときだけ未設定扱い
            if ($v === null) {
                return '🙅';
            }
            // truthy → 予約あり, falsy → 予約なし
            return $v ? '🙆' : '🙅';
        };

        ?>


        /* ===== 既存イベント（予約済・集計済み食数） ===== */
        const existingEvents = [
            <?php foreach ($myReservationDates as $reservedDate): ?>
            <?php
            // 指定日の詳細情報を取得（存在しない場合は空配列）
            $detail = $myReservationDetails[$reservedDate] ?? [];

            // タイトル文字列を組み立て
            $title = sprintf(
                '(朝:%s 昼:%s 夜:%s 弁当:%s)',
                $icon($detail['breakfast'] ?? null),
                $icon($detail['lunch']     ?? null),
                $icon($detail['dinner']    ?? null),
                $icon($detail['bento']     ?? null)
            );
            ?>
            {
                title: '<?= h($title) ?>',
                start: '<?= h($reservedDate) ?>',
                allDay: true,
                backgroundColor: '#28a745',
                borderColor: '#28a745',
                textColor: 'white',
                extendedProps: { displayOrder: -2 } // ★ 未予約イベントより優先表示
            },
            <?php endforeach; ?>


            <?php if (!empty($mealDataArray)): ?>
            <?php
            $mealTypes = [
                '1' => '朝',
                '2' => '昼',
                '3' => '夜',
                '4' => '弁当'
            ];
            foreach ($mealDataArray as $date => $meals):
            foreach ($mealTypes as $type => $name):
            if (isset($meals[$type]) && $meals[$type] > 0):
            ?>
            {
                title: '<?= $name ?>: <?= $meals[$type] ?>人',
                start: '<?= $date ?>',
                allDay: true,
                extendedProps: { displayOrder: <?= $type ?> }
            },
            <?php
            endif;
            endforeach;
            endforeach;
            endif;
            ?>
        ];

        /* =========================================================
           カレンダー ⇔ 入力欄 同期用ユーティリティ
        ========================================================= */
        /**
         * 指定日付を YYYY-MM-DD 形式にフォーマット
         * @param {Date} date
         * @returns {string}
         */
        function formatYmd(date) {
            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        }

        /**
         * カレンダー側の表示月が変わったら
         * 期間開始日＝月初、期間終了日＝月末 を自動設定
         * @param {FullCalendar.View} view
         */
        function updateInputsByCalendar(view) {
            if (!fromDateInput || !toDateInput) return;

            const start = view.currentStart;         // 当月 1 日（ローカル）
            const end   = new Date(view.currentEnd); // 翌月 1 日（ローカル）
            end.setDate(end.getDate() - 1);          // 当月末日に補正

            fromDateInput.value = formatYmd(start);
            toDateInput.value   = formatYmd(end);
        }


        /**
         * 期間開始日を手入力で変更したら
         * その日の属する月をカレンダーへジャンプ
         */
        function updateCalendarByInput() {
            if (!fromDateInput?.value) return;
            calendar.gotoDate(fromDateInput.value);
        }

        /* ===== FullCalendar 初期化 ===== */
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialDate: defaultDate,
            initialView: 'dayGridMonth',
            locale: 'ja',
            height: 'auto',
            contentHeight: 'auto',
            expandRows: true,
            aspectRatio: 1.35,
            customButtons:{
                nextMonth:{
                    text: '次月',
                    click: () => calendar.next()
                }
            },
            headerToolbar: {
                right: 'prev,today,nextMonth,next',
                center: ''
            },
            buttonText: {
                today: '今日'
            },
            /* ★ ビュー切替時に入力欄へ反映 */
            datesSet: (arg) => updateInputsByCalendar(arg.view),

            /* ----- 祝日 + 予約済 + 未予約 ----- */
            events: (fetchInfo, successCallback) => {
                const holidayEvents = [];
                for (let y = fetchInfo.start.getFullYear(); y <= fetchInfo.end.getFullYear(); y++) {
                    const holidays = JapaneseHolidays.getHolidaysOf(y) ?? [];
                    holidays.forEach(h => {
                        holidayEvents.push({
                            title: h.name,
                            start: `${y}-${String(h.month).padStart(2, '0')}-${String(h.date).padStart(2, '0')}`,
                            allDay: true,
                            backgroundColor: 'red',
                            borderColor: 'red',
                            textColor: 'white',
                            extendedProps: { displayOrder: 0 }
                        });
                    });
                }

                /* 未予約（予約日以外すべて） */
                // 未予約（予約日以外すべて）
                const unreservedEvents = [];
                const cur = new Date(fetchInfo.start);
                while (cur < fetchInfo.end) {
                    const dateStr = cur.toISOString().slice(0, 10);
                    if (!reservedDates.includes(dateStr)) {
                        unreservedEvents.push({
                            title: '未予約',
                            start: dateStr,
                            allDay: true,
                            backgroundColor: '#fd7e14',
                            borderColor: '#fd7e14',
                            textColor: 'white',
                            extendedProps: { displayOrder: -10 } // ← ★ここを変更
                        });
                    }
                    cur.setDate(cur.getDate() + 1);
                }


                successCallback([...existingEvents, ...holidayEvents, ...unreservedEvents]);
            },
            eventOrder: function (a, b) {
                const orderA = Number(a.extendedProps?.displayOrder ?? 0);
                const orderB = Number(b.extendedProps?.displayOrder ?? 0);

                // NaN 対策（念のため）
                const safeA = isNaN(orderA) ? 0 : orderA;
                const safeB = isNaN(orderB) ? 0 : orderB;

                return safeA - safeB;
            }, // ★ 表示優先度キーを変更
            dateClick: info => {
                const clickedDate = new Date(info.dateStr);           // クリックした日
                const today       = new Date();                       // 今日
                today.setHours(0, 0, 0, 0);                           // 時間を 00:00 に固定

                // クリック日 − 今日 の差（日数）
                const　MILLISECONDS_IN_DAY = 86_400_000; // 1 日のミリ秒数
                const diffDays = (clickedDate - today) / MILLISECONDS_IN_DAY;  // 86 400 000 = 1000*60*60*24

                const isMonday      = clickedDate.getDay() === 1;     // 月曜？
                const within14Days  = diffDays >= 0 && diffDays <= 14;// 当日を含め 14 日以内？

                // 週の一括予約は「15 日前」より先の月曜のみ許可
                if (isMonday && !within14Days) {
                    if (confirm('週の一括予約を行いますか？')) {
                        window.location.href =
                            '<?= $this->Url->build("/TReservationInfo/bulkAddForm") ?>?date=' + info.dateStr;
                    }else{
                        window.location.href =
                            '<?= $this->Url->build("/TReservationInfo/view") ?>?date=' + info.dateStr;
                    }
                    return; // 月曜処理で終了
                }

                // それ以外は通常の個別予約一覧へ
                window.location.href =
                    '<?= $this->Url->build("/TReservationInfo/view") ?>?date=' + info.dateStr;
            }
        });

        /* ===== カレンダー描画 & 初期同期 ===== */
        calendar.render();
        updateInputsByCalendar(calendar.view);          // 初回同期
        fromDateInput?.addEventListener('change', updateCalendarByInput);

        /* ===== 共通: ブック→ダウンロード ===== */
        async function downloadWorkbook(workbook, filename) {
            const buffer = await workbook.xlsx.writeBuffer();
            const blob = new Blob([buffer], {
                type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(link.href);
        }

        const excelButton      = document.getElementById('downloadExcel');
        const rankExportButton = document.getElementById('downloadExcelRank');
        const excelExportButton = document.getElementById('downloadExcelExport');

        if (excelButton) {
            excelButton.addEventListener('click', async () => {
                try {
                    /* ========== 1. パラメータチェック ========== */
                    const fromDate = fromDateInput.value;
                    const toDate   = toDateInput.value;

                    if (!fromDate || !toDate) {
                        alert('開始日・終了日を入力してください');
                        return;
                    }
                    if (fromDate > toDate) {
                        alert('開始日は終了日以前の日付を指定してください');
                        return;
                    }
                    console.info('抽出期間:', fromDate, '〜', toDate);

                    /* ========== 2. API 取得 ========== */
                    const response = await fetch(
                        '<?= $this->Url->build('/TReservationInfo/exportJson') ?>' +
                        `?from=${fromDate}&to=${toDate}`,
                        {
                            headers: { 'X-CSRF-Token': csrfToken },
                        },
                    );
                    if (!response.ok) throw new Error(`APIエラー: ${response.status}`);

                    const data = await response.json();
                    console.info('取得したデータ:', data);

                    const hasRooms   = data.rooms   && Object.keys(data.rooms).length   > 0;
                    const hasOverall = data.overall && Object.keys(data.overall).length > 0;

                    if (!hasRooms && !hasOverall) {
                        alert('データがありません');
                        return;
                    }

                    /* ========== 3. Excel 生成 ========== */
                    const workbook = new ExcelJS.Workbook();
                    workbook.creator  = '食数予約システム';
                    workbook.created  = new Date();
                    workbook.modified = new Date();

                    /**
                     * ヘッダー行を追加
                     * @param {ExcelJS.Worksheet} sheet
                     * @param {boolean} includeRoomName 部屋名列を含めるか
                     */
                    const addHeader = (sheet, includeRoomName = false) => {
                        const header = includeRoomName
                            ? ['日付', '部屋名', '朝食', '昼食', '夕食', '弁当', '合計']
                            : ['日付', '朝食', '昼食', '夕食', '弁当', '合計'];
                        const row = sheet.addRow(header);
                        row.font = { bold: true };
                        // ヘッダーで固定
                        sheet.views = [{ state: 'frozen', ySplit: 1 }];
                    };

                    /**
                     * 合計行を追加（表示したままに変更）
                     * @param {ExcelJS.Worksheet} sheet
                     * @param {boolean} includeRoomName 部屋名列を含めるか
                     */
                    const addTotalRow = (sheet, includeRoomName = false) => {
                        // 集計用配列 [朝, 昼, 夜, 弁当]
                        const mealTotals = [0, 0, 0, 0];

                        // ヘッダー行（1 行目）を除外して数値を加算
                        sheet.eachRow((row, rowNumber) => {
                            if (rowNumber === 1) return; // ヘッダーはスキップ
                            const offset = includeRoomName ? 2 : 1;
                            for (let i = 0; i < mealTotals.length; i++) {
                                mealTotals[i] += Number(row.getCell(offset + i + 1).value ?? 0);
                            }
                        });

                        // “総計”列
                        const grandTotal = mealTotals.reduce((a, b) => a + b, 0);

                        // “合計” 行
                        const rowValues = includeRoomName
                            ? ['合計', '', ...mealTotals, grandTotal]
                            : ['合計', ...mealTotals, grandTotal];

                        const totalRow = sheet.addRow(rowValues);
                        totalRow.font = { bold: true };

                        // 目立たせるため罫線を引く
                        totalRow.eachCell((cell) => {
                            cell.border = {
                                top:    { style: 'thin' },
                                bottom: { style: 'double' },
                            };
                        });
                    };

                    /* ----- 3-A. 全体シート（日付 × 部屋名） ----- */
                    const overallSheet = workbook.addWorksheet('全体');
                    addHeader(overallSheet, true);

                    if (hasRooms) {
                        /* rooms から作成：日付 × 部屋名 ------------------------ */
                        const allDates  = new Set();
                        const roomNames = Object.keys(data.rooms).sort();

                        // 全日付収集
                        roomNames.forEach((room) => {
                            Object.keys(data.rooms[room] ?? {}).forEach((d) => allDates.add(d));
                        });
                        const sortedDates = [...allDates].sort();

                        // 出力
                        sortedDates.forEach((date) => {
                            roomNames.forEach((room) => {
                                const counts = (data.rooms[room] ?? {})[date] ?? {};
                                const total =
                                    (counts['朝'] ?? 0) +
                                    (counts['昼'] ?? 0) +
                                    (counts['夜'] ?? 0) +
                                    (counts['弁当'] ?? 0);

                                overallSheet.addRow([
                                    date,
                                    room,
                                    counts['朝'] ?? 0,
                                    counts['昼'] ?? 0,
                                    counts['夜'] ?? 0,
                                    counts['弁当'] ?? 0,
                                    total,
                                ]);
                            });
                        });
                    } else if (hasOverall) {
                        /* 旧 API フォーマット（集計値のみ） -------------------- */
                        Object.keys(data.overall)
                            .sort()
                            .forEach((date) => {
                                const c = data.overall[date] ?? {};
                                const total =
                                    (c['朝'] ?? 0) +
                                    (c['昼'] ?? 0) +
                                    (c['夜'] ?? 0) +
                                    (c['弁当'] ?? 0);

                                overallSheet.addRow([
                                    date,
                                    '全体',
                                    c['朝'] ?? 0,
                                    c['昼'] ?? 0,
                                    c['夜'] ?? 0,
                                    c['弁当'] ?? 0,
                                    total,
                                ]);
                            });
                    }

                    // ★ 合計行を追加
                    addTotalRow(overallSheet, true);

                    /* ----- 3-B. 部屋別シート（存在する場合のみ） ----- */
                    if (hasRooms) {
                        Object.keys(data.rooms).forEach((roomNameRaw) => {
                            // Excel のシート名は 31 文字 & 禁止文字除去
                            const sheetName =
                                roomNameRaw.replace(/[:\\/?*\[\]]/g, '').substring(0, 31) || '部屋';
                            const sheet = workbook.addWorksheet(sheetName);
                            addHeader(sheet);

                            const roomData = data.rooms[roomNameRaw];
                            Object.keys(roomData)
                                .sort()
                                .forEach((date) => {
                                    const m = roomData[date];
                                    const total =
                                        (m['朝'] ?? 0) +
                                        (m['昼'] ?? 0) +
                                        (m['夜'] ?? 0) +
                                        (m['弁当'] ?? 0);

                                    sheet.addRow([
                                        date,
                                        m['朝'] ?? 0,
                                        m['昼'] ?? 0,
                                        m['夜'] ?? 0,
                                        m['弁当'] ?? 0,
                                        total,
                                    ]);
                                });

                            // ★ 各部屋シートにも合計行を追加
                            addTotalRow(sheet);
                        });
                    }

                    /* ========== 4. ダウンロード ========== */
                    await downloadWorkbook(workbook, `食数予約_${fromDate}〜${toDate}.xlsx`);
                } catch (error) {
                    console.error('エクスポート中にエラー発生:', error);
                    alert('エクスポート中にエラーが発生しました。管理者に連絡してください。');
                }
            });
        }
        if (excelExportButton) {
            excelExportButton.addEventListener('click', async () => {
                try {
                    /* ========== 1. パラメータチェック ========== */
                    const fromDate = fromDateInput.value;
                    const toDate   = toDateInput.value;

                    if (!fromDate || !toDate) {
                        alert('開始日・終了日を入力してください');
                        return;
                    }
                    if (fromDate > toDate) {
                        alert('開始日は終了日以前の日付を指定してください');
                        return;
                    }
                    console.info('抽出期間:', fromDate, '〜', toDate);

                    /* ========== 2. API 取得 ========== */
                    const response = await fetch(
                        '<?= $this->Url->build('/TReservationInfo/exportJsonChangeFlag') ?>' +
                        `?from=${fromDate}&to=${toDate}`,
                        {
                            headers: { 'X-CSRF-Token': csrfToken },
                        },
                    );
                    if (!response.ok) throw new Error(`APIエラー: ${response.status}`);

                    const data = await response.json();
                    console.info('取得したデータ:', data);

                    const hasRooms   = data.rooms   && Object.keys(data.rooms).length   > 0;
                    const hasOverall = data.overall && Object.keys(data.overall).length > 0;

                    if (!hasRooms && !hasOverall) {
                        alert('データがありません');
                        return;
                    }

                    /* ========== 3. Excel 生成 ========== */
                    const workbook = new ExcelJS.Workbook();
                    workbook.creator  = '食数予約システム';
                    workbook.created  = new Date();
                    workbook.modified = new Date();

                    /**
                     * ヘッダー行を追加
                     * @param {ExcelJS.Worksheet} sheet
                     * @param {boolean} includeRoomName 部屋名列を含めるか
                     */
                    const addHeader = (sheet, includeRoomName = false) => {
                        const header = includeRoomName
                            ? ['日付', '部屋名', '朝食', '昼食', '夕食', '弁当', '合計']
                            : ['日付', '朝食', '昼食', '夕食', '弁当', '合計'];
                        const row = sheet.addRow(header);
                        row.font = { bold: true };
                        // ヘッダーで固定
                        sheet.views = [{ state: 'frozen', ySplit: 1 }];
                    };

                    /**
                     * 合計行を追加（表示したままに変更）
                     * @param {ExcelJS.Worksheet} sheet
                     * @param {boolean} includeRoomName 部屋名列を含めるか
                     */
                    const addTotalRow = (sheet, includeRoomName = false) => {
                        // 集計用配列 [朝, 昼, 夜, 弁当]
                        const mealTotals = [0, 0, 0, 0];

                        // ヘッダー行（1 行目）を除外して数値を加算
                        sheet.eachRow((row, rowNumber) => {
                            if (rowNumber === 1) return; // ヘッダーはスキップ
                            const offset = includeRoomName ? 2 : 1;
                            for (let i = 0; i < mealTotals.length; i++) {
                                mealTotals[i] += Number(row.getCell(offset + i + 1).value ?? 0);
                            }
                        });

                        // “総計”列
                        const grandTotal = mealTotals.reduce((a, b) => a + b, 0);

                        // “合計” 行
                        const rowValues = includeRoomName
                            ? ['合計', '', ...mealTotals, grandTotal]
                            : ['合計', ...mealTotals, grandTotal];

                        const totalRow = sheet.addRow(rowValues);
                        totalRow.font = { bold: true };

                        // 目立たせるため罫線を引く
                        totalRow.eachCell((cell) => {
                            cell.border = {
                                top:    { style: 'thin' },
                                bottom: { style: 'double' },
                            };
                        });
                    };

                    /* ----- 3-A. 全体シート（日付 × 部屋名） ----- */
                    const overallSheet = workbook.addWorksheet('全体');
                    addHeader(overallSheet, true);

                    if (hasRooms) {
                        /* rooms から作成：日付 × 部屋名 ------------------------ */
                        const allDates  = new Set();
                        const roomNames = Object.keys(data.rooms).sort();

                        // 全日付収集
                        roomNames.forEach((room) => {
                            Object.keys(data.rooms[room] ?? {}).forEach((d) => allDates.add(d));
                        });
                        const sortedDates = [...allDates].sort();

                        // 出力
                        sortedDates.forEach((date) => {
                            roomNames.forEach((room) => {
                                const counts = (data.rooms[room] ?? {})[date] ?? {};
                                const total =
                                    (counts['朝'] ?? 0) +
                                    (counts['昼'] ?? 0) +
                                    (counts['夜'] ?? 0) +
                                    (counts['弁当'] ?? 0);

                                overallSheet.addRow([
                                    date,
                                    room,
                                    counts['朝'] ?? 0,
                                    counts['昼'] ?? 0,
                                    counts['夜'] ?? 0,
                                    counts['弁当'] ?? 0,
                                    total,
                                ]);
                            });
                        });
                    } else if (hasOverall) {
                        /* 旧 API フォーマット（集計値のみ） -------------------- */
                        Object.keys(data.overall)
                            .sort()
                            .forEach((date) => {
                                const c = data.overall[date] ?? {};
                                const total =
                                    (c['朝'] ?? 0) +
                                    (c['昼'] ?? 0) +
                                    (c['夜'] ?? 0) +
                                    (c['弁当'] ?? 0);

                                overallSheet.addRow([
                                    date,
                                    '全体',
                                    c['朝'] ?? 0,
                                    c['昼'] ?? 0,
                                    c['夜'] ?? 0,
                                    c['弁当'] ?? 0,
                                    total,
                                ]);
                            });
                    }

                    // ★ 合計行を追加
                    addTotalRow(overallSheet, true);

                    /* ----- 3-B. 部屋別シート（存在する場合のみ） ----- */
                    if (hasRooms) {
                        Object.keys(data.rooms).forEach((roomNameRaw) => {
                            // Excel のシート名は 31 文字 & 禁止文字除去
                            const sheetName =
                                roomNameRaw.replace(/[:\\/?*\[\]]/g, '').substring(0, 31) || '部屋';
                            const sheet = workbook.addWorksheet(sheetName);
                            addHeader(sheet);

                            const roomData = data.rooms[roomNameRaw];
                            Object.keys(roomData)
                                .sort()
                                .forEach((date) => {
                                    const m = roomData[date];
                                    const total =
                                        (m['朝'] ?? 0) +
                                        (m['昼'] ?? 0) +
                                        (m['夜'] ?? 0) +
                                        (m['弁当'] ?? 0);

                                    sheet.addRow([
                                        date,
                                        m['朝'] ?? 0,
                                        m['昼'] ?? 0,
                                        m['夜'] ?? 0,
                                        m['弁当'] ?? 0,
                                        total,
                                    ]);
                                });

                            // ★ 各部屋シートにも合計行を追加
                            addTotalRow(sheet);
                        });
                    }

                    /* ========== 4. ダウンロード ========== */
                    await downloadWorkbook(workbook, `食数実施(部屋)_${fromDate}〜${toDate}.xlsx`);
                } catch (error) {
                    console.error('エクスポート中にエラー発生:', error);
                    alert('エクスポート中にエラーが発生しました。管理者に連絡してください。');
                }
            });
        }


        /* ===== 実施食数表エクスポート ===== */
        if (!rankExportButton) return;

        rankExportButton.addEventListener('click', async () => {
            try {
                const fromDate = fromDateInput.value;
                const toDate   = toDateInput.value;
                if (!fromDate || !toDate) { alert('開始日・終了日を入力してください'); return; }
                if (fromDate > toDate)    { alert('開始日は終了日以前の日付を指定してください'); return; }

                const res = await fetch(
                    '<?= $this->Url->build('/TReservationInfo/exportJsonrank') ?>' +
                    `?from=${fromDate}&to=${toDate}`,
                    {
                        headers: { 'X-CSRF-Token': csrfToken }
                    }
                );
                if (!res.ok) throw new Error(`APIエラー: ${res.status}`);

                const json = await res.json();
                // 配列形式ならそのまま、オブジェクト形式なら整形
                const rows = Array.isArray(json)
                    ? json
                    : Object.values(json);

                if (rows.length === 0) { alert('データがありません'); return; }

                /* ===== Excel 作成 ===== */
                const wb = new ExcelJS.Workbook();
                const ws = wb.addWorksheet('実施食数表');

                // 列定義（英語キー → 日本語ヘッダー）
                const columns = [
                    { key: 'reservation_date', header: '日付'    },
                    { key: 'rank_name',        header: 'ランク'  },
                    { key: 'gender',           header: '性別'    },
                    { key: 'breakfast',        header: '朝食'    },
                    { key: 'lunch',            header: '昼食'    },
                    { key: 'dinner',           header: '夕食'    },
                    { key: 'bento',            header: '弁当'    },
                    { key: 'total_eaters',     header: '合計'    },
                ];

                // ヘッダー行
                const headerRow = ws.addRow(columns.map(c => c.header));
                headerRow.font = { bold: true };

                // データ行
                rows.forEach(r => ws.addRow(columns.map(c => r[c.key] ?? '')));

                await downloadWorkbook(wb, `実施食数表_${fromDate}〜${toDate}.xlsx`);
            } catch (e) {
                console.error(e);
                alert('エクスポートに失敗しました');
            }
        });
    });

</script>
</body>
</html>