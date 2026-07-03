<?php
/** @var string $csrfToken */
/** @var string|null $error */

use CircuitMap\Support\BasePath;
use CircuitMap\Support\View;
?>
<div class="login-page">
    <h1>Log in</h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= View::escape($error) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= BasePath::url('/login') ?>">
        <input type="hidden" name="csrf_token" value="<?= View::escape($csrfToken) ?>">
        <label>
            Username
            <input type="text" name="username" required autofocus>
        </label>
        <label>
            Password
            <input type="password" name="password" required>
        </label>
        <button type="submit">Log in</button>
    </form>
</div>
