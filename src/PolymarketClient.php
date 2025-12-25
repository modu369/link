<?php

class PolymarketClient
{
    private HttpClient $clobHttp;
    private string $marketsEndpoint;
    private string $orderEndpoint;
    private string $apiKey;
    private string $apiSecret;
    private string $apiPassphrase;

    public function __construct(array $config)
    {
        $headers = [];
        if ($config['api_key'] !== '') {
            $headers[] = 'X-API-KEY: ' . $config['api_key'];
        }
        if ($config['api_secret'] !== '') {
            $headers[] = 'X-API-SECRET: ' . $config['api_secret'];
        }
        if ($config['api_passphrase'] !== '') {
            $headers[] = 'X-API-PASSPHRASE: ' . $config['api_passphrase'];
        }

        $this->clobHttp = new HttpClient($config['clob_base_url'], $headers);
        $this->marketsEndpoint = $config['markets_endpoint'];
        $this->orderEndpoint = $config['order_endpoint'];
        $this->apiKey = $config['api_key'];
        $this->apiSecret = $config['api_secret'];
        $this->apiPassphrase = $config['api_passphrase'];
    }

    public function fetchMarketBySlug(string $slug): array
    {
        $response = $this->clobHttp->get($this->marketsEndpoint, ['slug' => $slug]);
        if ($response['ok'] && isset($response['data']['data']) && $response['data']['data'] === []) {
            return $this->clobHttp->get($this->marketsEndpoint, [
                'query' => $slug,
                'limit' => 1,
            ]);
        }

        return $response;
    }

    public function fetchMarketById(string $marketId): array
    {
        return $this->clobHttp->get($this->marketsEndpoint . '/' . urlencode($marketId));
    }

    public function placeOrder(array $payload): array
    {
        if ($this->apiKey === '' || $this->apiSecret === '' || $this->apiPassphrase === '') {
            return ['ok' => false, 'status' => 401, 'error' => 'Missing API credentials'];
        }

        return $this->clobHttp->post($this->orderEndpoint, $payload);
    }
}
