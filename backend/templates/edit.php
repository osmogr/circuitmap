<?php
/** @var string $csrfToken */
/** @var array<string, mixed> $circuit */
/** @var array<int, array<string, mixed>> $providers */
/** @var array<int, array<string, mixed>> $locations */
/** @var array<string, mixed>|null $currentUser */

use CircuitMap\Support\Asset;
use CircuitMap\Support\BasePath;
use CircuitMap\Support\View;
?>
<link rel="stylesheet" href="<?= Asset::url('/assets/vendor/leaflet/leaflet.css') ?>">
<link rel="stylesheet" href="<?= Asset::url('/assets/vendor/geoman/leaflet-geoman.css') ?>">
<div class="edit-page" data-circuit-uuid="<?= View::escape($circuit['uuid']) ?>">
    <aside class="edit-sidebar">
        <h1>Edit circuit</h1>
        <p><a href="<?= BasePath::url('/') ?>">Back to map</a></p>
        <label>
            Name
            <input type="text" id="edit-name" value="<?= View::escape($circuit['name']) ?>" maxlength="200">
        </label>
        <label>
            Description
            <textarea id="edit-description" maxlength="2000"><?= View::escape($circuit['description'] ?? '') ?></textarea>
        </label>
        <label>
            Tags
            <input type="text" id="edit-tags" value="<?= View::escape($circuit['tags'] ?? '') ?>">
        </label>
        <label>
            Circuit Provider
            <select id="edit-provider">
                <option value="">— none —</option>
                <?php foreach ($providers as $provider): ?>
                    <option value="<?= (int) $provider['id'] ?>" <?= (int) ($circuit['provider_id'] ?? 0) === (int) $provider['id'] ? 'selected' : '' ?>>
                        <?= View::escape($provider['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            A-Location
            <select id="edit-a-location">
                <option value="">— none —</option>
                <?php foreach ($locations as $location): ?>
                    <option value="<?= (int) $location['id'] ?>" <?= (int) ($circuit['a_location_id'] ?? 0) === (int) $location['id'] ? 'selected' : '' ?>>
                        <?= View::escape($location['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Z-Location
            <select id="edit-z-location">
                <option value="">— none —</option>
                <?php foreach ($locations as $location): ?>
                    <option value="<?= (int) $location['id'] ?>" <?= (int) ($circuit['z_location_id'] ?? 0) === (int) $location['id'] ? 'selected' : '' ?>>
                        <?= View::escape($location['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Circuit ID
            <input type="text" id="edit-circuit-id" value="<?= View::escape($circuit['provider_circuit_id'] ?? '') ?>" maxlength="200">
        </label>
        <label>
            Order Number
            <input type="text" id="edit-order-number" value="<?= View::escape($circuit['order_number'] ?? '') ?>" maxlength="200">
        </label>
        <label>
            Redundant
            <select id="edit-redundant">
                <option value="0" <?= (int) ($circuit['redundant'] ?? 0) === 0 ? 'selected' : '' ?>>No</option>
                <option value="1" <?= (int) ($circuit['redundant'] ?? 0) === 1 ? 'selected' : '' ?>>Yes</option>
            </select>
        </label>
        <label>
            Status
            <select id="edit-status-select">
                <?php foreach (['unknown', 'up', 'degraded', 'down'] as $option): ?>
                    <option value="<?= $option ?>" <?= ($circuit['status'] ?? 'unknown') === $option ? 'selected' : '' ?>>
                        <?= ucfirst($option) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if (($circuit['cacti_host_id'] ?? null) !== null): ?>
            <p class="hint">
                Monitored by Cacti — manual status changes are overwritten on
                the next poll.
            </p>
        <?php endif; ?>
        <label>
            Cacti Device ID
            <input type="number" id="edit-cacti-host-id" min="1" step="1"
                   value="<?= ($circuit['cacti_host_id'] ?? null) !== null ? (int) $circuit['cacti_host_id'] : '' ?>">
        </label>
        <label>
            Cacti Data Source ID
            <input type="number" id="edit-cacti-data-id" min="1" step="1"
                   value="<?= ($circuit['cacti_local_data_id'] ?? null) !== null ? (int) $circuit['cacti_local_data_id'] : '' ?>">
        </label>
        <label>
            Capacity (Mbps)
            <input type="number" id="edit-capacity-mbps" min="0" step="any"
                   value="<?= ($circuit['capacity_bps'] ?? null) !== null ? rtrim(rtrim(number_format(((int) $circuit['capacity_bps']) / 1_000_000, 6, '.', ''), '0'), '.') : '' ?>">
        </label>
        <p class="hint">
            Device ID links this circuit to a Cacti device for live up/down
            status (it's the "id" in the device's edit-page URL in Cacti).
            Data Source ID adds live traffic; with a capacity set, the map
            also shows utilization. Leave blank to keep status manual.
        </p>
        <p class="hint">
            Drag markers/vertices on the map to reposition. Click a placemark's
            popup to edit its own name/description. Click Save when done.
        </p>
        <button type="button" id="edit-save">Save changes</button>
        <button type="button" id="edit-delete" class="danger">Delete circuit</button>
        <p id="edit-status" role="status"></p>
        <h2>Version history</h2>
        <ul id="version-list"></ul>
    </aside>
    <div id="map"></div>
</div>
<script src="<?= Asset::url('/assets/js/base-path.js') ?>"></script>
<script src="<?= Asset::url('/assets/vendor/leaflet/leaflet.js') ?>"></script>
<script src="<?= Asset::url('/assets/vendor/geoman/leaflet-geoman.js') ?>"></script>
<script src="<?= Asset::url('/assets/js/csrf.js') ?>"></script>
<script src="<?= Asset::url('/assets/js/editor.js') ?>"></script>
