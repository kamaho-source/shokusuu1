<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>食数予約</title>
    <!-- Include Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <!-- Include FullCalendar CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.15/main.min.css">
</head>
<body>
<div class="container">
    <h1>食数予約</h1>
    <div id="calendar"></div>
</div>

<!-- Include jQuery and Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.min.js"></script>

<!-- Include FullCalendar JS -->
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js'></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            businessHours: true,
            locale: 'ja',
            events: [
                <?php if (!empty($mealData)): ?>
                <?php foreach ($mealData as $data): ?>
                {
                    title: '食数: <?= h($data->i_taberu_ninzuu + $data->i_tabenai_ninzuu) ?>',
                    start: '<?= h($data->d_reservation_date->format('Y-m-d')) ?>',
                    allDay: true
                },
                <?php endforeach; ?>
                <?php endif; ?>
            ],
            dateClick: function(info) {
                window.location.href = '<?= $this->Url->build('/TReservationInfo/add') ?>?date=' + info.dateStr;
                // ここでクリックされた日付に対して何か処理を行うことができます
                // 例えば、モーダルを開いてその日の予約を追加する処理を行うなど
            }
        });
        calendar.render();
    });
</script>
</body>
</html>
