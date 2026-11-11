<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
$paymentId = (int) ($_GET['id'] ?? 0);
$payment = find_payment($paymentId, $user['role'] === 'admin' ? null : (int) $user['id']);
if (!$payment) {
    fail_not_found($user['role'] === 'admin' ? 'admin' : 'user');
}

$mode = $user['role'] === 'admin' ? 'admin' : 'user';
render_shell_start('Receipt', $mode === 'admin' ? 'payments' : 'history', $mode, $payment['txn_ref']);
?>
<article class="receipt">
    <header class="receipt-head">
        <span class="mark" aria-hidden="true">₹</span>
        <div>
            <h2>SERP UPI</h2>
            <p class="muted">Payment receipt</p>
        </div>
    </header>
    <?php if ($payment['status'] !== 'success'): ?>
        <p class="flash flash-info">This payment is <?= e(status_label((string) $payment['status'])) ?>.</p>
    <?php else: ?>
        <p class="flash flash-success">Paid with your SERP UPI PIN.</p>
    <?php endif; ?>
    <dl class="facts">
        <div><dt>Receipt</dt><dd><?= e($payment['txn_ref']) ?></dd></div>
        <div><dt>Status</dt><dd><?= e(status_label((string) $payment['status'])) ?></dd></div>
        <div><dt>Biller</dt><dd><?= e($payment['biller_name']) ?></dd></div>
        <div><dt>Account</dt><dd><?= e($payment['account_number']) ?></dd></div>
        <div><dt>Name on bill</dt><dd><?= e($payment['customer_name']) ?></dd></div>
        <div><dt>Period</dt><dd><?= e($payment['billing_period']) ?></dd></div>
        <div><dt>Bill number</dt><dd><?= e($payment['bill_number']) ?></dd></div>
        <div><dt>Amount</dt><dd><?= e(money($payment['amount'])) ?></dd></div>
        <div><dt>Paid from</dt><dd><?= e($payment['upi_vpa'] !== '' ? (string) $payment['upi_vpa'] : 'SERP wallet') ?></dd></div>
        <?php if (!empty($payment['utr'])): ?>
            <div><dt>UTR</dt><dd><?= e((string) $payment['utr']) ?></dd></div>
        <?php else: ?>
            <div><dt>Authorised by</dt><dd>SERP UPI PIN</dd></div>
        <?php endif; ?>
        <div><dt>Customer</dt><dd><?= e($payment['full_name']) ?></dd></div>
        <div><dt>Confirmed</dt><dd><?= e(format_when($payment['verified_at'] ? (string) $payment['verified_at'] : null)) ?></dd></div>
    </dl>
    <?php if ($payment['admin_note'] !== ''): ?>
        <p style="margin-top:14px"><strong>Desk note.</strong> <?= e($payment['admin_note']) ?></p>
    <?php endif; ?>
    <p class="muted" style="margin-top:16px">This receipt shows a payment taken from the SERP wallet after the SERP UPI PIN was accepted. Keep it with the policy or electricity papers.</p>
    <?php if (setting('support_email') !== '' || setting('support_phone') !== ''): ?>
        <p class="muted">Desk: <?= e(trim(setting('support_email') . ' ' . setting('support_phone'))) ?></p>
    <?php endif; ?>
    <div class="cluster no-print" style="margin-top:16px">
        <button class="btn btn-primary" type="button" onclick="window.print()">Print</button>
        <a class="btn btn-ghost" href="<?= e(url('app/history.php')) ?>">Back to payments</a>
    </div>
</article>
<?php render_shell_end($mode, $mode === 'admin' ? 'payments' : 'history'); ?>
