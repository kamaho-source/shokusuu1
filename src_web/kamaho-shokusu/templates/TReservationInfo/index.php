<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AdminPage</title>
    <!-- Include Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <!-- Include FullCalendar CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/3.10.2/fullcalendar.min.css">
</head>
<body>
<div class="container">
    <h1>食数予約</h1>
    <div id="calendar"></div>
</div>

<!-- Include jQuery and Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.min.js"></script>

<!-- Include FullCalendar and Moment JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.1/moment.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/3.10.2/fullcalendar.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/3.10.2/locale-all.js"></script>

<script>
    $(document).ready(function() {
        $('#calendar').fullCalendar({
            // 他のカレンダーオプション
            locale: 'ja',
            dayClick: function(date, jsEvent, view) {
                var clickedDate = date.format('YYYY-MM-DD');
                $.ajax({
                    url: "/t_reservation_info/view/" + clickedDate,
                    dataType: "json",
                    success: function(data) {
                        alert("On " + data.date + ", the total quantity of meals is: " + data.totalQuantity);
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        console.log(textStatus, errorThrown);
                    }
                });
            }
        });
    });
</script>
</body>
</html>
