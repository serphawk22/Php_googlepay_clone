<?php
declare(strict_types=1);

function icon(string $name): string
{
    $inner = [
        'home' => '<path d="M4 11 12 4l8 7"/><path d="M6 10.5V20h12v-9.5"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.1.1l1.4-1.5a5 5 0 0 0-7-7.1L10.5 5"/><path d="M14 11a5 5 0 0 0-7.1-.1L5.5 12.4a5 5 0 0 0 7 7.1L13.5 19"/>',
        'bill' => '<path d="M7 3h10v18l-2-1.2L13 21l-2-1.2L9 21l-2-1.2L5 21V3h2z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'clock' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/>',
        'bell' => '<path d="M6 9a6 6 0 1 1 12 0c0 7 2.5 7 2.5 9h-17C3.5 16 6 16 6 9"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        'user' => '<circle cx="12" cy="8" r="3.2"/><path d="M5 19c1.4-3 3.6-4.5 7-4.5S17.6 16 19 19"/>',
        'users' => '<circle cx="9" cy="9" r="3"/><path d="M3.5 19c1-2.6 2.8-4 5.5-4s4.5 1.4 5.5 4"/><circle cx="17" cy="9" r="2.2"/><path d="M16 15c1.8.3 3.2 1.4 4.2 4"/>',
        'card' => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/>',
        'settings' => '<path d="M4 8h10"/><path d="M18 8h2"/><circle cx="16" cy="8" r="2"/><path d="M4 16h2"/><path d="M10 16h10"/><circle cx="8" cy="16" r="2"/>',
        'list' => '<path d="M9 7h11M9 12h11M9 17h11"/><path d="M4 7h.1M4 12h.1M4 17h.1"/>',
        'shield' => '<path d="M12 3 5 6v6c0 4.2 2.8 7.2 7 8.5 4.2-1.3 7-4.3 7-8.5V6l-7-3z"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'bolt' => '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/>',
        'out' => '<path d="M10 7V5H5v14h5v-2"/><path d="M10 12h9"/><path d="M16 8l4 4-4 4"/>',
    ];
    $body = $inner[$name] ?? $inner['list'];
    return '<svg class="icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}

function brand_mark(string $href): string
{
    return '<a class="brand" href="' . e($href) . '"><span class="mark" aria-hidden="true">&#8377;</span><span class="brand-text"><strong>SERP</strong><small>UPI</small></span></a>';
}

function render_pin_field(string $name, string $label, string $id, bool $hint = true): void
{
    echo '<div class="field">';
    echo '<label for="' . e($id) . '">' . e($label) . '</label>';
    echo '<div class="pin-wrap">';
    echo '<div class="pin-dots" data-pin-dots="' . e($id) . '" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span></div>';
    echo '<input id="' . e($id) . '" name="' . e($name) . '" class="pin-input" type="password" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="off" required>';
    echo '</div>';
    echo '<div class="pin-pad" data-pin-pad="' . e($id) . '">';
    foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', 'clear', '0', 'back'] as $key) {
        $text = $key === 'clear' ? 'Clear' : ($key === 'back' ? 'Del' : $key);
        $aria = $key === 'back' ? ' aria-label="Delete"' : ($key === 'clear' ? ' aria-label="Clear"' : '');
        echo '<button type="button" data-pin-key="' . e($key) . '"' . $aria . '>' . e($text) . '</button>';
    }
    echo '</div>';
    if ($hint) {
        echo '<p class="help">Six digits you choose in SERP UPI. Do not enter a bank, GPay, or PhonePe PIN.</p>';
    }
    echo '</div>';
}

function render_document_start(string $title, string $bodyClass = ''): void
{
    $fullTitle = $title === 'SERP UPI' ? 'SERP UPI' : $title . '  -  SERP UPI';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="theme-color" content="#0e7a4b">';
    echo '<meta name="description" content="SERP UPI pays LIC premiums and Bihar electricity bills, and reminds you before the due date.">';
    echo '<meta name="robots" content="noindex">';
    echo '<meta name="apple-mobile-web-app-capable" content="yes">';
    echo '<title>' . e($fullTitle) . '</title>';
    echo '<link rel="icon" href="' . e(url('assets/favicon.svg')) . '">';
    echo '<link rel="manifest" href="' . e(url('manifest.json')) . '">';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,560;9..144,650&family=Outfit:wght@400;500;600;700&display=swap">';
    echo '<link rel="stylesheet" href="' . e(url('assets/css/app.css')) . '?v=2">';
    echo '</head><body class="' . e($bodyClass) . '">';
    echo '<a class="skip" href="#content">Skip to content</a>';
}

function render_document_end(): void
{
    echo '<script src="' . e(url('assets/js/app.js')) . '"></script></body></html>';
}

function render_public_start(string $title): void
{
    $user = current_user();
    render_document_start($title, 'is-public');
    echo '<header class="site-header"><div class="wrap header-row">';
    echo brand_mark(url('index.php'));
    echo '<nav class="site-nav" aria-label="Public">';
    echo '<a class="hide-sm" href="' . e(url('index.php')) . '#how">How it works</a>';
    echo '<a class="hide-sm" href="' . e(url('index.php')) . '#safety">Safety</a>';
    if ($user) {
        echo '<a class="btn btn-primary" href="' . e(url(home_path($user))) . '">Open app</a>';
    } else {
        echo '<a class="hide-sm" href="' . e(url('login.php')) . '">Sign in</a>';
        echo '<a class="btn btn-primary" href="' . e(url('register.php')) . '">Get started</a>';
    }
    echo '</nav></div></header><main class="public-main" id="content">';
}

function render_public_end(): void
{
    echo '</main><footer class="site-footer"><div class="wrap footer-row">';
    echo '<strong>SERP UPI</strong>';
    echo '<span>LIC and Bihar electricity</span>';
    echo '<span>Pay inside SERP UPI with your 6-digit PIN</span>';
    echo '</div></footer>';
    render_document_end();
}

function shell_links(string $mode): array
{
    if ($mode === 'admin') {
        return [
            ['overview', 'Overview', 'admin/index.php', 'home'],
            ['users', 'Users', 'admin/users.php', 'users'],
            ['accounts', 'Linked accounts', 'admin/accounts.php', 'link'],
            ['billers', 'Billers', 'admin/billers.php', 'bolt'],
            ['records', 'Bill records', 'admin/records.php', 'list'],
            ['bills', 'Customer bills', 'admin/bills.php', 'bill'],
            ['payments', 'Payments', 'admin/payments.php', 'card'],
            ['reminders', 'Reminders', 'admin/reminders.php', 'bell'],
            ['settings', 'Settings', 'admin/settings.php', 'settings'],
            ['audit', 'Audit log', 'admin/audit.php', 'shield'],
        ];
    }
    return [
        ['home', 'Home', 'app/index.php', 'home'],
        ['accounts', 'Linked accounts', 'app/accounts.php', 'link'],
        ['bills', 'Bills', 'app/bills.php', 'bill'],
        ['wallet', 'Wallet', 'app/wallet.php', 'card'],
        ['history', 'Payments', 'app/history.php', 'clock'],
        ['reminders', 'Reminders', 'app/reminders.php', 'bell'],
        ['profile', 'Profile', 'app/profile.php', 'user'],
    ];
}

function bottom_links(string $mode): array
{
    if ($mode === 'admin') {
        return [
            ['overview', 'Home', 'admin/index.php', 'home'],
            ['payments', 'Payments', 'admin/payments.php', 'card'],
            ['bills', 'Bills', 'admin/bills.php', 'bill'],
            ['users', 'Users', 'admin/users.php', 'users'],
        ];
    }
    return [
        ['home', 'Home', 'app/index.php', 'home'],
        ['bills', 'Bills', 'app/bills.php', 'bill'],
        ['wallet', 'Wallet', 'app/wallet.php', 'card'],
        ['history', 'History', 'app/history.php', 'clock'],
        ['reminders', 'Alerts', 'app/reminders.php', 'bell'],
    ];
}

function render_nav_links(array $links, string $active, string $class): void
{
    foreach ($links as $link) {
        $isActive = $link[0] === $active;
        echo '<a class="' . $class . ($isActive ? ' is-active' : '') . '" href="' . e(url($link[2])) . '"' . ($isActive ? ' aria-current="page"' : '') . '>';
        echo icon($link[3]) . '<span>' . e($link[1]) . '</span></a>';
    }
}

function render_shell_start(string $title, string $active, string $mode = 'user', string $subtitle = ''): void
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    $unread = unread_count((int) $user['id']);
    render_document_start($title, $mode === 'admin' ? 'is-admin' : 'is-user');
    echo '<div class="nav-backdrop" data-nav-toggle></div>';
    echo '<div class="shell"><aside class="sidebar" id="sidebar">';
    echo brand_mark(url($mode === 'admin' ? 'admin/index.php' : 'app/index.php'));
    echo '<div class="who"><strong>' . e($user['full_name']) . '</strong><small>' . e($user['role'] === 'admin' ? 'Administrator' : 'Customer') . '</small></div>';
    echo '<nav class="side-links" aria-label="' . ($mode === 'admin' ? 'Admin' : 'Account') . '">';
    render_nav_links(shell_links($mode), $active, 'nav-link');
    echo '</nav><div class="sidebar-foot">';
    if ($mode === 'admin') {
        echo '<a class="nav-link" href="' . e(url('app/index.php')) . '">' . icon('bill') . '<span>Customer view</span></a>';
    } elseif ($user['role'] === 'admin') {
        echo '<a class="nav-link" href="' . e(url('admin/index.php')) . '">' . icon('shield') . '<span>Admin panel</span></a>';
    }
    if ($mode === 'admin') {
        echo '<a class="nav-link" href="' . e(url('app/profile.php')) . '">' . icon('user') . '<span>My profile</span></a>';
    }
    echo '<form method="post" action="' . e(url('logout.php')) . '">' . csrf_field();
    echo '<button class="nav-link nav-button" type="submit">' . icon('out') . '<span>Sign out</span></button></form>';
    echo '</div></aside>';
    echo '<div class="workspace"><header class="topbar">';
    echo '<button class="icon-btn" type="button" data-nav-toggle aria-label="Open menu">' . icon('menu') . '</button>';
    echo '<div class="topbar-title"><h1 class="page-title">' . e($title) . '</h1>';
    if ($subtitle !== '') {
        echo '<p>' . e($subtitle) . '</p>';
    }
    echo '</div>';
    echo '<a class="icon-btn bell" href="' . e(url('app/notifications.php')) . '" aria-label="Notifications">';
    echo icon('bell');
    if ($unread > 0) {
        echo '<span class="badge-count">' . ($unread > 9 ? '9+' : (int) $unread) . '</span>';
    }
    echo '</a></header><main class="app-main" id="content">';
    render_flashes();
}

function render_shell_end(string $mode = 'user', string $active = ''): void
{
    echo '</main></div></div>';
    echo '<nav class="bottom-nav" aria-label="Primary">';
    render_nav_links(bottom_links($mode), $active, 'bottom-link');
    echo '<button class="bottom-link" type="button" data-nav-toggle>' . icon('menu') . '<span>Menu</span></button>';
    echo '</nav>';
    render_document_end();
}

function require_admin(): array
{
    $user = require_user();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        render_shell_start('Not allowed', '', 'user');
        echo '<div class="empty"><h2>This area is for administrators.</h2>';
        echo '<p><a class="btn btn-primary" href="' . e(url('app/index.php')) . '">Back to your bills</a></p></div>';
        render_shell_end('user');
        exit;
    }
    return $user;
}

function fail_not_found(string $mode = 'user'): void
{
    http_response_code(404);
    render_shell_start('Not found', '', $mode);
    $home = $mode === 'admin' ? 'admin/index.php' : 'app/index.php';
    echo '<div class="empty"><h2>We could not find that record.</h2>';
    echo '<p><a class="btn btn-primary" href="' . e(url($home)) . '">Back to home</a></p></div>';
    render_shell_end($mode);
    exit;
}

function render_bill_card(array $bill): void
{
    $tone = ($bill['category'] ?? '') === 'lic' ? 'tone-lic' : 'tone-power';
    echo '<article class="bill-card ' . $tone . '">';
    echo '<div class="bill-card-top"><span class="badge">' . e(category_label((string) $bill['category'])) . '</span>';
    echo '<span class="badge badge-' . e((string) $bill['status']) . '">' . e(status_label((string) $bill['status'])) . '</span></div>';
    echo '<h3>' . e((string) $bill['biller_name']) . '</h3>';
    echo '<p class="muted">' . e((string) $bill['billing_period']) . '  -  ' . e((string) $bill['account_number']) . '</p>';
    echo '<p class="amount">' . e(money(amount_due($bill))) . '</p>';
    echo '<p class="due-line">' . e(due_label((string) $bill['due_date'])) . '</p>';
    if (($bill['status'] ?? '') !== 'paid' && (float) ($bill['late_fee'] ?? 0) > 0 && is_past_due($bill)) {
        echo '<p class="muted">Includes late fee ' . e(money($bill['late_fee'])) . '</p>';
    }
    echo '<div class="bill-card-actions">';
    echo '<a class="btn btn-sm btn-ghost" href="' . e(url('app/bills.php?id=' . (int) $bill['id'])) . '">Details</a>';
    if (($bill['status'] ?? '') !== 'paid') {
        echo '<a class="btn btn-sm btn-primary" href="' . e(url('app/pay.php?bill=' . (int) $bill['id'])) . '">Pay</a>';
    } else {
        $payment = successful_payment((int) $bill['id']);
        if ($payment) {
            echo '<a class="btn btn-sm btn-ghost" href="' . e(url('app/receipt.php?id=' . (int) $payment['id'])) . '">Receipt</a>';
        }
    }
    echo '</div></article>';
}

function render_bill_summary(array $bill): void
{
    $rows = [
        'Biller' => (string) $bill['biller_name'],
        'Category' => category_label((string) ($bill['category'] ?? '')),
        'Account' => (string) $bill['account_number'],
        'Name on bill' => (string) $bill['customer_name'],
        'Period' => (string) $bill['billing_period'],
        'Bill number' => (string) $bill['bill_number'],
        'Bill date' => format_date((string) $bill['bill_date']),
        'Due date' => format_date((string) $bill['due_date']) . '  -  ' . due_label((string) $bill['due_date']),
        'Amount' => money($bill['amount']),
    ];
    if ((float) ($bill['late_fee'] ?? 0) > 0) {
        $late = money($bill['late_fee']);
        if (($bill['status'] ?? '') !== 'paid' && is_past_due($bill)) {
            $late .= ' (included)';
        } elseif (($bill['status'] ?? '') !== 'paid') {
            $late .= ' (after the due date)';
        }
        $rows['Late fee'] = $late;
    }
    if ($bill['units'] !== null && $bill['units'] !== '') {
        $rows['Units'] = (string) $bill['units'] . ' kWh';
    }
    if (($bill['plan_name'] ?? '') !== '') {
        $rows['Plan'] = (string) $bill['plan_name'];
    }
    if (($bill['details'] ?? '') !== '') {
        $rows['Details'] = (string) $bill['details'];
    }
    $rows['Payable'] = money(amount_due($bill));
    echo '<dl class="facts">';
    foreach ($rows as $label => $value) {
        echo '<div><dt>' . e($label) . '</dt><dd>' . e($value) . '</dd></div>';
    }
    echo '</dl>';
}
