<?php
/** @var string $title */
/** @var string $content */
/** @var string $csrfToken */
/** @var array<string, mixed>|null $currentUser */

use CircuitMap\Support\Asset;
use CircuitMap\Support\BasePath;
use CircuitMap\Support\View;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= View::escape($csrfToken) ?>">
    <meta name="base-path" content="<?= View::escape(BasePath::get()) ?>">
    <title><?= View::escape($title) ?> - CircuitMap</title>
    <link rel="stylesheet" href="<?= Asset::url('/assets/css/app.css') ?>">
</head>
<body>
    <header class="site-header">
        <a class="site-title" href="<?= BasePath::url('/') ?>">CircuitMap</a>
        <nav>
            <a href="<?= BasePath::url('/circuits/report') ?>">All Circuits</a>
            <?php if ($currentUser !== null): ?>
                <?php if (in_array($currentUser['role'] ?? null, ['admin', 'editor'], true)): ?>
                    <a href="<?= BasePath::url('/admin/providers') ?>">Manage Circuit Providers</a>
                    <a href="<?= BasePath::url('/admin/locations') ?>">Manage Locations</a>
                <?php endif; ?>
                <?php if (($currentUser['role'] ?? null) === 'admin'): ?>
                    <a href="<?= BasePath::url('/admin/users') ?>">Admin</a>
                    <a href="<?= BasePath::url('/admin/export/circuits.kml') ?>" download>Export KML</a>
                    <a href="<?= BasePath::url('/admin/export/circuits.kmz') ?>" download>Export KMZ</a>
                    <a href="<?= BasePath::url('/admin/instance') ?>">Instance Transfer</a>
                <?php endif; ?>
                <span class="nav-user"><?= View::escape($currentUser['username']) ?></span>
                <form method="post" action="<?= BasePath::url('/logout') ?>" class="nav-logout-form">
                    <input type="hidden" name="csrf_token" value="<?= View::escape($csrfToken) ?>">
                    <button type="submit">Log out</button>
                </form>
            <?php else: ?>
                <a href="<?= BasePath::url('/login') ?>">Log in</a>
            <?php endif; ?>
        </nav>
    </header>
    <main>
        <?= $content ?>
    </main>
</body>
</html>
