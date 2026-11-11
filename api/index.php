<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$action = trim((string) api_in('action', ''));
if ($action === '') {
    $action = 'health';
}

try {
    api_ensure_schema();
} catch (Throwable $e) {
    api_out(['success' => false, 'message' => 'Database is not reachable. Start MySQL in XAMPP.'], 500);
}

try {
    if ($action === 'health') {
        api_out([
            'success' => true,
            'service' => 'SERP UPI API',
            'time' => date('c'),
            'razorpay' => RazorpayClient::isEnabled(),
        ]);
    }

    if ($action === 'login') {
        $email = clean_text((string) api_in('email'), 190);
        $password = (string) api_in('password');
        if (!valid_email($email) || $password === '') {
            api_out(['success' => false, 'message' => 'Enter email and password.'], 400);
        }
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        $dummy = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
        if (!$user || !password_verify($password, (string) ($user['password_hash'] ?? $dummy))) {
            api_out(['success' => false, 'message' => 'Email or password is incorrect.'], 401);
        }
        if ($user['status'] !== 'active') {
            api_out(['success' => false, 'message' => 'This account is suspended.'], 403);
        }
        db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);
        $token = api_issue_token((int) $user['id']);
        audit('api.login', 'user', (int) $user['id'], (string) $user['email']);
        api_out([
            'success' => true,
            'message' => 'Signed in.',
            'token' => $token,
            'user' => api_public_user($user),
        ]);
    }

    if ($action === 'logout') {
        api_revoke_token(api_bearer_token());
        api_out(['success' => true, 'message' => 'Signed out.']);
    }

    if ($action === 'me') {
        $user = api_require_user();
        api_out(['success' => true, 'user' => api_public_user($user)]);
    }

    if ($action === 'home') {
        $user = api_require_user();
        $userId = (int) $user['id'];
        $dueStmt = db()->prepare(
            bill_select_sql() . " WHERE b.user_id = ? AND b.status IN ('unpaid', 'overdue') ORDER BY b.due_date ASC LIMIT 20"
        );
        $dueStmt->execute([$userId]);
        $due = array_map('api_bill_row', $dueStmt->fetchAll());
        $total = 0.0;
        foreach ($due as $bill) {
            $total += (float) $bill['amount_due'];
        }
        $accounts = db()->prepare("SELECT COUNT(*) FROM linked_accounts WHERE user_id = ? AND status = 'active'");
        $accounts->execute([$userId]);
        api_out([
            'success' => true,
            'user' => api_public_user($user),
            'due_total' => number_format($total, 2, '.', ''),
            'due_total_label' => money($total),
            'accounts' => (int) $accounts->fetchColumn(),
            'bills' => $due,
            'razorpay_enabled' => RazorpayClient::isEnabled(),
        ]);
    }

    if ($action === 'billers') {
        api_require_user();
        $rows = active_billers();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'category' => (string) $row['category'],
                'category_label' => category_label((string) $row['category']),
                'region' => (string) $row['region'],
                'description' => (string) $row['description'],
                'bbps_biller_id' => (string) ($row['bbps_biller_id'] ?? ''),
                'bbps_customer_param' => (string) ($row['bbps_customer_param'] ?? ''),
            ];
        }
        api_out(['success' => true, 'billers' => $out]);
    }

    if ($action === 'accounts') {
        $user = api_require_user();
        $stmt = db()->prepare(
            "SELECT la.*, bi.name AS biller_name, bi.category, bi.region
             FROM linked_accounts la
             INNER JOIN billers bi ON bi.id = la.biller_id
             WHERE la.user_id = ? AND la.status = 'active'
             ORDER BY la.id DESC"
        );
        $stmt->execute([(int) $user['id']]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'biller_id' => (int) $row['biller_id'],
                'biller_name' => (string) $row['biller_name'],
                'category' => (string) $row['category'],
                'region' => (string) $row['region'],
                'account_number' => (string) $row['account_number'],
                'holder_name' => (string) $row['holder_name'],
                'nickname' => (string) $row['nickname'],
                'remind_days' => (int) $row['remind_days'],
            ];
        }
        api_out(['success' => true, 'accounts' => $out]);
    }

    if ($action === 'link_account') {
        $user = api_require_user();
        $userId = (int) $user['id'];
        $billerId = (int) api_in('biller_id');
        $number = clean_text((string) api_in('account_number'), 16);
        $holder = clean_text((string) api_in('holder_name'), 120);
        $nickname = clean_text((string) api_in('nickname'), 40);
        $remindDays = (int) api_in('remind_days', setting('default_remind_days', '3'));
        $billerStmt = db()->prepare("SELECT * FROM billers WHERE id = ? AND status = 'active' LIMIT 1");
        $billerStmt->execute([$billerId]);
        $biller = $billerStmt->fetch();
        if (!$biller) {
            api_out(['success' => false, 'message' => 'Choose a biller.'], 400);
        }
        if (!valid_account_number($number)) {
            api_out(['success' => false, 'message' => 'Enter the policy or consumer number, 8 to 16 digits.'], 400);
        }
        if (!valid_name($holder)) {
            api_out(['success' => false, 'message' => 'Enter the name on the policy or bill.'], 400);
        }
        if (!in_array($remindDays, remind_day_options(), true)) {
            $remindDays = 3;
        }
        $find = db()->prepare('SELECT id, status FROM linked_accounts WHERE user_id = ? AND biller_id = ? AND account_number = ? LIMIT 1');
        $find->execute([$userId, $billerId, $number]);
        $existing = $find->fetch();
        if ($existing && $existing['status'] === 'active') {
            api_out(['success' => false, 'message' => 'That account is already linked.'], 409);
        }
        if ($existing) {
            db()->prepare(
                "UPDATE linked_accounts
                 SET holder_name = ?, nickname = ?, remind_days = ?, status = 'active', updated_at = NOW()
                 WHERE id = ?"
            )->execute([$holder, $nickname, $remindDays, (int) $existing['id']]);
            $accountId = (int) $existing['id'];
        } else {
            db()->prepare(
                'INSERT INTO linked_accounts (user_id, biller_id, account_number, holder_name, nickname, remind_days)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$userId, $billerId, $number, $holder, $nickname, $remindDays]);
            $accountId = (int) db()->lastInsertId();
        }
        audit('api.account.link', 'linked_account', $accountId, $number);
        $imported = import_latest_bill($userId, $accountId);
        api_out([
            'success' => true,
            'message' => 'Account linked.',
            'account_id' => $accountId,
            'fetch' => $imported,
        ]);
    }

    if ($action === 'fetch_bill') {
        $user = api_require_user();
        $accountId = (int) api_in('account_id');
        $result = import_latest_bill((int) $user['id'], $accountId);
        api_out([
            'success' => !empty($result['ok']),
            'message' => (string) ($result['message'] ?? 'Fetch finished.'),
            'bill_id' => (int) ($result['bill_id'] ?? 0),
            'created' => !empty($result['created']),
        ], !empty($result['ok']) ? 200 : 400);
    }

    if ($action === 'fetch_all') {
        $user = api_require_user();
        $result = refresh_user_bills((int) $user['id']);
        $ok = ($result['created'] > 0) || empty($result['errors']);
        $message = 'Checked ' . $result['checked'] . ' account(s) and fetched ' . $result['created'] . ' new bill(s).';
        if (!empty($result['errors'])) {
            $message .= ' ' . $result['errors'][0];
            if ($result['created'] === 0) {
                $ok = false;
            }
        }
        api_out([
            'success' => $ok || $result['checked'] > 0,
            'message' => $message,
            'checked' => $result['checked'],
            'created' => $result['created'],
            'missing' => $result['missing'],
            'errors' => $result['errors'],
        ], $ok || $result['checked'] > 0 ? 200 : 400);
    }

    if ($action === 'bills') {
        $user = api_require_user();
        $status = (string) api_in('status', 'due');
        if (!in_array($status, ['due', 'paid', 'all'], true)) {
            $status = 'due';
        }
        $sql = bill_select_sql() . ' WHERE b.user_id = ?';
        $params = [(int) $user['id']];
        if ($status === 'due') {
            $sql .= " AND b.status IN ('unpaid', 'overdue')";
        } elseif ($status === 'paid') {
            $sql .= " AND b.status = 'paid'";
        }
        $sql .= ' ORDER BY b.due_date ASC, b.id DESC LIMIT 100';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        api_out([
            'success' => true,
            'status' => $status,
            'bills' => array_map('api_bill_row', $stmt->fetchAll()),
        ]);
    }

    if ($action === 'bill') {
        $user = api_require_user();
        $bill = find_bill((int) api_in('bill_id'), (int) $user['id']);
        if (!$bill) {
            api_out(['success' => false, 'message' => 'Bill not found.'], 404);
        }
        api_out([
            'success' => true,
            'bill' => api_bill_row($bill),
            'razorpay_enabled' => RazorpayClient::isEnabled(),
            'can_wallet_pay' => user_has_pin($user) && money_paise((string) $user['wallet_balance']) >= money_paise(number_format(amount_due($bill), 2, '.', '')),
        ]);
    }

    if ($action === 'pay_wallet') {
        $user = api_require_user();
        $billId = (int) api_in('bill_id');
        $pin = (string) api_in('pin');
        $result = pay_bill_with_pin((int) $user['id'], $billId, $pin);
        api_out([
            'success' => !empty($result['ok']),
            'message' => (string) ($result['message'] ?? 'Payment failed.'),
            'payment_id' => (int) ($result['payment_id'] ?? 0),
        ], !empty($result['ok']) ? 200 : 400);
    }

    if ($action === 'razorpay_order') {
        $user = api_require_user();
        $bill = find_bill((int) api_in('bill_id'), (int) $user['id']);
        if (!$bill) {
            api_out(['success' => false, 'message' => 'Bill not found.'], 404);
        }
        $result = start_razorpay_checkout((int) $user['id'], $bill);
        if (empty($result['ok'])) {
            api_out(['success' => false, 'message' => (string) ($result['message'] ?? 'Could not start Razorpay.')], 400);
        }
        api_out([
            'success' => true,
            'payment_id' => (int) $result['payment_id'],
            'order_id' => (string) $result['order_id'],
            'amount' => (int) $result['amount'],
            'key' => (string) $result['key'],
            'currency' => 'INR',
            'name' => setting('merchant_name', 'SERP UPI'),
            'description' => (string) $bill['biller_name'] . ' ' . $bill['bill_number'],
            'prefill' => [
                'name' => (string) $user['full_name'],
                'email' => (string) $user['email'],
                'contact' => (string) $user['mobile'],
            ],
        ]);
    }

    if ($action === 'razorpay_confirm') {
        $user = api_require_user();
        $result = complete_razorpay_payment(
            (int) $user['id'],
            clean_text((string) api_in('razorpay_order_id'), 64),
            clean_text((string) api_in('razorpay_payment_id'), 64),
            clean_text((string) api_in('razorpay_signature'), 128)
        );
        api_out([
            'success' => !empty($result['ok']),
            'message' => (string) ($result['message'] ?? 'Payment could not be completed.'),
            'payment_id' => (int) ($result['payment_id'] ?? 0),
        ], !empty($result['ok']) ? 200 : 400);
    }

    if ($action === 'wallet') {
        $user = api_require_user();
        $fresh = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $fresh->execute([(int) $user['id']]);
        $row = $fresh->fetch() ?: $user;
        $entries = db()->prepare(
            'SELECT id, amount, balance_after, kind, note, created_at
             FROM wallet_entries WHERE user_id = ? ORDER BY id DESC LIMIT 30'
        );
        $entries->execute([(int) $user['id']]);
        api_out([
            'success' => true,
            'user' => api_public_user($row),
            'entries' => $entries->fetchAll(),
        ]);
    }

    if ($action === 'wallet_topup') {
        $user = api_require_user();
        $result = wallet_topup((int) $user['id'], (string) api_in('amount'), (string) api_in('pin'));
        if (!empty($result['ok'])) {
            $fresh = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $fresh->execute([(int) $user['id']]);
            $row = $fresh->fetch() ?: $user;
            api_out([
                'success' => true,
                'message' => (string) $result['message'],
                'user' => api_public_user($row),
            ]);
        }
        api_out(['success' => false, 'message' => (string) ($result['message'] ?? 'Top-up failed.')], 400);
    }

    api_out(['success' => false, 'message' => 'Unknown action: ' . $action], 404);
} catch (Throwable $e) {
    error_log('SERP API: ' . $e->__toString());
    api_out(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
