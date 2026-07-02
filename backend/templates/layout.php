<?php
/** @var string $title */
/** @var string $content */
/** @var string $csrfToken */
/** @var array<string, mixed>|null $currentUser */

use CircuitMap\Support\View;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= View::escape($csrfToken) ?>">
    <title><?= View::escape($title) ?> - CircuitMap</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <header class="site-header">
        <a class="site-title" href="/">CircuitMap</a>
        <nav>
            <?php if ($currentUser !== null): ?>
                <?php if (($currentUser['role'] ?? null) === 'admin'): ?>
                    <a href="/admin/users">Admin</a>
                <?php endif; ?>
                <span class="nav-user"><?= View::escape($currentUser['username']) ?></span>
                <form method="post" action="/logout" class="nav-logout-form">
                    <input type="hidden" name="csrf_token" value="<?= View::escape($csrfToken) ?>">
                    <button type="submit">Log out</button>
                </form>
            <?php else: ?>
                <a href="/login">Log in</a>
            <?php endif; ?>
        </nav>
    </header>
    <main>
        <?= $content ?>
    </main>
</body>
</html>
