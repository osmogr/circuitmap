<?php
/** @var string $csrfToken */
/** @var string|null $error */
/** @var string|null $success */
/** @var string $currentUsername */

use CircuitMap\Support\BasePath;
use CircuitMap\Support\View;
?>
<div class="admin-page">
    <h1>Instance transfer</h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= View::escape($error) ?></p>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <p class="success"><?= View::escape($success) ?></p>
    <?php endif; ?>

    <h2>Export this instance</h2>
    <p>
        Downloads a single ZIP archive containing everything in this
        CircuitMap: all circuits with their full version history and KML
        geometry, providers, locations, user accounts (including password
        hashes) and the audit log. Keep the file safe — it contains
        credentials.
    </p>
    <p><a href="<?= BasePath::url('/admin/instance/export.zip') ?>" download>Download instance export (.zip)</a></p>

    <h2>Import into this instance</h2>
    <p>
        Restores an instance export archive here, duplicating the original
        CircuitMap exactly. Import only works on a fresh, empty instance
        (no circuits, providers or locations, and no user accounts beyond
        the bootstrap admin).
    </p>
    <p class="error">
        All user accounts on this instance — including the one you are
        logged in with — are replaced by the accounts from the archive. If
        the archive has no active admin named
        &ldquo;<?= View::escape($currentUsername) ?>&rdquo;, you will be
        logged out and must sign in with an admin account from the imported
        data.
    </p>
    <form method="post" action="<?= BasePath::url('/admin/instance/import') ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= View::escape($csrfToken) ?>">
        <label>
            Export archive
            <input type="file" name="archive" accept=".zip" required>
        </label>
        <label>
            Type <strong>REPLACE</strong> to confirm
            <input type="text" name="confirm" autocomplete="off" required>
        </label>
        <button type="submit">Import instance data</button>
    </form>
</div>
