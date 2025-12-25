<?php

class TradeService
{
    private PolymarketClient $client;
    private Db $db;

    public function __construct(PolymarketClient $client, Db $db)
    {
        $this->client = $client;
        $this->db = $db;
    }

    public function autoTrade(array $snapshot, float $buyThreshold, float $sellThreshold, float $orderSize): array
    {
        $actions = [];
        $upPrice = $snapshot['up_price'];
        $downPrice = $snapshot['down_price'];

        if ($upPrice !== null && $upPrice >= $buyThreshold) {
            $actions[] = $this->placeOrder('buy', 'up', $orderSize, $upPrice, $snapshot);
        }
        if ($downPrice !== null && $downPrice >= $buyThreshold) {
            $actions[] = $this->placeOrder('buy', 'down', $orderSize, $downPrice, $snapshot);
        }
        if ($upPrice !== null && $upPrice <= $sellThreshold) {
            $actions[] = $this->placeOrder('sell', 'up', $orderSize, $upPrice, $snapshot);
        }
        if ($downPrice !== null && $downPrice <= $sellThreshold) {
            $actions[] = $this->placeOrder('sell', 'down', $orderSize, $downPrice, $snapshot);
        }

        return $actions;
    }

    public function placeManualOrder(string $side, string $outcome, float $size, float $price, array $snapshot): array
    {
        return $this->placeOrder($side, $outcome, $size, $price, $snapshot);
    }

    private function placeOrder(string $side, string $outcome, float $size, float $price, array $snapshot): array
    {
        $payload = [
            'market_id' => $snapshot['market_id'],
            'side' => $side,
            'outcome' => $outcome,
            'size' => $size,
            'price' => $price,
        ];

        $response = $this->client->placeOrder($payload);
        $this->storeOrder($payload, $response);

        return [
            'payload' => $payload,
            'response' => $response,
        ];
    }

    private function storeOrder(array $payload, array $response): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO trade_orders (market_id, side, outcome, size, price, api_status, api_response, created_at)
             VALUES (:market_id, :side, :outcome, :size, :price, :api_status, :api_response, :created_at)'
        );
        $stmt->execute([
            ':market_id' => $payload['market_id'],
            ':side' => $payload['side'],
            ':outcome' => $payload['outcome'],
            ':size' => $payload['size'],
            ':price' => $payload['price'],
            ':api_status' => $response['status'] ?? 0,
            ':api_response' => json_encode($response, JSON_UNESCAPED_SLASHES),
            ':created_at' => gmdate('c'),
        ]);
    }
}
