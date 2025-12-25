<?php

class PolymarketClient
{
    private HttpClient $gammaHttp;
    private HttpClient $clobHttp;
    private string $gammaEventsEndpoint;
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

        $this->gammaHttp = new HttpClient($config['gamma_base_url']);
        $this->clobHttp = new HttpClient($config['clob_base_url'], $headers);
        $this->gammaEventsEndpoint = $config['gamma_events_endpoint'];
        $this->orderEndpoint = $config['order_endpoint'];
        $this->apiKey = $config['api_key'];
        $this->apiSecret = $config['api_secret'];
        $this->apiPassphrase = $config['api_passphrase'];
    }

    public function fetchEventBySlug(string $slug): array
    {
        return $this->gammaHttp->get($this->gammaEventsEndpoint, [
            'limit' => 1,
            'active' => 'true',
            'archived' => 'false',
            'closed' => 'false',
            'slug' => $slug,
        ]);
    }

    public function fetchLatestEvent(string $tagSlug, int $limit = 1): array
    {
        return $this->gammaHttp->get($this->gammaEventsEndpoint, [
            'limit' => $limit,
            'active' => 'true',
            'archived' => 'false',
            'closed' => 'false',
            'tag_slug' => $tagSlug,
        ]);
    }

    public function placeOrder(array $payload): array
    {
        if ($this->apiKey === '' || $this->apiSecret === '' || $this->apiPassphrase === '') {
            return ['ok' => false, 'status' => 401, 'error' => 'Missing API credentials'];
        }

        return $this->clobHttp->post($this->orderEndpoint, $payload);
    }
}
