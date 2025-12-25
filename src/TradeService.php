<?php

class TradeService
{
    public function recordTrade(array $trade): void
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('INSERT INTO trades (account_id, round_id, side, action, price, shares, reason) VALUES (:account_id, :round_id, :side, :action, :price, :shares, :reason)');
        $statement->execute([
            ':account_id' => $trade['account_id'],
            ':round_id' => $trade['round_id'],
            ':side' => $trade['side'],
            ':action' => $trade['action'],
            ':price' => $trade['price'],
            ':shares' => $trade['shares'],
            ':reason' => $trade['reason'],
        ]);
    }

    public function listTrades(int $limit = 50): array
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT trades.*, accounts.name AS account_name FROM trades JOIN accounts ON accounts.id = trades.account_id ORDER BY trades.id DESC LIMIT :limit');
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function listTradesForAccountToday(int $accountId): array
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT trades.*, accounts.name AS account_name FROM trades JOIN accounts ON accounts.id = trades.account_id WHERE trades.account_id = :account_id AND DATE(trades.created_at) = CURDATE() ORDER BY trades.id DESC');
        $statement->execute([':account_id' => $accountId]);

        return $statement->fetchAll();
    }

    public function hasOpenBuy(int $accountId, int $roundId, string $side): bool
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT COUNT(*) AS count FROM trades WHERE account_id = :account_id AND round_id = :round_id AND side = :side AND action = "BUY"');
        $statement->execute([
            ':account_id' => $accountId,
            ':round_id' => $roundId,
            ':side' => $side,
        ]);
        $result = $statement->fetch();

        return (int) $result['count'] > 0;
    }
}
