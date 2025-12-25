<?php

class PolymarketApiClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function fetchMarketBySlug(string $slug): array
    {
        $urls = $this->config['market_urls'] ?? [];
        return $this->fetchFirstMatch($urls, $slug, 'market');
    }

    public function fetchEventBySlug(string $slug): array
    {
        $urls = $this->config['event_urls'] ?? [];
        return $this->fetchFirstMatch($urls, $slug, 'event');
    }

    private function fetchFirstMatch(array $urls, string $slug, string $type): array
    {
        foreach ($urls as $urlTemplate) {
            $url = sprintf($urlTemplate, rawurlencode($slug));
            try {
                $payload = $this->fetchJson($url);
            } catch (Throwable $exception) {
                continue;
            }

            $items = $this->normalizePayload($payload, $type);
            foreach ($items as $item) {
                $itemSlug = $item['slug'] ?? $item['id'] ?? null;
                if ($itemSlug !== null && (string) $itemSlug === $slug) {
                    return (array) $item;
                }
            }

            if ($items !== []) {
                return (array) $items[0];
            }
        }

        return [];
    }

    private function fetchJson(string $url): array
    {
        $timeout = (int) ($this->config['timeout_seconds'] ?? 5);
        $userAgent = $this->config['user_agent'] ?? 'Mozilla/5.0 (compatible; PolymarketMonitor/1.0)';

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header' => [
                    'User-Agent: ' . $userAgent,
                    'Accept: application/json',
                ],
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('Failed to fetch Polymarket data.');
        }

        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    private function normalizePayload(array $payload, string $type): array
    {
        if ($type === 'market') {
            if (isset($payload['market'])) {
                return [(array) $payload['market']];
            }
            if (isset($payload['markets']) && is_array($payload['markets'])) {
                return array_map('array_filter', $payload['markets']);
            }
        }

        if ($type === 'event') {
            if (isset($payload['event'])) {
                return [(array) $payload['event']];
            }
            if (isset($payload['events']) && is_array($payload['events'])) {
                return array_map('array_filter', $payload['events']);
            }
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            if (isset($payload['data'][$type . 's']) && is_array($payload['data'][$type . 's'])) {
                return array_map('array_filter', $payload['data'][$type . 's']);
            }
            if (isset($payload['data'][$type]) && is_array($payload['data'][$type])) {
                return [(array) $payload['data'][$type]];
            }
            if (isset($payload['data'][0])) {
                return array_map('array_filter', $payload['data']);
            }
        }

        if (isset($payload[0])) {
            return array_map('array_filter', $payload);
        }

        return $payload !== [] ? [(array) $payload] : [];
    }
}
