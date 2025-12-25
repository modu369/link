<?php

class PriceFeed
{
    private HttpClient $http;
    private string $endpoint;
    private string $pricePath;

    public function __construct(HttpClient $http, string $endpoint, string $pricePath)
    {
        $this->http = $http;
        $this->endpoint = $endpoint;
        $this->pricePath = $pricePath;
    }

    public function fetchCurrentPrice(): array
    {
        $response = $this->http->get($this->endpoint);
        if (!$response['ok']) {
            return [
                'ok' => false,
                'status' => $response['status'] ?? 0,
                'error' => $response['error'] ?? 'Failed to fetch price feed',
            ];
        }

        $price = $this->extractPrice($response['data']);
        return [
            'ok' => $price !== null,
            'price' => $price,
            'raw' => $response['data'],
        ];
    }

    private function extractPrice($payload): ?float
    {
        if (!is_array($payload)) {
            return null;
        }

        $value = $payload;
        $segments = array_filter(explode('.', $this->pricePath));
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
