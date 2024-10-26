document.addEventListener('DOMContentLoaded', function() {
    var reservationTypeSelect = document.getElementById('c_reservation_type');
    var roomSelectionTable = document.getElementById('room-selection-table');
    var roomSelectGroup = document.getElementById('room-select-group');
    var userSelectionTable = document.getElementById('user-selection-table');
    var roomCheckboxes = document.getElementById('room-checkboxes');
    var userCheckboxes = document.getElementById('user-checkboxes');
    var roomSelect = document.getElementById('room-select'); // 集団予約用の部屋選択セレクトボックス

    // ベースURLを設定（プロジェクト名を含む）
    var baseUrl = window.location.origin + '/kamaho-shokusu/TReservationInfo';

    reservationTypeSelect.addEventListener('change', function() {
        var reservationType = parseInt(this.value, 10);
        if (reservationType === 1) {
            roomSelectionTable.style.display = 'block';
            roomSelectGroup.style.display = 'none';
            userSelectionTable.style.display = 'none';
            roomCheckboxes.innerHTML = '';

            for (var roomId in roomsData) {
                if (roomsData.hasOwnProperty(roomId)) {
                    var roomName = roomsData[roomId];
                    var row = document.createElement('tr');
                    var roomNameCell = document.createElement('td');
                    roomNameCell.textContent = roomName;
                    var morningCell = createCheckboxCell(`users[${roomId}][1]`, 1);
                    var afternoonCell = createCheckboxCell(`users[${roomId}][2]`, 2);
                    var eveningCell = createCheckboxCell(`users[${roomId}][3]`, 3);
                    row.appendChild(roomNameCell);
                    row.appendChild(morningCell);
                    row.appendChild(afternoonCell);
                    row.appendChild(eveningCell);
                    roomCheckboxes.appendChild(row);
                }
            }
        } else if (reservationType === 2) {
            roomSelectionTable.style.display = 'none';
            roomSelectGroup.style.display = 'block';
            userSelectionTable.style.display = 'block';
            userCheckboxes.innerHTML = '';

            roomSelect.addEventListener('change', function() {
                var roomId = this.value;
                userCheckboxes.innerHTML = '';

                if (roomId) {
                    var url = `${baseUrl}/getUsersByRoom/${roomId}`;

                    fetch(url)
                        .then(response => {
                            if (response.headers.get('content-type')?.includes('application/json')) {
                                return response.json();
                            } else {
                                return response.text().then(text => { throw new Error(text); });
                            }
                        })
                        .then(data => {
                            if (!data.usersByRoom) {
                                console.error('Invalid JSON response: usersByRoom property is missing');
                                alert('Invalid JSON response: usersByRoom property is missing');
                                return;
                            }

                            data.usersByRoom.forEach(user => {
                                var row = document.createElement('tr');
                                var nameCell = document.createElement('td');
                                nameCell.appendChild(document.createTextNode(user.name));
                                var morningCell = createCheckboxCell(`users[${user.id}][1]`, 1);
                                var afternoonCell = createCheckboxCell(`users[${user.id}][2]`, 2);
                                var eveningCell = createCheckboxCell(`users[${user.id}][3]`, 3);
                                row.appendChild(nameCell);
                                row.appendChild(morningCell);
                                row.appendChild(afternoonCell);
                                row.appendChild(eveningCell);
                                userCheckboxes.appendChild(row);
                            });
                        })
                        .catch(error => {
                            console.error('Fetch error:', error);
                            alert('Fetch error: ' + error.message);
                        });
                }
            });
        } else {
            roomSelectionTable.style.display = 'none';
            roomSelectGroup.style.display = 'none';
            userSelectionTable.style.display = 'none';
            roomCheckboxes.innerHTML = '';
            userCheckboxes.innerHTML = '';
        }
    });

    function createCheckboxCell(name, value) {
        var cell = document.createElement('td');
        var checkbox = document.createElement('input');
        checkbox.className = 'form-check-input';
        checkbox.type = 'checkbox';
        checkbox.name = name;
        checkbox.value = value;
        cell.appendChild(checkbox);
        return cell;
    }
});
