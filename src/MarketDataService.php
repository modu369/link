<?php

require_once __DIR__ . '/PriceService.php';
require_once __DIR__ . '/PolymarketApiClient.php';

class MarketDataService
{
    private array $config;
    private DateTimeZone $marketTimezone;
    private PolymarketApiClient $client;
    private PriceService $priceService;

    public function __construct()
    {
        $this->config = require __DIR__ . '/Config.php';
        $this->marketTimezone = new DateTimeZone('America/New_York');
        $this->client = new PolymarketApiClient($this->config['polymarket']);
        $this->priceService = new PriceService();
    }

    public function fetchSnapshot(): array
    {
        $eventSlug = $this->config['polymarket']['event_slug'];
        $market = $this->client->fetchMarketBySlug($eventSlug);
        $event = $this->client->fetchEventBySlug($eventSlug);
        $event = $event !== [] ? $event : $this->extractEventFromMarket($market);

        [$openTime, $closeTime, $roundKey] = $this->resolveRoundTimes($market, $event);

        $openPrice = $this->extractNumber([
            $event['priceToBeat'] ?? null,
            $event['price_to_beat'] ?? null,
            $event['priceToBeatUsd'] ?? null,
            $event['price_to_beat_usd'] ?? null,
            $event['strikePrice'] ?? null,
            $event['strike_price'] ?? null,
            $event['price'] ?? null,
            $event['startPrice'] ?? null,
            $event['start_price'] ?? null,
            $event['referencePrice'] ?? null,
            $event['reference_price'] ?? null,
            $market['priceToBeat'] ?? null,
            $market['price_to_beat'] ?? null,
            $market['priceToBeatUsd'] ?? null,
            $market['price_to_beat_usd'] ?? null,
            $market['openPrice'] ?? null,
            $market['open_price'] ?? null,
            $market['strikePrice'] ?? null,
            $market['strike_price'] ?? null,
            $market['price'] ?? null,
            $market['startPrice'] ?? null,
            $market['start_price'] ?? null,
            $market['referencePrice'] ?? null,
            $market['reference_price'] ?? null,
        ]);

        $currentPrice = $this->extractNumber([
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
            $market['referencePrice'] ?? null,
            $market['reference_price'] ?? null,
            $market['price'] ?? null,
            $event['currentPrice'] ?? null,
            $event['current_price'] ?? null,
            $event['spotPrice'] ?? null,
            $event['spot_price'] ?? null,
            $event['referencePrice'] ?? null,
            $event['reference_price'] ?? null,
        ]);
        if ($currentPrice === null) {
            $currentPrice = $this->priceService->fetchCurrentPrice();
        }

        $outcomePrices = $this->extractOutcomePrices($market);
        $upPrice = $outcomePrices['up'] ?? null;
        $downPrice = $outcomePrices['down'] ?? null;

        if ($openPrice === null && $currentPrice !== null) {
            $openPrice = $currentPrice;
        }

        if ($openPrice === null || $currentPrice === null) {
            throw new RuntimeException('Unable to resolve Polymarket prices from API response.');
        }

        if ($upPrice === null || $downPrice === null) {
            $priceDelta = $currentPrice - $openPrice;
            $ratio = $openPrice > 0 ? ($priceDelta / $openPrice) * 100 : 0;
            $upPrice = max(0, min(100, 50 + $ratio));
            $downPrice = 100 - $upPrice;
        }

        return [
            'event_title' => (string) ($event['title'] ?? $event['name'] ?? $market['question'] ?? $market['title'] ?? 'Bitcoin Up or Down'),
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

    private function extractEventFromMarket(array $market): array
    {
        if (isset($market['event']) && is_array($market['event'])) {
            return (array) $market['event'];
        }

        if (isset($market['event']) && is_string($market['event'])) {
            $decoded = json_decode($market['event'], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
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
            return new DateTimeImmutable('now', $this->marketTimezone);
        }

        if (is_numeric($value)) {
            return (new DateTimeImmutable('now', $this->marketTimezone))->setTimestamp((int) $value);
        }

        return new DateTimeImmutable((string) $value, $this->marketTimezone);
    }

    private function extractOutcomePrices(array $market): array
    {
        $outcomes = $market['outcomes'] ?? $market['outcomeNames'] ?? $market['outcome_names'] ?? null;
        $prices = $market['outcomePrices'] ?? $market['outcome_prices'] ?? $market['prices'] ?? null;
        if ($outcomes === null && isset($market['yesPrice'], $market['noPrice'])) {
            $outcomes = ['Yes', 'No'];
            $prices = [$market['yesPrice'], $market['noPrice']];
        }

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

    private function resolveRoundTimes(array $market, array $event): array
    {
        $start = $event['startTime'] ?? $event['start_time'] ?? $event['openTime'] ?? $event['open_time'] ?? null;
        $end = $event['endTime'] ?? $event['end_time'] ?? $event['closeTime'] ?? $event['close_time'] ?? null;

        $start = $start ?? ($market['startTime'] ?? $market['start_time'] ?? $market['openTime'] ?? $market['open_time'] ?? null);
        $end = $end ?? ($market['endTime'] ?? $market['end_time'] ?? $market['closeTime'] ?? $market['close_time'] ?? null);

        if ($start && $end) {
            $openTime = $this->parseTime($start);
            $closeTime = $this->parseTime($end);
        } else {
            $now = new DateTimeImmutable('now', $this->marketTimezone);
            $minute = (int) $now->format('i');
            $startMinute = $minute - ($minute % 15);
            $openTime = $now->setTime((int) $now->format('H'), $startMinute, 0);
            $closeTime = $openTime->modify('+15 minutes');
        }

        $roundKey = $event['id'] ?? $market['id'] ?? $openTime->format('YmdHi');

        return [$openTime, $closeTime, $roundKey];
    }
}
