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
        fetch(window.CircuitMapBasePath + '/api/circuits/' + encodeURIComponent(uuid) + '/geojson')
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
                }
            });
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

        var payload = {
            name: document.getElementById('edit-name').value,
            description: document.getElementById('edit-description').value,
            tags: document.getElementById('edit-tags').value,
            provider_id: providerValue === '' ? null : providerValue,
            provider_circuit_id: document.getElementById('edit-circuit-id').value,
            order_number: document.getElementById('edit-order-number').value,
            redundant: document.getElementById('edit-redundant').value,
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

    loadExisting();
    loadVersions();
})();
