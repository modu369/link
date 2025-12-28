<?php

class PageviewQueue
{
    private array $config;
    private string $queueKey;
    private string $shardSetKey;

    public function __construct(private Redis $redis, array $config = [])
    {
        $this->config = array_replace_recursive([
            'key' => 'queue:pageviews',
            'batch_size' => 1000,
            'sharding' => [
                'strategy' => 'time',
                'count' => 8,
                'time_format' => 'YmdH',
                'shards_set_ttl' => 172800,
            ],
        ], $config);

        $this->queueKey = $this->config['key'];
        $this->shardSetKey = $this->queueKey . ':shards';
    }

    public function enqueue(string $trackingId, array $payload, ?int $siteId = null): void
    {
        $shard = $this->resolveShard($siteId);
        $queueKey = $this->queueKeyForShard($shard);

        $event = [
            'tracking_id' => $trackingId,
            'payload' => $payload,
            'site_id' => $siteId,
            'enqueued_at' => date('Y-m-d H:i:s'),
        ];

        $encoded = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }

        $this->redis->lPush($queueKey, $encoded);
        $this->registerShard($shard);
    }

    public function fetchBatch(string $shard, int $batchSize): array
    {
        $batchSize = max(1, $batchSize);
        $queueKey = $this->queueKeyForShard($shard);

        $lua = <<<'LUA'
local key = KEYS[1]
local size = tonumber(ARGV[1])
local results = {}
for i = 1, size do
    local item = redis.call('RPOP', key)
    if not item then
        break
    end
    table.insert(results, item)
end
return results
LUA;

        $result = $this->redis->eval($lua, [$queueKey, $batchSize], 1);

        return is_array($result) ? $result : [];
    }

    public function listShards(): array
    {
        $strategy = $this->config['sharding']['strategy'] ?? 'time';
        if ($strategy === 'site_id') {
            $count = max(1, (int) ($this->config['sharding']['count'] ?? 1));
            return array_map('strval', range(0, $count - 1));
        }

        $shards = $this->redis->sMembers($this->shardSetKey);
        if (!$shards) {
            return [];
        }

        sort($shards);

        return array_values($shards);
    }

    public function getQueueDepth(string $shard): int
    {
        return (int) $this->redis->lLen($this->queueKeyForShard($shard));
    }

    public function queueKeyForShard(string $shard): string
    {
        return sprintf('%s:%s', $this->queueKey, $shard);
    }

    public function defaultBatchSize(): int
    {
        return (int) ($this->config['batch_size'] ?? 1000);
    }

    public function resolveShard(?int $siteId = null): string
    {
        $strategy = $this->config['sharding']['strategy'] ?? 'time';
        if ($strategy === 'site_id') {
            $count = max(1, (int) ($this->config['sharding']['count'] ?? 1));
            $bucket = $siteId === null ? 0 : ($siteId % $count);
            return (string) $bucket;
        }

        $format = $this->config['sharding']['time_format'] ?? 'YmdH';
        return date($format);
    }

    private function registerShard(string $shard): void
    {
        $this->redis->sAdd($this->shardSetKey, $shard);
        $ttl = (int) ($this->config['sharding']['shards_set_ttl'] ?? 0);
        if ($ttl > 0) {
            $this->redis->expire($this->shardSetKey, $ttl);
        }
    }
}
