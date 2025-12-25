<?php

class RuleService
{
    public function getRuleForAccount(int $accountId): ?array
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT * FROM rules WHERE account_id = :account_id');
        $statement->execute([':account_id' => $accountId]);
        $rule = $statement->fetch();

        return $rule ?: null;
    }

    public function updateRule(int $accountId, array $data): void
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('UPDATE rules SET
            active_minutes_before_close = :active_minutes_before_close,
            buy_shares = :buy_shares,
            up_min = :up_min,
            up_max = :up_max,
            up_buy_delta = :up_buy_delta,
            up_sell_stop = :up_sell_stop,
            down_min = :down_min,
            down_max = :down_max,
            down_buy_delta = :down_buy_delta,
            down_sell_stop = :down_sell_stop,
            sell_on_low_volatility = :sell_on_low_volatility,
            volatility_threshold = :volatility_threshold
            WHERE account_id = :account_id');
        $statement->execute([
            ':active_minutes_before_close' => $data['active_minutes_before_close'],
            ':buy_shares' => $data['buy_shares'],
            ':up_min' => $data['up_min'],
            ':up_max' => $data['up_max'],
            ':up_buy_delta' => $data['up_buy_delta'],
            ':up_sell_stop' => $data['up_sell_stop'],
            ':down_min' => $data['down_min'],
            ':down_max' => $data['down_max'],
            ':down_buy_delta' => $data['down_buy_delta'],
            ':down_sell_stop' => $data['down_sell_stop'],
            ':sell_on_low_volatility' => $data['sell_on_low_volatility'],
            ':volatility_threshold' => $data['volatility_threshold'],
            ':account_id' => $accountId,
        ]);
    }
}
