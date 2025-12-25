<?php

class RoundService
{
    public function getCurrentRound(): array
    {
        $marketService = new MarketDataService();
        $snapshot = $marketService->fetchSnapshot();

        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT * FROM rounds WHERE external_key = :external_key LIMIT 1');
        $statement->execute([':external_key' => $snapshot['round_key']]);
        $round = $statement->fetch();

        if ($round) {
            $this->updateRoundIfNeeded((int) $round['id'], $snapshot);
            $roundId = (int) $round['id'];
        } else {
            $roundId = $this->createRound($snapshot);
        }

        return [
            'id' => $roundId,
            'open_time' => $snapshot['open_time'],
            'close_time' => $snapshot['close_time'],
            'open_price' => $snapshot['open_price'],
            'current_price' => $snapshot['current_price'],
            'up_position' => $snapshot['up_position'],
            'down_position' => $snapshot['down_position'],
            'event_title' => $snapshot['event_title'],
        ];
    }

    private function createRound(array $snapshot): int
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('INSERT INTO rounds (external_key, open_time, close_time, open_price) VALUES (:external_key, :open_time, :close_time, :open_price)');
        $statement->execute([
            ':external_key' => $snapshot['round_key'],
            ':open_time' => $snapshot['open_time'],
            ':close_time' => $snapshot['close_time'],
            ':open_price' => $snapshot['open_price'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function updateRoundIfNeeded(int $roundId, array $snapshot): void
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('UPDATE rounds SET open_time = :open_time, close_time = :close_time, open_price = :open_price WHERE id = :id');
        $statement->execute([
            ':open_time' => $snapshot['open_time'],
            ':close_time' => $snapshot['close_time'],
            ':open_price' => $snapshot['open_price'],
            ':id' => $roundId,
        ]);
    }
}
