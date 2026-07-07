(function () {
    'use strict';

    var userDataEl = document.getElementById('circuitmap-user-data');
    var currentUser = null;
    if (userDataEl && userDataEl.textContent) {
        try {
            currentUser = JSON.parse(userDataEl.textContent);
        } catch (e) {
            currentUser = null;
        }
    }

    function canEdit(circuit) {
        if (!currentUser) {
            return false;
        }
        if (currentUser.role === 'admin') {
            return true;
        }
        if (currentUser.role === 'editor') {
            return currentUser.id === circuit.owner_id;
        }
        return false;
    }

    var allCircuits = [];
    var sortKey = 'name';
    var sortAsc = true;

    var providerSelect = document.getElementById('report-filter-provider');
    var locationSelect = document.getElementById('report-filter-location');
    var searchInput = document.getElementById('report-filter-search');
    var resetButton = document.getElementById('report-filter-reset');
    var tableBody = document.getElementById('report-table-body');
    var emptyNote = document.getElementById('report-empty');
    var countLabel = document.getElementById('report-count');
    var headers = document.querySelectorAll('#report-table th[data-sort]');

    function textOf(circuit, key) {
        var value = circuit[key];
        return value === null || value === undefined ? '' : String(value);
    }

    function fillSelect(select, values) {
        values.forEach(function (value) {
            var option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            select.appendChild(option);
        });
    }

    function populateFilters(circuits) {
        var providers = {};
        var locations = {};
        circuits.forEach(function (circuit) {
            if (circuit.provider_name) {
                providers[circuit.provider_name] = true;
            }
            if (circuit.a_location_name) {
                locations[circuit.a_location_name] = true;
            }
            if (circuit.z_location_name) {
                locations[circuit.z_location_name] = true;
            }
        });
        fillSelect(providerSelect, Object.keys(providers).sort());
        fillSelect(locationSelect, Object.keys(locations).sort());
    }

    function filteredCircuits() {
        var provider = providerSelect.value;
        var location = locationSelect.value;
        var search = searchInput.value.trim().toLowerCase();

        return allCircuits.filter(function (circuit) {
            if (provider && circuit.provider_name !== provider) {
                return false;
            }
            if (location && circuit.a_location_name !== location && circuit.z_location_name !== location) {
                return false;
            }
            if (search) {
                var haystack = [
                    circuit.name, circuit.description, circuit.tags,
                    circuit.provider_name, circuit.provider_circuit_id,
                    circuit.order_number, circuit.a_location_name,
                    circuit.z_location_name, circuit.status
                ].map(function (v) { return v ? String(v).toLowerCase() : ''; }).join(' ');
                if (haystack.indexOf(search) === -1) {
                    return false;
                }
            }
            return true;
        });
    }

    function sortCircuits(circuits) {
        var sorted = circuits.slice();
        sorted.sort(function (a, b) {
            var av = textOf(a, sortKey).toLowerCase();
            var bv = textOf(b, sortKey).toLowerCase();
            // Empty values sort last regardless of direction.
            if (av === '' && bv !== '') {
                return 1;
            }
            if (bv === '' && av !== '') {
                return -1;
            }
            var cmp = av.localeCompare(bv, undefined, { numeric: true });
            return sortAsc ? cmp : -cmp;
        });
        return sorted;
    }

    function updateHeaderIndicators() {
        headers.forEach(function (th) {
            th.classList.remove('sorted-asc', 'sorted-desc');
            if (th.getAttribute('data-sort') === sortKey) {
                th.classList.add(sortAsc ? 'sorted-asc' : 'sorted-desc');
            }
        });
    }

    function statusCell(circuit) {
        var cell = document.createElement('td');
        var dot = document.createElement('span');
        dot.className = 'status-dot';
        dot.style.backgroundColor = circuit.statusColor || '#6b7280';
        cell.appendChild(dot);
        cell.appendChild(document.createTextNode(' ' + (circuit.status || 'unknown')));
        return cell;
    }

    function textCell(value) {
        var cell = document.createElement('td');
        cell.textContent = value || '—';
        return cell;
    }

    function render() {
        var circuits = sortCircuits(filteredCircuits());
        tableBody.innerHTML = '';

        circuits.forEach(function (circuit) {
            var row = document.createElement('tr');

            var nameCell = document.createElement('td');
            var name = document.createElement('strong');
            name.textContent = circuit.name;
            nameCell.appendChild(name);
            if (canEdit(circuit)) {
                var editLink = document.createElement('a');
                editLink.href = window.CircuitMapBasePath + '/circuits/' + encodeURIComponent(circuit.uuid) + '/edit';
                editLink.textContent = 'edit';
                editLink.className = 'circuit-edit-link';
                nameCell.appendChild(document.createTextNode(' '));
                nameCell.appendChild(editLink);
            }
            row.appendChild(nameCell);

            row.appendChild(statusCell(circuit));
            row.appendChild(textCell(circuit.provider_name));
            row.appendChild(textCell(circuit.provider_circuit_id));
            row.appendChild(textCell(circuit.a_location_name));
            row.appendChild(textCell(circuit.z_location_name));
            row.appendChild(textCell(circuit.order_number));
            row.appendChild(textCell(Number(circuit.redundant) === 1 ? 'Yes' : 'No'));

            tableBody.appendChild(row);
        });

        emptyNote.hidden = circuits.length !== 0;
        countLabel.textContent = circuits.length + ' of ' + allCircuits.length + ' circuits';
        updateHeaderIndicators();
    }

    headers.forEach(function (th) {
        th.addEventListener('click', function () {
            var key = th.getAttribute('data-sort');
            if (sortKey === key) {
                sortAsc = !sortAsc;
            } else {
                sortKey = key;
                sortAsc = true;
            }
            render();
        });
    });

    providerSelect.addEventListener('change', render);
    locationSelect.addEventListener('change', render);
    searchInput.addEventListener('input', render);
    resetButton.addEventListener('click', function () {
        providerSelect.value = '';
        locationSelect.value = '';
        searchInput.value = '';
        render();
    });

    fetch(window.CircuitMapBasePath + '/api/circuits')
        .then(function (res) { return res.json(); })
        .then(function (data) {
            allCircuits = data.circuits || [];
            populateFilters(allCircuits);
            render();
        });
})();
