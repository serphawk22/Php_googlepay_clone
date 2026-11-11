<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
$userId = (int) $user['id'];
$status = (string) ($_GET['status'] ?? 'all');
$allowed = ['all', 'success', 'refunded', 'failed', 'initiated', 'pending_verification'];
if (!in_array($status, $allowed, true)) {
    $status = 'all';
}
$where = ' WHERE p.user_id = ?';
$params = [$userId];
if ($status !== 'all') {
    $where .= ' AND p.status = ?';
    $params[] = $status;
}
$rows = db()->prepare(
    'SELECT p.*, bi.name AS biller_name, b.billing_period, b.account_number
     FROM payments p
     INNER JOIN bills b ON b.id = p.bill_id
     INNER JOIN billers bi ON bi.id = b.biller_id' . $where . ' ORDER BY p.id DESC LIMIT 100'
);
$rows->execute($params);
$payments = $rows->fetchAll();
$paidStmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE user_id = ? AND status = 'success'");
$paidStmt->execute([$userId]);
$paidTotal = (float) $paidStmt->fetchColumn();

render_shell_start('Payments', 'history', 'user', 'Confirmed total ' . money($paidTotal));
?>
<div class="cluster" style="margin-bottom:14px">
    <?php foreach (['all' => 'All', 'success' => 'Paid', 'refunded' => 'Refunded', 'failed' => 'Failed'] as $key => $label): ?>
        <a class="btn btn-sm <?= $status === $key ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e(url('app/history.php' . ($key === 'all' ? '' : '?status=' . $key))) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>
<?php if (!$payments): ?>
    <div class="empty"><h2>No payments yet</h2></div>
<?php else: ?>
    <div class="panel table-wrap">
        <table class="data">
            <thead><tr><th>When</th><th>Biller</th><th>Account</th><th>Amount</th><th>Reference</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($payments as $payment): ?>
                <tr>
                    <td><?= e(format_when((string) $payment['created_at'])) ?></td>
                    <td><?= e($payment['biller_name']) ?><br><span class="muted"><?= e($payment['billing_period']) ?></span></td>
                    <td><?= e($payment['account_number']) ?></td>
                    <td><?= e(money($payment['amount'])) ?></td>
                    <td><?= e($payment['txn_ref']) ?></td>
                    <td><span class="badge badge-<?= e($payment['status']) ?>"><?= e(status_label((string) $payment['status'])) ?></span></td>
                    <td>
                        <?php if ($payment['status'] === 'success'): ?>
                            <a href="<?= e(url('app/receipt.php?id=' . (int) $payment['id'])) ?>">Receipt</a>
                        <?php elseif (in_array($payment['status'], ['initiated', 'pending_verification'], true)): ?>
                            <a href="<?= e(url('app/pay.php?bill=' . (int) $payment['bill_id'])) ?>">Open</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php render_shell_end('user', 'history'); ?>
