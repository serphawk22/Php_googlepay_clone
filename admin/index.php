<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

require_admin();

$customers = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
$openBills = (int) db()->query("SELECT COUNT(*) FROM bills WHERE status IN ('unpaid', 'overdue')")->fetchColumn();
$overdue = (int) db()->query("SELECT COUNT(*) FROM bills WHERE status = 'overdue'")->fetchColumn();
$waiting = (int) db()->query("SELECT COUNT(*) FROM payments WHERE status = 'pending_verification'")->fetchColumn();
$collected = (float) db()->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'success' AND verified_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$categories = db()->query(
    "SELECT bi.category, COALESCE(SUM(p.amount), 0) AS total
     FROM payments p
     INNER JOIN bills b ON b.id = p.bill_id
     INNER JOIN billers bi ON bi.id = b.biller_id
     WHERE p.status = 'success'
     GROUP BY bi.category"
)->fetchAll();
$maxCategory = 1.0;
foreach ($categories as $category) {
    $maxCategory = max($maxCategory, (float) $category['total']);
}
$recentPayments = db()->query(
    "SELECT p.*, u.full_name, bi.name AS biller_name
     FROM payments p
     INNER JOIN users u ON u.id = p.user_id
     INNER JOIN bills b ON b.id = p.bill_id
     INNER JOIN billers bi ON bi.id = b.biller_id
     ORDER BY p.id DESC
     LIMIT 8"
)->fetchAll();
$recentUsers = db()->query("SELECT id, full_name, email, status, created_at FROM users WHERE role = 'user' ORDER BY id DESC LIMIT 5")->fetchAll();

render_shell_start('Overview', 'overview', 'admin', 'Desk overview for bills, payments, and users.');
?>
<div class="stat-grid">
    <article class="stat"><span>Customers</span><strong><?= $customers ?></strong><a href="<?= e(url('admin/users.php')) ?>">Open users</a></article>
    <article class="stat"><span>Open bills</span><strong><?= $openBills ?></strong><a href="<?= e(url('admin/bills.php?status=open')) ?>">Open bills</a></article>
    <article class="stat"><span>Overdue</span><strong><?= $overdue ?></strong><a href="<?= e(url('admin/bills.php?status=overdue')) ?>">Review</a></article>
    <article class="stat"><span>UTR waiting</span><strong><?= $waiting ?></strong><a href="<?= e(url('admin/payments.php?status=pending_verification')) ?>">Confirm</a></article>
</div>
<div class="split" style="margin-top:16px">
    <section class="panel">
        <h2 style="font-size:24px">Collected this month</h2>
        <p class="amount"><?= e(money($collected)) ?></p>
        <div class="bars" style="margin-top:14px">
            <?php if (!$categories): ?>
                <p class="muted">Confirmed payments will split into LIC and electricity here.</p>
            <?php endif; ?>
            <?php foreach ($categories as $category): ?>
                <div class="bar-row">
                    <span><?= e(category_label((string) $category['category'])) ?></span>
                    <div class="bar"><span style="width:<?= e((string) round(((float) $category['total'] / $maxCategory) * 100)) ?>%"></span></div>
                    <strong><?= e(money($category['total'])) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="panel">
        <h2 style="font-size:24px">New customers</h2>
        <?php if (!$recentUsers): ?>
            <p class="muted">No customers have registered yet.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($recentUsers as $person): ?>
                    <li><a href="<?= e(url('admin/users.php?id=' . (int) $person['id'])) ?>"><?= e($person['full_name']) ?></a> · <?= e($person['email']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
<section class="panel" style="margin-top:16px">
    <h2 style="font-size:24px">Latest payments</h2>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>When</th><th>Customer</th><th>Biller</th><th>Amount</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recentPayments as $payment): ?>
                <tr>
                    <td><?= e(format_when((string) $payment['created_at'])) ?></td>
                    <td><?= e($payment['full_name']) ?></td>
                    <td><?= e($payment['biller_name']) ?></td>
                    <td><?= e(money($payment['amount'])) ?></td>
                    <td><a href="<?= e(url('admin/payments.php?id=' . (int) $payment['id'])) ?>"><?= e(status_label((string) $payment['status'])) ?></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentPayments): ?>
                <tr><td colspan="5">No payments yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_shell_end('admin', 'overview'); ?>
