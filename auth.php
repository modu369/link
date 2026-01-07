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
$entryValue = (string)($settings['entry_value'] ?? 'admin123');
header('Location: /portal.php?entry=' . urlencode($entryValue) . '&error=1');
exit;
