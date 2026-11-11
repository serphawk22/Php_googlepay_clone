<?php
declare(strict_types=1);

/**
 * Razorpay Payment Gateway + BBPS bill fetch / bill payment.
 * Docs: https://razorpay.com/docs/api/bills/bill-payments/
 */
final class RazorpayClient
{
    /** @var array<string, array{biller_id:string, param:string}> */
    private const DEFAULT_BILLERS = [
        'lic' => ['biller_id' => 'LIFE00000NATL6', 'param' => 'Policy Number'],
        'nbpdcl' => ['biller_id' => 'NBPDCL000BHI01', 'param' => 'CA Number'],
        'sbpdcl' => ['biller_id' => 'SBPDCL000BHI01', 'param' => 'CA Number'],
    ];

    /**
     * @return array{biller_id:string, param:string}|null
     */
    public static function mappingForBiller(array $biller): ?array
    {
        $bbpsId = trim((string) ($biller['bbps_biller_id'] ?? ''));
        $param = trim((string) ($biller['bbps_customer_param'] ?? ''));
        if ($bbpsId !== '' && $param !== '') {
            return ['biller_id' => $bbpsId, 'param' => $param];
        }
        $code = strtolower((string) ($biller['code'] ?? ''));
        if (!isset(self::DEFAULT_BILLERS[$code])) {
            return null;
        }
        $defaults = self::DEFAULT_BILLERS[$code];
        return [
            'biller_id' => $bbpsId !== '' ? $bbpsId : $defaults['biller_id'],
            'param' => $param !== '' ? $param : $defaults['param'],
        ];
    }

    public function upsertLocalRecord(int $billerId, array $record): array
    {
        $pdo = db();
        $existing = $pdo->prepare(
            'SELECT id FROM biller_records
             WHERE biller_id = ? AND account_number = ? AND billing_period = ?
             LIMIT 1'
        );
        $existing->execute([$billerId, $record['account_number'], $record['billing_period']]);
        $existingId = (int) $existing->fetchColumn();

        if ($existingId > 0) {
            $pdo->prepare(
                'UPDATE biller_records SET
                    customer_name = ?, bill_number = ?, bill_date = ?, due_date = ?,
                    amount = ?, late_fee = ?, units = ?, plan_name = ?, details = ?,
                    is_sample = 0, is_active = 1, updated_at = NOW()
                 WHERE id = ?'
            )->execute([
                $record['customer_name'],
                $record['bill_number'],
                $record['bill_date'],
                $record['due_date'],
                $record['amount'],
                $record['late_fee'],
                $record['units'],
                $record['plan_name'],
                $record['details'],
                $existingId,
            ]);
            $id = $existingId;
        } else {
            $pdo->prepare(
                'INSERT INTO biller_records (
                    biller_id, account_number, customer_name, billing_period, bill_number,
                    bill_date, due_date, amount, late_fee, units, plan_name, details, is_sample, is_active
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1)'
            )->execute([
                $billerId,
                $record['account_number'],
                $record['customer_name'],
                $record['billing_period'],
                $record['bill_number'],
                $record['bill_date'],
                $record['due_date'],
                $record['amount'],
                $record['late_fee'],
                $record['units'],
                $record['plan_name'],
                $record['details'],
            ]);
            $id = (int) $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare('SELECT * FROM biller_records WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Failed to load the saved bill record.');
        }
        return $row;
    }

    public static function isEnabled(): bool
    {
        return setting('razorpay_enabled', '0') === '1'
            && setting('razorpay_key_id') !== ''
            && setting('razorpay_key_secret') !== '';
    }

    public static function keyId(): string
    {
        return trim(setting('razorpay_key_id'));
    }

    public static function keySecret(): string
    {
        return trim(setting('razorpay_key_secret'));
    }

    /**
     * Fetch a bill and return a local biller_records-shaped array.
     *
     * @return array{ok:bool, message?:string, record?:array}
     */
    public function fetchBill(array $biller, array $context): array
    {
        set_time_limit(90);
        $map = self::mappingForBiller($biller);
        if ($map === null) {
            return ['ok' => false, 'message' => 'This biller has no BBPS biller ID.'];
        }
        $accountNumber = clean_text((string) ($context['account_number'] ?? ''), 40);
        $name = clean_text((string) ($context['customer_name'] ?? 'Customer'), 120);
        $phone = self::mobile10((string) ($context['customer_phone'] ?? ''));
        $email = clean_text((string) ($context['email'] ?? ''), 190);
        if ($accountNumber === '' || $phone === null) {
            return ['ok' => false, 'message' => 'A consumer number and a 10-digit mobile are required to fetch from Razorpay BBPS.'];
        }

        $created = $this->request('POST', '/v1/bill_payments/bill_requests', [
            'gateway_biller_id' => $map['biller_id'],
            'customer' => [
                'id' => 'serp_' . $phone,
                'name' => $name !== '' ? $name : 'Customer',
                'mobile' => '91' . $phone,
                'email' => $email,
            ],
            'device' => $this->device($phone, (string) ($context['ip'] ?? '')),
            'biller_data' => [
                'account_holder' => [
                    $map['param'] => $accountNumber,
                ],
            ],
        ]);
        if (empty($created['ok'])) {
            return ['ok' => false, 'message' => (string) ($created['message'] ?? 'Bill request failed.')];
        }
        $requestId = (string) ($created['data']['id'] ?? '');
        if ($requestId === '') {
            return ['ok' => false, 'message' => 'Razorpay did not return a bill request id.'];
        }

        $final = $this->poll('/v1/bill_payments/bill_requests/' . rawurlencode($requestId), 12, 2);
        if (empty($final['ok'])) {
            return ['ok' => false, 'message' => (string) ($final['message'] ?? 'Bill fetch timed out.')];
        }
        $body = $final['data'];
        $status = strtolower((string) ($body['status'] ?? ''));
        if ($status !== 'success') {
            $reason = (string) ($body['error_description'] ?? 'Razorpay could not fetch this bill.');
            return ['ok' => false, 'message' => $reason];
        }
        $bills = is_array($body['bills'] ?? null) ? $body['bills'] : [];
        $bill = $bills[0] ?? null;
        if (!is_array($bill)) {
            return ['ok' => false, 'message' => 'Razorpay returned no bill for this number.'];
        }
        $amountPaise = (int) ($bill['amount'] ?? 0);
        if ($amountPaise <= 0) {
            return ['ok' => false, 'message' => 'The biller returned a zero amount.'];
        }
        $billDate = self::unixDate($bill['bill_date'] ?? null);
        $dueDate = self::unixDate($bill['due_date'] ?? null) ?? date('Y-m-d', strtotime('+7 days'));
        $period = clean_text((string) ($bill['bill_period'] ?? ''), 40);
        if ($period === '') {
            $period = date('M Y', strtotime($billDate ?? 'today'));
        }
        $holder = clean_text((string) ($bill['account_holder_name'] ?? $name), 120);
        if ($holder === '') {
            $holder = 'Account holder';
        }

        return [
            'ok' => true,
            'record' => [
                'account_number' => str_clip($accountNumber, 16),
                'customer_name' => $holder,
                'billing_period' => $period,
                'bill_number' => str_clip((string) ($bill['bill_number'] ?? ('RZP' . substr($requestId, -10))), 40),
                'bill_date' => $billDate ?? date('Y-m-d'),
                'due_date' => $dueDate,
                'amount' => number_format($amountPaise / 100, 2, '.', ''),
                'late_fee' => '0.00',
                'units' => null,
                'plan_name' => '',
                'details' => str_clip('Razorpay BBPS · ' . $requestId, 255),
                'bbps_request_id' => $requestId,
                'bbps_amount_paise' => $amountPaise,
            ],
        ];
    }

    /**
     * @return array{ok:bool, message?:string, order_id?:string, amount?:int, key?:string}
     */
    public function createOrder(int $amountPaise, string $receipt, array $notes): array
    {
        if ($amountPaise < 100) {
            return ['ok' => false, 'message' => 'Amount must be at least ₹1.00.'];
        }
        $result = $this->request('POST', '/v1/orders', [
            'amount' => $amountPaise,
            'currency' => 'INR',
            'receipt' => str_clip($receipt, 40),
            'notes' => $notes,
        ]);
        if (empty($result['ok'])) {
            return ['ok' => false, 'message' => (string) ($result['message'] ?? 'Could not create Razorpay order.')];
        }
        $orderId = (string) ($result['data']['id'] ?? '');
        if ($orderId === '') {
            return ['ok' => false, 'message' => 'Razorpay did not return an order id.'];
        }
        return [
            'ok' => true,
            'order_id' => $orderId,
            'amount' => $amountPaise,
            'key' => self::keyId(),
        ];
    }

    public function verifySignature(string $orderId, string $paymentId, string $signature): bool
    {
        if ($orderId === '' || $paymentId === '' || $signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, self::keySecret());
        return hash_equals($expected, $signature);
    }

    /**
     * Confirm the captured PG payment on BBPS.
     *
     * @return array{ok:bool, message?:string, bill_payment_id?:string, gateway_txn?:string}
     */
    public function settleBill(array $bill, array $pgPayment, string $phone, string $email, string $name): array
    {
        set_time_limit(90);
        $requestId = trim((string) ($bill['bbps_request_id'] ?? ''));
        if ($requestId === '' && preg_match('/billreq_[A-Za-z0-9]+/', (string) ($bill['details'] ?? ''), $m)) {
            $requestId = $m[0];
        }
        if ($requestId === '') {
            return ['ok' => false, 'message' => 'This bill has no Razorpay bill request. Fetch it again before paying.'];
        }
        $amountPaise = (int) ($pgPayment['amount'] ?? 0);
        $method = strtolower((string) ($pgPayment['method'] ?? 'upi'));
        if (!in_array($method, ['card', 'upi', 'netbanking', 'wallet'], true)) {
            $method = 'upi';
        }
        $paymentId = (string) ($pgPayment['id'] ?? '');
        $instrument = [
            'id' => $paymentId,
            'amount' => $amountPaise,
            'currency' => 'INR',
            'provider' => 'razorpay',
            'method' => $method,
        ];
        if ($method === 'card') {
            $card = is_array($pgPayment['card'] ?? null) ? $pgPayment['card'] : [];
            $instrument['card'] = [
                'type' => (string) ($card['type'] ?? 'debit'),
                'last4' => (string) ($card['last4'] ?? '0000'),
                'auth_code' => 'Card',
            ];
        } elseif ($method === 'upi') {
            $vpa = (string) ($pgPayment['vpa'] ?? ($pgPayment['upi']['vpa'] ?? ''));
            $instrument['upi'] = ['vpa' => $vpa !== '' ? $vpa : $paymentId];
        } elseif ($method === 'netbanking') {
            $instrument['netbanking'] = [
                'account_number' => 'PG',
                'ifsc_code' => $paymentId,
            ];
        } else {
            $instrument['wallet'] = ['name' => (string) ($pgPayment['wallet'] ?? 'razorpay')];
        }

        $mobile = self::mobile10($phone);
        $idempotency = substr(str_replace('-', '', sprintf(
            '%04x%04x%04x%04x%04x%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        )), 0, 32);

        $payload = [
            'bill_request_id' => $requestId,
            'bill_pay_amount' => $amountPaise,
            'currency' => 'INR',
            'fees' => [
                'app_convenience_fee' => 0,
                'biller_convenience_fee' => 0,
            ],
            'payments' => [$instrument],
            'bills' => [[
                'bill_number' => (string) ($bill['bill_number'] ?? ''),
                'amount' => $amountPaise,
                'currency' => 'INR',
            ]],
            'customer' => [
                'id' => 'serp_' . ($mobile ?? 'user'),
                'name' => $name !== '' ? $name : 'Customer',
                'mobile' => '91' . ($mobile ?? '9999999999'),
                'email' => $email,
            ],
        ];

        $created = $this->request(
            'POST',
            '/v1/bill_payments/payments',
            $payload,
            ['X-Bill-Payments-Idempotency: ' . $idempotency]
        );
        if (empty($created['ok'])) {
            return ['ok' => false, 'message' => (string) ($created['message'] ?? 'BBPS payment could not be started.')];
        }
        $billPayId = (string) ($created['data']['id'] ?? '');
        if ($billPayId === '') {
            return ['ok' => false, 'message' => 'Razorpay did not return a bill payment id.'];
        }
        $final = $this->poll('/v1/bill_payments/payments/' . rawurlencode($billPayId), 12, 2);
        if (empty($final['ok'])) {
            return [
                'ok' => false,
                'message' => (string) ($final['message'] ?? 'BBPS payment is still processing. Check Razorpay dashboard before retrying.'),
                'bill_payment_id' => $billPayId,
            ];
        }
        $status = strtolower((string) ($final['data']['status'] ?? ''));
        if ($status !== 'success') {
            $reason = (string) ($final['data']['error_description'] ?? 'BBPS settlement failed.');
            return ['ok' => false, 'message' => $reason, 'bill_payment_id' => $billPayId];
        }
        return [
            'ok' => true,
            'bill_payment_id' => $billPayId,
            'gateway_txn' => (string) ($final['data']['gateway_transaction_id'] ?? ''),
        ];
    }

    public function fetchPayment(string $paymentId): array
    {
        return $this->request('GET', '/v1/payments/' . rawurlencode($paymentId));
    }

    public function refundPayment(string $paymentId, int $amountPaise): array
    {
        return $this->request('POST', '/v1/payments/' . rawurlencode($paymentId) . '/refund', [
            'amount' => $amountPaise,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $extraHeaders
     * @return array{ok:bool, message?:string, data?:array, status?:int}
     */
    public function request(string $method, string $path, array $body = [], array $extraHeaders = []): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'PHP cURL is required for Razorpay.'];
        }
        $ch = curl_init('https://api.razorpay.com' . $path);
        if ($ch === false) {
            return ['ok' => false, 'message' => 'Could not start the Razorpay request.'];
        }
        $headers = array_merge(['Accept: application/json'], $extraHeaders);
        $payload = '';
        if ($method !== 'GET') {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES) ?: '{}';
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => self::keyId() . ':' . self::keySecret(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $method === 'GET' ? null : $payload,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_PROXY => '',
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            return ['ok' => false, 'message' => 'Razorpay network error: ' . $error];
        }
        $data = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($data)) {
            return ['ok' => false, 'message' => 'Razorpay returned an invalid response (HTTP ' . $status . ').', 'status' => $status];
        }
        if ($status >= 400 || isset($data['error'])) {
            $desc = is_array($data['error'] ?? null)
                ? (string) ($data['error']['description'] ?? 'Razorpay request failed.')
                : 'Razorpay request failed.';
            return ['ok' => false, 'message' => $desc, 'data' => $data, 'status' => $status];
        }
        return ['ok' => true, 'data' => $data, 'status' => $status];
    }

    /**
     * @return array{ok:bool, message?:string, data?:array}
     */
    private function poll(string $path, int $tries, int $sleepSeconds): array
    {
        $last = ['ok' => false, 'message' => 'Razorpay did not finish in time.'];
        for ($i = 0; $i < $tries; $i++) {
            if ($i > 0) {
                sleep($sleepSeconds);
            }
            $result = $this->request('GET', $path);
            if (empty($result['ok'])) {
                $last = $result;
                continue;
            }
            $status = strtolower((string) ($result['data']['status'] ?? ''));
            if (in_array($status, ['success', 'failed'], true)) {
                return $result;
            }
            $last = $result;
        }
        return $last;
    }

    /**
     * @return array<string, string>
     */
    private function device(string $phone, string $ip): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '127.0.0.1';
        }
        $geo = trim(setting('razorpay_geocode', '25.5941,85.1376'));
        if (!preg_match('/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/', $geo)) {
            $geo = '25.5941,85.1376';
        }
        return [
            'initiating_channel' => 'internet',
            'ip' => $ip,
            'mac' => '00-14-22-01-23-45',
            'geocode' => $geo,
            'app' => 'SERP-UPI',
            'mobile' => $phone,
        ];
    }

    private static function mobile10(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }
        return preg_match('/^[6-9][0-9]{9}$/', $digits) ? $digits : null;
    }

    /** @param mixed $value */
    private static function unixDate($value): ?string
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $ts = (int) $value;
            if ($ts > 1000000000) {
                return date('Y-m-d', $ts);
            }
        }
        if (is_string($value) && $value !== '') {
            $ts = strtotime($value);
            return $ts ? date('Y-m-d', $ts) : null;
        }
        return null;
    }
}

function ensure_razorpay_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $billerCols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM billers') as $row) {
            $billerCols[(string) $row['Field']] = true;
        }
        if (empty($billerCols['bbps_biller_id'])) {
            $pdo->exec("ALTER TABLE billers ADD COLUMN bbps_biller_id VARCHAR(40) NOT NULL DEFAULT '' AFTER description");
        }
        if (empty($billerCols['bbps_customer_param'])) {
            $pdo->exec("ALTER TABLE billers ADD COLUMN bbps_customer_param VARCHAR(80) NOT NULL DEFAULT '' AFTER bbps_biller_id");
        }
        $defaults = [
            'lic' => ['LIFE00000NATL6', 'Policy Number'],
            'nbpdcl' => ['NBPDCL000BHI01', 'CA Number'],
            'sbpdcl' => ['SBPDCL000BHI01', 'CA Number'],
        ];
        $seed = $pdo->prepare(
            "UPDATE billers
             SET bbps_biller_id = IF(bbps_biller_id = '', ?, bbps_biller_id),
                 bbps_customer_param = IF(bbps_customer_param = '', ?, bbps_customer_param)
             WHERE code = ?"
        );
        foreach ($defaults as $code => $pair) {
            $seed->execute([$pair[0], $pair[1], $code]);
        }
        $pdo->exec("DELETE FROM settings WHERE skey LIKE 'payu\\_%'");

        $billCols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM bills') as $row) {
            $billCols[(string) $row['Field']] = true;
        }
        if (empty($billCols['bbps_request_id'])) {
            $pdo->exec("ALTER TABLE bills ADD COLUMN bbps_request_id VARCHAR(64) NOT NULL DEFAULT '' AFTER details");
        }
        $payCols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM payments') as $row) {
            $payCols[(string) $row['Field']] = true;
        }
        if (empty($payCols['gateway'])) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN gateway VARCHAR(20) NOT NULL DEFAULT '' AFTER upi_vpa");
        }
        if (empty($payCols['gateway_ref'])) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN gateway_ref VARCHAR(64) NOT NULL DEFAULT '' AFTER gateway");
        }
    } catch (Throwable $e) {
        error_log('ensure_razorpay_schema: ' . $e->getMessage());
    }
}

function attach_bbps_request(int $billId, string $requestId): void
{
    if ($billId < 1 || $requestId === '') {
        return;
    }
    try {
        db()->prepare('UPDATE bills SET bbps_request_id = ?, updated_at = NOW() WHERE id = ?')
            ->execute([str_clip($requestId, 64), $billId]);
    } catch (Throwable $e) {
        error_log('attach_bbps_request: ' . $e->getMessage());
    }
}
