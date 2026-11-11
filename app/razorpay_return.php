<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
$userId = (int) $user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('app/bills.php');
}

$orderId = clean_text((string) ($_POST['razorpay_order_id'] ?? ''), 64);
$paymentId = clean_text((string) ($_POST['razorpay_payment_id'] ?? ''), 64);
$signature = clean_text((string) ($_POST['razorpay_signature'] ?? ''), 128);

$result = complete_razorpay_payment($userId, $orderId, $paymentId, $signature);
if (!empty($result['ok']) && !empty($result['payment_id'])) {
    flash('success', (string) $result['message']);
    redirect('app/receipt.php?id=' . (int) $result['payment_id']);
}
flash('error', (string) ($result['message'] ?? 'Razorpay payment could not be completed.'));
redirect('app/bills.php');
