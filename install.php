<?php
declare(strict_types=1);

define('SERP_INSTALL', true);
require __DIR__ . '/includes/bootstrap.php';

if (serp_installed()) {
    render_document_start('Already installed', 'is-public');
    echo '<main class="auth-wrap" id="content"><div class="auth-card stack">';
    echo '<h1>SERP UPI is already installed.</h1>';
    echo '<p>The database <strong>serp_secure_upi</strong> is ready.</p>';
    echo '<a class="btn btn-primary" href="' . e(url('index.php')) . '">Open the website</a>';
    echo '</div></main>';
    render_document_end();
    exit;
}

$errors = [];
$needsReset = false;
$defaults = [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_user' => 'root',
    'admin_name' => 'Administrator',
    'admin_email' => 'admin@serpupi.local',
    'admin_mobile' => '9999999999',
    'merchant_name' => 'SERP UPI',
    'merchant_vpa' => '',
];

function serp_run_sql(PDO $pdo, string $sql): void
{
    $sql = preg_replace('/--.*$/m', '', $sql) ?? $sql;
    foreach (preg_split('/;/', $sql) ?: [] as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function serp_seed(PDO $pdo, array $admin, string $merchantName, string $merchantVpa): void
{
    $settings = [
        'merchant_vpa' => $merchantVpa,
        'merchant_name' => $merchantName,
        'support_phone' => '',
        'support_email' => $admin['email'],
        'show_sample_hints' => '1',
        'default_remind_days' => '3',
        'razorpay_enabled' => '0',
        'razorpay_key_id' => '',
        'razorpay_key_secret' => '',
        'razorpay_geocode' => '25.5941,85.1376',
    ];
    $settingStmt = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)');
    foreach ($settings as $key => $value) {
        $settingStmt->execute([$key, $value]);
    }

    $billerStmt = $pdo->prepare(
        'INSERT INTO billers (code, name, category, region, description, bbps_biller_id, bbps_customer_param)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $billers = [
        ['lic', 'LIC of India', 'lic', 'All India', 'Life insurance premiums', 'LIFE00000NATL6', 'Policy Number'],
        ['nbpdcl', 'North Bihar Power (NBPDCL)', 'electricity', 'North Bihar', 'Muzaffarpur, Darbhanga, Purnea and other north circles', 'NBPDCL000BHI01', 'CA Number'],
        ['sbpdcl', 'South Bihar Power (SBPDCL)', 'electricity', 'South Bihar', 'Patna, Gaya, Bhagalpur and other south circles', 'SBPDCL000BHI01', 'CA Number'],
    ];
    $ids = [];
    foreach ($billers as $biller) {
        $billerStmt->execute($biller);
        $ids[$biller[0]] = (int) $pdo->lastInsertId();
    }

    $today = new DateTimeImmutable('today');
    $period = $today->format('M Y');
    $previous = $today->modify('first day of last month')->format('M Y');
    $monthStart = $today->format('Y-m-01');
    $lastMonthStart = $today->modify('first day of last month')->format('Y-m-d');
    $day = static function (int $days) use ($today): string {
        $sign = $days >= 0 ? '+' : '';
        return $today->modify($sign . $days . ' days')->format('Y-m-d');
    };

    $recordStmt = $pdo->prepare(
        'INSERT INTO biller_records (
            biller_id, account_number, customer_name, billing_period, bill_number,
            bill_date, due_date, amount, late_fee, units, plan_name, details, is_sample, is_active
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)'
    );
    $samples = [
        [$ids['lic'], '5544332211', 'Sita Kumari', $period, 'LIC20261001', $monthStart, $day(9), '1846.00', '0.00', null, 'Jeevan Anand', 'Sample · monthly premium'],
        [$ids['lic'], '6677889900', 'Amit Prasad', $period, 'LIC20261002', $monthStart, $day(2), '6420.00', '0.00', null, 'Jeevan Labh', 'Sample · quarterly premium'],
        [$ids['nbpdcl'], '10020030041', 'Ravi Kumar', $period, 'NBP20261041', $monthStart, $day(3), '1274.00', '50.00', 186, 'Domestic', 'Sample · Muzaffarpur urban'],
        [$ids['sbpdcl'], '20030040052', 'Priya Singh', $period, 'SBP20261052', $monthStart, $day(1), '2118.00', '75.00', 242, 'Domestic', 'Sample · Patna'],
        [$ids['sbpdcl'], '20030040089', 'Manoj Yadav', $previous, 'SBP20260989', $lastMonthStart, $day(-3), '3450.00', '100.00', 310, 'Domestic', 'Sample · Gaya · already overdue'],
    ];
    foreach ($samples as $sample) {
        $recordStmt->execute($sample);
    }

    $pdo->prepare(
        'INSERT INTO users (full_name, email, mobile, password_hash, role, status) VALUES (?, ?, ?, ?, \'admin\', \'active\')'
    )->execute([$admin['name'], $admin['email'], $admin['mobile'], $admin['hash']]);
    $adminId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO audit_logs (actor_id, action, entity, details, ip) VALUES (?, \'install\', \'database\', ?, ?)'
    )->execute([$adminId, 'Created serp_secure_upi', str_clip((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 45)]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    $host = clean_text(field('db_host'), 80);
    $port = (int) field('db_port');
    $dbUser = clean_text(field('db_user'), 80);
    $dbPass = str_replace("\0", '', (string) ($_POST['db_pass'] ?? ''));
    $action = field('action');

    if (!preg_match('/^[a-zA-Z0-9.\-]+$/', $host)) {
        $errors[] = 'Enter a valid database host.';
    }
    if ($port < 1 || $port > 65535) {
        $errors[] = 'Enter a valid database port.';
    }
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,80}$/', $dbUser)) {
        $errors[] = 'Enter a valid database user.';
    }

    if (!$errors && $action === 'reset_partial') {
        try {
            $server = new PDO(
                "mysql:host={$host};port={$port};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $server->exec('DROP DATABASE IF EXISTS `serp_secure_upi`');
            @unlink(SERP_ROOT . '/config/config.php');
            @unlink(SERP_ROOT . '/config/installed.lock');
            flash('success', 'Removed the unfinished serp_secure_upi database. You can install again.');
            redirect('install.php');
        } catch (Throwable $e) {
            $errors[] = 'Could not remove the database. ' . $e->getMessage();
        }
    }

    $adminName = clean_text(field('admin_name'), 120);
    $adminEmail = clean_text(field('admin_email'), 190);
    $adminMobile = clean_text(field('admin_mobile'), 10);
    $adminPassword = field('admin_password');
    $adminPassword2 = field('admin_password2');
    $merchantName = clean_text(field('merchant_name'), 80);
    $merchantVpa = clean_text(field('merchant_vpa'), 120);

    if ($action !== 'reset_partial') {
        if (!valid_name($adminName)) {
            $errors[] = 'Enter the administrator name.';
        }
        if (!valid_email($adminEmail)) {
            $errors[] = 'Enter a valid administrator email.';
        }
        if (!valid_mobile($adminMobile)) {
            $errors[] = 'Enter a 10-digit Indian mobile number for the administrator.';
        }
        $passwordError = valid_password($adminPassword);
        if ($passwordError !== null) {
            $errors[] = $passwordError;
        }
        if ($adminPassword !== $adminPassword2) {
            $errors[] = 'The administrator passwords do not match.';
        }
        if ($merchantName === '') {
            $errors[] = 'Enter the name customers will see on the UPI payment.';
        }
        if ($merchantVpa !== '' && !valid_vpa($merchantVpa)) {
            $errors[] = 'Enter a valid merchant UPI ID, such as name@okaxis, or leave it blank.';
        }
    }

    if (!$errors && $action !== 'reset_partial') {
        try {
            $server = new PDO(
                "mysql:host={$host};port={$port};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $countStmt = $server->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?'
            );
            $countStmt->execute(['serp_secure_upi']);
            if ((int) $countStmt->fetchColumn() > 0) {
                $needsReset = true;
                $errors[] = 'serp_secure_upi already has tables. Remove that unfinished database before installing again.';
            } else {
                $schema = file_get_contents(SERP_ROOT . '/database/schema.sql');
                if ($schema === false || trim($schema) === '') {
                    throw new RuntimeException('database/schema.sql could not be read.');
                }
                serp_run_sql($server, $schema);
                $server->beginTransaction();
                serp_seed($server, [
                    'name' => $adminName,
                    'email' => $adminEmail,
                    'mobile' => $adminMobile,
                    'hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
                ], $merchantName, $merchantVpa);
                $server->commit();
                $config = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export([
                    'db_host' => $host,
                    'db_port' => $port,
                    'db_name' => 'serp_secure_upi',
                    'db_user' => $dbUser,
                    'db_pass' => $dbPass,
                ], true) . ";\n";
                if (file_put_contents(SERP_ROOT . '/config/config.php', $config) === false) {
                    throw new RuntimeException('Could not write config/config.php.');
                }
                if (file_put_contents(SERP_ROOT . '/config/installed.lock', date('c')) === false) {
                    throw new RuntimeException('Could not write the install lock.');
                }
                redirect('login.php?installed=1');
            }
        } catch (Throwable $e) {
            if (isset($server) && $server instanceof PDO && $server->inTransaction()) {
                $server->rollBack();
            }
            if (isset($server) && $server instanceof PDO) {
                try {
                    $server->exec('DROP DATABASE IF EXISTS `serp_secure_upi`');
                } catch (Throwable $ignored) {
                    error_log($ignored->__toString());
                }
            }
            @unlink(SERP_ROOT . '/config/config.php');
            @unlink(SERP_ROOT . '/config/installed.lock');
            if (!$needsReset) {
                $errors[] = 'Installation stopped. ' . $e->getMessage();
            }
        }
    }
}

render_document_start('Install SERP UPI', 'is-public');
echo '<main class="auth-wrap" id="content"><div class="auth-card stack" style="width:min(640px,100%)">';
echo '<p class="eyebrow">First-time setup</p><h1>Install SERP UPI</h1>';
echo '<p>This creates a separate MySQL database named <strong>serp_secure_upi</strong>, the administrator account, LIC, NBPDCL, and SBPDCL, plus five sample bills you can fetch.</p>';
render_flashes();
render_errors($errors);
echo '<form method="post" class="stack">';
echo csrf_field();
echo '<div class="form-row">';
echo '<div class="field"><label for="db_host">MySQL host</label><input id="db_host" name="db_host" value="' . old('db_host', $defaults['db_host']) . '" required></div>';
echo '<div class="field"><label for="db_port">Port</label><input id="db_port" name="db_port" value="' . old('db_port', $defaults['db_port']) . '" required></div>';
echo '</div>';
echo '<div class="form-row">';
echo '<div class="field"><label for="db_user">MySQL user</label><input id="db_user" name="db_user" value="' . old('db_user', $defaults['db_user']) . '" required></div>';
echo '<div class="field"><label for="db_pass">MySQL password</label><input id="db_pass" name="db_pass" type="password" value="' . old('db_pass') . '" autocomplete="new-password"><p class="help">XAMPP’s root user often has an empty password.</p></div>';
echo '</div>';
echo '<div class="field"><label>Database</label><input value="serp_secure_upi" readonly></div>';
echo '<div class="form-row">';
echo '<div class="field"><label for="admin_name">Administrator name</label><input id="admin_name" name="admin_name" value="' . old('admin_name', $defaults['admin_name']) . '" required></div>';
echo '<div class="field"><label for="admin_mobile">Mobile</label><input id="admin_mobile" name="admin_mobile" inputmode="numeric" value="' . old('admin_mobile', $defaults['admin_mobile']) . '" required></div>';
echo '</div>';
echo '<div class="field"><label for="admin_email">Administrator email</label><input id="admin_email" name="admin_email" type="email" value="' . old('admin_email', $defaults['admin_email']) . '" required></div>';
echo '<div class="form-row">';
echo '<div class="field"><label for="admin_password">Password</label><input id="admin_password" name="admin_password" type="password" autocomplete="new-password" required></div>';
echo '<div class="field"><label for="admin_password2">Confirm password</label><input id="admin_password2" name="admin_password2" type="password" autocomplete="new-password" required></div>';
echo '</div>';
echo '<div class="form-row">';
echo '<div class="field"><label for="merchant_name">Name on UPI payment</label><input id="merchant_name" name="merchant_name" value="' . old('merchant_name', $defaults['merchant_name']) . '" required></div>';
echo '<div class="field"><label for="merchant_vpa">Merchant UPI ID</label><input id="merchant_vpa" name="merchant_vpa" value="' . old('merchant_vpa') . '" placeholder="optional"><p class="help">Leave this blank. Customers pay inside SERP UPI with a 6-digit PIN and their SERP wallet.</p></div>';
echo '</div>';
echo '<button class="btn btn-primary btn-block" type="submit" name="action" value="install">Create serp_secure_upi</button>';
if ($needsReset) {
    echo '<button class="btn btn-danger btn-block" type="submit" name="action" value="reset_partial" formnovalidate data-confirm="This deletes the serp_secure_upi database. Continue?">Delete unfinished database and start again</button>';
}
echo '</form></div></main>';
render_document_end();
