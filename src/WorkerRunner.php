<?php

require_once __DIR__ . '/PolymarketApiClient.php';

class WorkerRunner
{
    public function runOnce(): void
    {
        $accountService = new AccountService();
        $ruleService = new RuleService();
        $tradeService = new TradeService();
        $roundService = new RoundService();
        $client = new PolymarketClient();

        $round = $roundService->getCurrentRound();
        $currentPrice = (float) $round['current_price'];
        $openPrice = (float) $round['open_price'];
        $upPosition = (float) $round['up_position'];
        $downPosition = (float) $round['down_position'];
        $volatility = abs($currentPrice - $openPrice);

        $now = new DateTimeImmutable();
        $closeTime = new DateTimeImmutable($round['close_time']);

        foreach ($accountService->listAccounts() as $account) {
            $loginOk = $client->login($account);
            $accountService->updateStatus($account['id'], $loginOk ? 'online' : 'offline', $loginOk ? null : 'Login failed');

            $rule = $ruleService->getRuleForAccount((int) $account['id']);
            if (!$rule) {
                continue;
            }

            $activeMinutes = (int) $rule['active_minutes_before_close'];
            if ($now < $closeTime->modify('-' . $activeMinutes . ' minutes')) {
                continue;
            }

            $shares = (int) $rule['buy_shares'];

            if (!$tradeService->hasOpenBuy((int) $account['id'], (int) $round['id'], 'UP')) {
                if ($upPosition >= $rule['up_min'] && $upPosition <= $rule['up_max'] && $currentPrice >= $openPrice + $rule['up_buy_delta']) {
                    if ($client->buy($account, 'UP', $shares)) {
                        $tradeService->recordTrade([
                            'account_id' => $account['id'],
                            'round_id' => $round['id'],
                            'side' => 'UP',
                            'action' => 'BUY',
                            'price' => $currentPrice,
                            'shares' => $shares,
                            'reason' => 'UP rule triggered',
                        ]);
                    }
                }
            } else {
                if ($upPosition <= $rule['up_sell_stop']) {
                    if ($client->sell($account, 'UP', $shares)) {
                        $tradeService->recordTrade([
                            'account_id' => $account['id'],
                            'round_id' => $round['id'],
                            'side' => 'UP',
                            'action' => 'SELL',
                            'price' => $currentPrice,
                            'shares' => $shares,
                            'reason' => 'UP stop loss',
                        ]);
                    }
                }
            }

            if (!$tradeService->hasOpenBuy((int) $account['id'], (int) $round['id'], 'DOWN')) {
                if ($downPosition >= $rule['down_min'] && $downPosition <= $rule['down_max'] && $currentPrice <= $openPrice - $rule['down_buy_delta']) {
                    if ($client->buy($account, 'DOWN', $shares)) {
                        $tradeService->recordTrade([
                            'account_id' => $account['id'],
                            'round_id' => $round['id'],
                            'side' => 'DOWN',
                            'action' => 'BUY',
                            'price' => $currentPrice,
                            'shares' => $shares,
                            'reason' => 'DOWN rule triggered',
                        ]);
                    }
                }
            } else {
                if ($downPosition <= $rule['down_sell_stop']) {
                    if ($client->sell($account, 'DOWN', $shares)) {
                        $tradeService->recordTrade([
                            'account_id' => $account['id'],
                            'round_id' => $round['id'],
                            'side' => 'DOWN',
                            'action' => 'SELL',
                            'price' => $currentPrice,
                            'shares' => $shares,
                            'reason' => 'DOWN stop loss',
                        ]);
                    }
                }
            }

            if ((int) $rule['sell_on_low_volatility'] === 1 && $volatility < (float) $rule['volatility_threshold']) {
                foreach (['UP', 'DOWN'] as $side) {
                    if ($tradeService->hasOpenBuy((int) $account['id'], (int) $round['id'], $side)) {
                        if ($client->sell($account, $side, $shares)) {
                            $tradeService->recordTrade([
                                'account_id' => $account['id'],
                                'round_id' => $round['id'],
                                'side' => $side,
                                'action' => 'SELL',
                                'price' => $currentPrice,
                                'shares' => $shares,
                                'reason' => 'Low volatility stop',
                            ]);
                        }
                    }
                }
            }
        }
    }
}
