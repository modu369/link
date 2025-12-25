<?php

class PriceService
{
    public function fetchCurrentPrice(): float
    {
        $config = require __DIR__ . '/Config.php';
        $cachePath = $config['price']['cache_path'] ?? null;
        if ($cachePath) {
            require_once __DIR__ . '/PriceCache.php';
            $cache = new PriceCache($cachePath);
            $cached = $cache->read();
            if (is_array($cached) && isset($cached['current_price'])) {
                return (float) $cached['current_price'];
            }
        }

        $urls = $config['price']['provider_urls'] ?? [];
        $timeout = (int) $config['price']['timeout_seconds'];

        if ($urls === []) {
            throw new RuntimeException('No price providers configured.');
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
            ],
        ]);

        foreach ($urls as $url) {
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                continue;
            }

            try {
                $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                continue;
            }

            $candidates = [
                $payload['data']['amount'] ?? null,
                $payload['data']['price'] ?? null,
                $payload['price'] ?? null,
                $payload['result']['price'] ?? null,
                $payload['prices'][0]['price'] ?? null,
                $payload['prices'][0]['value'] ?? null,
            ];

            foreach ($candidates as $candidate) {
                if ($candidate !== null && $candidate !== '' && is_numeric($candidate)) {
                    return (float) $candidate;
                }
            }
        }

        throw new RuntimeException('Unexpected price payload.');
    }
}
