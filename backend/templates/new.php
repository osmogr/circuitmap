<?php
/** @var string $csrfToken */
/** @var string|null $error */
/** @var array<int, array<string, mixed>> $providers */
/** @var array<int, array<string, mixed>> $locations */
/** @var array<string, mixed>|null $currentUser */

use CircuitMap\Support\BasePath;
use CircuitMap\Support\View;
?>
<div class="upload-page">
    <h1>New circuit</h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= View::escape($error) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= BasePath::url('/circuits/new') ?>">
        <input type="hidden" name="csrf_token" value="<?= View::escape($csrfToken) ?>">
        <label>
            Name
            <input type="text" name="name" required maxlength="200">
        </label>
        <label>
            Description
            <textarea name="description" maxlength="2000"></textarea>
        </label>
        <label>
            Tags
            <input type="text" name="tags" placeholder="comma, separated, tags">
        </label>
        <label>
            Circuit Provider
            <select name="provider_id">
                <option value="">— none —</option>
                <?php foreach ($providers as $provider): ?>
                    <option value="<?= (int) $provider['id'] ?>"><?= View::escape($provider['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if (($currentUser['role'] ?? null) === 'admin'): ?>
            <p class="hint"><a href="<?= BasePath::url('/admin/providers') ?>">Manage circuit providers</a></p>
        <?php endif; ?>
        <label>
            A-Location
            <select name="a_location_id">
                <option value="">— none —</option>
                <?php foreach ($locations as $location): ?>
                    <option value="<?= (int) $location['id'] ?>"><?= View::escape($location['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Z-Location
            <select name="z_location_id">
                <option value="">— none —</option>
                <?php foreach ($locations as $location): ?>
                    <option value="<?= (int) $location['id'] ?>"><?= View::escape($location['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if (($currentUser['role'] ?? null) === 'admin'): ?>
            <p class="hint"><a href="<?= BasePath::url('/admin/locations') ?>">Manage locations</a></p>
        <?php endif; ?>
        <label>
            Circuit ID
            <input type="text" name="provider_circuit_id" maxlength="200">
        </label>
        <label>
            Order Number
            <input type="text" name="order_number" maxlength="200">
        </label>
        <label>
            Redundant
            <select name="redundant">
                <option value="0" selected>No</option>
                <option value="1">Yes</option>
            </select>
        </label>
        <p class="hint">
            This creates an empty circuit. You'll draw its geometry in the editor next.
        </p>
        <button type="submit">Create and edit</button>
    </form>
</div>
