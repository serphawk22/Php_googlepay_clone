<?php
declare(strict_types=1);

if (!defined('SERP_ROOT')) {
    define('SERP_ROOT', dirname(__DIR__));
}

$logDir = SERP_ROOT . DIRECTORY_SEPARATOR . 'storage';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $logDir . DIRECTORY_SEPARATOR . 'php-error.log');
error_reporting(E_ALL);

require_once SERP_ROOT . '/includes/helpers.php';
require_once SERP_ROOT . '/includes/razorpay.php';
require_once SERP_ROOT . '/includes/billing.php';
require_once SERP_ROOT . '/includes/layout.php';

set_exception_handler(static function (Throwable $e): void {
    error_log($e->__toString());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!DOCTYPE html><html lang="en"><meta charset="utf-8"><title>SERP UPI</title><p>Something went wrong. Please try again.</p></html>';
});

if (!headers_sent()) {
    if (!(defined('SERP_API') && SERP_API)) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
}

function serp_installed(): bool
{
    return is_file(SERP_ROOT . '/config/config.php') && is_file(SERP_ROOT . '/config/installed.lock');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookiePath = base_path();
    if ($cookiePath === '') {
        $cookiePath = '/';
    }
    session_name('SERPUPI');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => request_is_https(),
    ]);
    session_start();
}

$isInstaller = defined('SERP_INSTALL') && SERP_INSTALL;
if (!$isInstaller && !serp_installed()) {
    redirect('install.php');
}

if (!$isInstaller) {
    $GLOBALS['config'] = require SERP_ROOT . '/config/config.php';
    try {
        db();
        ensure_razorpay_schema();
    } catch (Throwable $e) {
        error_log($e->__toString());
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="en"><meta charset="utf-8"><title>SERP UPI</title><p>Database connection failed. Check that MySQL is running and config/config.php matches it.</p></html>';
        exit;
    }
    if (!empty($_SESSION['user_id'])) {
        run_reminder_engine(false);
    }
}
