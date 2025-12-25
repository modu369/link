const priceEl = document.getElementById('currentPrice');
const upEl = document.getElementById('upPosition');
const downEl = document.getElementById('downPosition');
const signalEl = document.getElementById('signal');
const openTimeEl = document.getElementById('openTime');
const closeTimeEl = document.getElementById('closeTime');
const eventTitleEl = document.getElementById('eventTitle');
const countdownEl = document.getElementById('countdown');

let latestCloseTime = null;
let latestServerTime = null;

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

        priceEl.textContent = Number(data.current_price).toFixed(2);
        upEl.textContent = `${Number(data.up_position).toFixed(2)}¢`;
        downEl.textContent = `${Number(data.down_position).toFixed(2)}¢`;

        const delta = data.current_price - data.open_price;
        const direction = delta >= 0 ? '上涨' : '下跌';
        signalEl.textContent = `当前价格${direction} ${Math.abs(delta).toFixed(2)}，UP ${data.up_position}¢ / DOWN ${data.down_position}¢`;

        latestCloseTime = data.close_time;
        latestServerTime = data.server_time;
        updateCountdown();
    } catch (error) {
        signalEl.textContent = `数据加载失败：${error.message}`;
    }
}

loadMarket();
setInterval(loadMarket, 1000);
setInterval(updateCountdown, 1000);
