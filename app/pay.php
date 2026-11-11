<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
$userId = (int) $user['id'];
$billId = (int) ($_GET['bill'] ?? $_POST['bill_id'] ?? 0);
$bill = find_bill($billId, $userId);
if (!$bill) {
    fail_not_found('user');
}

$checkout = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    if (field('action') === 'pay') {
        $result = pay_bill_with_pin($userId, $billId, field('serp_pin'));
        if (!empty($result['ok']) && !empty($result['payment_id'])) {
            flash('success', (string) $result['message']);
            redirect('app/receipt.php?id=' . (int) $result['payment_id']);
        }
        flash('error', (string) ($result['message'] ?? 'Payment could not be completed.'));
    } elseif (field('action') === 'razorpay') {
        $result = start_razorpay_checkout($userId, $bill);
        if (!empty($result['ok'])) {
            $checkout = $result;
        } else {
            flash('error', (string) ($result['message'] ?? 'Could not start Razorpay.'));
            redirect('app/pay.php?bill=' . $billId);
        }
    }
    if ($checkout === null) {
        redirect('app/pay.php?bill=' . $billId);
    }
}

$paid = successful_payment($billId);
$fresh = current_user(true) ?? $user;
$balancePaise = money_paise((string) ($fresh['wallet_balance'] ?? '0'));
$duePaise = money_paise(number_format(amount_due($bill), 2, '.', ''));
$hasPin = user_has_pin($fresh);

render_shell_start('Pay bill', 'bills', 'user', (string) $bill['biller_name'] . ' · ' . $bill['account_number']);
?>
<div class="split">
    <section class="panel">
        <?php render_bill_summary($bill); ?>
    </section>
    <section class="panel stack">
        <h2 style="font-size:26px">Pay this bill</h2>
        <?php if (RazorpayClient::isEnabled() && $bill['status'] !== 'paid'): ?>
            <p class="notice">Pay with Razorpay (UPI, card, or netbanking). After the payment is captured, SERP sends it to BBPS for LIC or the electricity biller.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="razorpay">
                <input type="hidden" name="bill_id" value="<?= $billId ?>">
                <button class="btn btn-primary" type="submit">Pay <?= e(money(amount_due($bill))) ?> with Razorpay</button>
            </form>
        <?php endif; ?>
        <p class="notice">Or enter the 6-digit SERP UPI PIN to pay from your SERP wallet. Do not type a bank, GPay, or PhonePe PIN.</p>
        <?php if ($bill['status'] === 'paid' && $paid): ?>
            <p>This bill is paid.</p>
            <a class="btn btn-primary" href="<?= e(url('app/receipt.php?id=' . (int) $paid['id'])) ?>">Open receipt</a>
        <?php elseif (!$hasPin): ?>
            <p>Set a 6-digit SERP UPI PIN before the first payment.</p>
            <a class="btn btn-primary" href="<?= e(url('app/profile.php')) ?>#pin">Create PIN</a>
        <?php elseif ($balancePaise < $duePaise): ?>
            <p>This bill needs <strong><?= e(money(amount_due($bill))) ?></strong>. Your SERP wallet has <strong><?= e(money(paise_to_money($balancePaise))) ?></strong>.</p>
            <a class="btn btn-primary" href="<?= e(url('app/wallet.php')) ?>">Add money</a>
        <?php else: ?>
            <p>You will pay <strong><?= e(money(amount_due($bill))) ?></strong> from a wallet balance of <strong><?= e(money(paise_to_money($balancePaise))) ?></strong>.</p>
            <form method="post" class="stack" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pay">
                <input type="hidden" name="bill_id" value="<?= $billId ?>">
                <?php render_pin_field('serp_pin', 'SERP UPI PIN', 'serp_pin'); ?>
                <button class="btn btn-primary" type="submit">Pay now</button>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php if (is_array($checkout)): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(function () {
  var options = {
    key: <?= json_encode((string) $checkout['key']) ?>,
    amount: <?= (int) $checkout['amount'] ?>,
    currency: 'INR',
    name: <?= json_encode(setting('merchant_name', 'SERP UPI')) ?>,
    description: <?= json_encode((string) $bill['biller_name'] . ' ' . $bill['bill_number']) ?>,
    order_id: <?= json_encode((string) $checkout['order_id']) ?>,
    prefill: {
      name: <?= json_encode((string) $fresh['full_name']) ?>,
      email: <?= json_encode((string) $fresh['email']) ?>,
      contact: <?= json_encode((string) $fresh['mobile']) ?>
    },
    theme: { color: '#1f4d3a' },
    callback_url: <?= json_encode(absolute_url('app/razorpay_return.php')) ?>,
    redirect: true
  };
  var rzp = new Razorpay(options);
  rzp.on('payment.failed', function () {
    window.location = <?= json_encode(url('app/pay.php?bill=' . $billId)) ?>;
  });
  rzp.open();
})();
</script>
<?php endif; ?>
<?php render_shell_end('user', 'bills'); ?>
