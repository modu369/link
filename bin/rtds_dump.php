<?php

$host = 'ws-live-data.polymarket.com';
$port = 443;
$path = '/';
$timeout = 10;

$options = getopt('', ['path::', 'send::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/rtds_dump.php [--path=/] [--send='{}']\n";
    exit(0);
}

if (isset($options['path'])) {
    $path = $options['path'];
}

$sendPayload = $options['send'] ?? null;

$context = stream_context_create([
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
]);

$socket = stream_socket_client(
    'tls://' . $host . ':' . $port,
    $errno,
    $errstr,
    $timeout,
    STREAM_CLIENT_CONNECT,
    $context
);

if ($socket === false) {
    fwrite(STDERR, "Failed to connect: $errstr ($errno)\n");
    exit(1);
}

stream_set_timeout($socket, $timeout);

$key = base64_encode(random_bytes(16));
$request = "GET {$path} HTTP/1.1\r\n";
$request .= "Host: {$host}\r\n";
$request .= "Upgrade: websocket\r\n";
$request .= "Connection: Upgrade\r\n";
$request .= "Sec-WebSocket-Key: {$key}\r\n";
$request .= "Sec-WebSocket-Version: 13\r\n\r\n";

fwrite($socket, $request);

$response = '';
while (!str_contains($response, "\r\n\r\n")) {
    $chunk = fread($socket, 1024);
    if ($chunk === '' || $chunk === false) {
        fwrite(STDERR, "Handshake failed: no response\n");
        exit(1);
    }
    $response .= $chunk;
}

if (!str_contains($response, " 101 ")) {
    fwrite(STDERR, "Handshake failed:\n{$response}\n");
    exit(1);
}

if ($sendPayload !== null) {
    sendFrame($socket, $sendPayload);
}

while (!feof($socket)) {
    $frame = readFrame($socket);
    if ($frame === null) {
        break;
    }

    if ($frame['opcode'] === 0x8) {
        fwrite(STDOUT, "[close]" . PHP_EOL);
        break;
    }

    if ($frame['opcode'] === 0x9) {
        sendFrame($socket, $frame['payload'], 0xA);
        continue;
    }

    if ($frame['opcode'] === 0x1 || $frame['opcode'] === 0x2) {
        fwrite(STDOUT, $frame['payload'] . PHP_EOL);
    }
}

fclose($socket);

function readFrame($socket): ?array
{
    $header = fread($socket, 2);
    if ($header === '' || $header === false) {
        return null;
    }

    $bytes = unpack('Cfirst/Csecond', $header);
    $fin = ($bytes['first'] >> 7) & 1;
    $opcode = $bytes['first'] & 0x0F;
    $masked = ($bytes['second'] >> 7) & 1;
    $length = $bytes['second'] & 0x7F;

    if ($length === 126) {
        $ext = fread($socket, 2);
        if ($ext === false || strlen($ext) !== 2) {
            return null;
        }
        $length = unpack('n', $ext)[1];
    } elseif ($length === 127) {
        $ext = fread($socket, 8);
        if ($ext === false || strlen($ext) !== 8) {
            return null;
        }
        $parts = unpack('N2', $ext);
        $length = ($parts[1] << 32) + $parts[2];
    }

    $mask = '';
    if ($masked) {
        $mask = fread($socket, 4);
        if ($mask === false || strlen($mask) !== 4) {
            return null;
        }
    }

    $payload = '';
    $remaining = $length;
    while ($remaining > 0) {
        $chunk = fread($socket, $remaining);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $payload .= $chunk;
        $remaining -= strlen($chunk);
    }

    if ($masked) {
        $payload = applyMask($payload, $mask);
    }

    return [
        'fin' => (bool) $fin,
        'opcode' => $opcode,
        'payload' => $payload,
    ];
}

function sendFrame($socket, string $payload, int $opcode = 0x1): void
{
    $fin = 0x80;
    $header = chr($fin | ($opcode & 0x0F));
    $length = strlen($payload);
    $maskBit = 0x80;

    if ($length <= 125) {
        $header .= chr($maskBit | $length);
    } elseif ($length <= 65535) {
        $header .= chr($maskBit | 126) . pack('n', $length);
    } else {
        $header .= chr($maskBit | 127) . pack('N2', 0, $length);
    }

    $mask = random_bytes(4);
    $maskedPayload = applyMask($payload, $mask);

    fwrite($socket, $header . $mask . $maskedPayload);
}

function applyMask(string $payload, string $mask): string
{
    $masked = '';
    $maskLength = strlen($mask);
    $payloadLength = strlen($payload);

    for ($i = 0; $i < $payloadLength; $i++) {
        $masked .= $payload[$i] ^ $mask[$i % $maskLength];
    }

    return $masked;
}
