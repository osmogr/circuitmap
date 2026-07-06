<?php
/** @var array<string, mixed>|null $currentUser */

use CircuitMap\Support\Asset;
use CircuitMap\Support\BasePath;
?>
<div class="report-page">
    <h1>All Circuits</h1>
    <div class="report-filters">
        <label>
            Circuit Provider
            <select id="report-filter-provider">
                <option value="">All providers</option>
            </select>
        </label>
        <label>
            Site / Location
            <select id="report-filter-location">
                <option value="">All locations</option>
            </select>
        </label>
        <label>
            Search
            <input type="search" id="report-filter-search" placeholder="Name, circuit ID, order #…">
        </label>
        <button type="button" id="report-filter-reset">Reset</button>
        <span id="report-count" class="report-count"></span>
    </div>
    <div class="report-table-wrap">
        <table id="report-table">
            <thead>
                <tr>
                    <th data-sort="name">Circuit Name</th>
                    <th data-sort="status">Status</th>
                    <th data-sort="provider_name">Provider</th>
                    <th data-sort="provider_circuit_id">Provider Circuit ID</th>
                    <th data-sort="a_location_name">A-Location</th>
                    <th data-sort="z_location_name">Z-Location</th>
                    <th data-sort="order_number">Order Number</th>
                </tr>
            </thead>
            <tbody id="report-table-body"></tbody>
        </table>
    </div>
    <p id="report-empty" class="hint" hidden>No circuits match the current filters.</p>
</div>
<script type="application/json" id="circuitmap-user-data"><?= json_encode(
    // Only id/role are sent to the client (used by report.js's canEdit check);
    // the full user row includes password_hash and must never reach a
    // <script> tag readable from page source.
    $currentUser === null ? null : ['id' => $currentUser['id'], 'role' => $currentUser['role']],
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?></script>
<script src="<?= Asset::url('/assets/js/base-path.js') ?>"></script>
<script src="<?= Asset::url('/assets/js/report.js') ?>"></script>
