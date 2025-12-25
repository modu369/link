const priceEl = document.getElementById('currentPrice');
const upEl = document.getElementById('upPosition');
const downEl = document.getElementById('downPosition');
const signalEl = document.getElementById('signal');

async function loadMarket() {
    if (!priceEl) {
        return;
    }

    try {
        const response = await fetch('/api/market.php');
        const data = await response.json();
        if (data.error) {
            throw new Error(data.error);
        }

        priceEl.textContent = data.current_price.toFixed(2);
        upEl.textContent = `${data.up_position}%`;
        downEl.textContent = `${data.down_position}%`;

        const delta = data.current_price - data.open_price;
        const direction = delta >= 0 ? '上涨' : '下跌';
        signalEl.textContent = `当前价格${direction} ${Math.abs(delta).toFixed(2)}，UP ${data.up_position} / DOWN ${data.down_position}`;
    } catch (error) {
        signalEl.textContent = `数据加载失败：${error.message}`;
    }
}

loadMarket();
setInterval(loadMarket, 5000);
