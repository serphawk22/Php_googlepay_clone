<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
$userId = (int) $user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && field('action') === 'refresh') {
    require_post();
    $result = refresh_user_bills($userId);
    if ($result['created'] === 0 && !empty($result['errors'])) {
        flash('error', 'Fetch failed: ' . $result['errors'][0]);
    } else {
        $msg = 'Checked ' . $result['checked'] . ' account(s) and fetched ' . $result['created'] . ' new bill(s).';
        if (!empty($result['errors'])) {
            $msg .= ' Some accounts failed: ' . $result['errors'][0];
        }
        flash($result['created'] > 0 || empty($result['errors']) ? 'success' : 'error', $msg);
    }
    redirect('app/bills.php');
}

$billId = (int) ($_GET['id'] ?? 0);
if ($billId > 0) {
    $bill = find_bill($billId, $userId);
    if (!$bill) {
        fail_not_found('user');
    }
    $open = open_payment($billId);
    $paid = successful_payment($billId);
    $reminders = db()->prepare('SELECT * FROM reminders WHERE bill_id = ? ORDER BY remind_on ASC');
    $reminders->execute([$billId]);
    render_shell_start('Bill ' . $bill['bill_number'], 'bills', 'user', (string) $bill['biller_name']);
    ?>
    <div class="split">
        <section class="panel">
            <?php render_bill_summary($bill); ?>
            <div class="cluster" style="margin-top:16px">
                <?php if ($bill['status'] !== 'paid'): ?>
                    <a class="btn btn-primary" href="<?= e(url('app/pay.php?bill=' . $billId)) ?>">Pay <?= e(money(amount_due($bill))) ?></a>
                <?php elseif ($paid): ?>
                    <a class="btn btn-primary" href="<?= e(url('app/receipt.php?id=' . (int) $paid['id'])) ?>">View receipt</a>
                <?php endif; ?>
                <a class="btn btn-ghost" href="<?= e(url('app/bills.php')) ?>">All bills</a>
            </div>
            <?php if ($open): ?>
                <p class="notice" style="margin-top:14px">A payment is <?= e(status_label((string) $open['status'])) ?> for this bill. Reference <?= e($open['txn_ref']) ?>.</p>
            <?php endif; ?>
        </section>
        <section class="panel">
            <h2 style="font-size:24px">Reminders</h2>
            <?php $rows = $reminders->fetchAll(); ?>
            <?php if (!$rows): ?>
                <p class="muted">No reminders were scheduled for this bill.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($rows as $reminder): ?>
                        <li><?= e(format_date((string) $reminder['remind_on'])) ?> · <span class="badge badge-<?= e($reminder['status']) ?>"><?= e(status_label((string) $reminder['status'])) ?></span><br><?= e($reminder['message']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
    <?php
    render_shell_end('user', 'bills');
    exit;
}

$status = (string) ($_GET['status'] ?? 'due');
if (!in_array($status, ['due', 'paid', 'all'], true)) {
    $status = 'due';
}
$sql = bill_select_sql() . ' WHERE b.user_id = ?';
$params = [$userId];
if ($status === 'due') {
    $sql .= " AND b.status IN ('unpaid', 'overdue')";
} elseif ($status === 'paid') {
    $sql .= " AND b.status = 'paid'";
}
$sql .= ' ORDER BY b.due_date ASC, b.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$bills = $stmt->fetchAll();

render_shell_start('Bills', 'bills', 'user', 'Fetched LIC and electricity bills.');
?>
<div class="cluster" style="margin-bottom:14px">
    <a class="btn btn-sm <?= $status === 'due' ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e(url('app/bills.php')) ?>">Due</a>
    <a class="btn btn-sm <?= $status === 'paid' ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e(url('app/bills.php?status=paid')) ?>">Paid</a>
    <a class="btn btn-sm <?= $status === 'all' ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e(url('app/bills.php?status=all')) ?>">All</a>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="refresh">
        <button class="btn btn-sm btn-ghost" type="submit">Fetch latest</button>
    </form>
</div>
<?php if (!$bills): ?>
    <div class="empty"><h2>No bills in this list</h2><p class="muted">Link an account and fetch, or ask the desk to add the bill record for your number.</p></div>
<?php else: ?>
    <div class="card-grid">
        <?php foreach ($bills as $bill) { render_bill_card($bill); } ?>
    </div>
<?php endif; ?>
<?php render_shell_end('user', 'bills'); ?>
