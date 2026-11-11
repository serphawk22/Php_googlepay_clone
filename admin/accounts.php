<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    $accountId = (int) field('account_id');
    $status = field('status') === 'active' ? 'active' : 'removed';
    $stmt = db()->prepare('UPDATE linked_accounts SET status = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$status, $accountId]);
    audit('account.' . $status, 'linked_account', $accountId, '');
    flash('success', $status === 'active' ? 'Account restored.' : 'Account disabled for that customer.');
    redirect('admin/accounts.php');
}

$q = search_term();
$where = ' WHERE 1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (la.account_number LIKE ? OR la.holder_name LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$page = paged(
    'SELECT COUNT(*) FROM linked_accounts la INNER JOIN users u ON u.id = la.user_id INNER JOIN billers bi ON bi.id = la.biller_id' . $where,
    'SELECT la.*, u.full_name, u.email, bi.name AS biller_name, bi.category
     FROM linked_accounts la
     INNER JOIN users u ON u.id = la.user_id
     INNER JOIN billers bi ON bi.id = la.biller_id' . $where . ' ORDER BY la.id DESC',
    $params
);

render_shell_start('Linked accounts', 'accounts', 'admin', 'Every policy and consumer number a customer has saved.');
?>
<form class="filters" method="get">
    <div class="field"><label for="q">Search</label><input id="q" name="q" value="<?= e($q) ?>"></div>
    <button class="btn btn-primary" type="submit">Search</button>
</form>
<div class="panel table-wrap">
    <table class="data">
        <thead><tr><th>Customer</th><th>Biller</th><th>Number</th><th>Name on bill</th><th>Reminder</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($page['rows'] as $account): ?>
            <tr>
                <td><a href="<?= e(url('admin/users.php?id=' . (int) $account['user_id'])) ?>"><?= e($account['full_name']) ?></a><br><span class="muted"><?= e($account['email']) ?></span></td>
                <td><?= e($account['biller_name']) ?></td>
                <td><?= e($account['account_number']) ?></td>
                <td><?= e($account['holder_name']) ?><?= $account['nickname'] !== '' ? '<br><span class="muted">' . e($account['nickname']) . '</span>' : '' ?></td>
                <td><?= (int) $account['remind_days'] ?> days</td>
                <td><?= e($account['status']) ?></td>
                <td>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                        <input type="hidden" name="status" value="<?= $account['status'] === 'active' ? 'removed' : 'active' ?>">
                        <button class="btn btn-sm btn-ghost" type="submit"><?= $account['status'] === 'active' ? 'Disable' : 'Restore' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= pager_links((int) $page['page'], (int) $page['pages'], 'admin/accounts.php') ?>
<?php render_shell_end('admin', 'accounts'); ?>
