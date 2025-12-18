<?php
declare(strict_types=1);
require __DIR__ . '/../src/IpResolver.php';

$dbPath = __DIR__ . '/../data/qqwry.ipdb';
$ip = $_GET['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');

$resolver = new IpResolver($dbPath);
$result = $resolver->resolve($ip);

header('Content-Type: text/plain; charset=utf-8');
echo "查询 IP: {$ip}\n";
echo "--------------------------------\n";

if (empty($result)) {
    echo "未查询到结果\n";
    exit;
}

foreach ($result as $k => $v) {
    printf("%-15s : %s\n", $k, $v);
}
