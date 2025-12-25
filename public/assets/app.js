const priceEl = document.getElementById('currentPrice');
const upEl = document.getElementById('upPosition');
const downEl = document.getElementById('downPosition');
const signalEl = document.getElementById('signal');
const openTimeEl = document.getElementById('openTime');
const closeTimeEl = document.getElementById('closeTime');
const eventTitleEl = document.getElementById('eventTitle');
const countdownEl = document.getElementById('countdown');
const openPriceEl = document.getElementById('openPrice');

let latestCloseTime = null;
let latestServerTime = null;
let wsLive = null;
let wsMarket = null;
let upAssetId = null;
let downAssetId = null;
let latestCurrentPrice = null;
let latestUpPrice = null;
let latestDownPrice = null;

function formatCountdown(seconds) {
    if (seconds <= 0 || Number.isNaN(seconds)) {
        return '00:00';
    }
    const mins = Math.floor(seconds / 60).toString().padStart(2, '0');
    const secs = Math.floor(seconds % 60).toString().padStart(2, '0');
    return `${mins}:${secs}`;
}

function updateCountdown() {
    if (!latestCloseTime || !latestServerTime || !countdownEl) {
        return;
    }

    const now = new Date(latestServerTime);
    const close = new Date(latestCloseTime);
    const diffSeconds = Math.max(0, (close - now) / 1000);
    countdownEl.textContent = formatCountdown(diffSeconds);
    latestServerTime = new Date(now.getTime() + 1000).toISOString();
}

async function loadMarket() {
    if (!priceEl) {
        return;
    }

    try {
        const response = await fetch('/api/market.php', { cache: 'no-store' });
        const data = await response.json();
        if (data.error) {
            throw new Error(data.error);
        }

        if (eventTitleEl) {
            eventTitleEl.textContent = data.event_title;
        }
        if (openTimeEl) {
            openTimeEl.textContent = data.open_time;
        }
        if (closeTimeEl) {
            closeTimeEl.textContent = data.close_time;
        }
        if (openPriceEl) {
            const openPriceValue = Number(data.open_price);
            openPriceEl.textContent = Number.isFinite(openPriceValue) ? openPriceValue.toFixed(2) : '--';
        }

        upAssetId = data.up_asset_id || upAssetId;
        downAssetId = data.down_asset_id || downAssetId;

        if (Number.isFinite(Number(data.current_price))) {
            latestCurrentPrice = Number(data.current_price);
            priceEl.textContent = latestCurrentPrice.toFixed(2);
        }
        if (Number.isFinite(Number(data.up_position))) {
            latestUpPrice = Number(data.up_position);
            upEl.textContent = `${latestUpPrice.toFixed(2)}¢`;
        }
        if (Number.isFinite(Number(data.down_position))) {
            latestDownPrice = Number(data.down_position);
            downEl.textContent = `${latestDownPrice.toFixed(2)}¢`;
        }
        updateSignal();

        if (!wsLive && data.ws_live_url) {
            connectLivePrice(data.ws_live_url);
        }
        if (!wsMarket && data.ws_market_url && (upAssetId || downAssetId)) {
            connectMarketPrices(data.ws_market_url);
        }

        latestCloseTime = data.close_time;
        latestServerTime = data.server_time;
        updateCountdown();
    } catch (error) {
        signalEl.textContent = `数据加载失败：${error.message}`;
    }
}

function connectLivePrice(url) {
    wsLive = new WebSocket(url);
    wsLive.onopen = () => {
        wsLive.send(JSON.stringify({
            action: 'subscribe',
            subscriptions: [{
                topic: 'crypto_prices_chainlink',
                type: 'update',
                filters: JSON.stringify({ symbol: 'btc/usd' }),
            }],
        }));
    };
    wsLive.onmessage = (event) => {
        try {
            const data = JSON.parse(event.data);
            if (data.topic !== 'crypto_prices_chainlink' || data.type !== 'update') {
                return;
            }
            const rawValue = data.payload?.full_accuracy_value;
            if (!rawValue) {
                return;
            }
            const price = Number(rawValue) / 1e18;
            latestCurrentPrice = price;
            priceEl.textContent = price.toFixed(2);
            updateSignal();
        } catch (error) {
            // ignore parse errors
        }
    };
    wsLive.onclose = () => {
        wsLive = null;
        setTimeout(() => connectLivePrice(url), 1000);
    };
}

function connectMarketPrices(url) {
    wsMarket = new WebSocket(url);
    wsMarket.onopen = () => {
        const assetIds = [upAssetId, downAssetId].filter(Boolean);
        wsMarket.send(JSON.stringify({
            assets_ids: assetIds,
            type: 'market',
        }));
    };
    wsMarket.onmessage = (event) => {
        try {
            const data = JSON.parse(event.data);
            if (data.event_type !== 'price_change') {
                return;
            }
            const changes = data.price_changes || [];
            changes.forEach((change) => {
                if (change.asset_id === upAssetId && change.price) {
                    latestUpPrice = Number(change.price) * 100;
                }
                if (change.asset_id === downAssetId && change.price) {
                    latestDownPrice = Number(change.price) * 100;
                }
            });
            if (latestUpPrice !== null) {
                upEl.textContent = `${latestUpPrice.toFixed(2)}¢`;
            }
            if (latestDownPrice !== null) {
                downEl.textContent = `${latestDownPrice.toFixed(2)}¢`;
            }
            updateSignal();
        } catch (error) {
            // ignore parse errors
        }
    };
    wsMarket.onclose = () => {
        wsMarket = null;
        setTimeout(() => connectMarketPrices(url), 1000);
    };
}

function updateSignal() {
    if (latestCurrentPrice === null || !openPriceEl) {
        return;
    }
    const openPrice = Number(openPriceEl.textContent.replace(/,/g, '')) || null;
    if (!openPrice) {
        return;
    }
    const delta = latestCurrentPrice - openPrice;
    const direction = delta >= 0 ? '上涨' : '下跌';
    const upText = latestUpPrice !== null ? `${latestUpPrice.toFixed(2)}¢` : '--';
    const downText = latestDownPrice !== null ? `${latestDownPrice.toFixed(2)}¢` : '--';
    signalEl.textContent = `当前价格${direction} ${Math.abs(delta).toFixed(2)}，UP ${upText} / DOWN ${downText}`;
}

loadMarket();
setInterval(loadMarket, 5000);
setInterval(updateCountdown, 1000);
