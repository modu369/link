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

        if (!isset($payload['data']['amount'])) {
            throw new RuntimeException('Unexpected price payload.');
        }

        return (float) $payload['data']['amount'];
    }
}
