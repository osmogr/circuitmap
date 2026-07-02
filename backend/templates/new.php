<?php
/** @var string $csrfToken */
/** @var string|null $error */

use CircuitMap\Support\View;
?>
<div class="upload-page">
    <h1>New circuit</h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= View::escape($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/circuits/new">
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
        <p class="hint">
            This creates an empty circuit. You'll draw its geometry in the editor next.
        </p>
        <button type="submit">Create and edit</button>
    </form>
</div>
