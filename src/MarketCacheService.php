<?php

class MarketCacheService
{
    private PriceCache $priceCache;
    private MarketCache $marketCache;

    public function __construct(array $config)
    {
        require_once __DIR__ . '/PriceCache.php';
        require_once __DIR__ . '/MarketCache.php';

        $pricePath = $config['price']['cache_path'] ?? __DIR__ . '/../storage/price.json';
        $marketPath = $config['market']['cache_path'] ?? __DIR__ . '/../storage/market.json';
        $this->priceCache = new PriceCache($pricePath);
        $this->marketCache = new MarketCache($marketPath);
    }

    public function readPrice(): ?array
    {
        return $this->priceCache->read();
    }

    public function readMarket(): ?array
    {
        return $this->marketCache->read();
    }

    public function readRealtime(): array
    {
        $price = $this->priceCache->read() ?? [];
        $market = $this->marketCache->read() ?? [];

        return [
            'current_price' => $price['current_price'] ?? null,
            'price_to_beat' => $price['price_to_beat'] ?? null,
            'up_position' => $price['up_cents'] ?? ($market['up_price'] ?? null),
            'down_position' => $price['down_cents'] ?? ($market['down_price'] ?? null),
            'current_timestamp_ms' => $price['current_timestamp_ms'] ?? null,
            'market_timestamp_ms' => $market['timestamp_ms'] ?? null,
        ];
    }
}
