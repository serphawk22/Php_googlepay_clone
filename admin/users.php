<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$admin = require_admin();
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    $userId = (int) field('user_id');
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    $action = field('action');
    if (!$target) {
        flash('error', 'That user was not found.');
        redirect('admin/users.php');
    }
    if ($action === 'save') {
        $name = clean_text(field('full_name'), 120);
        $email = clean_text(field('email'), 190);
        $mobile = clean_text(field('mobile'), 10);
        if (!valid_name($name) || !valid_email($email) || !valid_mobile($mobile)) {
            $errors[] = 'Name, email, and a 10-digit mobile number are required.';
        } else {
            $clash = db()->prepare('SELECT id FROM users WHERE (email = ? OR mobile = ?) AND id <> ? LIMIT 1');
            $clash->execute([$email, $mobile, $userId]);
            if ($clash->fetch()) {
                $errors[] = 'That email or mobile belongs to another account.';
            } else {
                db()->prepare('UPDATE users SET full_name = ?, email = ?, mobile = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$name, $email, $mobile, $userId]);
                audit('user.update', 'user', $userId, $email);
                flash('success', 'User updated.');
                redirect('admin/users.php?id=' . $userId);
            }
        }
    } elseif ($action === 'suspend' || $action === 'activate') {
        if ($target['role'] !== 'user') {
            flash('error', 'Administrator accounts stay active. Change that password from Profile.');
        } else {
            $status = $action === 'suspend' ? 'suspended' : 'active';
            db()->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $userId]);
            audit('user.' . $status, 'user', $userId, (string) $target['email']);
            flash('success', $status === 'suspended' ? 'User suspended. They can no longer sign in.' : 'User activated.');
        }
        redirect('admin/users.php?id=' . $userId);
    } elseif ($action === 'reset') {
        if ($target['role'] !== 'user') {
            flash('error', 'Reset your own password from Profile.');
        } else {
            $temporary = random_password();
            db()->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
                ->execute([password_hash($temporary, PASSWORD_DEFAULT), $userId]);
            audit('user.reset', 'user', $userId, (string) $target['email']);
            flash('success', 'Temporary password for ' . $target['full_name'] . ': ' . $temporary);
        }
        redirect('admin/users.php?id=' . $userId);
    } elseif ($action === 'wallet') {
        $result = admin_adjust_wallet($userId, (int) $admin['id'], field('direction'), field('amount'), field('note'));
        flash(!empty($result['ok']) ? 'success' : 'error', (string) $result['message']);
        redirect('admin/users.php?id=' . $userId);
    } elseif ($action === 'reset_pin') {
        clear_user_pin($userId);
        flash('success', 'SERP UPI PIN cleared. They must choose a new one in Profile.');
        redirect('admin/users.php?id=' . $userId);
    }
    $detailId = $userId;
} else {
    $detailId = (int) ($_GET['id'] ?? 0);
}

if (isset($_GET['export'])) {
    $people = db()->query('SELECT full_name, email, mobile, role, status, wallet_balance, pin_hash, created_at, last_login_at FROM users ORDER BY id DESC LIMIT 5000')->fetchAll();
    $rows = [];
    foreach ($people as $person) {
        $rows[] = [$person['full_name'], $person['email'], $person['mobile'], $person['role'], $person['status'], $person['wallet_balance'], $person['pin_hash'] !== '' ? 'yes' : 'no', $person['created_at'], $person['last_login_at']];
    }
    csv_download('serp-upi-users.csv', ['Name', 'Email', 'Mobile', 'Role', 'Status', 'Wallet', 'PIN set', 'Created', 'Last login'], $rows);
}

if ($detailId > 0) {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$detailId]);
    $person = $stmt->fetch();
    if (!$person) {
        fail_not_found('admin');
    }
    $accounts = db()->prepare(
        'SELECT la.*, bi.name AS biller_name FROM linked_accounts la INNER JOIN billers bi ON bi.id = la.biller_id WHERE la.user_id = ? ORDER BY la.id DESC'
    );
    $accounts->execute([$detailId]);
    $bills = db()->prepare(bill_select_sql() . ' WHERE b.user_id = ? ORDER BY b.due_date DESC LIMIT 20');
    $bills->execute([$detailId]);
    $payments = db()->prepare('SELECT * FROM payments WHERE user_id = ? ORDER BY id DESC LIMIT 20');
    $payments->execute([$detailId]);
    render_shell_start($person['full_name'], 'users', 'admin', $person['email']);
    render_errors($errors);
    ?>
    <p><a href="<?= e(url('admin/users.php')) ?>">All users</a></p>
    <div class="split" style="margin-top:12px">
        <form method="post" class="panel stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
            <div class="field"><label for="full_name">Name</label><input id="full_name" name="full_name" value="<?= old('full_name', (string) $person['full_name']) ?>" required></div>
            <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="<?= old('email', (string) $person['email']) ?>" required></div>
            <div class="field"><label for="mobile">Mobile</label><input id="mobile" name="mobile" value="<?= old('mobile', (string) $person['mobile']) ?>" required></div>
            <p class="muted">Role: <?= e($person['role']) ?> · Status: <?= e($person['status']) ?> · Last login: <?= e(format_when($person['last_login_at'] ? (string) $person['last_login_at'] : null)) ?></p>
            <button class="btn btn-primary" type="submit">Save user</button>
        </form>
        <div class="panel stack">
            <h2 style="font-size:24px">Access</h2>
            <?php if ($person['role'] === 'user' && (int) $person['id'] !== (int) $admin['id']): ?>
                <?php if ($person['status'] === 'active'): ?>
                    <form method="post" data-confirm="Suspend this user?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="suspend">
                        <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                        <button class="btn btn-danger" type="submit">Suspend</button>
                    </form>
                <?php else: ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="activate">
                        <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                        <button class="btn btn-primary" type="submit">Activate</button>
                    </form>
                <?php endif; ?>
                <form method="post" data-confirm="Create a temporary password?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset">
                    <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                    <button class="btn btn-ghost" type="submit">Reset password</button>
                </form>
            <?php else: ?>
                <p class="muted">Change this administrator password from <a href="<?= e(url('app/profile.php')) ?>">Profile</a>.</p>
            <?php endif; ?>
            <p>Wallet balance: <strong><?= e(money($person['wallet_balance'] ?? 0)) ?></strong></p>
            <p>SERP UPI PIN: <?= ($person['pin_hash'] ?? '') !== '' ? 'set' : 'not set' ?></p>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="wallet">
                <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                <div class="field"><label for="amount">Wallet amount</label><input id="amount" name="amount" inputmode="decimal" required></div>
                <div class="field"><label for="direction">Action</label>
                    <select id="direction" name="direction">
                        <option value="credit">Add money</option>
                        <option value="debit">Deduct money</option>
                    </select>
                </div>
                <div class="field"><label for="note">Note</label><input id="note" name="note" maxlength="255"></div>
                <button class="btn btn-primary" type="submit">Update wallet</button>
            </form>
            <form method="post" data-confirm="Clear this SERP UPI PIN? They will choose a new one.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_pin">
                <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                <button class="btn btn-ghost" type="submit">Clear PIN</button>
            </form>
        </div>
    </div>
    <section class="panel" style="margin-top:16px">
        <h2 style="font-size:24px">Linked accounts</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>Biller</th><th>Number</th><th>Name</th><th>Reminder</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($accounts->fetchAll() as $account): ?>
                <tr>
                    <td><?= e($account['biller_name']) ?></td>
                    <td><?= e($account['account_number']) ?></td>
                    <td><?= e($account['holder_name']) ?></td>
                    <td><?= (int) $account['remind_days'] ?> days</td>
                    <td><?= e($account['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <section class="panel" style="margin-top:16px">
        <h2 style="font-size:24px">Bills and payments</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>Period</th><th>Biller</th><th>Amount</th><th>Due</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($bills->fetchAll() as $bill): ?>
                <tr>
                    <td><a href="<?= e(url('admin/bills.php?id=' . (int) $bill['id'])) ?>"><?= e($bill['billing_period']) ?></a></td>
                    <td><?= e($bill['biller_name']) ?></td>
                    <td><?= e(money(amount_due($bill))) ?></td>
                    <td><?= e(format_date((string) $bill['due_date'])) ?></td>
                    <td><?= e($bill['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="table-wrap" style="margin-top:12px"><table class="data">
            <thead><tr><th>Reference</th><th>Amount</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($payments->fetchAll() as $payment): ?>
                <tr>
                    <td><a href="<?= e(url('admin/payments.php?id=' . (int) $payment['id'])) ?>"><?= e($payment['txn_ref']) ?></a></td>
                    <td><?= e(money($payment['amount'])) ?></td>
                    <td><?= e(status_label((string) $payment['status'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php
    render_shell_end('admin', 'users');
    exit;
}

$q = search_term();
$status = (string) ($_GET['status'] ?? '');
$where = ' WHERE 1=1';
$params = [];
if (in_array($status, ['active', 'suspended'], true)) {
    $where .= ' AND status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where .= ' AND (full_name LIKE ? OR email LIKE ? OR mobile LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$page = paged('SELECT COUNT(*) FROM users' . $where, 'SELECT * FROM users' . $where . ' ORDER BY id DESC', $params);
render_shell_start('Users', 'users', 'admin', $page['total'] . ' accounts');
?>
<form class="filters" method="get">
    <div class="field"><label for="q">Search</label><input id="q" name="q" value="<?= e($q) ?>"></div>
    <div class="field"><label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All</option>
            <option value="active"<?= selected_attr($status, 'active') ?>>Active</option>
            <option value="suspended"<?= selected_attr($status, 'suspended') ?>>Suspended</option>
        </select>
    </div>
    <button class="btn btn-primary" type="submit">Filter</button>
    <a class="btn btn-ghost" href="<?= e(url('admin/users.php?export=1')) ?>">Export CSV</a>
</form>
<div class="panel table-wrap">
    <table class="data">
        <thead><tr><th>Name</th><th>Email</th><th>Mobile</th><th>Wallet</th><th>Role</th><th>Status</th><th>Joined</th></tr></thead>
        <tbody>
        <?php foreach ($page['rows'] as $person): ?>
            <tr>
                <td><a href="<?= e(url('admin/users.php?id=' . (int) $person['id'])) ?>"><?= e($person['full_name']) ?></a></td>
                <td><?= e($person['email']) ?></td>
                <td><?= e($person['mobile']) ?></td>
                <td><?= e(money($person['wallet_balance'] ?? 0)) ?></td>
                <td><?= e($person['role']) ?></td>
                <td><span class="badge badge-<?= e($person['status']) ?>"><?= e($person['status']) ?></span></td>
                <td><?= e(format_date((string) $person['created_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= pager_links((int) $page['page'], (int) $page['pages'], 'admin/users.php') ?>
<?php render_shell_end('admin', 'users'); ?>
