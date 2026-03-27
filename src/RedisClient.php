<?php

class RedisClient
{
    private static ?Redis $connection = null;

    public static function connection(array $config): Redis
    {
        if (self::$connection instanceof Redis) {
            return self::$connection;
        }

        $redis = new Redis();
        // 核心优化：将 connect 改为 pconnect，开启 PHP-FPM 级别的 Redis 长连接
        $redis->pconnect($config['host'], (int) $config['port']);

        if (!empty($config['prefix'])) {
            $redis->setOption(Redis::OPT_PREFIX, $config['prefix']);
        }

        self::$connection = $redis;

        return self::$connection;
    }
}
