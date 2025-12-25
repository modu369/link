<?php

class MarketService
{
    private PolymarketClient $client;
    private Db $db;
    private string $upLabel;
    private string $downLabel;

    public function __construct(PolymarketClient $client, Db $db, string $upLabel, string $downLabel)
    {
        $this->client = $client;
        $this->db = $db;
        $this->upLabel = $upLabel;
        $this->downLabel = $downLabel;
    }

    public function fetchMarketSnapshot(string $eventSlug): array
    {
        $eventResponse = $this->client->fetchEventBySlug($eventSlug);
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

        $marketId = $eventData['market_id'];
        $marketResponse = $this->client->fetchMarketById($marketId);
        if (!$marketResponse['ok']) {
            return [
                'ok' => false,
                'error' => $marketResponse['error'] ?? 'Failed to load market',
                'details' => $marketResponse,
            ];
        }

        $marketData = $this->extractMarket($marketResponse['data']);
        $snapshot = $this->buildSnapshot($eventData, $marketData);
        $this->storeSnapshot($snapshot);

        return ['ok' => true, 'data' => $snapshot];
    }

    private function extractEvent(array $payload): ?array
    {
        $event = $payload['event'] ?? null;
        if (!$event && isset($payload['events'][0])) {
            $event = $payload['events'][0];
        }
        if (!$event && isset($payload[0])) {
            $event = $payload[0];
        }
        if (!$event) {
            return null;
        }

        return [
            'id' => (string) ($event['id'] ?? ''),
            'market_id' => (string) ($event['market_id'] ?? ($event['marketId'] ?? ($event['markets'][0]['id'] ?? ''))),
            'title' => (string) ($event['title'] ?? ''),
            'open_time' => (string) ($event['open_time'] ?? ($event['openTime'] ?? '')),
            'close_time' => (string) ($event['close_time'] ?? ($event['closeTime'] ?? '')),
            'opening_price' => $event['opening_price'] ?? ($event['openingPrice'] ?? null),
        ];
    }

    private function extractMarket(array $payload): array
    {
        $market = $payload['market'] ?? null;
        if (!$market && isset($payload['markets'][0])) {
            $market = $payload['markets'][0];
        }
        if (!$market && isset($payload[0])) {
            $market = $payload[0];
        }
        if (!$market) {
            return [];
        }

        return $market;
    }

    private function buildSnapshot(array $event, array $market): array
    {
        $currentPrice = $market['current_price'] ?? ($market['price'] ?? ($market['last_price'] ?? null));
        $outcomes = $market['outcomes'] ?? ($market['outcomePrices'] ?? []);
        $up = $this->extractOutcomePrice($outcomes, $this->upLabel);
        $down = $this->extractOutcomePrice($outcomes, $this->downLabel);

        return [
            'event_id' => $event['id'],
            'event_title' => $event['title'],
            'market_id' => (string) ($event['market_id'] ?? ''),
            'open_time' => $event['open_time'],
            'close_time' => $event['close_time'],
            'opening_price' => $event['opening_price'],
            'current_price' => $currentPrice,
            'up_price' => $up,
            'down_price' => $down,
            'captured_at' => gmdate('c'),
        ];
    }

    private function extractOutcomePrice($outcomes, string $label): ?float
    {
        if (is_array($outcomes)) {
            foreach ($outcomes as $key => $value) {
                if (is_array($value)) {
                    $name = $value['name'] ?? ($value['label'] ?? '');
                    if (strcasecmp($name, $label) === 0) {
                        return isset($value['price']) ? (float) $value['price'] : null;
                    }
                }

                if (is_string($key) && strcasecmp($key, $label) === 0) {
                    return (float) $value;
                }
            }
        }

        return null;
    }

    private function storeSnapshot(array $snapshot): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO market_snapshots (event_id, market_id, open_time, close_time, opening_price, current_price, up_price, down_price, captured_at)
             VALUES (:event_id, :market_id, :open_time, :close_time, :opening_price, :current_price, :up_price, :down_price, :captured_at)'
        );
        $stmt->execute([
            ':event_id' => $snapshot['event_id'],
            ':market_id' => $snapshot['market_id'],
            ':open_time' => $snapshot['open_time'],
            ':close_time' => $snapshot['close_time'],
            ':opening_price' => $snapshot['opening_price'],
            ':current_price' => $snapshot['current_price'],
            ':up_price' => $snapshot['up_price'],
            ':down_price' => $snapshot['down_price'],
            ':captured_at' => $snapshot['captured_at'],
        ]);
    }
}
