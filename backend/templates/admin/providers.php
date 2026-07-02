<?php
/** @var string $csrfToken */
/** @var array<int, array<string, mixed>> $providers */
/** @var string|null $error */

use CircuitMap\Support\View;
?>
<div class="admin-page">
    <h1>Manage circuit providers</h1>
    <p><a href="/admin/users">Manage users</a></p>
    <?php if (!empty($error)): ?>
        <p class="error"><?= View::escape($error) ?></p>
    <?php endif; ?>

    <?php foreach ($providers as $provider): ?>
        <form id="provider-form-<?= (int) $provider['id'] ?>" method="post"
              action="/admin/providers/<?= (int) $provider['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= View::escape($csrfToken) ?>">
        </form>
    <?php endforeach; ?>

    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Tech Support Number</th>
                <th>Account ID</th>
                <th>Local Rep Contact Info</th>
                <th>Active</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($providers as $provider): ?>
                <?php $formId = 'provider-form-' . (int) $provider['id']; ?>
                <tr>
                    <td>
                        <input type="text" form="<?= $formId ?>" name="name"
                               value="<?= View::escape($provider['name']) ?>" required maxlength="200">
                    </td>
                    <td>
                        <input type="text" form="<?= $formId ?>" name="tech_support_number"
                               value="<?= View::escape($provider['tech_support_number'] ?? '') ?>" maxlength="200">
                    </td>
                    <td>
                        <input type="text" form="<?= $formId ?>" name="account_id"
                               value="<?= View::escape($provider['account_id'] ?? '') ?>" maxlength="200">
                    </td>
                    <td>
                        <input type="text" form="<?= $formId ?>" name="local_rep_contact"
                               value="<?= View::escape($provider['local_rep_contact'] ?? '') ?>" maxlength="500">
                    </td>
                    <td><?= $provider['is_active'] ? 'Yes' : 'No' ?></td>
                    <td>
                        <button type="submit" form="<?= $formId ?>">Save</button>
                        <form method="post" action="/admin/providers/<?= (int) $provider['id'] ?>/active" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= View::escape($csrfToken) ?>">
                            <input type="hidden" name="active" value="<?= $provider['is_active'] ? '0' : '1' ?>">
                            <button type="submit"><?= $provider['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Add provider</h2>
    <form method="post" action="/admin/providers" class="new-provider-form">
        <input type="hidden" name="csrf_token" value="<?= View::escape($csrfToken) ?>">
        <label>
            Provider Name
            <input type="text" name="name" required maxlength="200">
        </label>
        <label>
            Tech Support Number
            <input type="text" name="tech_support_number" maxlength="200">
        </label>
        <label>
            Account ID
            <input type="text" name="account_id" maxlength="200">
        </label>
        <label>
            Local Rep Contact Info
            <input type="text" name="local_rep_contact" maxlength="500">
        </label>
        <button type="submit">Create provider</button>
    </form>
</div>
