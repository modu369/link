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
        $redis->connect($config['host'], (int) $config['port']);

        if (!empty($config['prefix'])) {
            $redis->setOption(Redis::OPT_PREFIX, $config['prefix']);
        }

        self::$connection = $redis;

        return self::$connection;
    }
}
