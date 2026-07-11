(function () {
    'use strict';

    var pageEl = document.querySelector('.edit-page');
    var uuid = pageEl.getAttribute('data-circuit-uuid');

    var map = L.map('map').setView([20, 0], 2);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    map.pm.addControls({
        position: 'topleft',
        drawMarker: true,
        drawPolyline: true,
        drawPolygon: true,
        drawCircle: false,
        drawCircleMarker: false,
        drawRectangle: false,
        drawText: false,
        editMode: true,
        dragMode: true,
        removalMode: true
    });

    // Per-layer properties keyed by Leaflet's internal stamp id, since
    // Geoman edits geometry directly on the layer but does not track
    // arbitrary feature properties like name/description for us.
    var propsByLayerId = {};
    var editableLayers = L.layerGroup().addTo(map);

    function bindPopup(layer) {
        var id = L.stamp(layer);
        layer.on('click', function () {
            var props = propsByLayerId[id] || { name: '', description: '' };
            var container = document.createElement('div');

            var nameInput = document.createElement('input');
            nameInput.type = 'text';
            nameInput.placeholder = 'Name';
            nameInput.value = props.name || '';

            var descInput = document.createElement('textarea');
            descInput.placeholder = 'Description';
            descInput.value = props.description || '';

            var saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.textContent = 'Update';
            saveBtn.addEventListener('click', function () {
                propsByLayerId[id] = {
                    name: nameInput.value,
                    description: descInput.value
                };
                layer.closePopup();
            });

            container.appendChild(nameInput);
            container.appendChild(descInput);
            container.appendChild(saveBtn);

            layer.bindPopup(container).openPopup();
        });
    }

    function loadExisting() {
        return fetch(window.CircuitMapBasePath + '/api/circuits/' + encodeURIComponent(uuid) + '/geojson')
            .then(function (res) { return res.json(); })
            .then(function (geojson) {
                var layer = L.geoJSON(geojson, {
                    style: { color: '#2563eb', weight: 3 },
                    onEachFeature: function (feature, layer) {
                        var id = L.stamp(layer);
                        propsByLayerId[id] = {
                            name: (feature.properties && feature.properties.name) || '',
                            description: (feature.properties && feature.properties.description) || ''
                        };
                        bindPopup(layer);
                    }
                });
                layer.eachLayer(function (l) {
                    editableLayers.addLayer(l);
                    l.pm.enable({ allowSelfIntersection: false });
                });
                if (layer.getBounds().isValid()) {
                    map.fitBounds(layer.getBounds(), { maxZoom: 16 });
                    return true;
                }
                return false;
            })
            .catch(function () { return false; });
    }

    function sitePopupHtml(location) {
        var box = document.createElement('div');
        var title = document.createElement('strong');
        title.textContent = location.name;
        box.appendChild(title);
        [
            ['Status', location.status || 'unknown'],
            ['Address', location.address]
        ].forEach(function (pair) {
            if (!pair[1]) {
                return;
            }
            var row = document.createElement('div');
            row.textContent = pair[0] + ': ' + pair[1];
            box.appendChild(row);
        });
        return box;
    }

    function loadSites() {
        return fetch(window.CircuitMapBasePath + '/api/locations')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var bounds = L.latLngBounds([]);
                (data.locations || []).forEach(function (location) {
                    if (typeof location.latitude !== 'number' || typeof location.longitude !== 'number') {
                        return;
                    }
                    var icon = L.divIcon({
                        className: 'location-marker',
                        // statusColor is a server-generated hex value (StatusColor),
                        // same trust level as iconSymbol.
                        html: '<span class="location-marker-symbol">' + (location.iconSymbol || '📍') + '</span>'
                            + '<span class="location-status-dot" style="background-color: '
                            + (location.statusColor || '#6b7280') + '"></span>',
                        iconSize: [28, 28],
                        iconAnchor: [14, 14]
                    });
                    // pmIgnore keeps Geoman's edit/drag/removal modes off site
                    // markers (they are reference points, not circuit geometry);
                    // snapIgnore: false still lets drawn vertices snap to them.
                    L.marker([location.latitude, location.longitude], {
                        icon: icon,
                        pmIgnore: true,
                        snapIgnore: false
                    })
                        .bindTooltip(location.name)
                        .bindPopup(function () { return sitePopupHtml(location); })
                        .addTo(map);
                    bounds.extend([location.latitude, location.longitude]);
                });
                return bounds;
            })
            .catch(function () { return L.latLngBounds([]); });
    }

    map.on('pm:create', function (e) {
        var layer = e.layer;
        editableLayers.addLayer(layer);
        propsByLayerId[L.stamp(layer)] = { name: '', description: '' };
        bindPopup(layer);
        layer.pm.enable({ allowSelfIntersection: false });
    });

    map.on('pm:remove', function (e) {
        delete propsByLayerId[L.stamp(e.layer)];
    });

    function collectFeatureCollection() {
        var features = [];
        editableLayers.eachLayer(function (layer) {
            var geojson = layer.toGeoJSON();
            var props = propsByLayerId[L.stamp(layer)] || { name: '', description: '' };
            geojson.properties = props;
            features.push(geojson);
        });
        return { type: 'FeatureCollection', features: features };
    }

    function save() {
        var statusEl = document.getElementById('edit-status');
        statusEl.textContent = 'Saving...';

        var providerValue = document.getElementById('edit-provider').value;
        var aLocationValue = document.getElementById('edit-a-location').value;
        var zLocationValue = document.getElementById('edit-z-location').value;
        var cactiDataValue = document.getElementById('edit-cacti-data-id').value.trim();
        var capacityMbpsValue = document.getElementById('edit-capacity-mbps').value.trim();
        // The form takes Mbps for humans; the API stores bits/sec.
        var capacityBps = capacityMbpsValue === '' || isNaN(parseFloat(capacityMbpsValue))
            ? null
            : Math.round(parseFloat(capacityMbpsValue) * 1000000);

        var payload = {
            name: document.getElementById('edit-name').value,
            description: document.getElementById('edit-description').value,
            tags: document.getElementById('edit-tags').value,
            provider_id: providerValue === '' ? null : providerValue,
            provider_circuit_id: document.getElementById('edit-circuit-id').value,
            order_number: document.getElementById('edit-order-number').value,
            redundant: document.getElementById('edit-redundant').value,
            a_location_id: aLocationValue === '' ? null : aLocationValue,
            z_location_id: zLocationValue === '' ? null : zLocationValue,
            cacti_local_data_id: cactiDataValue === '' ? null : cactiDataValue,
            capacity_bps: capacityBps,
            geojson: collectFeatureCollection()
        };

        fetch(window.CircuitMapBasePath + '/circuits/' + encodeURIComponent(uuid), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.CircuitMapCsrf.token()
            },
            body: JSON.stringify(payload)
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok) {
                        throw new Error(data.error || 'Save failed');
                    }
                    return data;
                });
            })
            .then(function (data) {
                statusEl.textContent = 'Saved as version ' + data.version + '.';
                loadVersions();
            })
            .catch(function (err) {
                statusEl.textContent = 'Error: ' + err.message;
            });
    }

    function loadVersions() {
        fetch(window.CircuitMapBasePath + '/circuits/' + encodeURIComponent(uuid) + '/versions')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var list = document.getElementById('version-list');
                list.innerHTML = '';
                (data.versions || []).forEach(function (v) {
                    var item = document.createElement('li');
                    item.textContent = 'v' + v.version_number + ' (' + v.created_at + ') ';

                    var revertBtn = document.createElement('button');
                    revertBtn.type = 'button';
                    revertBtn.textContent = 'Revert';
                    revertBtn.addEventListener('click', function () {
                        if (!confirm('Revert to version ' + v.version_number + '?')) {
                            return;
                        }
                        fetch(window.CircuitMapBasePath + '/circuits/' + encodeURIComponent(uuid) + '/revert/' + v.version_number, {
                            method: 'POST',
                            headers: { 'X-CSRF-Token': window.CircuitMapCsrf.token() }
                        }).then(function () {
                            window.location.reload();
                        });
                    });

                    item.appendChild(revertBtn);
                    list.appendChild(item);
                });
            });
    }

    function saveStatus() {
        var statusEl = document.getElementById('edit-status');
        var select = document.getElementById('edit-status-select');

        fetch(window.CircuitMapBasePath + '/circuits/' + encodeURIComponent(uuid) + '/status', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': window.CircuitMapCsrf.token()
            },
            body: 'status=' + encodeURIComponent(select.value)
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok) {
                        throw new Error(data.error || 'Status update failed');
                    }
                    return data;
                });
            })
            .then(function () {
                statusEl.textContent = 'Status updated.';
            })
            .catch(function (err) {
                statusEl.textContent = 'Error: ' + err.message;
            });
    }

    function deleteCircuit() {
        if (!confirm('Delete this circuit? This cannot be undone from the UI.')) {
            return;
        }
        fetch(window.CircuitMapBasePath + '/circuits/' + encodeURIComponent(uuid), {
            method: 'DELETE',
            headers: { 'X-CSRF-Token': window.CircuitMapCsrf.token() }
        }).then(function (res) {
            if (res.ok) {
                window.location.href = window.CircuitMapBasePath + '/';
            } else {
                document.getElementById('edit-status').textContent = 'Delete failed.';
            }
        });
    }

    document.getElementById('edit-save').addEventListener('click', save);
    document.getElementById('edit-status-select').addEventListener('change', saveStatus);
    document.getElementById('edit-delete').addEventListener('click', deleteCircuit);

    // Frame the map on the circuit geometry when it exists; otherwise on
    // the site markers, so a new empty circuit doesn't open at world zoom.
    Promise.all([loadExisting(), loadSites()]).then(function (results) {
        if (!results[0] && results[1].isValid()) {
            map.fitBounds(results[1], { maxZoom: 12, padding: [30, 30] });
        }
    });
    loadVersions();
})();
