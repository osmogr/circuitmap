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

    var map = L.map('map').setView([20, 0], 2);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    var layersByUuid = {};
    var circuitsByUuid = {};
    var statusDotsByUuid = {};
    var checkboxesByUuid = {};

    function formatBps(bps) {
        if (bps === null || bps === undefined || isNaN(bps)) {
            return null;
        }
        var units = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
        var value = Number(bps);
        var unit = 0;
        while (value >= 1000 && unit < units.length - 1) {
            value = value / 1000;
            unit++;
        }
        return (value >= 100 || value === Math.round(value)
            ? Math.round(value)
            : value.toFixed(value >= 10 ? 1 : 2)) + ' ' + units[unit];
    }

    function metaRow(label, value) {
        if (!value) {
            return null;
        }
        var row = document.createElement('div');
        row.textContent = label + ': ' + value;
        return row;
    }

    function popupHtml(feature, circuit) {
        var props = feature.properties || {};
        var name = circuit && circuit.name
            ? String(circuit.name)
            : (props.name ? String(props.name) : '(unnamed)');
        var featureName = props.name && String(props.name) !== name ? String(props.name) : null;
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

        if (circuit) {
            var meta = document.createElement('div');
            meta.className = 'circuit-popup-meta';
            var utilization = circuit.utilizationPct !== null && circuit.utilizationPct !== undefined
                ? circuit.utilizationPct + '% of ' + formatBps(circuit.capacity_bps)
                : null;
            [
                metaRow('Feature', featureName),
                metaRow('ID', circuit.uuid),
                metaRow('Status', circuit.status),
                metaRow('Traffic in', formatBps(circuit.usage_in_bps)),
                metaRow('Traffic out', formatBps(circuit.usage_out_bps)),
                metaRow('Utilization', utilization),
                metaRow('Traffic as of', circuit.usage_updated_at),
                metaRow('A-Location', circuit.a_location_name),
                metaRow('Z-Location', circuit.z_location_name),
                metaRow('Provider', circuit.provider_name),
                metaRow('Provider circuit ID', circuit.provider_circuit_id),
                metaRow('Order number', circuit.order_number),
                metaRow('Account ID', circuit.provider_account_id),
                metaRow('Tech support', circuit.provider_tech_support_number),
                metaRow('Local rep', circuit.provider_local_rep_contact)
            ].forEach(function (row) {
                if (row) {
                    meta.appendChild(row);
                }
            });
            box.appendChild(meta);
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
                            return popupHtml(feature, circuit);
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
            circuitsByUuid[circuit.uuid] = circuit;
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
            statusDotsByUuid[circuit.uuid] = statusDot;
            checkboxesByUuid[circuit.uuid] = checkbox;

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

    function circuitsAtLocation(locationId) {
        // SQLite/PDO may serialize ids as strings, so compare numerically.
        var id = Number(locationId);
        return Object.keys(circuitsByUuid)
            .map(function (uuid) { return circuitsByUuid[uuid]; })
            .filter(function (circuit) {
                return Number(circuit.a_location_id) === id
                    || Number(circuit.z_location_id) === id;
            });
    }

    function focusCircuit(circuit) {
        map.closePopup();
        var layer = layersByUuid[circuit.uuid];
        var checkbox = checkboxesByUuid[circuit.uuid];
        var ready;
        if (layer && map.hasLayer(layer)) {
            ready = Promise.resolve();
        } else if (checkbox) {
            checkbox.checked = true;
            ready = toggleCircuit(circuit, checkbox);
        } else {
            return;
        }
        ready.then(function () {
            var loaded = layersByUuid[circuit.uuid];
            if (!loaded) {
                return;
            }
            var bounds = loaded.getBounds();
            if (bounds.isValid()) {
                map.fitBounds(bounds, { padding: [20, 20], maxZoom: 16 });
            }
            // Popups live on the sub-layers (bound in onEachFeature), so
            // openPopup() on the geoJSON group itself is a no-op.
            var popupLayer = null;
            loaded.eachLayer(function (l) {
                if (!popupLayer && l.getPopup && l.getPopup()) {
                    popupLayer = l;
                }
            });
            if (popupLayer) {
                popupLayer.openPopup();
            }
        });
    }

    function locationPopupHtml(location) {
        var box = document.createElement('div');
        var title = document.createElement('strong');
        title.textContent = location.name;
        box.appendChild(title);

        var meta = document.createElement('div');
        meta.className = 'circuit-popup-meta';
        [
            metaRow('Address', location.address),
            metaRow('Notes', location.notes)
        ].forEach(function (row) {
            if (row) {
                meta.appendChild(row);
            }
        });
        box.appendChild(meta);

        var circuits = circuitsAtLocation(location.id);
        var section = document.createElement('div');
        section.className = 'circuit-popup-meta location-popup-circuits';
        var heading = document.createElement('div');
        heading.textContent = 'Circuits at this site (' + circuits.length + ')';
        section.appendChild(heading);
        if (circuits.length === 0) {
            var none = document.createElement('div');
            none.className = 'location-popup-no-circuits';
            none.textContent = 'No circuits at this site';
            section.appendChild(none);
        } else {
            var list = document.createElement('ul');
            circuits.forEach(function (circuit) {
                var item = document.createElement('li');
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'location-popup-circuit';
                var statusDot = document.createElement('span');
                statusDot.className = 'status-dot';
                statusDot.style.backgroundColor = circuit.statusColor || '#6b7280';
                statusDot.title = 'status: ' + (circuit.status || 'unknown');
                button.appendChild(statusDot);
                button.appendChild(document.createTextNode(' ' + circuit.name + ' '));
                var statusText = document.createElement('span');
                statusText.className = 'location-popup-circuit-status';
                statusText.textContent = circuit.status || 'unknown';
                button.appendChild(statusText);
                button.addEventListener('click', function () {
                    focusCircuit(circuit);
                });
                item.appendChild(button);
                list.appendChild(item);
            });
            section.appendChild(list);
        }
        box.appendChild(section);

        return box;
    }

    function renderLocationMarkers(locations) {
        locations.forEach(function (location) {
            if (typeof location.latitude !== 'number' || typeof location.longitude !== 'number') {
                return;
            }
            var icon = L.divIcon({
                className: 'location-marker',
                html: '<span class="location-marker-symbol">' + (location.iconSymbol || '📍') + '</span>',
                iconSize: [28, 28],
                iconAnchor: [14, 14]
            });
            L.marker([location.latitude, location.longitude], { icon: icon })
                .bindPopup(function () {
                    return locationPopupHtml(location);
                })
                .addTo(map);
        });
    }

    // Periodically re-pulls circuit metadata so status/traffic written by
    // the Cacti poller shows up without a page reload. Existing circuit
    // objects are mutated in place: popup content is built lazily from
    // them, so only line/marker colors and sidebar dots need an explicit
    // repaint. Circuits added or removed since page load still need a
    // reload; this only refreshes ones already listed.
    function refreshCircuitStatuses() {
        fetch(window.CircuitMapBasePath + '/api/circuits')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                (data.circuits || []).forEach(function (fresh) {
                    var circuit = circuitsByUuid[fresh.uuid];
                    if (!circuit) {
                        return;
                    }
                    Object.keys(fresh).forEach(function (key) {
                        circuit[key] = fresh[key];
                    });

                    var color = circuit.statusColor || '#6b7280';
                    var layer = layersByUuid[circuit.uuid];
                    if (layer) {
                        layer.setStyle({ color: color, fillColor: color });
                    }
                    var statusDot = statusDotsByUuid[circuit.uuid];
                    if (statusDot) {
                        statusDot.style.backgroundColor = color;
                        statusDot.title = 'status: ' + (circuit.status || 'unknown');
                    }
                });
            })
            .catch(function () {
                // Transient fetch failure; the next tick retries.
            });
    }

    fetch(window.CircuitMapBasePath + '/api/circuits')
        .then(function (res) { return res.json(); })
        .then(function (data) { renderCircuitList(data.circuits || []); })
        .then(function () { setInterval(refreshCircuitStatuses, 60000); });

    fetch(window.CircuitMapBasePath + '/api/locations')
        .then(function (res) { return res.json(); })
        .then(function (data) { renderLocationMarkers(data.locations || []); });
})();
