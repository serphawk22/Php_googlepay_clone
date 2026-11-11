<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

require_admin();
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && field('action') === 'update') {
    require_post();
    $billId = (int) field('bill_id');
    $bill = find_bill($billId);
    if (!$bill) {
        flash('error', 'That bill was not found.');
        redirect('admin/bills.php');
    }
    if ($bill['status'] === 'paid') {
        flash('error', 'Paid bills stay as they were confirmed.');
        redirect('admin/bills.php?id=' . $billId);
    }
    $amount = parse_money(field('amount'));
    $lateRaw = field('late_fee');
    $late = $lateRaw === '' ? '0.00' : parse_money($lateRaw);
    $due = parse_date(field('due_date'));
    $details = clean_text(field('details'), 255);
    $plan = clean_text(field('plan_name'), 120);
    if ($amount === null || (float) $amount <= 0) {
        $errors[] = 'Enter an amount greater than zero.';
    }
    if ($late === null) {
        $errors[] = 'Enter a late fee of zero or more.';
    }
    if ($due === null) {
        $errors[] = 'Enter a due date.';
    }
    if (!$errors && $due !== null && $amount !== null && $late !== null) {
        $status = $due < date('Y-m-d') ? 'overdue' : 'unpaid';
        db()->prepare(
            'UPDATE bills SET amount = ?, late_fee = ?, due_date = ?, details = ?, plan_name = ?, status = ?, updated_at = NOW() WHERE id = ? AND status <> \'paid\''
        )->execute([$amount, $late, $due, $details, $plan, $status, $billId]);
        $daysStmt = db()->prepare('SELECT remind_days FROM linked_accounts WHERE id = ?');
        $daysStmt->execute([(int) $bill['linked_account_id']]);
        $days = (int) ($daysStmt->fetchColumn() ?: 3);
        $payable = (float) $amount + ($status === 'overdue' ? (float) $late : 0);
        reschedule_reminders((int) $bill['user_id'], $billId, $due, $days, (string) $bill['biller_name'], $payable);
        notify((int) $bill['user_id'], 'Bill updated', $bill['biller_name'] . ' ' . $bill['billing_period'] . ' was updated. Payable ' . money($payable) . '.', 'app/bills.php?id=' . $billId);
        audit('bill.update', 'bill', $billId, $due);
        flash('success', 'Bill updated and pending reminders were rescheduled.');
        redirect('admin/bills.php?id=' . $billId);
    }
}

$billId = (int) ($_GET['id'] ?? $_POST['bill_id'] ?? 0);
if ($billId > 0) {
    $bill = find_bill($billId);
    if (!$bill) {
        fail_not_found('admin');
    }
    $open = open_payment($billId);
    render_shell_start('Bill ' . $bill['bill_number'], 'bills', 'admin', $bill['biller_name']);
    render_errors($errors);
    ?>
    <p><a href="<?= e(url('admin/bills.php')) ?>">All customer bills</a> · <a href="<?= e(url('admin/users.php?id=' . (int) $bill['user_id'])) ?>">Customer</a></p>
    <div class="split" style="margin-top:12px">
        <section class="panel">
            <?php render_bill_summary($bill); ?>
            <?php if ($open): ?>
                <p class="notice" style="margin-top:12px">A payment is <?= e(status_label((string) $open['status'])) ?>. If you change the amount, the customer is asked to start again.</p>
            <?php endif; ?>
        </section>
        <?php if ($bill['status'] === 'paid'): ?>
            <section class="panel"><p>This bill is paid and locked.</p>
                <?php $paid = successful_payment($billId); if ($paid): ?>
                    <a class="btn btn-primary" href="<?= e(url('app/receipt.php?id=' . (int) $paid['id'])) ?>">Receipt</a>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <form method="post" class="panel stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="bill_id" value="<?= $billId ?>">
                <h2 style="font-size:24px">Correct this bill</h2>
                <div class="field"><label for="amount">Amount</label><input id="amount" name="amount" value="<?= old('amount', (string) $bill['amount']) ?>" required></div>
                <div class="field"><label for="late_fee">Late fee</label><input id="late_fee" name="late_fee" value="<?= old('late_fee', (string) $bill['late_fee']) ?>"></div>
                <div class="field"><label for="due_date">Due date</label><input id="due_date" name="due_date" type="date" value="<?= old('due_date', (string) $bill['due_date']) ?>" required></div>
                <div class="field"><label for="plan_name">Plan</label><input id="plan_name" name="plan_name" value="<?= old('plan_name', (string) $bill['plan_name']) ?>"></div>
                <div class="field"><label for="details">Details</label><input id="details" name="details" value="<?= old('details', (string) $bill['details']) ?>"></div>
                <button class="btn btn-primary" type="submit">Save bill</button>
            </form>
        <?php endif; ?>
    </div>
    <?php
    render_shell_end('admin', 'bills');
    exit;
}

$status = (string) ($_GET['status'] ?? 'open');
$allowed = ['open', 'unpaid', 'overdue', 'paid', 'all'];
if (!in_array($status, $allowed, true)) {
    $status = 'open';
}
$q = search_term();
$where = ' WHERE 1=1';
$params = [];
if ($status === 'open') {
    $where .= " AND b.status IN ('unpaid', 'overdue')";
} elseif ($status !== 'all') {
    $where .= ' AND b.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where .= ' AND (b.account_number LIKE ? OR b.customer_name LIKE ? OR b.bill_number LIKE ? OR u.email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$from = ' FROM bills b INNER JOIN billers bi ON bi.id = b.biller_id INNER JOIN users u ON u.id = b.user_id';
$page = paged(
    'SELECT COUNT(*)' . $from . $where,
    'SELECT b.*, bi.name AS biller_name, bi.category, u.full_name, u.email,
        (SELECT p.amount FROM payments p WHERE p.bill_id = b.id AND p.status = \'success\' ORDER BY p.id DESC LIMIT 1) AS paid_amount'
        . $from . $where . ' ORDER BY b.due_date ASC, b.id DESC',
    $params
);
render_shell_start('Customer bills', 'bills', 'admin', $page['total'] . ' bills');
?>
<form class="filters" method="get">
    <div class="field"><label for="q">Search</label><input id="q" name="q" value="<?= e($q) ?>"></div>
    <div class="field"><label for="status">Status</label>
        <select id="status" name="status">
            <?php foreach (['open' => 'Open', 'unpaid' => 'Unpaid', 'overdue' => 'Overdue', 'paid' => 'Paid', 'all' => 'All'] as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= selected_attr($status, $key) ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="btn btn-primary" type="submit">Filter</button>
</form>
<div class="panel table-wrap">
    <table class="data">
        <thead><tr><th>Customer</th><th>Biller</th><th>Account</th><th>Period</th><th>Due</th><th>Payable</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($page['rows'] as $bill): ?>
            <tr>
                <td><?= e($bill['full_name']) ?></td>
                <td><?= e($bill['biller_name']) ?></td>
                <td><a href="<?= e(url('admin/bills.php?id=' . (int) $bill['id'])) ?>"><?= e($bill['account_number']) ?></a></td>
                <td><?= e($bill['billing_period']) ?></td>
                <td><?= e(format_date((string) $bill['due_date'])) ?></td>
                <td><?= e(money(amount_due($bill))) ?></td>
                <td><span class="badge badge-<?= e($bill['status']) ?>"><?= e($bill['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= pager_links((int) $page['page'], (int) $page['pages'], 'admin/bills.php') ?>
<?php render_shell_end('admin', 'bills'); ?>
