<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$admin = require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    $paymentId = (int) field('payment_id');
    if (field('action') === 'refund') {
        $result = refund_payment($paymentId, (int) $admin['id'], field('admin_note'));
    } else {
        $decision = field('action') === 'reject' ? 'failed' : 'success';
        $result = decide_payment($paymentId, (int) $admin['id'], $decision, field('utr'), field('admin_note'));
    }
    flash(!empty($result['ok']) ? 'success' : 'error', (string) $result['message']);
    redirect('admin/payments.php?id=' . $paymentId);
}

$status = (string) ($_GET['status'] ?? '');
$allowed = ['', 'initiated', 'pending_verification', 'success', 'failed', 'refunded'];
if (!in_array($status, $allowed, true)) {
    $status = '';
}
$q = search_term();
$where = ' WHERE 1=1';
$params = [];
if ($status !== '') {
    $where .= ' AND p.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where .= ' AND (p.txn_ref LIKE ? OR p.utr LIKE ? OR u.email LIKE ? OR b.account_number LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$from = ' FROM payments p
    INNER JOIN bills b ON b.id = p.bill_id
    INNER JOIN billers bi ON bi.id = b.biller_id
    INNER JOIN users u ON u.id = p.user_id';

if (isset($_GET['export'])) {
    $stmt = db()->prepare(
        'SELECT p.created_at, u.full_name, u.email, bi.name AS biller_name, b.account_number, b.billing_period, p.amount, p.status, p.utr, p.txn_ref, p.upi_vpa'
        . $from . $where . ' ORDER BY p.id DESC LIMIT 5000'
    );
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = array_values($row);
    }
    csv_download('serp-upi-payments.csv', ['When', 'Customer', 'Email', 'Biller', 'Account', 'Period', 'Amount', 'Status', 'UTR', 'Reference', 'UPI ID'], $rows);
}

$paymentId = (int) ($_GET['id'] ?? 0);
if ($paymentId > 0) {
    $payment = find_payment($paymentId);
    if (!$payment) {
        fail_not_found('admin');
    }
    render_shell_start('Payment ' . $payment['txn_ref'], 'payments', 'admin', $payment['full_name']);
    ?>
    <p><a href="<?= e(url('admin/payments.php')) ?>">All payments</a></p>
    <div class="split" style="margin-top:12px">
        <section class="panel">
            <dl class="facts">
                <div><dt>Customer</dt><dd><a href="<?= e(url('admin/users.php?id=' . (int) $payment['user_id'])) ?>"><?= e($payment['full_name']) ?></a></dd></div>
                <div><dt>Email</dt><dd><?= e($payment['email']) ?></dd></div>
                <div><dt>Biller</dt><dd><?= e($payment['biller_name']) ?></dd></div>
                <div><dt>Account</dt><dd><?= e($payment['account_number']) ?></dd></div>
                <div><dt>Period</dt><dd><?= e($payment['billing_period']) ?></dd></div>
                <div><dt>Amount</dt><dd><?= e(money($payment['amount'])) ?></dd></div>
                <div><dt>Paid from</dt><dd><?= e($payment['upi_vpa']) ?></dd></div>
                <div><dt>Reference</dt><dd><?= e($payment['txn_ref']) ?></dd></div>
                <div><dt>Authorisation</dt><dd><?= e((string) ($payment['utr'] !== null && $payment['utr'] !== '' ? $payment['utr'] : 'SERP UPI PIN')) ?></dd></div>
                <div><dt>Status</dt><dd><?= e(status_label((string) $payment['status'])) ?></dd></div>
                <div><dt>Customer note</dt><dd><?= e($payment['user_note'] !== '' ? $payment['user_note'] : '—') ?></dd></div>
                <div><dt>Desk note</dt><dd><?= e($payment['admin_note'] !== '' ? $payment['admin_note'] : '—') ?></dd></div>
            </dl>
        </section>
        <section class="panel stack">
            <?php if (in_array($payment['status'], ['initiated', 'pending_verification'], true)): ?>
                <form method="post" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="confirm">
                    <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                    <h2 style="font-size:24px">Confirm payment</h2>
                    <div class="field"><label for="utr">UTR</label><input id="utr" name="utr" value="<?= e((string) $payment['utr']) ?>" required></div>
                    <div class="field"><label for="admin_note">Note</label><input id="admin_note" name="admin_note" maxlength="255"></div>
                    <button class="btn btn-primary" type="submit">Mark paid</button>
                </form>
                <form method="post" data-confirm="Reject this payment? The bill stays unpaid.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                    <input type="hidden" name="admin_note" value="Rejected by desk">
                    <button class="btn btn-danger" type="submit">Reject</button>
                </form>
            <?php elseif ($payment['status'] === 'success'): ?>
                <p>This bill was paid inside SERP UPI with the customer PIN.</p>
                <a class="btn btn-primary" href="<?= e(url('app/receipt.php?id=' . (int) $payment['id'])) ?>">Open receipt</a>
                <form method="post" class="stack" data-confirm="Refund this payment to the SERP wallet and mark the bill unpaid?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="refund">
                    <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                    <div class="field"><label for="admin_note">Refund note</label><input id="admin_note" name="admin_note" maxlength="255"></div>
                    <button class="btn btn-danger" type="submit">Refund to wallet</button>
                </form>
            <?php else: ?>
                <p>This payment is <?= e(status_label((string) $payment['status'])) ?>.</p>
                <?php if ($payment['status'] !== 'failed'): ?>
                    <a class="btn btn-primary" href="<?= e(url('app/receipt.php?id=' . (int) $payment['id'])) ?>">Open receipt</a>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
    <?php
    render_shell_end('admin', 'payments');
    exit;
}

$page = paged(
    'SELECT COUNT(*)' . $from . $where,
    'SELECT p.*, u.full_name, bi.name AS biller_name, b.account_number' . $from . $where . ' ORDER BY p.id DESC',
    $params
);
render_shell_start('Payments', 'payments', 'admin', $page['total'] . ' payments');
?>
<form class="filters" method="get">
    <div class="field"><label for="q">Search</label><input id="q" name="q" value="<?= e($q) ?>"></div>
    <div class="field"><label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All</option>
            <?php foreach (['success' => 'Paid', 'refunded' => 'Refunded', 'failed' => 'Failed', 'pending_verification' => 'Waiting', 'initiated' => 'Started'] as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= selected_attr($status, $key) ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="btn btn-primary" type="submit">Filter</button>
    <a class="btn btn-ghost" href="<?= e(url('admin/payments.php?export=1&status=' . rawurlencode($status) . '&q=' . rawurlencode($q))) ?>">Export CSV</a>
</form>
<div class="panel table-wrap">
    <table class="data">
        <thead><tr><th>When</th><th>Customer</th><th>Biller</th><th>Amount</th><th>UTR</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($page['rows'] as $payment): ?>
            <tr>
                <td><?= e(format_when((string) $payment['created_at'])) ?></td>
                <td><?= e($payment['full_name']) ?></td>
                <td><?= e($payment['biller_name']) ?><br><span class="muted"><?= e($payment['account_number']) ?></span></td>
                <td><?= e(money($payment['amount'])) ?></td>
                <td><?= e((string) ($payment['utr'] ?: '—')) ?></td>
                <td><a href="<?= e(url('admin/payments.php?id=' . (int) $payment['id'])) ?>"><?= e(status_label((string) $payment['status'])) ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= pager_links((int) $page['page'], (int) $page['pages'], 'admin/payments.php') ?>
<?php render_shell_end('admin', 'payments'); ?>
