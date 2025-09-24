<?php
$this->assign('title', '食数予約');
$user = $this->request->getAttribute('identity');
$isChild = ($user && (int)$user->get('i_user_level') === 1);
// 所属部屋 ID はコントローラ（MUserGroup 経由）で $userRoomId としてセット済みを想定
$myReservationDates = $myReservationDates ?? [];
$myReservationDetails = $myReservationDetails ?? [];
$mealDataArray = $mealDataArray ?? [];

// 今日の日付
$today = date('Y-m-d');
// 今日の予約情報（コントローラでセットしておくこと）
$todayReservation = $myReservationDetails[$today] ?? [];
$hasTodayReservation = !empty($todayReservation) && (
                ($todayReservation['breakfast'] ?? false) ||
                ($todayReservation['lunch'] ?? false) ||
                ($todayReservation['dinner'] ?? false) ||
                ($todayReservation['bento'] ?? false)
        );
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>食数予約</title>
    <meta name="csrfToken" content="<?= h($this->request->getAttribute('csrfToken')) ?>">
    <!-- 追加: Bootstrap 5 CSS（CSS の重複は問題ないため残します） -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
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

    <!-- 本日の予約状況を大きく表示 -->
    <div class="reservation-status my-4 text-center">
        <?php
        // 表示用ラベル（数値キー → ラベル）
        $mealLabels = [
                1 => '朝食',
                2 => '昼食',
                3 => '夕食',
                4 => '弁当',
        ];
        // 本日の予約配列のキー対応（数値キー → 配列キー）
        $mealKeys = [
                1 => 'breakfast',
                2 => 'lunch',
                3 => 'dinner',
                4 => 'bento',
        ];
        ?>
        <?php if ($isChild): ?>
            <?php if ($hasTodayReservation): ?>
                <div class="alert alert-success py-4" style="font-size:1.5rem;">
                    <i class="bi bi-check-circle-fill" style="font-size:3rem;color:green;"></i>
                    <div class="mt-2 fw-bold" style="font-size:2rem;">本日の予約状況：予約済み</div>
                    <div class="mt-3" style="font-size:1.2rem;">
                        <?php foreach ($mealLabels as $key => $label): ?>
                            <?php $arrKey = $mealKeys[$key] ?? null; ?>
                            <?php if ($arrKey && ($todayReservation[$arrKey] ?? false)): ?>
                                <span style="margin-right:10px;"><?= h($label) ?>：予約あり</span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-4 d-flex flex-wrap justify-content-center" style="gap: 10px;">
                        <?php foreach ($mealLabels as $key => $label): ?>
                            <?php
                            $href = $this->Url->build([
                                    'controller' => 'TReservationInfo',
                                    'action' => 'changeEdit',
                                    $userRoomId, // /:roomId
                                    $today,      // /:date
                                    $key         // /:meal (1|2|3|4)
                            ]);
                            ?>
                            <a
                                    href="<?= $href ?>"
                                    class="btn btn-lg btn-warning"
                                    data-meal="<?= (int)$key ?>"
                                    data-role="change"
                            >
                                <?= h($label) ?>を変更
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-danger py-4" style="font-size:1.5rem;">
                    <i class="bi bi-x-circle-fill" style="font-size:3rem;color:red;"></i>
                    <div class="mt-2 fw-bold" style="font-size:2rem;">本日の予約状況：未予約</div>
                    <div class="mt-4 d-flex flex-wrap justify-content-center" style="gap: 10px;">
                        <?php foreach ($mealLabels as $key => $label): ?>
                            <?php
                            $href = $this->Url->build([
                                    'controller' => 'TReservationInfo',
                                    'action' => 'changeEdit',
                                    $userRoomId,
                                    $today,
                                    $key
                            ]);
                            ?>
                            <a
                                    href="<?= $href ?>"
                                    class="btn btn-lg btn-primary"
                                    data-meal="<?= (int)$key ?>"
                                    data-role="add"
                            >
                                <?= h($label) ?>を追加
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>



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
    <?php endif; ?>

    <div id="calendar"></div>
</div>

<?php
// 追加: 弁当変更ガード用（本日の昼食予約状態と昼食変更URL）
$lunchReserved  = (bool)($todayReservation['lunch'] ?? false);
$lunchChangeUrl = $this->Url->build([
        'controller' => 'TReservationInfo',
        'action'     => 'changeEdit',
        $userRoomId,
        $today,
        2 // lunch
]);

// 追加: 昼変更ガードの“逆側”（本日の弁当予約状態と弁当変更URL）
$bentoReserved  = (bool)($todayReservation['bento'] ?? false);
$bentoChangeUrl = $this->Url->build([
        'controller' => 'TReservationInfo',
        'action'     => 'changeEdit',
        $userRoomId,
        $today,
        4 // bento
]);
?>

<!-- 追加: Bootstrapモーダル（弁当変更時の警告） -->
<div class="modal fade" id="bentoLunchWarnModal" tabindex="-1" aria-labelledby="bentoLunchWarnTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bentoLunchWarnTitle">弁当の変更について</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                本日は<strong>昼食の予約が登録されています</strong>。<br>
                お弁当を変更する前に、<u>昼食の予約を無効（取り消し）</u>にしてください。
            </div>
            <div class="modal-footer">
                <a href="<?= h($lunchChangeUrl) ?>" class="btn btn-primary">昼食の予約を変更する</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
            </div>
        </div>
    </div>
</div>

<!-- 追加: Bootstrapモーダル（昼変更時の警告：弁当が入っている場合） -->
<div class="modal fade" id="lunchBentoWarnModal" tabindex="-1" aria-labelledby="lunchBentoWarnTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lunchBentoWarnTitle">昼食の変更について</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                本日は<strong>弁当の予約が登録されています</strong>。<br>
                昼食を変更する前に、<u>弁当の予約を無効（取り消し）</u>にしてください。
            </div>
            <div class="modal-footer">
                <a href="<?= h($bentoChangeUrl) ?>" class="btn btn-primary">弁当の予約を変更する</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
            </div>
        </div>
    </div>
</div>

<?= $this->Html->script('jquery-3.5.1.slim.min.js') ?>
<?= $this->Html->script('index.global.min.js') ?>
<?= $this->Html->script('japanese-holidays.min.js') ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>
<!-- ❌ このページ内の Bootstrap 5 JS は削除（default.php で読み込むものを使用） -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const csrfToken = document
            .querySelector('meta[name="csrfToken"]')
            ?.getAttribute('content') ?? '';

        // 本日の昼食/弁当予約状態（PHP から受け取り）
        const LUNCH_RESERVED_TODAY = <?= $lunchReserved ? 'true' : 'false' ?>;
        const BENTO_RESERVED_TODAY = <?= $bentoReserved ? 'true' : 'false' ?>;

        const calendarEl    = document.getElementById('calendar');
        const fromDateInput = document.getElementById('fromDate');
        const toDateInput   = document.getElementById('toDate');

        const defaultDate = (() => {
            const d = new Date();
            d.setDate(d.getDate() + 14);
            return d;
        })();

        const reservedDates = [
            <?php foreach ($myReservationDates as $reservedDate): ?>
            '<?= h($reservedDate) ?>',
            <?php endforeach; ?>
        ];
        <?php
        $icon = static function ($v) {
            if ($v === null) {
                return '×';
            }
            return $v ? '⚪︎' : '×';
        };
        ?>

        const existingEvents = [
            <?php foreach ($myReservationDates as $reservedDate): ?>
            <?php
            $detail = $myReservationDetails[$reservedDate] ?? [];
            $title = sprintf(
                    '朝:%s 昼:%s 夜:%s 弁当:%s',
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
                extendedProps: { displayOrder: -2 }
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
            $selfKeys = ['1' => 'breakfast', '2' => 'lunch', '3' => 'dinner', '4' => 'bento'];

            foreach ($mealDataArray as $date => $meals):
            foreach ($mealTypes as $type => $name):
            if (isset($meals[$type]) && $meals[$type] > 0):
            if ($isChild) {
                $selfKey = $selfKeys[$type] ?? null;
                $selfMark = $selfKey ? $icon(($myReservationDetails[$date][$selfKey] ?? null)) : '×';
                $userName = $user ? $user->get('c_user_name') : '';
                $titleForType = "{$name}: {$selfMark} {$userName}";
                $bgColor = ($selfMark === '⚪︎') ? '#28a745' : '#fd7e14';
            } else {
                $titleForType = "{$name}: {$meals[$type]}人";
                $bgColor = null;
            }
            ?>
            {
                title: '<?= h($titleForType) ?>',
                start: '<?= $date ?>',
                allDay: true,
                extendedProps: { displayOrder: <?= $type ?> }<?php if ($isChild): ?>,
                backgroundColor: '<?= $bgColor ?>',
                borderColor: '<?= $bgColor ?>',
                textColor: 'white'<?php endif; ?>
            },
            <?php
            endif;
            endforeach;
            endforeach;
            endif;
            ?>
        ];

        function formatYmd(date) {
            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        }

        function updateInputsByCalendar(view) {
            if (!fromDateInput || !toDateInput) return;

            const start = view.currentStart;
            const end   = new Date(view.currentEnd);
            end.setDate(end.getDate() - 1);

            fromDateInput.value = formatYmd(start);
            toDateInput.value   = formatYmd(end);
        }

        function updateCalendarByInput() {
            if (!fromDateInput?.value) return;
            calendar.gotoDate(fromDateInput.value);
        }

        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialDate: defaultDate,
            initialView: 'dayGridMonth',
            locale: 'ja',
            firstDay: 1,
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
            datesSet: (arg) => updateInputsByCalendar(arg.view),

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
                            extendedProps: { displayOrder: -10 }
                        });
                    }
                    cur.setDate(cur.getDate() + 1);
                }

                successCallback([...existingEvents, ...holidayEvents, ...unreservedEvents]);
            },
            eventOrder: function (a, b) {
                const orderA = Number(a.extendedProps?.displayOrder ?? 0);
                const orderB = Number(b.extendedProps?.displayOrder ?? 0);

                const safeA = isNaN(orderA) ? 0 : orderA;
                const safeB = isNaN(orderB) ? 0 : orderB;

                return safeA - safeB;
            },
            dateClick: info => {
                const clickedDate = new Date(info.dateStr);
                const today       = new Date();
                today.setHours(0, 0, 0, 0);

                const MILLISECONDS_IN_DAY = 86_400_000;
                const diffDays = (clickedDate - today) / MILLISECONDS_IN_DAY;

                const isMonday      = clickedDate.getDay() === 1;
                const within14Days  = diffDays >= 0 && diffDays <= 14;

                if (isMonday && !within14Days) {
                    if (confirm('週の一括予約を行いますか？')) {
                        window.location.href =
                            '<?= $this->Url->build("/TReservationInfo/bulkAddForm") ?>?date=' + info.dateStr;
                    } else {
                        window.location.href =
                            '<?= $this->Url->build("/TReservationInfo/view") ?>?date=' + info.dateStr;
                    }
                    return;
                }

                window.location.href =
                    '<?= $this->Url->build("/TReservationInfo/view") ?>?date=' + info.dateStr;
            }
        });

        calendar.render();
        updateInputsByCalendar(calendar.view);
        fromDateInput?.addEventListener('change', updateCalendarByInput);

        async function downloadWorkbook(workbook, filename) {
            workbook.worksheets.forEach((worksheet) => {
                worksheet.columns.forEach((column, colIdx) => {
                    let maxLength = 10;
                    worksheet.eachRow({ includeEmpty: true }, (row) => {
                        const cellValue = row.getCell(colIdx + 1).value;
                        if (cellValue) {
                            let cellText = typeof cellValue === 'object'
                                ? String(cellValue.text || (cellValue.richText ? cellValue.richText.map(rt => rt.text).join('') : ''))
                                : String(cellValue);
                            const length = Array.from(cellText).reduce((sum, ch) => {
                                return sum + (ch.match(/[ -~]/) ? 1 : 2);
                            }, 0);
                            if (length > maxLength) maxLength = length;
                        }
                    });
                    column.width = maxLength + 2;
                });
            });

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

                    const workbook = new ExcelJS.Workbook();
                    workbook.creator  = '食数予約システム';
                    workbook.created  = new Date();
                    workbook.modified = new Date();

                    const addHeader = (sheet, includeRoomName = false) => {
                        const header = includeRoomName
                            ? ['日付', '部屋名', '朝食', '昼食', '夕食', '弁当', '合計']
                            : ['日付', '朝食', '昼食', '夕食', '弁当', '合計'];
                        const row = sheet.addRow(header);
                        row.font = { bold: true };
                        sheet.views = [{ state: 'frozen', ySplit: 1 }];
                    };

                    const addTotalRow = (sheet, includeRoomName = false) => {
                        const mealTotals = [0, 0, 0, 0];

                        sheet.eachRow((row, rowNumber) => {
                            if (rowNumber === 1) return;
                            const offset = includeRoomName ? 2 : 1;
                            for (let i = 0; i < mealTotals.length; i++) {
                                mealTotals[i] += Number(row.getCell(offset + i + 1).value ?? 0);
                            }
                        });

                        const grandTotal = mealTotals.reduce((a, b) => a + b, 0);

                        const rowValues = includeRoomName
                            ? ['合計', '', ...mealTotals, grandTotal]
                            : ['合計', ...mealTotals, grandTotal];

                        const totalRow = sheet.addRow(rowValues);
                        totalRow.font = { bold: true };
                        totalRow.eachCell((cell) => {
                            cell.border = {
                                top:    { style: 'thin' },
                                bottom: { style: 'double' },
                            };
                        });
                    };

                    const overallSheet = workbook.addWorksheet('全体');
                    addHeader(overallSheet, true);

                    if (hasRooms) {
                        const allDates  = new Set();
                        const roomNames = Object.keys(data.rooms).sort();

                        roomNames.forEach((room) => {
                            Object.keys(data.rooms[room] ?? {}).forEach((d) => allDates.add(d));
                        });
                        const sortedDates = [...allDates].sort();

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

                    addTotalRow(overallSheet, true);

                    if (hasRooms) {
                        Object.keys(data.rooms).forEach((roomNameRaw) => {
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

                            addTotalRow(sheet);
                        });
                    }

                    await downloadWorkbook(workbook, `食数予約_${fromDate}〜${toDate}.xlsx`);
                } catch (error) {
                    console.error('エクスポート中にエラー発生:', error);
                    alert('エクスポート中にエラーが発生しました。管理者に連絡してください。');
                }
            });
        }

        // ★修正点: 早期 return をやめ、存在する場合のみリスナー登録
        if (rankExportButton) {
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
                    const rows = Array.isArray(json)
                        ? json
                        : Object.values(json);

                    if (rows.length === 0) { alert('データがありません'); return; }

                    const wb = new ExcelJS.Workbook();
                    const ws = wb.addWorksheet('実施食数表');

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

                    const headerRow = ws.addRow(columns.map(c => c.header));
                    headerRow.font = { bold: true };

                    rows.forEach(r => ws.addRow(columns.map(c => r[c.key] ?? '')));

                    ws.columns.forEach((column, colIdx) => {
                        let maxLength = 10;
                        ws.eachRow({ includeEmpty: true }, (row) => {
                            const cellValue = row.getCell(colIdx + 1).value;
                            if (cellValue) {
                                let cellText = typeof cellValue === 'object'
                                    ? String(cellValue.text || (cellValue.richText ? cellValue.richText.map(rt => rt.text).join('') : ''))
                                    : String(cellValue);
                                const length = Array.from(cellText).reduce((sum, ch) => {
                                    return sum + (ch.match(/[ -~]/) ? 1 : 2);
                                }, 0);
                                if (length > maxLength) maxLength = length;
                            }
                        });
                        column.width = maxLength + 2;
                    });

                    await downloadWorkbook(wb, `実施食数表_${fromDate}〜${toDate}.xlsx`);
                } catch (e) {
                    console.error(e);
                    alert('エクスポートに失敗しました');
                }
            });
        }

        /* 直前編集ガード & 遷移前確認を統一（昼⇔弁当の相互ガード） */
        const MEAL_LABELS = {1: '朝食', 2: '昼食', 3: '夕食', 4: '弁当'};

        function reservationClickHandler(ev) {
            const a = ev.currentTarget;
            const meal = Number(a.dataset.meal || 0);
            const role = a.dataset.role || ''; // "change" or "add"
            const label = MEAL_LABELS[meal] || '';

            // 1) 昼⇔弁当の衝突チェック（先にモーダルを出す）
            if (meal === 4 && LUNCH_RESERVED_TODAY) {
                ev.preventDefault();
                ev.stopPropagation();
                if (typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation();
                const modalEl = document.getElementById('bentoLunchWarnModal');
                if (modalEl && window.bootstrap?.Modal) {
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.show();
                } else {
                    alert('先に昼食の予約を無効にしてください。');
                }
                return;
            }
            if (meal === 2 && BENTO_RESERVED_TODAY) {
                ev.preventDefault();
                ev.stopPropagation();
                if (typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation();
                const modalEl = document.getElementById('lunchBentoWarnModal');
                if (modalEl && window.bootstrap?.Modal) {
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.show();
                } else {
                    alert('先に弁当の予約を無効にしてください。');
                }
                return;
            }

            // 2) 衝突がなければ confirm を表示 → OK なら遷移
            ev.preventDefault(); // いったん止める（OK 時に明示遷移）
            const msg = role === 'change'
                ? `${label}の予約を変更しますか？`
                : `${label}の予約を追加しますか？`;
            if (confirm(msg)) {
                window.location.href = a.getAttribute('href');
            } else {
                return;
            }
        }

        // 対象アンカーに直接リスナーを付与（inline onclick は撤去済み）
        document.querySelectorAll('a[data-meal][data-role]').forEach(a => {
            a.addEventListener('click', reservationClickHandler, false);
        });

    });

</script>
</body>
</html>
