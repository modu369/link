<?php

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/PriceService.php';
require __DIR__ . '/../src/RoundService.php';
require __DIR__ . '/../src/AccountService.php';
require __DIR__ . '/../src/RuleService.php';
require __DIR__ . '/../src/TradeService.php';
require __DIR__ . '/../src/PolymarketClient.php';

$accountService = new AccountService();
$ruleService = new RuleService();
$tradeService = new TradeService();
$roundService = new RoundService();
$priceService = new PriceService();
$client = new PolymarketClient();

try {
    $round = $roundService->getCurrentRound();
    $currentPrice = $priceService->fetchCurrentPrice();
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

$openPrice = (float) $round['open_price'];
$priceDelta = $currentPrice - $openPrice;
$upPosition = max(0, min(100, 50 + ($priceDelta / $openPrice) * 100));
$downPosition = 100 - $upPosition;
$volatility = abs($priceDelta);

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

fwrite(STDOUT, 'Worker completed at ' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . PHP_EOL);
