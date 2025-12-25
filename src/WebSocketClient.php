<?php

class WebSocketClient
{
    private string $host;
    private int $port;
    private string $path;
    private $socket;

    public function __construct(string $url)
    {
        $parts = parse_url($url);
        $this->host = $parts['host'] ?? '';
        $this->port = (int) ($parts['port'] ?? 443);
        $this->path = $parts['path'] ?? '/';
    }

    public function connect(): void
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $this->socket = stream_socket_client(
            'ssl://' . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->socket) {
            throw new RuntimeException('WebSocket connection failed: ' . $errstr);
        }

        stream_set_timeout($this->socket, 5);

        $key = base64_encode(random_bytes(16));
        $headers = [
            'GET ' . $this->path . ' HTTP/1.1',
            'Host: ' . $this->host,
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . $key,
            'Sec-WebSocket-Version: 13',
            'User-Agent: PolymarketMonitor/1.0',
        ];

        $request = implode("\r\n", $headers) . "\r\n\r\n";
        fwrite($this->socket, $request);

        $response = stream_get_contents($this->socket);
        if ($response === false || !str_contains($response, '101 Switching Protocols')) {
            throw new RuntimeException('WebSocket handshake failed.');
        }
    }

    public function send(string $payload): void
    {
        $frame = $this->encode($payload);
        fwrite($this->socket, $frame);
    }

    public function receive(): ?string
    {
        $header = fread($this->socket, 2);
        if ($header === false || strlen($header) < 2) {
            return null;
        }

        $first = ord($header[0]);
        $second = ord($header[1]);
        $length = $second & 127;
        if ($length === 126) {
            $ext = fread($this->socket, 2);
            $length = unpack('n', $ext)[1];
        } elseif ($length === 127) {
            $ext = fread($this->socket, 8);
            $length = unpack('J', $ext)[1];
        }

        $mask = ($second & 128) === 128;
        $maskKey = '';
        if ($mask) {
            $maskKey = fread($this->socket, 4);
        }

        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        if ($mask && $maskKey !== '') {
            $decoded = '';
            for ($i = 0; $i < $length; $i++) {
                $decoded .= $data[$i] ^ $maskKey[$i % 4];
            }
            $data = $decoded;
        }

        $opcode = $first & 0x0F;
        if ($opcode === 0x8) {
            return null;
        }

        return $data;
    }

    private function encode(string $payload): string
    {
        $length = strlen($payload);
        $frame = chr(0x81);

        if ($length <= 125) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $length);
        }

        $mask = random_bytes(4);
        $frame .= $mask;

        $maskedPayload = '';
        for ($i = 0; $i < $length; $i++) {
            $maskedPayload .= $payload[$i] ^ $mask[$i % 4];
        }

        return $frame . $maskedPayload;
    }
}
