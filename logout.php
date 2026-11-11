<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $signedIn = current_user();
    redirect($signedIn ? home_path($signedIn) : 'login.php');
}
csrf_verify();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
}
session_destroy();
header('Location: ' . absolute_url('login.php?out=1'));
exit;
