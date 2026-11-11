<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
$userId = (int) $user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    if (field('action') === 'dismiss') {
        $stmt = db()->prepare("UPDATE reminders SET status = 'dismissed' WHERE id = ? AND user_id = ? AND status = 'pending'");
        $stmt->execute([(int) field('reminder_id'), $userId]);
        flash($stmt->rowCount() === 1 ? 'success' : 'error', $stmt->rowCount() === 1 ? 'Reminder dismissed.' : 'That reminder could not be dismissed.');
        redirect('app/reminders.php');
    }
}

$stmt = db()->prepare(
    "SELECT r.*, b.account_number, b.due_date, bi.name AS biller_name
     FROM reminders r
     INNER JOIN bills b ON b.id = r.bill_id
     INNER JOIN billers bi ON bi.id = b.biller_id
     WHERE r.user_id = ? AND r.status <> 'dismissed'
     ORDER BY r.remind_on ASC, r.id ASC
     LIMIT 100"
);
$stmt->execute([$userId]);
$reminders = $stmt->fetchAll();

render_shell_start('Reminders', 'reminders', 'user', 'A reminder is sent on the lead day and again on the due date, when you open SERP UPI.');
?>
<?php if (!$reminders): ?>
    <div class="empty"><h2>No reminders yet</h2><p class="muted">They are created when a bill is fetched. Choose how many days ahead on each linked account.</p></div>
<?php else: ?>
    <div class="stack">
        <?php foreach ($reminders as $reminder): ?>
            <article class="panel">
                <div class="cluster">
                    <span class="badge badge-<?= e($reminder['status']) ?>"><?= e(status_label((string) $reminder['status'])) ?></span>
                    <strong><?= e(format_date((string) $reminder['remind_on'])) ?></strong>
                </div>
                <h3 style="margin-top:8px"><?= e($reminder['biller_name']) ?></h3>
                <p><?= e($reminder['message']) ?></p>
                <p class="muted"><?= e($reminder['account_number']) ?> · bill <?= e(due_label((string) $reminder['due_date'])) ?></p>
                <div class="cluster" style="margin-top:10px">
                    <a class="btn btn-sm btn-primary" href="<?= e(url('app/bills.php?id=' . (int) $reminder['bill_id'])) ?>">Open bill</a>
                    <?php if ($reminder['status'] === 'pending'): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="dismiss">
                            <input type="hidden" name="reminder_id" value="<?= (int) $reminder['id'] ?>">
                            <button class="btn btn-sm btn-ghost" type="submit">Dismiss</button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php render_shell_end('user', 'reminders'); ?>
