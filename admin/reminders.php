<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

require_admin();
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    $action = field('action');
    if ($action === 'run') {
        run_reminder_engine(true);
        audit('reminders.run', 'reminder', null, '');
        flash('success', 'Due reminders were checked and sent to Notifications.');
        redirect('admin/reminders.php');
    }
    if ($action === 'broadcast') {
        $title = clean_text(field('title'), 160);
        $body = clean_block(field('body'), 500);
        if ($title === '' || $body === '') {
            $errors[] = 'Enter a title and a message.';
        } else {
            $ids = db()->query("SELECT id FROM users WHERE role = 'user' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                notify((int) $id, $title, $body, '');
            }
            audit('reminders.broadcast', 'notification', null, $title);
            flash('success', 'Sent to ' . count($ids) . ' active customer(s).');
            redirect('admin/reminders.php');
        }
    }
}

$q = search_term();
$where = ' WHERE 1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (u.full_name LIKE ? OR u.email LIKE ? OR b.account_number LIKE ? OR r.message LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$from = ' FROM reminders r
    INNER JOIN users u ON u.id = r.user_id
    INNER JOIN bills b ON b.id = r.bill_id
    INNER JOIN billers bi ON bi.id = b.biller_id';
$page = paged(
    'SELECT COUNT(*)' . $from . $where,
    'SELECT r.*, u.full_name, b.account_number, bi.name AS biller_name' . $from . $where . ' ORDER BY r.remind_on DESC, r.id DESC',
    $params
);

render_shell_start('Reminders', 'reminders', 'admin', 'Lead-day and due-day reminders for every customer bill.');
render_errors($errors);
?>
<div class="split">
    <form method="post" class="panel stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="broadcast">
        <h2 style="font-size:24px">Message every customer</h2>
        <div class="field"><label for="title">Title</label><input id="title" name="title" value="<?= old('title') ?>" required></div>
        <div class="field"><label for="body">Message</label><textarea id="body" name="body" required><?= old('body') ?></textarea></div>
        <button class="btn btn-primary" type="submit">Send notification</button>
    </form>
    <form method="post" class="panel stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="run">
        <h2 style="font-size:24px">Check due reminders</h2>
        <p class="muted">Reminders also run when someone signs in. Use this if you want them sent now.</p>
        <button class="btn btn-ghost" type="submit">Run reminder check</button>
    </form>
</div>
<form class="filters" method="get" style="margin-top:16px">
    <div class="field"><label for="q">Search</label><input id="q" name="q" value="<?= e($q) ?>"></div>
    <button class="btn btn-primary" type="submit">Search</button>
</form>
<div class="panel table-wrap">
    <table class="data">
        <thead><tr><th>When</th><th>Customer</th><th>Biller</th><th>Message</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($page['rows'] as $reminder): ?>
            <tr>
                <td><?= e(format_date((string) $reminder['remind_on'])) ?></td>
                <td><?= e($reminder['full_name']) ?></td>
                <td><?= e($reminder['biller_name']) ?><br><span class="muted"><?= e($reminder['account_number']) ?></span></td>
                <td><?= e($reminder['message']) ?></td>
                <td><span class="badge badge-<?= e($reminder['status']) ?>"><?= e($reminder['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= pager_links((int) $page['page'], (int) $page['pages'], 'admin/reminders.php') ?>
<?php render_shell_end('admin', 'reminders'); ?>
