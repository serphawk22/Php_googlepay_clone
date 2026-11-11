<?php
declare(strict_types=1);

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function e_nl(string $value): string
{
    return nl2br(e($value), false);
}

function str_clip(string $value, int $max): string
{
    if (function_exists('mb_substr') && function_exists('mb_strlen')) {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
    return strlen($value) > $max ? substr($value, 0, $max) : $value;
}

function clean_text(string $value, int $max): string
{
    $value = str_replace("\0", '', $value);
    $value = trim((string) (preg_replace('/\s+/u', ' ', $value) ?? ''));
    return str_clip($value, $max);
}

function clean_block(string $value, int $max): string
{
    $value = str_replace(["\0", "\r\n", "\r"], ["", "\n", "\n"], trim($value));
    return str_clip($value, $max);
}

function field(string $key, string $source = 'post'): string
{
    $bag = $source === 'get' ? $_GET : $_POST;
    return trim((string) ($bag[$key] ?? ''));
}

function base_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $doc = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
    $root = realpath(SERP_ROOT) ?: SERP_ROOT;
    $doc = str_replace('\\', '/', $doc);
    $root = str_replace('\\', '/', $root);
    if ($doc !== '' && strncasecmp($root, $doc, strlen($doc)) === 0) {
        $rel = trim(substr($root, strlen($doc)), '/');
        $path = $rel === '' ? '' : '/' . $rel;
        return $path;
    }
    $path = '';
    return $path;
}

function url(string $path = ''): string
{
    $base = base_path();
    $path = ltrim($path, '/');
    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }
    return ($base === '' ? '' : $base) . '/' . $path;
}

function request_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function absolute_url(string $path): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (request_is_https() ? 'https' : 'http') . '://' . $host . url($path);
}

function redirect(string $path): void
{
    header('Location: ' . absolute_url($path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);
    return is_array($flashes) ? $flashes : [];
}

function render_flashes(): void
{
    foreach (take_flashes() as $flash) {
        $type = preg_replace('/[^a-z]/', '', (string) ($flash['type'] ?? 'info')) ?: 'info';
        echo '<div class="flash flash-' . $type . '" role="status">' . e($flash['message'] ?? '') . '</div>';
    }
}

function render_errors(array $errors): void
{
    if (!$errors) {
        return;
    }
    echo '<div class="flash flash-error" role="alert"><ul>';
    foreach ($errors as $error) {
        echo '<li>' . e((string) $error) . '</li>';
    }
    echo '</ul></div>';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['csrf'] ?? '';
    $known = csrf_token();
    if (!is_string($sent) || !hash_equals($known, $sent)) {
        http_response_code(419);
        exit('Your session expired. Go back and try again.');
    }
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        exit('Method not allowed.');
    }
    csrf_verify();
}

function old(string $key, string $default = ''): string
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && array_key_exists($key, $_POST)) {
        return e((string) $_POST[$key]);
    }
    return e($default);
}

function selected_attr($current, $expected): string
{
    return (string) $current === (string) $expected ? ' selected' : '';
}

function checked_attr($current, $expected = '1'): string
{
    return (string) $current === (string) $expected ? ' checked' : '';
}

function money($amount): string
{
    $negative = (float) $amount < 0;
    $parts = explode('.', number_format(abs((float) $amount), 2, '.', ''));
    $rupees = $parts[0];
    $paise = $parts[1];
    if (strlen($rupees) > 3) {
        $last3 = substr($rupees, -3);
        $rest = substr($rupees, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) ?? $rest;
        $rupees = $rest . ',' . $last3;
    }
    $rupee = "\u{20B9}";
    return ($negative ? '-' . $rupee : $rupee) . $rupees . '.' . $paise;
}

function format_date(?string $date): string
{
    if (!$date) {
        return '-';
    }
    $time = strtotime($date);
    return $time ? date('d M Y', $time) : '-';
}

function format_when(?string $date): string
{
    if (!$date) {
        return '-';
    }
    $time = strtotime($date);
    return $time ? date('d M Y, h:i A', $time) : '-';
}

function days_until(string $date): int
{
    $today = new DateTimeImmutable('today');
    $due = new DateTimeImmutable($date);
    return (int) $today->diff($due)->format('%r%a');
}

function due_label(string $date): string
{
    $days = days_until($date);
    if ($days === 0) {
        return 'Due today';
    }
    if ($days === 1) {
        return 'Due tomorrow';
    }
    if ($days > 1) {
        return 'Due in ' . $days . ' days';
    }
    if ($days === -1) {
        return 'Overdue by 1 day';
    }
    return 'Overdue by ' . abs($days) . ' days';
}

function is_past_due(array $bill): bool
{
    return (string) ($bill['due_date'] ?? '') < date('Y-m-d');
}

function amount_due(array $bill): float
{
    if (($bill['status'] ?? '') === 'paid' && isset($bill['paid_amount']) && $bill['paid_amount'] !== null && $bill['paid_amount'] !== '') {
        return round((float) $bill['paid_amount'], 2);
    }
    $amount = (float) ($bill['amount'] ?? 0);
    if (($bill['status'] ?? '') !== 'paid' && is_past_due($bill)) {
        $amount += (float) ($bill['late_fee'] ?? 0);
    }
    return round($amount, 2);
}

function category_label(string $category): string
{
    return $category === 'lic' ? 'LIC' : 'Electricity';
}

function status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

function money_paise(string $amount): int
{
    $raw = trim($amount);
    $negative = str_starts_with($raw, '-');
    if ($negative) {
        $raw = substr($raw, 1);
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
        return 0;
    }
    [$rupees, $paise] = array_pad(explode('.', $raw, 2), 2, '0');
    $value = ((int) $rupees) * 100 + (int) str_pad(substr($paise, 0, 2), 2, '0');
    return $negative ? -$value : $value;
}

function paise_to_money(int $paise): string
{
    $negative = $paise < 0;
    $paise = abs($paise);
    return ($negative ? '-' : '') . intdiv($paise, 100) . '.' . str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
}

function pin_rule_error(string $pin): ?string
{
    if (!preg_match('/^[0-9]{6}$/', $pin)) {
        return 'Enter a 6-digit SERP UPI PIN.';
    }
    if (preg_match('/^(\d)\1{5}$/', $pin)) {
        return 'Choose a PIN that is not the same digit six times.';
    }
    $weak = ['012345', '123456', '234567', '345678', '456789', '567890', '098765', '987654', '876543', '765432', '654321', '543210'];
    if (in_array($pin, $weak, true)) {
        return 'Choose a PIN that is not a straight sequence.';
    }
    return null;
}

function greeting(): string
{
    $hour = (int) date('G');
    if ($hour < 12) {
        return 'Good morning';
    }
    if ($hour < 17) {
        return 'Good afternoon';
    }
    return 'Good evening';
}

function first_name(string $full): string
{
    $parts = preg_split('/\s+/', trim($full)) ?: [];
    $first = (string) ($parts[0] ?? '');
    return $first !== '' ? $first : $full;
}

function valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 190;
}

function valid_mobile(string $mobile): bool
{
    return (bool) preg_match('/^[6-9][0-9]{9}$/', $mobile);
}

function valid_name(string $name): bool
{
    return (bool) preg_match('/^\p{L}[\p{L} .\'-]{1,119}$/u', $name);
}

function valid_account_number(string $number): bool
{
    return (bool) preg_match('/^[0-9]{8,16}$/', $number);
}

function valid_password(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Use at least 8 characters.';
    }
    if (strlen($password) > 72) {
        return 'Use 72 characters or fewer.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Include a letter and a number.';
    }
    return null;
}

function valid_vpa(string $vpa): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9.\-_]{2,40}@[a-zA-Z]{2,64}$/', $vpa);
}

function valid_utr(string $utr): bool
{
    return (bool) preg_match('/^[A-Z0-9]{8,22}$/', $utr);
}

function parse_money(string $raw): ?string
{
    $raw = str_replace(',', '', trim($raw));
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
        return null;
    }
    $number = (float) $raw;
    if ($number < 0 || $number > 9999999.99) {
        return null;
    }
    return number_format($number, 2, '.', '');
}

function parse_date(string $raw): ?string
{
    $date = DateTime::createFromFormat('Y-m-d', $raw);
    if (!$date || $date->format('Y-m-d') !== $raw) {
        return null;
    }
    return $raw;
}

function remind_day_options(): array
{
    return [1, 2, 3, 5, 7];
}

function app_config(string $key, string $default = ''): string
{
    $config = $GLOBALS['config'] ?? [];
    return isset($config[$key]) ? (string) $config[$key] : $default;
}

function db(): PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    $config = $GLOBALS['config'] ?? null;
    if (!is_array($config)) {
        throw new RuntimeException('Database is not configured.');
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        (int) $config['db_port'],
        $config['db_name']
    );
    $GLOBALS['pdo'] = new PDO($dsn, (string) $config['db_user'], (string) $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $GLOBALS['pdo'];
}

function settings(bool $reload = false): array
{
    static $all = null;
    if ($reload) {
        $all = null;
    }
    if ($all === null) {
        $all = [];
        foreach (db()->query('SELECT skey, svalue FROM settings') as $row) {
            $all[(string) $row['skey']] = (string) $row['svalue'];
        }
    }
    return $all;
}

function setting(string $key, string $default = ''): string
{
    $all = settings();
    return $all[$key] ?? $default;
}

function save_settings(array $pairs): void
{
    $pdo = db();
    $select = $pdo->prepare('SELECT skey FROM settings WHERE skey = ?');
    $update = $pdo->prepare('UPDATE settings SET svalue = ? WHERE skey = ?');
    $insert = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)');
    foreach ($pairs as $key => $value) {
        $select->execute([(string) $key]);
        if ($select->fetch()) {
            $update->execute([(string) $value, (string) $key]);
        } else {
            $insert->execute([(string) $key, (string) $value]);
        }
    }
    settings(true);
}

function current_user(bool $reload = false): ?array
{
    static $loaded = false;
    static $user = null;
    if ($reload) {
        $loaded = false;
        $user = null;
    }
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id < 1) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        unset($_SESSION['user_id']);
        return null;
    }
    if ($row['status'] !== 'active') {
        $_SESSION = [];
        flash('error', 'This account is suspended.');
        return null;
    }
    $user = $row;
    return $user;
}

function home_path(array $user): string
{
    return ($user['role'] ?? '') === 'admin' ? 'admin/index.php' : 'app/index.php';
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    unset($_SESSION['csrf'], $_SESSION['fails'], $_SESSION['lock_until']);
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);
    current_user(true);
    audit('login', 'user', (int) $user['id'], (string) $user['email']);
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        if (empty($_SESSION['flashes'])) {
            flash('error', 'Please sign in to continue.');
        }
        redirect('login.php');
    }
    return $user;
}

function notify(int $userId, string $title, string $body, string $link = ''): void
{
    $stmt = db()->prepare('INSERT INTO notifications (user_id, title, body, link) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, str_clip($title, 160), str_clip($body, 500), str_clip($link, 255)]);
}

function notify_admins(string $title, string $body, string $link = ''): void
{
    $ids = db()->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        notify((int) $id, $title, $body, $link);
    }
}

function unread_count(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function audit(string $action, string $entity = '', ?int $entityId = null, string $details = ''): void
{
    $actor = (int) ($_SESSION['user_id'] ?? 0);
    $stmt = db()->prepare('INSERT INTO audit_logs (actor_id, action, entity, entity_id, details, ip) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $actor > 0 ? $actor : null,
        str_clip($action, 80),
        str_clip($entity, 40),
        $entityId,
        str_clip($details, 500),
        str_clip((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 45),
    ]);
}

function bill_select_sql(): string
{
    return 'SELECT b.*, bi.name AS biller_name, bi.category, bi.region, bi.code AS biller_code,
        (SELECT p.amount FROM payments p WHERE p.bill_id = b.id AND p.status = \'success\' ORDER BY p.id DESC LIMIT 1) AS paid_amount
        FROM bills b
        INNER JOIN billers bi ON bi.id = b.biller_id';
}

function page_from_request(): int
{
    return max(1, (int) ($_GET['page'] ?? 1));
}

function paged(string $countSql, string $listSql, array $params, int $per = 20): array
{
    $page = page_from_request();
    $count = db()->prepare($countSql);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $per));
    if ($page > $pages) {
        $page = $pages;
    }
    $offset = ($page - 1) * $per;
    $list = db()->prepare($listSql . ' LIMIT ' . (int) $per . ' OFFSET ' . (int) $offset);
    $list->execute($params);
    return [
        'rows' => $list->fetchAll(),
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'per' => $per,
    ];
}

function pager_links(int $page, int $pages, string $path): string
{
    if ($pages <= 1) {
        return '';
    }
    $html = '<nav class="pager" aria-label="Pages">';
    $query = $_GET;
    unset($query['export']);
    if ($page > 1) {
        $query['page'] = $page - 1;
        $html .= '<a class="btn btn-sm btn-ghost" href="' . e(url($path) . '?' . http_build_query($query)) . '">Previous</a>';
    }
    $html .= '<span>Page ' . (int) $page . ' of ' . (int) $pages . '</span>';
    if ($page < $pages) {
        $query['page'] = $page + 1;
        $html .= '<a class="btn btn-sm btn-ghost" href="' . e(url($path) . '?' . http_build_query($query)) . '">Next</a>';
    }
    $html .= '</nav>';
    return $html;
}

function search_term(string $key = 'q'): string
{
    return str_replace(['%', '_'], '', clean_text(field($key, 'get'), 40));
}

function random_password(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < 10; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out . 'a7';
}

function csv_download(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}
