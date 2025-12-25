<?php

class MarketCache
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

    public function write(float $upPrice, float $downPrice, int $timestampMs): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $payload = [
            'up_price' => $upPrice,
            'down_price' => $downPrice,
            'timestamp_ms' => $timestampMs,
        ];

        file_put_contents($this->path, json_encode($payload));
    }
}
