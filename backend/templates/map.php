<?php
/** @var array<string, mixed>|null $currentUser */

use CircuitMap\Support\View;
?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<div class="map-page">
    <aside class="circuit-list">
        <h2>Circuits</h2>
        <?php if ($currentUser !== null): ?>
            <p><a href="/upload">Upload a circuit</a></p>
            <p><a href="/circuits/new">Create new circuit</a></p>
        <?php endif; ?>
        <ul id="circuit-toggle-list"></ul>
    </aside>
    <div id="map"></div>
</div>
<script type="application/json" id="circuitmap-user-data"><?= json_encode(
    // Only id/role are sent to the client (used by map.js's canEdit check);
    // the full user row includes password_hash and must never reach a
    // <script> tag readable from page source.
    $currentUser === null ? null : ['id' => $currentUser['id'], 'role' => $currentUser['role']],
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?></script>
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/js/map.js"></script>
