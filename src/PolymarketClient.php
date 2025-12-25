<?php

class PolymarketClient
{
    private HttpClient $http;
    private string $eventsEndpoint;
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

        $this->http = new HttpClient($config['base_url'], $headers);
        $this->eventsEndpoint = $config['events_endpoint'];
        $this->marketsEndpoint = $config['markets_endpoint'];
        $this->orderEndpoint = $config['order_endpoint'];
        $this->apiKey = $config['api_key'];
        $this->apiSecret = $config['api_secret'];
        $this->apiPassphrase = $config['api_passphrase'];
    }

    public function fetchEventBySlug(string $slug): array
    {
        $response = $this->http->get($this->eventsEndpoint, ['slug' => $slug]);
        return $response;
    }

    public function fetchMarketById(string $marketId): array
    {
        $response = $this->http->get($this->marketsEndpoint, ['id' => $marketId]);
        return $response;
    }

    public function placeOrder(array $payload): array
    {
        if ($this->apiKey === '' || $this->apiSecret === '' || $this->apiPassphrase === '') {
            return ['ok' => false, 'status' => 401, 'error' => 'Missing API credentials'];
        }

        return $this->http->post($this->orderEndpoint, $payload);
    }
}
