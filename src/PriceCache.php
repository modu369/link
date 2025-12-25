<?php

class PriceCache
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function read(): ?array
    {
        if (!file_exists($this->path)) {
            return null;
        }

        $contents = file_get_contents($this->path);
        if ($contents === false || $contents === '') {
            return null;
        }

        $payload = json_decode($contents, true);
        if (!is_array($payload)) {
            return null;
        }

        return $payload;
    }

    public function writeCurrent(float $price, int $timestampMs, int $roundStartMs, float $priceToBeat, float $upCents, float $downCents): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $payload = [
            'current_price' => $price,
            'current_timestamp_ms' => $timestampMs,
            'round_start_ms' => $roundStartMs,
            'price_to_beat' => $priceToBeat,
            'up_cents' => $upCents,
            'down_cents' => $downCents,
        ];

        file_put_contents($this->path, json_encode($payload));
    }
}
