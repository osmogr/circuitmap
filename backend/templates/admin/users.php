<?php
/** @var string $csrfToken */
/** @var array<int, array<string, mixed>> $users */
/** @var array<string, mixed> $currentUser */
/** @var string|null $error */

use CircuitMap\Support\View;
?>
<div class="admin-page">
    <h1>Manage users</h1>
    <p><a href="/admin/audit-log">View audit log</a></p>
    <?php if (!empty($error)): ?>
        <p class="error"><?= View::escape($error) ?></p>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Username</th>
                <th>Role</th>
                <th>Active</th>
                <th>Last login</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= View::escape($user['username']) ?></td>
                    <td><?= View::escape($user['role']) ?></td>
                    <td><?= $user['is_active'] ? 'Yes' : 'No' ?></td>
                    <td><?= View::escape($user['last_login_at'] ?? 'never') ?></td>
                    <td>
                        <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/role" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= View::escape($csrfToken) ?>">
                            <select name="role" class="role-select">
                                <option value="editor" <?= $user['role'] === 'editor' ? 'selected' : '' ?>>editor</option>
                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                            </select>
                        </form>
                        <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/active" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= View::escape($csrfToken) ?>">
                            <input type="hidden" name="active" value="<?= $user['is_active'] ? '0' : '1' ?>">
                            <button type="submit"><?= $user['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Add user</h2>
    <form method="post" action="/admin/users" class="new-user-form">
        <input type="hidden" name="csrf_token" value="<?= View::escape($csrfToken) ?>">
        <label>
            Username
            <input type="text" name="username" required>
        </label>
        <label>
            Password
            <input type="password" name="password" required minlength="12">
        </label>
        <label>
            Role
            <select name="role">
                <option value="editor">editor</option>
                <option value="admin">admin</option>
            </select>
        </label>
        <button type="submit">Create user</button>
    </form>
</div>
<script src="/assets/js/admin.js"></script>
