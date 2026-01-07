<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /login.html');
    exit;
}

$settings = loadSettings();
$password = (string)($_POST['password'] ?? '');

if ($password !== '' && hash_equals($settings['admin_password'], $password)) {
    $_SESSION['authenticated'] = true;
    header('Location: /index.html');
    exit;
}

$_SESSION['authenticated'] = false;
header('Location: /login.html?error=1');
exit;
