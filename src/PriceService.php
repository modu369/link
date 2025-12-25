<?php

class PriceService
{
    public function fetchCurrentPrice(): float
    {
        $config = require __DIR__ . '/Config.php';
        $url = $config['price']['provider_url'];
        $timeout = (int) $config['price']['timeout_seconds'];

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('Unable to fetch BTC price.');
        }

        $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        $candidates = [
            $payload['data']['amount'] ?? null,
            $payload['data']['price'] ?? null,
            $payload['price'] ?? null,
            $payload['result']['price'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '' && is_numeric($candidate)) {
                return (float) $candidate;
            }
        }

        throw new RuntimeException('Unexpected price payload.');
    }
}
