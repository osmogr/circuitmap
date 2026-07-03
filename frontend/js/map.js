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
        return currentUser.role === 'admin' || currentUser.id === circuit.owner_id;
    }

    var map = L.map('map').setView([20, 0], 2);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    var layersByUuid = {};

    function popupHtml(feature) {
        var props = feature.properties || {};
        var name = props.name ? String(props.name) : '(unnamed)';
        var description = props.description ? String(props.description) : '';
        var box = document.createElement('div');
        var title = document.createElement('strong');
        title.textContent = name;
        box.appendChild(title);
        if (description) {
            var desc = document.createElement('div');
            // Server-side KmlSanitizer (HTMLPurifier) has already cleaned this
            // HTML before it reached the API response.
            desc.innerHTML = description;
            box.appendChild(desc);
        }
        return box;
    }

    function loadCircuitGeometry(circuit, checkbox) {
        var color = circuit.statusColor || '#6b7280';

        return fetch(window.CircuitMapBasePath + '/api/circuits/' + encodeURIComponent(circuit.uuid) + '/geojson')
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('Failed to load geometry');
                }
                return res.json();
            })
            .then(function (geojson) {
                var layer = L.geoJSON(geojson, {
                    style: { color: color, weight: 4 },
                    pointToLayer: function (feature, latlng) {
                        return L.circleMarker(latlng, {
                            radius: 7,
                            color: color,
                            fillColor: color,
                            fillOpacity: 0.9
                        });
                    },
                    onEachFeature: function (feature, layer) {
                        layer.bindPopup(function () {
                            return popupHtml(feature);
                        });
                    }
                });
                layersByUuid[circuit.uuid] = layer;
                layer.addTo(map);
            })
            .catch(function () {
                checkbox.checked = false;
            });
    }

    function toggleCircuit(circuit, checkbox) {
        if (checkbox.checked) {
            if (layersByUuid[circuit.uuid]) {
                layersByUuid[circuit.uuid].addTo(map);
                return Promise.resolve();
            }
            return loadCircuitGeometry(circuit, checkbox);
        }
        if (layersByUuid[circuit.uuid]) {
            map.removeLayer(layersByUuid[circuit.uuid]);
        }
        return Promise.resolve();
    }

    function zoomToFitLoadedCircuits() {
        var bounds = L.latLngBounds([]);
        Object.keys(layersByUuid).forEach(function (uuid) {
            bounds.extend(layersByUuid[uuid].getBounds());
        });
        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [20, 20], maxZoom: 16 });
        }
    }

    function renderCircuitList(circuits) {
        var list = document.getElementById('circuit-toggle-list');
        list.innerHTML = '';

        var initialLoads = [];

        circuits.forEach(function (circuit) {
            var item = document.createElement('li');
            var label = document.createElement('label');
            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = true;
            checkbox.addEventListener('change', function () {
                toggleCircuit(circuit, checkbox);
            });

            var statusDot = document.createElement('span');
            statusDot.className = 'status-dot';
            statusDot.style.backgroundColor = circuit.statusColor || '#6b7280';
            statusDot.title = 'status: ' + (circuit.status || 'unknown');

            label.appendChild(checkbox);
            label.appendChild(statusDot);
            label.appendChild(document.createTextNode(' ' + circuit.name));
            item.appendChild(label);

            if (canEdit(circuit)) {
                var editLink = document.createElement('a');
                editLink.href = window.CircuitMapBasePath + '/circuits/' + encodeURIComponent(circuit.uuid) + '/edit';
                editLink.textContent = 'edit';
                editLink.className = 'circuit-edit-link';
                item.appendChild(document.createTextNode(' '));
                item.appendChild(editLink);
            }

            list.appendChild(item);

            initialLoads.push(toggleCircuit(circuit, checkbox));
        });

        Promise.allSettled(initialLoads).then(zoomToFitLoadedCircuits);
    }

    fetch(window.CircuitMapBasePath + '/api/circuits')
        .then(function (res) { return res.json(); })
        .then(function (data) { renderCircuitList(data.circuits || []); });
})();
