<?php

class PingTrackingRedis extends InterProcessRedis {
    const TTL = 3600;
    private $key;

    public function __construct() {
        parent::__construct();
        $this->key = "ZP-PING|" . self::$devid . '|' . self::$user . '|' . Request::GetAuthDomain();
    }

    /**
     * Checks if there are newer ping requests for the same device & user so
     * the current process could be terminated
     *
     * @access public
     * @return boolean true if the current process is obsolete
     */
    public function DoForcePingTimeout() {
        while (true) {
            self::$redis->watch($this->key);
            $savedtime = self::$redis->get($this->key);
            if ($savedtime === false || $savedtime < $_SERVER['REQUEST_TIME']) {
                $res = self::$redis->multi()->setex($this->key, self::TTL, $_SERVER['REQUEST_TIME'])->exec();
                if ($res === false) {
                    ZLog::Write(LOGLEVEL_DEBUG, "DoForcePingTimeout(): set just failed, retrying");
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("DoForcePingTimeout() key: '%s' reqtime: '%s' last_error: '%s'", $this->key, $_SERVER['REQUEST_TIME'], self::$redis->getLastError()));
                    continue;
                }
                else {
                    return false;
                }
            }

            if ($savedtime === $_SERVER['REQUEST_TIME']) {
                self::$redis->unwatch();

                return false;
            }
            if ($savedtime > $_SERVER['REQUEST_TIME']) {
                self::$redis->unwatch();

                return true;
            }
        }
    }
}
