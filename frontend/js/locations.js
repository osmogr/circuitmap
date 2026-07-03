(function () {
    'use strict';

    var map = null;
    var marker = null;
    var activeLatInput = null;
    var activeLngInput = null;

    function ensureMap() {
        if (map) {
            return;
        }
        map = L.map('location-picker-map').setView([20, 0], 2);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);
        marker = L.marker([20, 0], { draggable: true }).addTo(map);
        marker.on('dragend', function () {
            syncInputsFromMarker(marker.getLatLng());
        });
        map.on('click', function (e) {
            marker.setLatLng(e.latlng);
            syncInputsFromMarker(e.latlng);
        });
    }

    function syncInputsFromMarker(latlng) {
        if (activeLatInput && activeLngInput) {
            activeLatInput.value = latlng.lat.toFixed(6);
            activeLngInput.value = latlng.lng.toFixed(6);
        }
    }

    function activateMapFor(latInput, lngInput) {
        ensureMap();
        activeLatInput = latInput;
        activeLngInput = lngInput;
        document.getElementById('location-picker-map').hidden = false;

        var lat = parseFloat(latInput.value);
        var lng = parseFloat(lngInput.value);
        var hasCoords = !isNaN(lat) && !isNaN(lng);
        var center = hasCoords ? [lat, lng] : [20, 0];
        var zoom = hasCoords ? 15 : 2;

        map.setView(center, zoom);
        marker.setLatLng(center);
        // Leaflet needs an explicit size recalculation after un-hiding a
        // container that was display:none during initial layout.
        setTimeout(function () { map.invalidateSize(); }, 0);
    }

    function wireManualInputSync(latInput, lngInput) {
        function onChange() {
            if (activeLatInput !== latInput || !map) {
                return;
            }
            var lat = parseFloat(latInput.value);
            var lng = parseFloat(lngInput.value);
            if (!isNaN(lat) && !isNaN(lng)) {
                marker.setLatLng([lat, lng]);
            }
        }
        latInput.addEventListener('input', onChange);
        lngInput.addEventListener('input', onChange);
    }

    function geocode(address, statusEl, latInput, lngInput) {
        statusEl.hidden = true;

        fetch(window.CircuitMapBasePath + '/admin/locations/geocode', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.CircuitMapCsrf.token()
            },
            body: JSON.stringify({ address: address })
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            })
            .then(function (result) {
                activateMapFor(latInput, lngInput);
                if (result.ok) {
                    latInput.value = result.data.latitude.toFixed(6);
                    lngInput.value = result.data.longitude.toFixed(6);
                    marker.setLatLng([result.data.latitude, result.data.longitude]);
                    map.setView([result.data.latitude, result.data.longitude], 15);
                } else {
                    statusEl.textContent = (result.data.error || 'Address not found') + ' — pick the location on the map.';
                    statusEl.hidden = false;
                }
            })
            .catch(function () {
                activateMapFor(latInput, lngInput);
                statusEl.textContent = 'Lookup failed — pick the location on the map.';
                statusEl.hidden = false;
            });
    }

    document.querySelectorAll('.location-geocode-btn').forEach(function (btn) {
        var target = btn.getAttribute('data-target');
        var prefix = target === 'new' ? 'new-location' : 'location-' + target;
        var addressInput = document.getElementById(prefix + '-address');
        var latInput = document.getElementById(prefix + '-latitude');
        var lngInput = document.getElementById(prefix + '-longitude');
        var statusEl = document.querySelector('.location-geocode-status[data-target="' + target + '"]');

        if (!addressInput || !latInput || !lngInput || !statusEl) {
            return;
        }

        wireManualInputSync(latInput, lngInput);
        btn.addEventListener('click', function () {
            geocode(addressInput.value, statusEl, latInput, lngInput);
        });
    });
})();
