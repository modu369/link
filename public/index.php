<?php

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/MarketDataService.php';
require __DIR__ . '/../src/RoundService.php';
require __DIR__ . '/../src/AccountService.php';
require __DIR__ . '/../src/RuleService.php';
require __DIR__ . '/../src/TradeService.php';

$accountService = new AccountService();
$ruleService = new RuleService();
$tradeService = new TradeService();
$roundService = new RoundService();

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$page = $_GET['page'] ?? 'dashboard';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action']) && $_POST['action'] === 'add_account') {
            $accountService->createAccount([
                'name' => trim($_POST['name'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'api_key' => trim($_POST['api_key'] ?? ''),
                'api_secret' => trim($_POST['api_secret'] ?? ''),
            ]);
            header('Location: /?page=accounts');
            exit;
        }

        if (isset($_POST['action']) && $_POST['action'] === 'delete_account') {
            $accountService->deleteAccount((int) $_POST['account_id']);
            header('Location: /?page=accounts');
            exit;
        }

        if (isset($_POST['action']) && $_POST['action'] === 'update_rule') {
            $ruleService->updateRule((int) $_POST['account_id'], [
                'active_minutes_before_close' => (int) $_POST['active_minutes_before_close'],
                'buy_shares' => (int) $_POST['buy_shares'],
                'up_min' => (int) $_POST['up_min'],
                'up_max' => (int) $_POST['up_max'],
                'up_buy_delta' => (float) $_POST['up_buy_delta'],
                'up_sell_stop' => (int) $_POST['up_sell_stop'],
                'down_min' => (int) $_POST['down_min'],
                'down_max' => (int) $_POST['down_max'],
                'down_buy_delta' => (float) $_POST['down_buy_delta'],
                'down_sell_stop' => (int) $_POST['down_sell_stop'],
                'sell_on_low_volatility' => isset($_POST['sell_on_low_volatility']) ? 1 : 0,
                'volatility_threshold' => (float) $_POST['volatility_threshold'],
            ]);
            header('Location: /?page=rules&account_id=' . (int) $_POST['account_id']);
            exit;
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$round = null;
$accounts = [];
$trades = [];
$rule = null;

try {
    if ($page === 'dashboard') {
        $round = $roundService->getCurrentRound();
    }

    if ($page === 'accounts') {
        $accounts = $accountService->listAccounts();
    }

    if ($page === 'rules') {
        $accountId = (int) ($_GET['account_id'] ?? 0);
        if ($accountId > 0) {
            $accounts = [$accountService->getAccount($accountId)];
            $rule = $ruleService->getRuleForAccount($accountId);
        }
    }

    if ($page === 'trades') {
        $trades = $tradeService->listTrades();
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Polymarket BTC 控制台</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="logo">Polymarket BTC</div>
        <nav>
            <a href="/?page=dashboard" class="<?= $page === 'dashboard' ? 'active' : '' ?>">实时看板</a>
            <a href="/?page=accounts" class="<?= $page === 'accounts' ? 'active' : '' ?>">账户管理</a>
            <a href="/?page=trades" class="<?= $page === 'trades' ? 'active' : '' ?>">交易记录</a>
        </nav>
    </aside>
    <main class="content">
        <header class="content-header">
            <div>
                <h1><?= $page === 'dashboard' ? 'Bitcoin Up or Down' : ($page === 'accounts' ? '账户管理' : ($page === 'rules' ? '规则配置' : '交易记录')) ?></h1>
                <p class="subtitle">Polymarket 实时数据 + 账户自动交易引擎</p>
            </div>
            <div class="status-pill">系统在线</div>
        </header>

        <?php if ($error): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($page === 'dashboard' && $round): ?>
            <section class="hero">
                <div>
                    <div class="hero-label">Market</div>
                    <div class="hero-title" id="eventTitle"><?= e($round['event_title']) ?></div>
                    <div class="hero-subtitle">本轮时间：<span id="openTime"><?= e($round['open_time']) ?></span> - <span id="closeTime"><?= e($round['close_time']) ?></span></div>
                </div>
                <div class="countdown">
                    <div class="countdown-label">距封盘</div>
                    <div class="countdown-time" id="countdown">--:--</div>
                </div>
            </section>
            <section class="grid">
                <div class="card metric">
                    <h3>PRICE TO BEAT</h3>
                    <p class="metric-value" id="openPrice"><?= e(number_format($round['open_price'], 2)) ?></p>
                </div>
                <div class="card metric">
                    <h3>CURRENT PRICE</h3>
                    <p class="metric-value" id="currentPrice">加载中...</p>
                </div>
                <div class="card metric">
                    <h3>UP / DOWN 价格</h3>
                    <p class="metric-value"><span id="upPosition">--</span> <span class="metric-unit">UP</span></p>
                    <p class="metric-sub"><span id="downPosition">--</span> <span class="metric-unit">DOWN</span></p>
                </div>
            </section>
            <section class="card full">
                <h3>实时信号</h3>
                <div class="signal" id="signal">等待数据...</div>
            </section>
        <?php endif; ?>

        <?php if ($page === 'accounts'): ?>
            <section class="card">
                <h3>新增账户</h3>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="add_account">
                    <label>账户名称<input type="text" name="name" required></label>
                    <label>登录邮箱<input type="email" name="email" required></label>
                    <label>API Key<input type="text" name="api_key" required></label>
                    <label>API Secret<input type="password" name="api_secret" required></label>
                    <button type="submit" class="primary">保存账户</button>
                </form>
            </section>
            <section class="card full">
                <h3>账户列表</h3>
                <table>
                    <thead>
                        <tr>
                            <th>名称</th>
                            <th>邮箱</th>
                            <th>状态</th>
                            <th>最后登录</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $account): ?>
                            <tr>
                                <td><?= e($account['name']) ?></td>
                                <td><?= e($account['email']) ?></td>
                                <td><span class="pill <?= $account['status'] === 'online' ? 'pill-ok' : 'pill-warn' ?>"><?= e($account['status']) ?></span></td>
                                <td><?= e($account['last_login_at'] ?? '-') ?></td>
                                <td>
                                    <a class="link" href="/?page=rules&account_id=<?= (int) $account['id'] ?>">规则</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('确认删除该账户?');">
                                        <input type="hidden" name="action" value="delete_account">
                                        <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                        <button type="submit" class="link danger">删除</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($page === 'rules' && $rule && $accounts && $accounts[0]): ?>
            <section class="card full">
                <h3>规则配置：<?= e($accounts[0]['name']) ?></h3>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="update_rule">
                    <input type="hidden" name="account_id" value="<?= (int) $accounts[0]['id'] ?>">
                    <label>生效时间（收盘前分钟）<input type="number" name="active_minutes_before_close" value="<?= e($rule['active_minutes_before_close']) ?>" required></label>
                    <label>买入股数<input type="number" name="buy_shares" value="<?= e($rule['buy_shares']) ?>" required></label>
                    <label>UP 最低区间<input type="number" name="up_min" value="<?= e($rule['up_min']) ?>" required></label>
                    <label>UP 最高区间<input type="number" name="up_max" value="<?= e($rule['up_max']) ?>" required></label>
                    <label>UP 买入触发（高于开盘价）<input type="number" step="0.01" name="up_buy_delta" value="<?= e($rule['up_buy_delta']) ?>" required></label>
                    <label>UP 止损线<input type="number" name="up_sell_stop" value="<?= e($rule['up_sell_stop']) ?>" required></label>
                    <label>DOWN 最低区间<input type="number" name="down_min" value="<?= e($rule['down_min']) ?>" required></label>
                    <label>DOWN 最高区间<input type="number" name="down_max" value="<?= e($rule['down_max']) ?>" required></label>
                    <label>DOWN 买入触发（低于开盘价）<input type="number" step="0.01" name="down_buy_delta" value="<?= e($rule['down_buy_delta']) ?>" required></label>
                    <label>DOWN 止损线<input type="number" name="down_sell_stop" value="<?= e($rule['down_sell_stop']) ?>" required></label>
                    <label class="toggle">价格波幅小于 M 自动卖出
                        <input type="checkbox" name="sell_on_low_volatility" <?= (int) $rule['sell_on_low_volatility'] === 1 ? 'checked' : '' ?>>
                    </label>
                    <label>M（波幅阈值）<input type="number" step="0.01" name="volatility_threshold" value="<?= e($rule['volatility_threshold']) ?>"></label>
                    <button type="submit" class="primary">保存规则</button>
                </form>
            </section>
        <?php elseif ($page === 'rules'): ?>
            <div class="card">请选择账户查看规则。</div>
        <?php endif; ?>

        <?php if ($page === 'trades'): ?>
            <section class="card full">
                <h3>交易记录</h3>
                <table>
                    <thead>
                        <tr>
                            <th>时间</th>
                            <th>账户</th>
                            <th>方向</th>
                            <th>动作</th>
                            <th>价格</th>
                            <th>股数</th>
                            <th>原因</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trades as $trade): ?>
                            <tr>
                                <td><?= e($trade['created_at']) ?></td>
                                <td><?= e($trade['account_name']) ?></td>
                                <td><?= e($trade['side']) ?></td>
                                <td><?= e($trade['action']) ?></td>
                                <td><?= e(number_format($trade['price'], 2)) ?></td>
                                <td><?= e($trade['shares']) ?></td>
                                <td><?= e($trade['reason']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>
    </main>
</div>
<script src="/assets/app.js"></script>
</body>
</html>
