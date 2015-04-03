<?php

abstract class InterProcessRedis extends InterProcessStorage {
    protected static $redis;

    public function __construct() {
        if (!self::$redis) {
            if (!class_exists('Redis')) {
                throw new FatalException("InterProcessRedis(): Redis class is not found. Check that the PHPRedis lib is in your include path", 0, null, LOGLEVEL_FATAL);
            }

            self::$redis = new Redis();
            self::$redis->connect(IPC_REDIS_IP, IPC_REDIS_PORT);
        }
    }

    /**
     * Indicates if the shared memory is active
     *
     * @access public
     * @return boolean
     */
    public function IsActive() {
        $str = "Testing connection";
        return strcmp(self::$redis->echo($str), $str) == 0;
    }

    /**
     * Reinitializes inter-process data storage
     *
     * @access public
     * @return boolean
     */
    public function ReInitSharedMem() {
        return self::$redis->flushDB();
    }
}
