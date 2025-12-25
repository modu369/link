<?php

class AccountService
{
    public function listAccounts(): array
    {
        $pdo = Database::connection();
        return $pdo->query('SELECT * FROM accounts ORDER BY id DESC')->fetchAll();
    }

    public function getAccount(int $accountId): ?array
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT * FROM accounts WHERE id = :id');
        $statement->execute([':id' => $accountId]);
        $account = $statement->fetch();

        return $account ?: null;
    }

    public function createAccount(array $data): int
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('INSERT INTO accounts (name, email, api_key, api_secret, status, last_login_at, last_error) VALUES (:name, :email, :api_key, :api_secret, :status, :last_login_at, :last_error)');
        $statement->execute([
            ':name' => $data['name'],
            ':email' => $data['email'],
            ':api_key' => $data['api_key'],
            ':api_secret' => $data['api_secret'],
            ':status' => 'offline',
            ':last_login_at' => null,
            ':last_error' => null,
        ]);

        $accountId = (int) $pdo->lastInsertId();
        $this->ensureRuleDefaults($accountId);

        return $accountId;
    }

    public function deleteAccount(int $accountId): void
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('DELETE FROM accounts WHERE id = :id');
        $statement->execute([':id' => $accountId]);
    }

    public function updateStatus(int $accountId, string $status, ?string $error = null): void
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('UPDATE accounts SET status = :status, last_login_at = :last_login_at, last_error = :last_error WHERE id = :id');
        $statement->execute([
            ':status' => $status,
            ':last_login_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':last_error' => $error,
            ':id' => $accountId,
        ]);
    }

    private function ensureRuleDefaults(int $accountId): void
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('INSERT INTO rules (account_id, active_minutes_before_close, buy_shares, up_min, up_max, up_buy_delta, up_sell_stop, down_min, down_max, down_buy_delta, down_sell_stop, sell_on_low_volatility, volatility_threshold)
            VALUES (:account_id, :active_minutes_before_close, :buy_shares, :up_min, :up_max, :up_buy_delta, :up_sell_stop, :down_min, :down_max, :down_buy_delta, :down_sell_stop, :sell_on_low_volatility, :volatility_threshold)');
        $statement->execute([
            ':account_id' => $accountId,
            ':active_minutes_before_close' => 5,
            ':buy_shares' => 1,
            ':up_min' => 90,
            ':up_max' => 98,
            ':up_buy_delta' => 60,
            ':up_sell_stop' => 80,
            ':down_min' => 90,
            ':down_max' => 98,
            ':down_buy_delta' => 60,
            ':down_sell_stop' => 80,
            ':sell_on_low_volatility' => 0,
            ':volatility_threshold' => 10,
        ]);
    }
}
