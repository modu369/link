<?php

class MarketDataService
{
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/Config.php';
    }

    public function fetchSnapshot(): array
    {
        $eventSlug = $this->config['polymarket']['event_slug'];
        $event = $this->fetchEvent($eventSlug);
        $market = $this->extractPrimaryMarket($event);

        $openTime = $this->parseTime($event['startTime'] ?? $event['start_time'] ?? $event['openTime'] ?? $event['open_time'] ?? null);
        $closeTime = $this->parseTime($event['endTime'] ?? $event['end_time'] ?? $event['closeTime'] ?? $event['close_time'] ?? null);

        $openPrice = $this->extractNumber([
            $event['priceToBeat'] ?? null,
            $event['price_to_beat'] ?? null,
            $event['strikePrice'] ?? null,
            $event['strike_price'] ?? null,
            $market['priceToBeat'] ?? null,
            $market['price_to_beat'] ?? null,
            $market['openPrice'] ?? null,
            $market['open_price'] ?? null,
            $market['strikePrice'] ?? null,
            $market['strike_price'] ?? null,
        ]);

        $currentPrice = $this->extractNumber([
            $event['currentPrice'] ?? null,
            $event['current_price'] ?? null,
            $market['currentPrice'] ?? null,
            $market['current_price'] ?? null,
            $market['indexPrice'] ?? null,
            $market['index_price'] ?? null,
            $market['lastPrice'] ?? null,
            $market['last_price'] ?? null,
            $market['lastTradePrice'] ?? null,
            $market['last_trade_price'] ?? null,
            $market['spotPrice'] ?? null,
            $market['spot_price'] ?? null,
            $market['price'] ?? null,
        ]);

        $outcomePrices = $this->extractOutcomePrices($market);
        $upPrice = $outcomePrices['up'] ?? null;
        $downPrice = $outcomePrices['down'] ?? null;

        if ($openPrice === null && $currentPrice !== null) {
            $openPrice = $currentPrice;
        }

        if ($currentPrice === null && $openPrice !== null) {
            $currentPrice = $openPrice;
        }

        if ($openPrice === null || $currentPrice === null) {
            throw new RuntimeException('Unable to resolve Polymarket prices from API response.');
        }

        if ($upPrice === null || $downPrice === null) {
            $priceDelta = $currentPrice - $openPrice;
            $upPrice = max(0, min(100, 50 + ($priceDelta / $openPrice) * 100));
            $downPrice = 100 - $upPrice;
        }

        $roundKey = $event['id'] ?? $eventSlug . '-' . $openTime->format('YmdHi');

        return [
            'event_title' => (string) ($event['title'] ?? $event['name'] ?? 'Bitcoin Up or Down'),
            'event_slug' => $eventSlug,
            'round_key' => (string) $roundKey,
            'open_time' => $openTime->format('Y-m-d H:i:s'),
            'close_time' => $closeTime->format('Y-m-d H:i:s'),
            'open_price' => $openPrice,
            'current_price' => $currentPrice,
            'up_position' => round($upPrice, 2),
            'down_position' => round($downPrice, 2),
        ];
    }

    private function fetchEvent(string $slug): array
    {
        $urls = $this->config['polymarket']['event_urls'];
        foreach ($urls as $urlTemplate) {
            $url = sprintf($urlTemplate, rawurlencode($slug));
            try {
                $payload = $this->fetchJson($url);
            } catch (Throwable $exception) {
                continue;
            }

            $event = $this->normalizeEvent($payload);
            if ($event !== []) {
                return $event;
            }
        }

        throw new RuntimeException('Unable to fetch Polymarket event data.');
    }

    private function fetchJson(string $url): array
    {
        $timeout = (int) $this->config['polymarket']['timeout_seconds'];
        $userAgent = $this->config['polymarket']['user_agent'];

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

    private function normalizeEvent(array $payload): array
    {
        if (isset($payload['event'])) {
            return (array) $payload['event'];
        }

        if (isset($payload['events']) && is_array($payload['events'])) {
            return (array) ($payload['events'][0] ?? []);
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            if (isset($payload['data']['event'])) {
                return (array) $payload['data']['event'];
            }
            if (isset($payload['data'][0])) {
                return (array) $payload['data'][0];
            }
        }

        if (isset($payload[0])) {
            return (array) $payload[0];
        }

        return $payload;
    }

    private function extractPrimaryMarket(array $event): array
    {
        $markets = $event['markets'] ?? $event['market'] ?? [];
        if (is_string($markets)) {
            $decoded = json_decode($markets, true);
            if (is_array($decoded)) {
                $markets = $decoded;
            }
        }

        if (isset($markets['id'])) {
            return (array) $markets;
        }

        if (is_array($markets) && $markets !== []) {
            return (array) ($markets[0] ?? []);
        }

        return [];
    }

    private function parseTime($value): DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return new DateTimeImmutable();
        }

        if (is_numeric($value)) {
            return (new DateTimeImmutable())->setTimestamp((int) $value);
        }

        return new DateTimeImmutable((string) $value);
    }

    private function extractOutcomePrices(array $market): array
    {
        $outcomes = $market['outcomes'] ?? $market['outcomeNames'] ?? null;
        $prices = $market['outcomePrices'] ?? $market['prices'] ?? null;

        if (is_string($outcomes)) {
            $outcomes = json_decode($outcomes, true);
        }

        if (is_string($prices)) {
            $prices = json_decode($prices, true);
        }

        if (!is_array($outcomes) || !is_array($prices)) {
            return [];
        }

        $mapped = [];
        foreach ($outcomes as $index => $name) {
            $price = $prices[$index] ?? null;
            if ($price === null) {
                continue;
            }

            $normalizedPrice = (float) $price;
            if ($normalizedPrice <= 1) {
                $normalizedPrice *= 100;
            }

            $key = strtolower((string) $name);
            if ($key === 'up' || $key === 'yes') {
                $mapped['up'] = $normalizedPrice;
            }
            if ($key === 'down' || $key === 'no') {
                $mapped['down'] = $normalizedPrice;
            }
        }

        return $mapped;
    }

    private function extractNumber(array $candidates): ?float
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            if (is_numeric($candidate)) {
                return (float) $candidate;
            }

            if (is_string($candidate)) {
                $normalized = preg_replace('/[^0-9.\-]/', '', $candidate);
                if ($normalized !== '' && is_numeric($normalized)) {
                    return (float) $normalized;
                }
            }
        }

        return null;
    }
}
