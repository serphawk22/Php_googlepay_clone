<?php
declare(strict_types=1);

/**
 * Resolves the latest bill for an account.
 * When Razorpay is enabled, fetches live LIC / Bihar electricity data;
 * otherwise reads the local book.
 */
final class BillerGateway
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array|null $context Optional keys: customer_name, customer_phone, ip, allow_local_fallback
     */
    public function fetchLatest(int $billerId, string $accountNumber, ?array $context = null): ?array
    {
        $context = $context ?? [];
        $allowFallback = !array_key_exists('allow_local_fallback', $context)
            || !empty($context['allow_local_fallback']);

        if (RazorpayClient::isEnabled()) {
            $billerStmt = $this->pdo->prepare('SELECT * FROM billers WHERE id = ? LIMIT 1');
            $billerStmt->execute([$billerId]);
            $biller = $billerStmt->fetch();
            if ($biller && RazorpayClient::mappingForBiller($biller)) {
                $client = new RazorpayClient();
                $result = $client->fetchBill($biller, [
                    'account_number' => $accountNumber,
                    'customer_name' => (string) ($context['customer_name'] ?? ''),
                    'customer_phone' => (string) ($context['customer_phone'] ?? ''),
                    'email' => (string) ($context['email'] ?? ''),
                    'ip' => (string) ($context['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')),
                ]);
                if (!empty($result['ok']) && !empty($result['record'])) {
                    $row = $client->upsertLocalRecord($billerId, $result['record']);
                    $row['bbps_request_id'] = (string) ($result['record']['bbps_request_id'] ?? '');
                    return $row;
                }
                $GLOBALS['serp_last_bbps_error'] = (string) ($result['message'] ?? 'Razorpay bill fetch failed.');
                if (!$allowFallback) {
                    return null;
                }
            }
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM biller_records
             WHERE biller_id = ? AND account_number = ? AND is_active = 1
             ORDER BY due_date DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$billerId, $accountNumber]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

function linked_account(int $accountId, ?int $userId = null): ?array
{
    $sql = 'SELECT la.*, bi.name AS biller_name, bi.category
            FROM linked_accounts la
            INNER JOIN billers bi ON bi.id = la.biller_id
            WHERE la.id = ? AND la.status = \'active\'';
    $params = [$accountId];
    if ($userId !== null) {
        $sql .= ' AND la.user_id = ?';
        $params[] = $userId;
    }
    $stmt = db()->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_bill(int $billId, ?int $userId = null): ?array
{
    $sql = bill_select_sql() . ' WHERE b.id = ?';
    $params = [$billId];
    if ($userId !== null) {
        $sql .= ' AND b.user_id = ?';
        $params[] = $userId;
    }
    $stmt = db()->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function schedule_reminders(int $userId, int $billId, string $dueDate, int $remindDays, string $billerName, float $amount): void
{
    $label = money($amount);
    $dates = [];
    if ($remindDays > 0) {
        $lead = date('Y-m-d', strtotime($dueDate . ' -' . $remindDays . ' days'));
        $dates[$lead] = $billerName . ' bill of ' . $label . ' is due on ' . format_date($dueDate) . '.';
    }
    $dates[$dueDate] = $billerName . ' bill of ' . $label . ' is due today.';
    $stmt = db()->prepare('INSERT IGNORE INTO reminders (user_id, bill_id, remind_on, message) VALUES (?, ?, ?, ?)');
    foreach ($dates as $day => $message) {
        $stmt->execute([$userId, $billId, $day, str_clip($message, 255)]);
    }
}

function reschedule_reminders(int $userId, int $billId, string $dueDate, int $remindDays, string $billerName, float $amount): void
{
    db()->prepare("DELETE FROM reminders WHERE bill_id = ? AND status = 'pending'")->execute([$billId]);
    schedule_reminders($userId, $billId, $dueDate, $remindDays, $billerName, $amount);
}

function import_record_for_account(array $account, array $record): array
{
    $pdo = db();
    $userId = (int) $account['user_id'];
    $billerId = (int) $account['biller_id'];
    $existing = $pdo->prepare(
        'SELECT id FROM bills WHERE user_id = ? AND biller_id = ? AND account_number = ? AND billing_period = ? LIMIT 1'
    );
    $existing->execute([$userId, $billerId, (string) $account['account_number'], (string) $record['billing_period']]);
    $existingId = $existing->fetchColumn();
    if ($existingId) {
        $requestId = trim((string) ($record['bbps_request_id'] ?? ''));
        if ($requestId !== '') {
            attach_bbps_request((int) $existingId, $requestId);
        }
        return [
            'ok' => true,
            'created' => false,
            'bill_id' => (int) $existingId,
            'message' => 'This bill is already in the account.',
        ];
    }

    $payable = (float) $record['amount'];
    $status = 'unpaid';
    if ((string) $record['due_date'] < date('Y-m-d')) {
        $payable += (float) $record['late_fee'];
        $status = 'overdue';
    }
    $units = ($record['units'] === null || $record['units'] === '') ? null : (int) $record['units'];

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO bills (
                user_id, linked_account_id, biller_id, master_id, bill_number, billing_period,
                bill_date, due_date, amount, late_fee, units, plan_name, details, customer_name, account_number, status
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $userId,
            (int) $account['id'],
            $billerId,
            (int) $record['id'],
            (string) $record['bill_number'],
            (string) $record['billing_period'],
            (string) $record['bill_date'],
            (string) $record['due_date'],
            number_format((float) $record['amount'], 2, '.', ''),
            number_format((float) $record['late_fee'], 2, '.', ''),
            $units,
            (string) ($record['plan_name'] ?? ''),
            (string) ($record['details'] ?? ''),
            (string) $record['customer_name'],
            (string) $account['account_number'],
            $status,
        ]);
        $billId = (int) $pdo->lastInsertId();
        $requestId = trim((string) ($record['bbps_request_id'] ?? ''));
        if ($requestId !== '') {
            attach_bbps_request($billId, $requestId);
        }
        schedule_reminders(
            $userId,
            $billId,
            (string) $record['due_date'],
            (int) $account['remind_days'],
            (string) $account['biller_name'],
            $payable
        );
        notify(
            $userId,
            'Bill fetched',
            $account['biller_name'] . '  -  ' . $record['billing_period'] . '  -  ' . money($payable) . '. ' . due_label((string) $record['due_date']) . '.',
            'app/bills.php?id=' . $billId
        );
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((string) $e->getCode() === '23000') {
            $existing->execute([$userId, $billerId, (string) $account['account_number'], (string) $record['billing_period']]);
            $existingId = $existing->fetchColumn();
            if ($existingId) {
                return [
                    'ok' => true,
                    'created' => false,
                    'bill_id' => (int) $existingId,
                    'message' => 'This bill is already in the account.',
                ];
            }
        }
        throw $e;
    }

    audit('bill.fetch', 'bill', $billId, $account['account_number'] . ' ' . $record['billing_period']);
    return [
        'ok' => true,
        'created' => true,
        'bill_id' => $billId,
        'message' => 'Fetched the ' . $record['billing_period'] . ' bill.',
    ];
}

function import_latest_bill(int $userId, int $accountId): array
{
    $account = linked_account($accountId, $userId);
    if (!$account) {
        return ['ok' => false, 'message' => 'That linked account was not found.'];
    }
    $userStmt = db()->prepare('SELECT full_name, mobile, email FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $userRow = $userStmt->fetch() ?: [];
    $GLOBALS['serp_last_bbps_error'] = '';
    $liveFetch = RazorpayClient::isEnabled();
    $record = (new BillerGateway(db()))->fetchLatest(
        (int) $account['biller_id'],
        (string) $account['account_number'],
        [
            'customer_name' => (string) ($account['holder_name'] ?: ($userRow['full_name'] ?? '')),
            'customer_phone' => (string) ($userRow['mobile'] ?? ''),
            'email' => (string) ($userRow['email'] ?? ''),
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
            // When a live BBPS connector is on, do not silently fall back to sample desk records.
            'allow_local_fallback' => !$liveFetch,
        ]
    );
    if (!$record) {
        $bbpsError = trim((string) ($GLOBALS['serp_last_bbps_error'] ?? ''));
        if ($bbpsError !== '') {
            return ['ok' => false, 'message' => $bbpsError];
        }
        if (setting('razorpay_key_id') !== '' && setting('razorpay_enabled', '0') !== '1') {
            return ['ok' => false, 'message' => 'Razorpay keys are saved, but bill fetch is turned off. Enable Razorpay in Admin settings. Until then only the sample numbers on the accounts page can be fetched.'];
        }
        return ['ok' => false, 'message' => 'No bill is available for this number yet.'];
    }
    $result = import_record_for_account($account, $record);
    if (!empty($result['created'])) {
        run_reminder_engine(true);
    }
    if (empty($result['created']) && !empty($result['ok'])) {
        $result['message'] = 'Latest bill (' . $record['billing_period'] . ') is already in your account.';
    }
    return $result;
}

function push_record_to_linked_users(int $recordId): int
{
    $stmt = db()->prepare('SELECT * FROM biller_records WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$recordId]);
    $record = $stmt->fetch();
    if (!$record) {
        return 0;
    }
    $accounts = db()->prepare(
        'SELECT la.*, bi.name AS biller_name
         FROM linked_accounts la
         INNER JOIN billers bi ON bi.id = la.biller_id
         WHERE la.biller_id = ? AND la.account_number = ? AND la.status = \'active\''
    );
    $accounts->execute([(int) $record['biller_id'], (string) $record['account_number']]);
    $created = 0;
    foreach ($accounts->fetchAll() as $account) {
        $result = import_record_for_account($account, $record);
        if (!empty($result['created'])) {
            $created++;
        }
    }
    if ($created > 0) {
        run_reminder_engine(true);
    }
    return $created;
}

function run_reminder_engine(bool $force = false): void
{
    try {
        if (!$force && !empty($_SESSION['reminder_ran']) && (int) $_SESSION['reminder_ran'] > time() - 600) {
            return;
        }
        $pdo = db();
        $pdo->exec("UPDATE bills SET status = 'overdue' WHERE status = 'unpaid' AND due_date < CURDATE()");
        $rows = $pdo->query(
            "SELECT r.id, r.user_id, r.bill_id, r.message
             FROM reminders r
             INNER JOIN bills b ON b.id = r.bill_id
             WHERE r.status = 'pending' AND r.remind_on <= CURDATE() AND b.status <> 'paid'
             ORDER BY r.remind_on ASC
             LIMIT 200"
        )->fetchAll();
        $mark = $pdo->prepare("UPDATE reminders SET status = 'sent' WHERE id = ? AND status = 'pending'");
        foreach ($rows as $row) {
            $mark->execute([(int) $row['id']]);
            if ($mark->rowCount() === 1) {
                notify((int) $row['user_id'], 'Payment reminder', (string) $row['message'], 'app/bills.php?id=' . (int) $row['bill_id']);
            }
        }
        $_SESSION['reminder_ran'] = time();
    } catch (Throwable $e) {
        error_log($e->__toString());
    }
}

function open_payment(int $billId): ?array
{
    $stmt = db()->prepare(
        "SELECT * FROM payments WHERE bill_id = ? AND status IN ('initiated', 'pending_verification') ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$billId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function successful_payment(int $billId): ?array
{
    $stmt = db()->prepare("SELECT * FROM payments WHERE bill_id = ? AND status = 'success' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$billId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_payment(int $paymentId, ?int $userId = null): ?array
{
    $sql = 'SELECT p.*, b.bill_number, b.billing_period, b.account_number, b.customer_name, b.due_date,
                   b.amount AS bill_amount, b.late_fee, b.plan_name, b.details, b.units, b.status AS bill_status,
                   bi.name AS biller_name, bi.category, u.full_name, u.email, u.mobile
            FROM payments p
            INNER JOIN bills b ON b.id = p.bill_id
            INNER JOIN billers bi ON bi.id = b.biller_id
            INNER JOIN users u ON u.id = p.user_id
            WHERE p.id = ?';
    $params = [$paymentId];
    if ($userId !== null) {
        $sql .= ' AND p.user_id = ?';
        $params[] = $userId;
    }
    $stmt = db()->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function start_payment(int $userId, array $bill): array
{
    if ((int) $bill['user_id'] !== $userId) {
        return ['ok' => false, 'message' => 'This bill is not in your account.'];
    }
    if (($bill['status'] ?? '') === 'paid') {
        return ['ok' => false, 'message' => 'This bill is already paid.'];
    }
    $vpa = setting('merchant_vpa');
    if (!valid_vpa($vpa)) {
        return ['ok' => false, 'message' => 'Payments are not open yet. Add the merchant UPI ID in admin settings.'];
    }
    $existing = open_payment((int) $bill['id']);
    if ($existing) {
        return [
            'ok' => true,
            'created' => false,
            'payment_id' => (int) $existing['id'],
            'message' => 'Continue the payment already in progress.',
        ];
    }
    $amount = amount_due($bill);
    if ($amount <= 0) {
        return ['ok' => false, 'message' => 'This bill has no payable amount.'];
    }
    $ref = 'SERP' . date('ymdHis') . strtoupper(bin2hex(random_bytes(3)));
    $stmt = db()->prepare(
        "INSERT INTO payments (user_id, bill_id, amount, txn_ref, upi_vpa, status) VALUES (?, ?, ?, ?, ?, 'initiated')"
    );
    $stmt->execute([
        $userId,
        (int) $bill['id'],
        number_format($amount, 2, '.', ''),
        $ref,
        $vpa,
    ]);
    $paymentId = (int) db()->lastInsertId();
    audit('payment.start', 'payment', $paymentId, $ref);
    return [
        'ok' => true,
        'created' => true,
        'payment_id' => $paymentId,
        'message' => 'UPI payment started.',
    ];
}

function submit_utr(int $userId, int $paymentId, string $utr, string $note): array
{
    $utr = strtoupper(clean_text($utr, 22));
    if (!valid_utr($utr)) {
        return ['ok' => false, 'message' => 'Enter the UTR from your UPI app. Use 8 to 22 letters or digits.'];
    }
    $stmt = db()->prepare(
        "UPDATE payments
         SET utr = ?, user_note = ?, status = 'pending_verification', updated_at = NOW()
         WHERE id = ? AND user_id = ? AND status = 'initiated'"
    );
    $stmt->execute([$utr, clean_text($note, 255), $paymentId, $userId]);
    if ($stmt->rowCount() !== 1) {
        return ['ok' => false, 'message' => 'This payment cannot take a UTR now.'];
    }
    $payment = find_payment($paymentId, $userId);
    $label = $payment ? (string) $payment['txn_ref'] : (string) $paymentId;
    notify_admins('UTR submitted', 'Payment ' . $label . ' with UTR ' . $utr . ' is waiting for confirmation.', 'admin/payments.php?status=pending_verification');
    audit('payment.utr', 'payment', $paymentId, $utr);
    return ['ok' => true, 'message' => 'UTR submitted. SERP UPI will confirm the payment after it is checked.'];
}

function cancel_payment(int $userId, int $paymentId): array
{
    $stmt = db()->prepare(
        "UPDATE payments
         SET status = 'failed', admin_note = 'Cancelled by customer', updated_at = NOW()
         WHERE id = ? AND user_id = ? AND status = 'initiated'"
    );
    $stmt->execute([$paymentId, $userId]);
    if ($stmt->rowCount() !== 1) {
        return ['ok' => false, 'message' => 'This payment cannot be cancelled.'];
    }
    audit('payment.cancel', 'payment', $paymentId, '');
    return ['ok' => true, 'message' => 'Payment cancelled. You can start it again.'];
}

function decide_payment(int $paymentId, int $adminId, string $decision, string $utr, string $note): array
{
    if (!in_array($decision, ['success', 'failed'], true)) {
        return ['ok' => false, 'message' => 'Unknown decision.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment || !in_array($payment['status'], ['initiated', 'pending_verification'], true)) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This payment is no longer open.'];
        }
        $billStmt = $pdo->prepare('SELECT * FROM bills WHERE id = ? FOR UPDATE');
        $billStmt->execute([(int) $payment['bill_id']]);
        $bill = $billStmt->fetch();
        if (!$bill || $bill['status'] === 'paid') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'The bill is already settled.'];
        }
        $note = clean_text($note, 255);
        if ($decision === 'success') {
            $finalUtr = strtoupper($utr !== '' ? clean_text($utr, 22) : (string) $payment['utr']);
            if (!valid_utr($finalUtr)) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Enter the UTR (8 to 22 letters or digits) before confirming.'];
            }
            $pdo->prepare(
                "UPDATE payments
                 SET status = 'success', utr = ?, admin_note = ?, verified_by = ?, verified_at = NOW(), updated_at = NOW()
                 WHERE id = ?"
            )->execute([$finalUtr, $note, $adminId, $paymentId]);
            $pdo->prepare("UPDATE bills SET status = 'paid', paid_at = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute([(int) $bill['id']]);
            $pdo->prepare("UPDATE reminders SET status = 'dismissed' WHERE bill_id = ? AND status = 'pending'")
                ->execute([(int) $bill['id']]);
            notify(
                (int) $payment['user_id'],
                'Payment confirmed',
                'Payment ' . $payment['txn_ref'] . ' is confirmed. Your receipt is ready.',
                'app/receipt.php?id=' . $paymentId
            );
            $pdo->commit();
            audit('payment.confirm', 'payment', $paymentId, $finalUtr);
            return ['ok' => true, 'message' => 'Payment confirmed and the bill is marked paid.'];
        }
        $pdo->prepare(
            "UPDATE payments
             SET status = 'failed', admin_note = ?, verified_by = ?, verified_at = NOW(), updated_at = NOW()
             WHERE id = ?"
        )->execute([$note !== '' ? $note : 'Rejected', $adminId, $paymentId]);
        notify(
            (int) $payment['user_id'],
            'Payment not confirmed',
            'Payment ' . $payment['txn_ref'] . ' was not confirmed. You can pay the bill again.',
            'app/pay.php?bill=' . (int) $bill['id']
        );
        $pdo->commit();
        audit('payment.reject', 'payment', $paymentId, $note);
        return ['ok' => true, 'message' => 'Payment rejected. The bill stays unpaid.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function refresh_user_bills(int $userId): array
{
    $stmt = db()->prepare("SELECT id FROM linked_accounts WHERE user_id = ? AND status = 'active' ORDER BY id ASC");
    $stmt->execute([$userId]);
    $created = 0;
    $checked = 0;
    $missing = 0;
    $errors = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $accountId) {
        $checked++;
        $result = import_latest_bill($userId, (int) $accountId);
        if (!empty($result['created'])) {
            $created++;
        } elseif (empty($result['ok'])) {
            $missing++;
            if (!empty($result['message'])) {
                $errors[] = (string) $result['message'];
            }
        }
    }
    return [
        'checked' => $checked,
        'created' => $created,
        'missing' => $missing,
        'errors' => array_values(array_unique($errors)),
    ];
}

function sample_accounts(): array
{
    if (setting('show_sample_hints', '1') !== '1') {
        return [];
    }
    return db()->query(
        "SELECT DISTINCT br.account_number, br.customer_name, bi.name AS biller_name, bi.category
         FROM biller_records br
         INNER JOIN billers bi ON bi.id = br.biller_id
         WHERE br.is_sample = 1 AND br.is_active = 1
         ORDER BY bi.category, bi.name, br.account_number"
    )->fetchAll();
}

function user_has_pin(array $user): bool
{
    return ($user['pin_hash'] ?? '') !== '';
}

function pin_check(PDO $pdo, array $user, string $pin): array
{
    $lockedUntil = $user['pin_locked_until'] ?? null;
    if ($lockedUntil && strtotime((string) $lockedUntil) > time()) {
        return ['ok' => false, 'message' => 'PIN entry is locked for a few minutes. Try again later.'];
    }
    if (($user['pin_hash'] ?? '') === '') {
        return ['ok' => false, 'message' => 'Set a 6-digit SERP UPI PIN in Profile before you pay.'];
    }
    if (!preg_match('/^[0-9]{6}$/', $pin) || !password_verify($pin, (string) $user['pin_hash'])) {
        $attempts = (int) $user['pin_attempts'] + 1;
        $lock = null;
        $message = 'That SERP UPI PIN is incorrect.';
        if ($attempts >= 5) {
            $lock = date('Y-m-d H:i:s', time() + 600);
            $attempts = 0;
            $message = 'Too many incorrect PINs. Entry is locked for 10 minutes.';
        }
        $pdo->prepare('UPDATE users SET pin_attempts = ?, pin_locked_until = ? WHERE id = ?')
            ->execute([$attempts, $lock, (int) $user['id']]);
        return ['ok' => false, 'message' => $message];
    }
    $pdo->prepare('UPDATE users SET pin_attempts = 0, pin_locked_until = NULL WHERE id = ?')
        ->execute([(int) $user['id']]);
    return ['ok' => true, 'message' => ''];
}

function save_user_pin(int $userId, string $pin, ?string $current): array
{
    $rule = pin_rule_error($pin);
    if ($rule !== null) {
        return ['ok' => false, 'message' => $rule];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, pin_hash, pin_attempts, pin_locked_until FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Account not found.'];
        }
        $changing = ($user['pin_hash'] ?? '') !== '';
        if ($changing) {
            $check = pin_check($pdo, $user, (string) $current);
            if (empty($check['ok'])) {
                $pdo->commit();
                return $check;
            }
        }
        $pdo->prepare('UPDATE users SET pin_hash = ?, pin_attempts = 0, pin_locked_until = NULL, updated_at = NOW() WHERE id = ?')
            ->execute([password_hash($pin, PASSWORD_DEFAULT), $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    audit($changing ? 'pin.change' : 'pin.set', 'user', $userId, '');
    return ['ok' => true, 'message' => $changing ? 'SERP UPI PIN changed.' : 'SERP UPI PIN saved. You can pay with it now.'];
}

function clear_user_pin(int $userId): void
{
    db()->prepare('UPDATE users SET pin_hash = \'\', pin_attempts = 0, pin_locked_until = NULL, updated_at = NOW() WHERE id = ?')
        ->execute([$userId]);
    audit('pin.reset', 'user', $userId, '');
}

function wallet_apply(PDO $pdo, int $userId, int $deltaPaise, string $kind, string $note, ?int $paymentId, ?int $actorId): array
{
    $stmt = $pdo->prepare('SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['ok' => false, 'message' => 'Account not found.'];
    }
    $next = money_paise((string) $row['wallet_balance']) + $deltaPaise;
    if ($next < 0) {
        return ['ok' => false, 'message' => 'The SERP wallet does not have enough balance for this payment.'];
    }
    $nextAmount = paise_to_money($next);
    $deltaAmount = paise_to_money($deltaPaise);
    $pdo->prepare('UPDATE users SET wallet_balance = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$nextAmount, $userId]);
    $pdo->prepare(
        'INSERT INTO wallet_entries (user_id, amount, balance_after, kind, note, payment_id, actor_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$userId, $deltaAmount, $nextAmount, $kind, str_clip($note, 255), $paymentId, $actorId]);
    return ['ok' => true, 'message' => '', 'balance' => $nextAmount];
}

function wallet_topup(int $userId, string $amount, string $pin): array
{
    if (!preg_match('/^\d+(\.\d{1,2})?$/', trim($amount))) {
        return ['ok' => false, 'message' => 'Enter an amount in rupees.'];
    }
    $paise = money_paise(trim($amount));
    if ($paise < 100 || $paise > 10000000) {
        return ['ok' => false, 'message' => 'Add between 1.00 and 1,00,000.00 at a time.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, pin_hash, pin_attempts, pin_locked_until FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Account not found.'];
        }
        $check = pin_check($pdo, $user, trim($pin));
        if (empty($check['ok'])) {
            $pdo->commit();
            return $check;
        }
        $applied = wallet_apply($pdo, $userId, $paise, 'topup', 'Added in the SERP wallet', null, $userId);
        if (empty($applied['ok'])) {
            $pdo->rollBack();
            return $applied;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    audit('wallet.topup', 'user', $userId, paise_to_money($paise));
    return ['ok' => true, 'message' => 'Added ' . money(paise_to_money($paise)) . ' to your SERP wallet.'];
}

function admin_adjust_wallet(int $userId, int $adminId, string $direction, string $amount, string $note): array
{
    if (!in_array($direction, ['credit', 'debit'], true)) {
        return ['ok' => false, 'message' => 'Choose add or deduct.'];
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', trim($amount))) {
        return ['ok' => false, 'message' => 'Enter an amount in rupees.'];
    }
    $paise = money_paise(trim($amount));
    if ($paise < 1 || $paise > 1000000000) {
        return ['ok' => false, 'message' => 'Enter an amount greater than zero.'];
    }
    $note = clean_text($note, 255);
    if ($note === '') {
        $note = $direction === 'credit' ? 'Added by admin' : 'Deducted by admin';
    }
    $delta = $direction === 'credit' ? $paise : -$paise;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $applied = wallet_apply($pdo, $userId, $delta, 'adjustment', $note, null, $adminId);
        if (empty($applied['ok'])) {
            $pdo->rollBack();
            return $applied;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    audit('wallet.' . $direction, 'user', $userId, paise_to_money($paise));
    return ['ok' => true, 'message' => 'Wallet updated. Balance is ' . money((string) $applied['balance']) . '.'];
}

function pay_bill_with_pin(int $userId, int $billId, string $pin): array
{
    $pin = trim($pin);
    if (!preg_match('/^[0-9]{6}$/', $pin)) {
        return ['ok' => false, 'message' => 'Enter the 6-digit SERP UPI PIN.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    $paymentId = 0;
    $ref = '';
    try {
        $userStmt = $pdo->prepare('SELECT id, pin_hash, pin_attempts, pin_locked_until, status FROM users WHERE id = ? FOR UPDATE');
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        if (!$user || $user['status'] !== 'active') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This account cannot pay right now.'];
        }
        $check = pin_check($pdo, $user, $pin);
        if (empty($check['ok'])) {
            $pdo->commit();
            return $check;
        }
        $billStmt = $pdo->prepare('SELECT * FROM bills WHERE id = ? AND user_id = ? FOR UPDATE');
        $billStmt->execute([$billId, $userId]);
        $bill = $billStmt->fetch();
        if (!$bill) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This bill is not in your account.'];
        }
        if ($bill['status'] === 'paid') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This bill is already paid.'];
        }
        $amountPaise = money_paise(number_format(amount_due($bill), 2, '.', ''));
        if ($amountPaise <= 0) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This bill has no payable amount.'];
        }
        $applied = wallet_apply($pdo, $userId, -$amountPaise, 'bill', 'Bill ' . $bill['bill_number'], null, $userId);
        if (empty($applied['ok'])) {
            $pdo->rollBack();
            return $applied;
        }
        $pdo->prepare(
            "UPDATE payments SET status = 'failed', admin_note = 'Closed when the bill was paid in SERP UPI', updated_at = NOW()
             WHERE bill_id = ? AND status IN ('initiated', 'pending_verification')"
        )->execute([$billId]);
        $ref = 'SERP' . date('ymdHis') . strtoupper(bin2hex(random_bytes(3)));
        $amount = paise_to_money($amountPaise);
        $pdo->prepare(
            "INSERT INTO payments (user_id, bill_id, amount, txn_ref, upi_vpa, status, verified_at)
             VALUES (?, ?, ?, ?, 'SERP wallet', 'success', NOW())"
        )->execute([$userId, $billId, $amount, $ref]);
        $paymentId = (int) $pdo->lastInsertId();
        $entryStmt = $pdo->prepare(
            "SELECT id FROM wallet_entries WHERE user_id = ? AND payment_id IS NULL AND kind = 'bill' ORDER BY id DESC LIMIT 1"
        );
        $entryStmt->execute([$userId]);
        $entryId = (int) $entryStmt->fetchColumn();
        if ($entryId > 0) {
            $pdo->prepare('UPDATE wallet_entries SET payment_id = ? WHERE id = ?')->execute([$paymentId, $entryId]);
        }
        $pdo->prepare("UPDATE bills SET status = 'paid', paid_at = NOW(), updated_at = NOW() WHERE id = ?")
            ->execute([$billId]);
        $pdo->prepare("UPDATE reminders SET status = 'dismissed' WHERE bill_id = ? AND status = 'pending'")
            ->execute([$billId]);
        notify(
            $userId,
            'Payment complete',
            'Payment ' . $ref . ' is complete. Your receipt is ready.',
            'app/receipt.php?id=' . $paymentId
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    audit('payment.pin', 'payment', $paymentId, $ref);
    return [
        'ok' => true,
        'payment_id' => $paymentId,
        'message' => 'Payment complete. The bill is marked paid.',
    ];
}

function refund_payment(int $paymentId, int $adminId, string $note): array
{
    $note = clean_text($note, 255);
    if ($note === '') {
        $note = 'Refunded to the SERP wallet';
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment || $payment['status'] !== 'success') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Only a completed SERP payment can be refunded.'];
        }
        $billStmt = $pdo->prepare('SELECT * FROM bills WHERE id = ? FOR UPDATE');
        $billStmt->execute([(int) $payment['bill_id']]);
        $bill = $billStmt->fetch();
        if (!$bill || $bill['status'] !== 'paid') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'The bill is not in a paid state.'];
        }
        $paise = money_paise((string) $payment['amount']);
        $applied = wallet_apply($pdo, (int) $payment['user_id'], $paise, 'refund', $note, $paymentId, $adminId);
        if (empty($applied['ok'])) {
            $pdo->rollBack();
            return $applied;
        }
        $pdo->prepare(
            "UPDATE payments SET status = 'refunded', admin_note = ?, verified_by = ?, verified_at = NOW(), updated_at = NOW() WHERE id = ?"
        )->execute([$note, $adminId, $paymentId]);
        $status = ((string) $bill['due_date'] < date('Y-m-d')) ? 'overdue' : 'unpaid';
        $pdo->prepare("UPDATE bills SET status = ?, paid_at = NULL, updated_at = NOW() WHERE id = ?")
            ->execute([$status, (int) $bill['id']]);
        $daysStmt = $pdo->prepare('SELECT remind_days FROM linked_accounts WHERE id = ? LIMIT 1');
        $daysStmt->execute([(int) $bill['linked_account_id']]);
        $days = (int) $daysStmt->fetchColumn();
        $billerStmt = $pdo->prepare('SELECT name FROM billers WHERE id = ? LIMIT 1');
        $billerStmt->execute([(int) $bill['biller_id']]);
        $billerName = (string) $billerStmt->fetchColumn();
        reschedule_reminders(
            (int) $bill['user_id'],
            (int) $bill['id'],
            (string) $bill['due_date'],
            $days,
            $billerName !== '' ? $billerName : 'Bill',
            (float) $payment['amount']
        );
        notify(
            (int) $payment['user_id'],
            'Payment refunded',
            'Payment ' . $payment['txn_ref'] . ' was returned to your SERP wallet. The bill is unpaid again.',
            'app/pay.php?bill=' . (int) $bill['id']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    audit('payment.refund', 'payment', $paymentId, $note);
    return ['ok' => true, 'message' => 'Payment refunded to the SERP wallet. The bill is unpaid again.'];
}

function start_razorpay_checkout(int $userId, array $bill): array
{
    if (!RazorpayClient::isEnabled()) {
        return ['ok' => false, 'message' => 'Razorpay is not enabled. Add the Key ID and secret in admin settings.'];
    }
    if ((int) $bill['user_id'] !== $userId) {
        return ['ok' => false, 'message' => 'This bill is not in your account.'];
    }
    if (($bill['status'] ?? '') === 'paid') {
        return ['ok' => false, 'message' => 'This bill is already paid.'];
    }
    $amountPaise = money_paise(number_format(amount_due($bill), 2, '.', ''));
    if ($amountPaise < 100) {
        return ['ok' => false, 'message' => 'This bill has no payable amount.'];
    }
    $client = new RazorpayClient();
    $order = $client->createOrder($amountPaise, 'SERP' . (int) $bill['id'] . date('His'), [
        'bill_id' => (string) $bill['id'],
        'user_id' => (string) $userId,
    ]);
    if (empty($order['ok'])) {
        return $order;
    }
    db()->prepare(
        "INSERT INTO payments (user_id, bill_id, amount, txn_ref, upi_vpa, gateway, status)
         VALUES (?, ?, ?, ?, 'Razorpay', 'razorpay', 'initiated')"
    )->execute([
        $userId,
        (int) $bill['id'],
        number_format($amountPaise / 100, 2, '.', ''),
        (string) $order['order_id'],
    ]);
    $paymentId = (int) db()->lastInsertId();
    audit('payment.razorpay.start', 'payment', $paymentId, (string) $order['order_id']);
    return [
        'ok' => true,
        'payment_id' => $paymentId,
        'order_id' => (string) $order['order_id'],
        'amount' => $amountPaise,
        'key' => (string) $order['key'],
    ];
}

function complete_razorpay_payment(int $userId, string $orderId, string $razorpayPaymentId, string $signature): array
{
    $client = new RazorpayClient();
    if (!$client->verifySignature($orderId, $razorpayPaymentId, $signature)) {
        return ['ok' => false, 'message' => 'Payment signature did not match. The payment was not accepted.'];
    }
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT * FROM payments WHERE txn_ref = ? AND user_id = ? AND gateway = 'razorpay' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$orderId, $userId]);
    $payment = $stmt->fetch();
    if (!$payment) {
        return ['ok' => false, 'message' => 'This Razorpay order is not in your account.'];
    }
    if ($payment['status'] === 'success') {
        return ['ok' => true, 'payment_id' => (int) $payment['id'], 'message' => 'This payment is already complete.'];
    }
    $bill = find_bill((int) $payment['bill_id'], $userId);
    if (!$bill) {
        return ['ok' => false, 'message' => 'The bill for this payment was not found.'];
    }
    $fetched = $client->fetchPayment($razorpayPaymentId);
    if (empty($fetched['ok'])) {
        return ['ok' => false, 'message' => (string) ($fetched['message'] ?? 'Could not confirm the Razorpay payment.')];
    }
    $pg = $fetched['data'];
    if (($pg['status'] ?? '') !== 'captured' && ($pg['status'] ?? '') !== 'authorized') {
        return ['ok' => false, 'message' => 'Razorpay has not captured this payment yet.'];
    }
    $user = current_user(true) ?? [];
    $settled = $client->settleBill(
        $bill,
        $pg,
        (string) ($user['mobile'] ?? ''),
        (string) ($user['email'] ?? ''),
        (string) ($user['full_name'] ?? '')
    );
    if (empty($settled['ok'])) {
        $refund = $client->refundPayment($razorpayPaymentId, (int) ($pg['amount'] ?? 0));
        $note = (string) ($settled['message'] ?? 'BBPS settlement failed.');
        if (!empty($refund['ok'])) {
            $note .= ' The Razorpay payment was refunded.';
        } else {
            $note .= ' Automatic refund did not complete. Check the Razorpay dashboard.';
        }
        $pdo->prepare(
            "UPDATE payments SET status = 'failed', utr = ?, gateway_ref = ?, admin_note = ?, updated_at = NOW() WHERE id = ?"
        )->execute([
            str_clip($razorpayPaymentId, 22),
            str_clip((string) ($settled['bill_payment_id'] ?? ''), 64),
            str_clip($note, 255),
            (int) $payment['id'],
        ]);
        return ['ok' => false, 'message' => $note];
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE payments
             SET status = 'success', utr = ?, gateway_ref = ?, verified_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status = 'initiated'"
        )->execute([
            str_clip($razorpayPaymentId, 22),
            str_clip((string) ($settled['gateway_txn'] !== '' ? $settled['gateway_txn'] : $settled['bill_payment_id']), 64),
            (int) $payment['id'],
        ]);
        $pdo->prepare("UPDATE bills SET status = 'paid', paid_at = NOW(), updated_at = NOW() WHERE id = ? AND status <> 'paid'")
            ->execute([(int) $bill['id']]);
        $pdo->prepare("UPDATE reminders SET status = 'dismissed' WHERE bill_id = ? AND status = 'pending'")
            ->execute([(int) $bill['id']]);
        notify(
            $userId,
            'Payment complete',
            'Razorpay payment ' . $razorpayPaymentId . ' is complete. Your receipt is ready.',
            'app/receipt.php?id=' . (int) $payment['id']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    audit('payment.razorpay', 'payment', (int) $payment['id'], $razorpayPaymentId);
    return [
        'ok' => true,
        'payment_id' => (int) $payment['id'],
        'message' => 'Payment complete. The bill is marked paid.',
    ];
}

function active_billers(?string $category = null): array
{
    if ($category === null) {
        return db()->query("SELECT * FROM billers WHERE status = 'active' ORDER BY category, name")->fetchAll();
    }
    $stmt = db()->prepare("SELECT * FROM billers WHERE status = 'active' AND category = ? ORDER BY name");
    $stmt->execute([$category]);
    return $stmt->fetchAll();
}
