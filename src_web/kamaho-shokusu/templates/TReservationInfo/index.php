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
                <?php if (!empty($mealDataArray)) : ?>
                <?php foreach ($mealDataArray as $date => $meals): ?>
                <?php
                $ordername = ['A','1,朝', '2,昼', '3,夜'];
                $order = [1, 2, 3];
                foreach ($order as $mealType):
                ?>
                {
                    title: '<?= $ordername[$mealType] ?>: <?= isset($meals[$mealType])?$meals[$mealType]:'-' ?>人',
                    start: '<?= $date ?>',
                    allDay: true,
                },
                <?php endforeach; ?>
                <?php endforeach; ?>
                <?php endif; ?>
            ],
            dateClick: function(info) {
                window.location.href = '<?= $this->Url->build('/TReservationInfo/view') ?>?date=' + info.dateStr;
            }
        });
        calendar.render();
    });
</script>
</body>
</html>
