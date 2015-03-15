<?php

abstract class InterProcessRedis {
    protected static $redis;

    public function __construct() {
        if (!self::$redis) {
            if (!class_exists('Redis')) {
                throw new FatalException("InterProcessRedis(): Redis class is not found. Check that the Redis PHP lib is in your include path", 0, null, LOGLEVEL_FATAL);
            }

            self::$redis = new Redis();
            self::$redis->connect(IPC_REDIS_IP, IPC_REDIS_PORT);
        }
    }
}
