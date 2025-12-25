<?php

class MarketService
{
    private PolymarketClient $client;
    private Db $db;
    private PriceFeed $priceFeed;
    private string $upLabel;
    private string $downLabel;

    public function __construct(PolymarketClient $client, Db $db, PriceFeed $priceFeed, string $upLabel, string $downLabel)
    {
        $this->client = $client;
        $this->db = $db;
        $this->priceFeed = $priceFeed;
        $this->upLabel = $upLabel;
        $this->downLabel = $downLabel;
    }

    public function fetchMarketSnapshot(?string $eventSlug, string $tagSlug, int $limit): array
    {
        $eventResponse = $eventSlug
            ? $this->client->fetchEventBySlug($eventSlug)
            : $this->client->fetchLatestEvent($tagSlug, $limit);
        if (!$eventResponse['ok']) {
            return [
                'ok' => false,
                'error' => $eventResponse['error'] ?? 'Failed to load event',
                'details' => $eventResponse,
            ];
        }

        $eventData = $this->extractEvent($eventResponse['data']);
        if ($eventData === null) {
            return [
                'ok' => false,
                'error' => 'Event not found for slug',
                'details' => $eventResponse['data'],
            ];
        }

        $marketData = $eventData['market'];
        $priceResponse = $this->priceFeed->fetchCurrentPrice();
        $currentPrice = $priceResponse['ok'] ? $priceResponse['price'] : null;
        $openingPrice = $this->resolveOpeningPrice($eventData['id'], $currentPrice);
        $snapshot = $this->buildSnapshot($eventData, $marketData);
        $snapshot['current_price'] = $currentPrice;
        $snapshot['opening_price'] = $openingPrice;
        $snapshot['price_to_beat'] = $openingPrice;
        $this->storeSnapshot($snapshot);

        return ['ok' => true, 'data' => $snapshot];
    }

    private function extractEvent(array $payload): ?array
    {
        $event = $payload['event'] ?? null;
        if (!$event && isset($payload['data'][0])) {
            $event = $payload['data'][0];
        }
        if (!$event) {
            return null;
        }

        $market = $event['markets'][0] ?? null;
        return [
            'id' => (string) ($event['id'] ?? ''),
            'slug' => (string) ($event['slug'] ?? ''),
            'market_id' => (string) ($market['id'] ?? ''),
            'title' => (string) ($event['title'] ?? ''),
            'open_time' => (string) ($event['eventStartTime'] ?? ($event['startTime'] ?? ($event['startDate'] ?? ''))),
            'close_time' => (string) ($event['endDate'] ?? ($event['endTime'] ?? '')),
            'opening_price' => null,
            'market' => $market ?? [],
        ];
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
            'event_title' => $event['title'],
            'market_id' => (string) ($event['market_id'] ?? ''),
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

    private function resolveOpeningPrice(string $eventId, ?float $currentPrice): ?float
    {
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
