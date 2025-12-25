<?php

class MarketCacheService
{
    private PriceCache $priceCache;
    private MarketCache $marketCache;

    public function __construct(array $config)
    {
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
}
