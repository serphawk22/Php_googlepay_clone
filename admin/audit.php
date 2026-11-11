<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

require_admin();
$q = search_term();
$where = ' WHERE 1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (a.action LIKE ? OR a.details LIKE ? OR u.email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$page = paged(
    'SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id' . $where,
    'SELECT a.*, u.full_name, u.email FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id' . $where . ' ORDER BY a.id DESC',
    $params,
    30
);

render_shell_start('Audit log', 'audit', 'admin', 'Sign-ins, bill fetches, payment decisions, and setting changes.');
?>
<form class="filters" method="get">
    <div class="field"><label for="q">Search</label><input id="q" name="q" value="<?= e($q) ?>"></div>
    <button class="btn btn-primary" type="submit">Search</button>
</form>
<div class="panel table-wrap">
    <table class="data">
        <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($page['rows'] as $row): ?>
            <tr>
                <td><?= e(format_when((string) $row['created_at'])) ?></td>
                <td><?= e($row['full_name'] ?: 'System') ?><?= $row['email'] ? '<br><span class="muted">' . e($row['email']) . '</span>' : '' ?></td>
                <td><?= e($row['action']) ?><?= $row['entity'] ? '<br><span class="muted">' . e($row['entity']) . ($row['entity_id'] ? ' #' . (int) $row['entity_id'] : '') . '</span>' : '' ?></td>
                <td><?= e($row['details']) ?></td>
                <td><?= e($row['ip']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= pager_links((int) $page['page'], (int) $page['pages'], 'admin/audit.php') ?>
<?php render_shell_end('admin', 'audit'); ?>
