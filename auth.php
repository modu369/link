<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /portal.html');
    exit;
}

$settings = loadSettings();
$password = (string)($_POST['password'] ?? '');

if ($password !== '' && hash_equals($settings['admin_password'], $password)) {
    $_SESSION['authenticated'] = true;
    header('Location: /console.html');
    exit;
}

$_SESSION['authenticated'] = false;
header('Location: /portal.html?error=1');
exit;
