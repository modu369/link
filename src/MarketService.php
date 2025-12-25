<?php

class MarketService
{
    private PolymarketClient $client;
    private Db $db;
    private PriceFeed $priceFeed;
    private string $slugPrefix;
    private int $intervalSeconds;
    private string $timezone;
    private string $upLabel;
    private string $downLabel;

    public function __construct(
        PolymarketClient $client,
        Db $db,
        PriceFeed $priceFeed,
        string $slugPrefix,
        int $intervalSeconds,
        string $timezone,
        string $upLabel,
        string $downLabel
    )
    {
        $this->client = $client;
        $this->db = $db;
        $this->priceFeed = $priceFeed;
        $this->slugPrefix = $slugPrefix;
        $this->intervalSeconds = $intervalSeconds;
        $this->timezone = $timezone;
        $this->upLabel = $upLabel;
        $this->downLabel = $downLabel;
    }

    public function fetchMarketSnapshot(?string $eventSlug, bool $includeRaw = false): array
    {
        $resolvedSlug = $this->resolveEventSlug($eventSlug);
        $marketResponse = $this->client->fetchMarketBySlug($resolvedSlug);
        if (!$marketResponse['ok']) {
            return [
                'ok' => false,
                'error' => $marketResponse['error'] ?? 'Failed to load market',
                'details' => $marketResponse,
            ];
        }

        $marketData = $this->extractMarket($marketResponse['data']);
        if ($marketData === null) {
            return [
                'ok' => false,
                'error' => 'Market not found for slug',
                'details' => $marketResponse['data'],
            ];
        }

        $marketId = (string) ($marketData['id'] ?? '');
        if ($marketId !== '' && ($marketData['outcomes'] ?? null) === null && ($marketData['outcomePrices'] ?? null) === null) {
            $marketById = $this->client->fetchMarketById($marketId);
            if ($marketById['ok']) {
                $detailed = $this->extractMarket($marketById['data']);
                if (is_array($detailed)) {
                    $marketData = array_merge($marketData, $detailed);
                }
            }
        }

        $priceResponse = $this->priceFeed->fetchCurrentPrice();
        $currentPrice = $priceResponse['ok'] ? $priceResponse['price'] : null;
        $eventWindow = $this->buildEventWindow($resolvedSlug);
        $eventId = $eventWindow['id'] ?? $resolvedSlug;
        $openingPrice = $this->resolveOpeningPrice($eventId, $currentPrice);
        $snapshot = $this->buildSnapshot($eventWindow, $marketData);
        $snapshot['current_price'] = $currentPrice;
        $snapshot['opening_price'] = $openingPrice;
        $snapshot['price_to_beat'] = $openingPrice;
        if ($includeRaw) {
            $snapshot['market_raw'] = $marketResponse['data'];
            $snapshot['price_raw'] = $priceResponse['raw'] ?? null;
            $snapshot['price_error'] = $priceResponse['ok'] ? null : ($priceResponse['error'] ?? 'Price feed unavailable');
        }
        $this->storeSnapshot($snapshot);

        return ['ok' => true, 'data' => $snapshot];
    }

    private function extractMarket(array $payload): ?array
    {
        if (isset($payload['market']) && is_array($payload['market'])) {
            return $payload['market'];
        }

        if (isset($payload['data']) && is_array($payload['data']) && isset($payload['data'][0])) {
            return $payload['data'][0];
        }

        if (!is_array($payload) || $payload === []) {
            return null;
        }

        return $payload;
    }

    private function buildSnapshot(array $event, array $market): array
    {
        $outcomeLabels = $this->parseOutcomeField($market['outcomes'] ?? []);
        $outcomePrices = $this->parseOutcomeField($market['outcomePrices'] ?? []);
        $up = $this->extractOutcomePrice($outcomeLabels, $outcomePrices, $this->upLabel);
        $down = $this->extractOutcomePrice($outcomeLabels, $outcomePrices, $this->downLabel);

        return [
            'event_id' => $event['id'],
            'event_slug' => $event['slug'],
            'event_title' => $event['title'] !== '' ? $event['title'] : (string) ($market['question'] ?? ($market['title'] ?? '')),
            'market_id' => (string) ($market['id'] ?? ''),
            'open_time' => $event['open_time'],
            'close_time' => $event['close_time'],
            'opening_price' => $event['opening_price'],
            'up_price' => $up,
            'down_price' => $down,
            'best_bid' => isset($market['bestBid']) ? (float) $market['bestBid'] : null,
            'best_ask' => isset($market['bestAsk']) ? (float) $market['bestAsk'] : null,
            'last_trade_price' => isset($market['lastTradePrice']) ? (float) $market['lastTradePrice'] : null,
            'captured_at' => gmdate('c'),
        ];
    }

    private function parseOutcomeField($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    private function extractOutcomePrice(array $labels, array $prices, string $label): ?float
    {
        foreach ($labels as $index => $name) {
            if (strcasecmp((string) $name, $label) === 0 && isset($prices[$index])) {
                return (float) $prices[$index];
            }
        }

        return null;
    }

    private function resolveEventSlug(?string $eventSlug): string
    {
        if ($eventSlug !== null && $eventSlug !== '') {
            return $eventSlug;
        }

        $interval = max(60, $this->intervalSeconds);
        $timestamp = intdiv(time(), $interval) * $interval;

        return $this->slugPrefix . $timestamp;
    }

    private function buildEventWindow(string $slug): array
    {
        $timestamp = $this->extractTimestampFromSlug($slug);
        $start = $timestamp ?? (intdiv(time(), $this->intervalSeconds) * $this->intervalSeconds);
        $end = $start + $this->intervalSeconds;
        $timezone = new DateTimeZone($this->timezone);
        $startLocal = (new DateTimeImmutable('@' . $start))->setTimezone($timezone);
        $endLocal = (new DateTimeImmutable('@' . $end))->setTimezone($timezone);
        $dateLabel = $startLocal->format('F j');
        $timeLabel = $startLocal->format('g:iA') . '-' . $endLocal->format('g:iA') . ' ' . $startLocal->format('T');

        return [
            'id' => $slug,
            'slug' => $slug,
            'title' => 'Bitcoin Up or Down - ' . $dateLabel . ', ' . $timeLabel,
            'open_time' => $startLocal->format('Y-m-d H:i:s T'),
            'close_time' => $endLocal->format('Y-m-d H:i:s T'),
            'opening_price' => null,
        ];
    }

    private function extractTimestampFromSlug(string $slug): ?int
    {
        if (preg_match('/(\\d{10})$/', $slug, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function resolveOpeningPrice(?string $eventId, ?float $currentPrice): ?float
    {
        if ($eventId === null || $eventId === '') {
            return $currentPrice;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT opening_price FROM market_snapshots WHERE event_id = :event_id AND opening_price IS NOT NULL ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([':event_id' => $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['opening_price'])) {
            return (float) $row['opening_price'];
        }

        return $currentPrice;
    }

    private function storeSnapshot(array $snapshot): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO market_snapshots (event_id, event_slug, event_title, market_id, open_time, close_time, opening_price, price_to_beat, current_price, up_price, down_price, captured_at)
             VALUES (:event_id, :event_slug, :event_title, :market_id, :open_time, :close_time, :opening_price, :price_to_beat, :current_price, :up_price, :down_price, :captured_at)'
        );
        $stmt->execute([
            ':event_id' => $snapshot['event_id'],
            ':event_slug' => $snapshot['event_slug'],
            ':event_title' => $snapshot['event_title'],
            ':market_id' => $snapshot['market_id'],
            ':open_time' => $snapshot['open_time'],
            ':close_time' => $snapshot['close_time'],
            ':opening_price' => $snapshot['opening_price'],
            ':price_to_beat' => $snapshot['price_to_beat'],
            ':current_price' => $snapshot['current_price'],
            ':up_price' => $snapshot['up_price'],
            ':down_price' => $snapshot['down_price'],
            ':captured_at' => $snapshot['captured_at'],
        ]);
    }
}
