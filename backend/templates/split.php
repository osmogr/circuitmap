<?php
/** @var string $csrfToken */
/** @var string $token */
/** @var array<int, array{key: string, label: string, placemarkCount: int, value: string, included: bool, disabled: bool}> $rows */
/** @var array<string, string> $shared */
/** @var string $originalName */
/** @var string $originalFilename */
/** @var string|null $error */
/** @var array<string, mixed>|null $currentUser */

use CircuitMap\Support\BasePath;
use CircuitMap\Support\View;
?>
<div class="split-page">
    <h1>Multiple folders detected</h1>
    <p>
        &ldquo;<?= View::escape($originalFilename) ?>&rdquo; contains more than one folder.
        You can import each folder as its own circuit, or import the whole file as a single circuit.
    </p>
    <?php if (!empty($error)): ?>
        <p class="error"><?= View::escape($error) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= BasePath::url('/upload/confirm-split') ?>">
        <input type="hidden" name="csrf_token" value="<?= View::escape($csrfToken) ?>">
        <input type="hidden" name="pending_token" value="<?= View::escape($token) ?>">
        <table class="split-table">
            <thead>
                <tr>
                    <th>Import</th>
                    <th>Folder</th>
                    <th>Placemarks</th>
                    <th>Circuit name</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr<?= $row['disabled'] ? ' class="split-row-disabled"' : '' ?>>
                        <td>
                            <input type="checkbox" name="include[]" value="<?= View::escape($row['key']) ?>"
                                <?= $row['included'] ? 'checked' : '' ?><?= $row['disabled'] ? ' disabled' : '' ?>>
                        </td>
                        <td><?= View::escape($row['label']) ?></td>
                        <td><?= (int) $row['placemarkCount'] ?></td>
                        <td>
                            <?php if ($row['disabled']): ?>
                                <em>empty folder</em>
                            <?php else: ?>
                                <input type="text" name="names[<?= View::escape($row['key']) ?>]"
                                    value="<?= View::escape($row['value']) ?>" maxlength="200">
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($shared !== []): ?>
            <section class="split-shared">
                <h2>Applied to every imported circuit</h2>
                <dl>
                    <?php foreach ($shared as $label => $value): ?>
                        <dt><?= View::escape($label) ?></dt>
                        <dd><?= View::escape($value) ?></dd>
                    <?php endforeach; ?>
                </dl>
            </section>
        <?php endif; ?>
        <div class="split-actions">
            <button type="submit" name="mode" value="split">Create selected circuits</button>
            <button type="submit" name="mode" value="single" class="secondary">
                Import as a single circuit (&ldquo;<?= View::escape($originalName) ?>&rdquo;)
            </button>
            <a href="<?= BasePath::url('/upload') ?>">Cancel</a>
        </div>
    </form>
</div>
