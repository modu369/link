<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /portal.php');
    exit;
}

$settings = loadSettings();
$password = (string)($_POST['password'] ?? '');

if (!($_SESSION['entry_valid'] ?? false)) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

if ($password !== '' && hash_equals($settings['admin_password'], $password)) {
    $_SESSION['authenticated'] = true;
    header('Location: /console.php');
    exit;
}

$_SESSION['authenticated'] = false;
header('Location: /portal.php?entry=' . urlencode(hash('sha256', (string)$settings['admin_password'])) . '&error=1');
exit;
