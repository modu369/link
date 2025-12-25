<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Ratchet\Client\WebSocket;
use function Ratchet\Client\connect as ws_connect;

$options = getopt('', ['send::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/rtds_pawl.php --send='{\"type\":\"subscribe\",\"channel\":\"markets\"}'\n";
    exit(0);
}

$sendPayload = $options['send'] ?? null;

ws_connect('wss://ws-live-data.polymarket.com')->then(
    function (WebSocket $conn) use ($sendPayload) {
        echo "Connected\n";

        if ($sendPayload !== null) {
            $conn->send($sendPayload);
        }

        $conn->on('message', function ($msg) {
            echo $msg . "\n";
        });

        $conn->on('close', function () {
            echo "Connection closed\n";
        });
    },
    function (\Exception $e) {
        echo "Could not connect: {$e->getMessage()}\n";
    }
);
