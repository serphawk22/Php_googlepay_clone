<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
$userId = (int) $user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    if (field('action') === 'refresh') {
        $result = refresh_user_bills($userId);
        if ($result['checked'] === 0) {
            flash('error', 'Link an LIC policy or an electricity connection before fetching bills.');
        } elseif ($result['created'] === 0 && !empty($result['errors'])) {
            flash('error', 'Fetch failed: ' . $result['errors'][0]);
        } else {
            $msg = 'Checked ' . $result['checked'] . ' account(s) and fetched ' . $result['created'] . ' new bill(s).';
            if (!empty($result['errors'])) {
                $msg .= ' Some accounts failed: ' . $result['errors'][0];
            }
            flash($result['created'] > 0 || empty($result['errors']) ? 'success' : 'error', $msg);
        }
        redirect('app/index.php');
    }
}

$dueStmt = db()->prepare(bill_select_sql() . " WHERE b.user_id = ? AND b.status IN ('unpaid', 'overdue') ORDER BY b.due_date ASC");
$dueStmt->execute([$userId]);
$due = $dueStmt->fetchAll();
$dueTotal = 0.0;
$urgent = 0;
foreach ($due as $bill) {
    $dueTotal += amount_due($bill);
    if (days_until((string) $bill['due_date']) <= 3) {
        $urgent++;
    }
}

$recent = db()->prepare(
    "SELECT p.*, bi.name AS biller_name
     FROM payments p
     INNER JOIN bills b ON b.id = p.bill_id
     INNER JOIN billers bi ON bi.id = b.biller_id
     WHERE p.user_id = ?
     ORDER BY p.id DESC
     LIMIT 5"
);
$recent->execute([$userId]);
$payments = $recent->fetchAll();

$accountCount = db()->prepare("SELECT COUNT(*) FROM linked_accounts WHERE user_id = ? AND status = 'active'");
$accountCount->execute([$userId]);
$accounts = (int) $accountCount->fetchColumn();

render_shell_start(greeting() . ', ' . first_name((string) $user['full_name']), 'home', 'user', 'LIC premiums and Bihar electricity, with reminders before the due date.');
?>
<section class="due-hero">
    <div>
        <p>Open bills</p>
        <strong><?= e(money($dueTotal)) ?></strong>
        <span><?= (int) count($due) ?> bill<?= count($due) === 1 ? '' : 's' ?> waiting</span>
    </div>
    <a class="btn" href="<?= e(url('app/bills.php')) ?>">Review bills</a>
</section>

<?php if ($urgent > 0): ?>
    <div class="notice" style="margin-top:16px">
        <strong><?= (int) $urgent ?> bill<?= $urgent === 1 ? ' needs' : 's need' ?> attention.</strong>
        <p class="muted">Due today, overdue, or due within 3 days. Open the bill and pay it with your SERP UPI PIN.</p>
    </div>
<?php endif; ?>

<div class="cluster" style="margin:16px 0">
    <a class="btn btn-primary" href="<?= e(url('app/accounts.php')) ?>">Link an account</a>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="refresh">
        <button class="btn btn-ghost" type="submit">Fetch latest bills</button>
    </form>
</div>

<?php if (!$due): ?>
    <div class="empty">
        <h2>No open bills</h2>
        <p class="muted"><?= $accounts > 0 ? 'You are up to date, or the biller has not published a new bill yet.' : 'Link an LIC policy or a Bihar electricity consumer number to begin.' ?></p>
    </div>
<?php else: ?>
    <div class="card-grid">
        <?php foreach ($due as $bill) { render_bill_card($bill); } ?>
    </div>
<?php endif; ?>

<div class="split" style="margin-top:18px">
    <section class="panel">
        <h2 style="font-size:24px">Recent payments</h2>
        <?php if (!$payments): ?>
            <p class="muted" style="margin-top:8px">Payments you make with your SERP UPI PIN show up here.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>When</th><th>Biller</th><th>Amount</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?= e(format_when((string) $payment['created_at'])) ?></td>
                            <td><?= e($payment['biller_name']) ?></td>
                            <td><?= e(money($payment['amount'])) ?></td>
                            <td><span class="badge badge-<?= e($payment['status']) ?>"><?= e(status_label((string) $payment['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p style="margin-top:12px"><a href="<?= e(url('app/history.php')) ?>">Full history</a></p>
    </section>
    <section class="panel">
        <h2 style="font-size:24px">On this desk</h2>
        <div class="stat-grid" style="margin-top:12px">
            <div class="stat"><span>Wallet</span><strong><?= e(money($user['wallet_balance'] ?? 0)) ?></strong></div>
            <div class="stat"><span>Linked</span><strong><?= $accounts ?></strong></div>
            <div class="stat"><span>Open</span><strong><?= (int) count($due) ?></strong></div>
        </div>
        <p class="muted" style="margin-top:14px"><?= (int) $accounts ?> active connection<?= $accounts === 1 ? '' : 's' ?>. Reminders are checked when you sign in, and they appear under Notifications.</p>
        <p style="margin-top:12px"><a href="<?= e(url('app/reminders.php')) ?>">See reminders</a></p>
    </section>
</div>
<?php render_shell_end('user', 'home'); ?>
