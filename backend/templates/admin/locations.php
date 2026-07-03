<?php
/** @var string $csrfToken */
/** @var array<int, array<string, mixed>> $locations */
/** @var string|null $error */

use CircuitMap\Support\BasePath;
use CircuitMap\Support\View;
?>
<div class="admin-page">
    <h1>Manage locations</h1>
    <p><a href="<?= BasePath::url('/admin/providers') ?>">Manage circuit providers</a></p>
    <?php if (!empty($error)): ?>
        <p class="error"><?= View::escape($error) ?></p>
    <?php endif; ?>

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
                               value="<?= View::escape($location['address'] ?? '') ?>" maxlength="500">
                    </td>
                    <td>
                        <input type="text" form="<?= $formId ?>" name="notes"
                               value="<?= View::escape($location['notes'] ?? '') ?>" maxlength="500">
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

    <h2>Add location</h2>
    <form method="post" action="<?= BasePath::url('/admin/locations') ?>" class="new-location-form">
        <input type="hidden" name="csrf_token" value="<?= View::escape($csrfToken) ?>">
        <label>
            Location Name
            <input type="text" name="name" required maxlength="200">
        </label>
        <label>
            Address
            <input type="text" name="address" maxlength="500">
        </label>
        <label>
            Notes
            <input type="text" name="notes" maxlength="500">
        </label>
        <button type="submit">Create location</button>
    </form>
</div>
