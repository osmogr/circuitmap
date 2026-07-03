<?php
/** @var array<int, array<string, mixed>> $entries */

use CircuitMap\Support\BasePath;
use CircuitMap\Support\View;
?>
<div class="admin-page">
    <h1>Audit log</h1>
    <p><a href="<?= BasePath::url('/admin/users') ?>">Back to users</a></p>
    <table>
        <thead>
            <tr>
                <th>Time (UTC)</th>
                <th>User ID</th>
                <th>Event</th>
                <th>Circuit ID</th>
                <th>Detail</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?= View::escape($entry['created_at']) ?></td>
                    <td><?= View::escape((string) ($entry['user_id'] ?? '')) ?></td>
                    <td><?= View::escape($entry['event_type']) ?></td>
                    <td><?= View::escape((string) ($entry['circuit_id'] ?? '')) ?></td>
                    <td><?= View::escape($entry['detail'] ?? '') ?></td>
                    <td><?= View::escape($entry['ip_address'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
