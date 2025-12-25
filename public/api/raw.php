<?php

require_once __DIR__ . '/../../src/bootstrap.php';

$base = $_GET['base'] ?? 'clob';
$path = $_GET['path'] ?? '/';

$allowedBases = [
    'clob' => $config['raw_api']['clob_base_url'],
    'data' => $config['raw_api']['data_base_url'],
];

if (!isset($allowedBases[$base])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid base. Use clob or data.']);
    exit;
}

if (!str_starts_with($path, '/')) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Path must start with /']);
    exit;
}

$query = $_GET;
unset($query['base'], $query['path']);

$client = new HttpClient($allowedBases[$base]);
$response = $client->get($path, $query);

header('Content-Type: application/json; charset=utf-8');

echo json_encode($response, JSON_UNESCAPED_SLASHES);
