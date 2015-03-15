<?php

abstract class InterProcessRedis
{
    protected static $redis;

    public function __construct()
    {
        if (!self::$redis) {
            self::$redis = new Redis();
            self::$redis->connect('127.0.0.1');
        }
    }
}
