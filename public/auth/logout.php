<?php
declare(strict_types=1);

/**
 * ROUTLAW — Logout
 */

require __DIR__ . '/../../config/autoload.php';

use Routlaw\Security\Auth;
use Routlaw\Security\Headers;
use Routlaw\Db\Connection;

Headers::emit(false);

session_start();

// Destroy session
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
session_destroy();

header('Location: /auth/login.php?logged_out=1');
exit;