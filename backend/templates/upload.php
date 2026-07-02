<?php
/** @var string $csrfToken */
/** @var string|null $error */

use CircuitMap\Support\View;
?>
<div class="upload-page">
    <h1>Upload circuit</h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= View::escape($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/upload" enctype="multipart/form-data">
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
            KML or KMZ file
            <input type="file" name="kml_file" accept=".kml,.kmz" required>
        </label>
        <button type="submit">Upload</button>
    </form>
</div>
