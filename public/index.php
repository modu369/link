<?php

$config = require __DIR__ . '/../config.php';
$pollInterval = $config['app']['poll_interval_ms'];
$defaultEventSlug = $config['polymarket']['event_slug'];
$defaultUrl = $defaultEventSlug ? 'https://polymarket.com/event/' . $defaultEventSlug : '';
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>Polymarket BTC 实时监控</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: "Segoe UI", sans-serif;
            background: #0b0d12;
            color: #f5f6fa;
            margin: 0;
            padding: 24px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
        }
        .card {
            background: #151a23;
            border: 1px solid #1f2633;
            border-radius: 12px;
            padding: 16px;
        }
        .label {
            font-size: 12px;
            color: #8b93a7;
            margin-bottom: 6px;
        }
        .value {
            font-size: 20px;
            font-weight: 600;
        }
        .row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        input, select, button {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #2a3344;
            background: #0f131b;
            color: #f5f6fa;
        }
        button {
            cursor: pointer;
            background: #2463eb;
            border: none;
        }
        button.secondary {
            background: #394255;
        }
        .status {
            font-size: 12px;
            color: #9aa4b6;
        }
        .price-up {
            color: #4ade80;
        }
        .price-down {
            color: #f87171;
        }
    </style>
</head>
<body>
    <h1>Bitcoin 实时监控 & 自动交易面板</h1>
    <div class="row">
        <input type="text" id="event-url" placeholder="粘贴 Polymarket 链接" value="<?php echo htmlspecialchars($defaultUrl, ENT_QUOTES); ?>" style="flex: 1; min-width: 280px;">
        <input type="text" id="event-slug" placeholder="或直接输入 event slug" value="<?php echo htmlspecialchars($defaultEventSlug, ENT_QUOTES); ?>" style="flex: 1; min-width: 200px;">
        <button id="refresh">手动刷新</button>
    </div>

    <div class="grid">
        <div class="card">
            <div class="label">本轮名称</div>
            <div class="value" id="event-title">-</div>
        </div>
        <div class="card">
            <div class="label">本轮开盘时间</div>
            <div class="value" id="open-time">-</div>
        </div>
        <div class="card">
            <div class="label">本轮封盘时间</div>
            <div class="value" id="close-time">-</div>
        </div>
        <div class="card">
            <div class="label">开盘价格</div>
            <div class="value" id="opening-price">-</div>
        </div>
        <div class="card">
            <div class="label">Price to beat</div>
            <div class="value" id="price-to-beat">-</div>
        </div>
        <div class="card">
            <div class="label">现价</div>
            <div class="value" id="current-price">-</div>
        </div>
        <div class="card">
            <div class="label">BUY Up 位置</div>
            <div class="value" id="up-price">-</div>
        </div>
        <div class="card">
            <div class="label">BUY Down 位置</div>
            <div class="value" id="down-price">-</div>
        </div>
    </div>

    <div class="card" style="margin-top: 16px;">
        <div class="row">
            <select id="trade-side">
                <option value="buy">买入</option>
                <option value="sell">卖出</option>
            </select>
            <select id="trade-outcome">
                <option value="up">Up</option>
                <option value="down">Down</option>
            </select>
            <input type="number" id="trade-size" placeholder="数量" step="0.01" value="10">
            <input type="number" id="trade-price" placeholder="价格(可选)" step="0.0001">
            <button class="secondary" id="submit-trade">提交交易</button>
        </div>
        <div class="status" id="trade-status">等待操作。</div>
    </div>

    <div class="status" id="last-updated" style="margin-top: 12px;">最后更新：-</div>

    <script>
        const pollInterval = <?php echo (int) $pollInterval; ?>;
        const elements = {
            eventTitle: document.getElementById('event-title'),
            openTime: document.getElementById('open-time'),
            closeTime: document.getElementById('close-time'),
            openingPrice: document.getElementById('opening-price'),
            priceToBeat: document.getElementById('price-to-beat'),
            currentPrice: document.getElementById('current-price'),
            upPrice: document.getElementById('up-price'),
            downPrice: document.getElementById('down-price'),
            lastUpdated: document.getElementById('last-updated'),
            tradeStatus: document.getElementById('trade-status'),
        };

        function resolveQuery() {
            const url = document.getElementById('event-url').value.trim();
            const slug = document.getElementById('event-slug').value.trim();
            const params = new URLSearchParams();
            if (slug) {
                params.set('slug', slug);
            }
            if (url) {
                params.set('url', url);
            }
            return params.toString();
        }

        function updateValue(element, value) {
            element.textContent = value ?? '-';
        }

        async function fetchSnapshot() {
            try {
                const query = resolveQuery();
                const response = await fetch(`/api/market.php?${query}`);
                const payload = await response.json();
                if (!payload.ok) {
                    elements.lastUpdated.textContent = `获取失败：${payload.error || 'unknown'}`;
                    return;
                }
                const data = payload.data;
                updateValue(elements.eventTitle, data.event_title);
                updateValue(elements.openTime, data.open_time);
                updateValue(elements.closeTime, data.close_time);
                updateValue(elements.openingPrice, data.opening_price);
                updateValue(elements.priceToBeat, data.price_to_beat);
                updateValue(elements.currentPrice, data.current_price);
                updateValue(elements.upPrice, data.up_price);
                updateValue(elements.downPrice, data.down_price);
                elements.lastUpdated.textContent = `最后更新：${new Date().toLocaleTimeString()}`;
            } catch (error) {
                elements.lastUpdated.textContent = `获取失败：${error.message}`;
            }
        }

        async function submitTrade() {
            elements.tradeStatus.textContent = '提交中...';
            const body = {
                side: document.getElementById('trade-side').value,
                outcome: document.getElementById('trade-outcome').value,
                size: parseFloat(document.getElementById('trade-size').value || '0'),
                price: document.getElementById('trade-price').value ? parseFloat(document.getElementById('trade-price').value) : null,
                slug: document.getElementById('event-slug').value.trim(),
            };

            try {
                const response = await fetch('/api/trade.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body),
                });
                const payload = await response.json();
                if (!payload.ok) {
                    elements.tradeStatus.textContent = `交易失败：${payload.error || 'unknown'}`;
                    return;
                }
                elements.tradeStatus.textContent = '交易提交成功。';
            } catch (error) {
                elements.tradeStatus.textContent = `交易失败：${error.message}`;
            }
        }

        document.getElementById('refresh').addEventListener('click', fetchSnapshot);
        document.getElementById('submit-trade').addEventListener('click', submitTrade);

        fetchSnapshot();
        setInterval(fetchSnapshot, pollInterval);
    </script>
</body>
</html>
