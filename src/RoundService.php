<?php

class RoundService
{
    public function getCurrentRound(): array
    {
        $pdo = Database::connection();
        $statement = $pdo->query('SELECT * FROM rounds ORDER BY id DESC LIMIT 1');
        $round = $statement->fetch();

        if ($round && new DateTimeImmutable($round['close_time']) > new DateTimeImmutable()) {
            return $round;
        }

        return $this->startNewRound();
    }

    public function startNewRound(): array
    {
        $pdo = Database::connection();
        $config = require __DIR__ . '/Config.php';
        $duration = (int) $config['round']['duration_minutes'];

        $priceService = new PriceService();
        $openPrice = $priceService->fetchCurrentPrice();

        $openTime = new DateTimeImmutable();
        $closeTime = $openTime->modify('+' . $duration . ' minutes');

        $statement = $pdo->prepare('INSERT INTO rounds (open_time, close_time, open_price) VALUES (:open_time, :close_time, :open_price)');
        $statement->execute([
            ':open_time' => $openTime->format('Y-m-d H:i:s'),
            ':close_time' => $closeTime->format('Y-m-d H:i:s'),
            ':open_price' => $openPrice,
        ]);

        $roundId = (int) $pdo->lastInsertId();

        return [
            'id' => $roundId,
            'open_time' => $openTime->format('Y-m-d H:i:s'),
            'close_time' => $closeTime->format('Y-m-d H:i:s'),
            'open_price' => $openPrice,
        ];
    }
}
