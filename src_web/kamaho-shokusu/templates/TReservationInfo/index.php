<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>食数予約</title>
</head>
<body>
<div class="container">
    <h1>食数予約</h1>
    <div id="calendar"></div>
</div>

<!-- Include jQuery and Bootstrap JS -->
<?= $this->Html->script('jquery-3.5.1.slim.min.js') ?>

<!-- Include FullCalendar JS -->
<?= $this->Html->script('index.global.min.js') ?>

<!-- Include japanese-holidays.js -->
<?= $this->Html->script('japanese-holidays.min.js') ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');

        // PHPで生成された既存のイベント
        var existingEvents = [
            <?php if (!empty($mealDataArray)) : ?>
            <?php foreach ($mealDataArray as $date => $meals): ?>
            <?php
            // 明示的に順番を指定
            $mealTypes = [
                '1' => '朝',
                '2' => '昼',
                '3' => '夜'
            ];
            foreach ($mealTypes as $mealType => $mealName):
            if (isset($meals[$mealType]) && $meals[$mealType] > 0):
            ?>
            {
                title: '<?= $mealName ?>: <?= $meals[$mealType] ?>人',
                start: '<?= $date ?>',
                allDay: true,
                displayOrder: <?= $mealType + 1 ?> // 1: 朝, 2: 昼, 3: 夜に対応
            },
            <?php endif; ?>
            <?php endforeach; ?>
            <?php endforeach; ?>
            <?php endif; ?>
        ];

        // FullCalendarの設定
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth', // 月間ビュー
            businessHours: true, // ビジネス時間を表示
            locale: 'ja', // 日本語表示
            events: function(fetchInfo, successCallback, failureCallback) {
                // 表示範囲の年を取得
                var startYear = fetchInfo.start.getFullYear();
                var endYear = fetchInfo.end.getFullYear();

                var holidayEvents = [];
                // 表示範囲の各年について祝日を取得
                for (var year = startYear; year <= endYear; year++) {
                    var holidays = JapaneseHolidays.getHolidaysOf(year);

                    if (holidays && Array.isArray(holidays)) {
                        holidays.forEach(function(holiday) {
                            var month = String(holiday.month).padStart(2, '0'); // 月を2桁に
                            var date = String(holiday.date).padStart(2, '0'); // 日を2桁に
                            var formattedDate = year + '-' + month + '-' + date; // YYYY-MM-DD形式に変換

                            holidayEvents.push({
                                title: holiday.name, // 祝日の名前
                                start: formattedDate, // 正しいフォーマットの日付
                                allDay: true, // 終日イベント
                                backgroundColor: 'red', // 祝日を強調するための色設定
                                borderColor: 'red',
                                textColor: 'white', // 文字色を白に
                                displayOrder: 0 // 祝日は常に最初に表示されるように
                            });
                        });
                    }
                }

                // 既存のイベントと祝日イベントを結合してコールバック
                successCallback(existingEvents.concat(holidayEvents));
            },
            // イベントの順序をソートしないように設定
            eventOrder: 'displayOrder',
            dateClick: function(info) {
                window.location.href = '<?= $this->Url->build('/TReservationInfo/view') ?>?date=' + info.dateStr;
            }
        });

        calendar.render(); // カレンダーをレンダリング
    });
</script>
</body>
</html>
