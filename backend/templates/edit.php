<?php
/** @var string $csrfToken */
/** @var array<string, mixed> $circuit */

use CircuitMap\Support\View;
?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<link rel="stylesheet" href="/assets/vendor/geoman/leaflet-geoman.css">
<div class="edit-page" data-circuit-uuid="<?= View::escape($circuit['uuid']) ?>">
    <aside class="edit-sidebar">
        <h1>Edit circuit</h1>
        <p><a href="/">Back to map</a></p>
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
            Status
            <select id="edit-status-select">
                <?php foreach (['unknown', 'up', 'degraded', 'down'] as $option): ?>
                    <option value="<?= $option ?>" <?= ($circuit['status'] ?? 'unknown') === $option ? 'selected' : '' ?>>
                        <?= ucfirst($option) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="hint">
            This is set manually for now (no live status integration yet).
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
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/vendor/geoman/leaflet-geoman.js"></script>
<script src="/assets/js/csrf.js"></script>
<script src="/assets/js/editor.js"></script>
