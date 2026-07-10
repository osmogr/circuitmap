<?php
/** @var string $csrfToken */
/** @var array<int, array<string, mixed>> $locations */
/** @var array<string, array{label: string, symbol: string}> $iconOptions */
/** @var string|null $error */

use CircuitMap\Support\Asset;
use CircuitMap\Support\BasePath;
use CircuitMap\Support\StatusColor;
use CircuitMap\Support\View;
?>
<link rel="stylesheet" href="<?= Asset::url('/assets/vendor/leaflet/leaflet.css') ?>">
<div class="admin-page">
    <h1>Manage locations</h1>
    <p><a href="<?= BasePath::url('/admin/providers') ?>">Manage circuit providers</a></p>
    <?php if (!empty($error)): ?>
        <p class="error"><?= View::escape($error) ?></p>
    <?php endif; ?>
    <p class="hint">Set latitude and longitude (together) to show a location on the map, with the icon below.</p>
    <p class="hint">
        Cacti Device ID links this site to a Cacti device for live up/down
        status (it's the "id" in the device's edit-page URL in Cacti).
    </p>

    <?php foreach ($locations as $location): ?>
        <form id="location-form-<?= (int) $location['id'] ?>" method="post"
              action="<?= BasePath::url('/admin/locations/' . (int) $location['id']) ?>">
            <input type="hidden" name="csrf_token" value="<?= View::escape($csrfToken) ?>">
        </form>
    <?php endforeach; ?>

    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Address</th>
                <th>Notes</th>
                <th>Latitude</th>
                <th>Longitude</th>
                <th>Icon</th>
                <th>Cacti Device ID</th>
                <th>Status</th>
                <th>Active</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($locations as $location): ?>
                <?php $formId = 'location-form-' . (int) $location['id']; ?>
                <tr>
                    <td>
                        <input type="text" form="<?= $formId ?>" name="name"
                               value="<?= View::escape($location['name']) ?>" required maxlength="200">
                    </td>
                    <td>
                        <input type="text" form="<?= $formId ?>" name="address"
                               id="location-<?= (int) $location['id'] ?>-address"
                               value="<?= View::escape($location['address'] ?? '') ?>" maxlength="500">
                        <button type="button" class="location-geocode-btn" data-target="<?= (int) $location['id'] ?>">Look up</button>
                        <p class="hint location-geocode-status" data-target="<?= (int) $location['id'] ?>" hidden></p>
                    </td>
                    <td>
                        <input type="text" form="<?= $formId ?>" name="notes"
                               value="<?= View::escape($location['notes'] ?? '') ?>" maxlength="500">
                    </td>
                    <td>
                        <input type="text" form="<?= $formId ?>" name="latitude" inputmode="decimal"
                               id="location-<?= (int) $location['id'] ?>-latitude"
                               value="<?= View::escape((string) ($location['latitude'] ?? '')) ?>" maxlength="20">
                    </td>
                    <td>
                        <input type="text" form="<?= $formId ?>" name="longitude" inputmode="decimal"
                               id="location-<?= (int) $location['id'] ?>-longitude"
                               value="<?= View::escape((string) ($location['longitude'] ?? '')) ?>" maxlength="20">
                    </td>
                    <td>
                        <select form="<?= $formId ?>" name="icon">
                            <?php foreach ($iconOptions as $key => $option): ?>
                                <option value="<?= View::escape($key) ?>"
                                    <?= ($location['icon'] ?? 'generic') === $key ? 'selected' : '' ?>>
                                    <?= View::escape($option['symbol'] . ' ' . $option['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="number" form="<?= $formId ?>" name="cacti_host_id" min="1" step="1"
                               value="<?= ($location['cacti_host_id'] ?? null) !== null ? (int) $location['cacti_host_id'] : '' ?>">
                    </td>
                    <td>
                        <span class="status-dot"
                              style="background-color: <?= View::escape(StatusColor::forStatus($location['status'] ?? null)) ?>"></span>
                        <?= View::escape($location['status'] ?? 'unknown') ?>
                    </td>
                    <td><?= $location['is_active'] ? 'Yes' : 'No' ?></td>
                    <td>
                        <button type="submit" form="<?= $formId ?>">Save</button>
                        <form method="post" action="<?= BasePath::url('/admin/locations/' . (int) $location['id'] . '/active') ?>" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= View::escape($csrfToken) ?>">
                            <input type="hidden" name="active" value="<?= $location['is_active'] ? '0' : '1' ?>">
                            <button type="submit"><?= $location['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div id="location-picker-map" class="location-picker-map" hidden></div>

    <h2>Add location</h2>
    <form method="post" action="<?= BasePath::url('/admin/locations') ?>" class="new-location-form">
        <input type="hidden" name="csrf_token" value="<?= View::escape($csrfToken) ?>">
        <label>
            Location Name
            <input type="text" name="name" required maxlength="200">
        </label>
        <label>
            Address
            <input type="text" name="address" id="new-location-address" maxlength="500">
        </label>
        <button type="button" class="location-geocode-btn" data-target="new">Look up</button>
        <p class="hint location-geocode-status" data-target="new" hidden></p>
        <label>
            Notes
            <input type="text" name="notes" maxlength="500">
        </label>
        <label>
            Latitude
            <input type="text" name="latitude" id="new-location-latitude" inputmode="decimal" maxlength="20">
        </label>
        <label>
            Longitude
            <input type="text" name="longitude" id="new-location-longitude" inputmode="decimal" maxlength="20">
        </label>
        <label>
            Icon
            <select name="icon">
                <?php foreach ($iconOptions as $key => $option): ?>
                    <option value="<?= View::escape($key) ?>">
                        <?= View::escape($option['symbol'] . ' ' . $option['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Cacti Device ID
            <input type="number" name="cacti_host_id" min="1" step="1">
        </label>
        <button type="submit">Create location</button>
    </form>
</div>
<script src="<?= Asset::url('/assets/js/base-path.js') ?>"></script>
<script src="<?= Asset::url('/assets/vendor/leaflet/leaflet.js') ?>"></script>
<script src="<?= Asset::url('/assets/js/csrf.js') ?>"></script>
<script src="<?= Asset::url('/assets/js/locations.js') ?>"></script>
