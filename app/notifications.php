<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
$userId = (int) $user['id'];
$stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 80');
$stmt->execute([$userId]);
$notes = $stmt->fetchAll();
db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$userId]);

render_shell_start('Notifications', 'reminders', 'user', 'Bill fetches, reminders, and payment updates.');
?>
<?php if (!$notes): ?>
    <div class="empty"><h2>Nothing here yet</h2><p class="muted">When a bill is fetched or a due date arrives, it will show up here.</p></div>
<?php else: ?>
    <div class="stack">
        <?php foreach ($notes as $note): ?>
            <article class="panel">
                <div class="cluster">
                    <h3><?= e($note['title']) ?></h3>
                    <?php if ((int) $note['is_read'] === 0): ?><span class="badge badge-pending">New</span><?php endif; ?>
                </div>
                <p style="margin-top:6px"><?= e_nl((string) $note['body']) ?></p>
                <p class="muted"><?= e(format_when((string) $note['created_at'])) ?></p>
                <?php if ($note['link'] !== ''): ?>
                    <p style="margin-top:8px"><a href="<?= e(url((string) $note['link'])) ?>">Open</a></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php render_shell_end('user', 'reminders'); ?>
