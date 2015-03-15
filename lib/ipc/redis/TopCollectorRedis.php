<?php

class TopCollectorRedis extends InterProcessRedis {
    const PREFIX = 'ZP-TOP|';
    protected $preserved;
    protected $latest;
    protected $key;

    public function __construct() {
        parent::__construct();
        $this->preserved = array();
        // static vars come from the parent class
        $this->latest = array(  "pid"       => getmypid(),
                                "ip"        => Request::GetRemoteAddr(),
                                "user"      => Request::GetAuthUser(),
                                "start"     => $_SERVER['REQUEST_TIME'],
                                "devtype"   => Request::GetDeviceType(),
                                "devid"     => Request::GetDeviceID(),
                                "devagent"  => Request::GetUserAgent(),
                                "command"   => Request::GetCommandCode(),
                                "ended"     => 0,
                                "push"      => false,
                        );
        $this->key = self::PREFIX.Request::GetDeviceID().'|'.Request::GetAuthUser().'|'.getmypid();
        $this->AnnounceInformation("initializing");
    }

    /**
     * Destructor
     * indicates that the process is shutting down
     *
     * @access public
     */
    public function __destruct() {
        $this->AnnounceInformation("OK", false, true);
    }

    /**
     * Advices all other processes that they should start/stop
     * collecting data. The data saved is a timestamp. It has to be
     * reactivated every couple of seconds
     *
     * @param boolean $stop (opt) default false (do collect)
     *
     * @access public
     * @return boolean indicating if it was set to collect before
     */
    public function CollectData($stop = false) {
        //for now we always collect top data
        return true;
    }

    /**
     * Announces a string to the TopCollector
     *
     * @param string  $info
     * @param boolean $preserve    info should be displayed when process terminates
     * @param boolean $terminating indicates if the process is terminating
     *
     * @access public
     * @return boolean
     */
    public function AnnounceInformation($addinfo, $preserve = false, $terminating = false) {
        $this->latest["addinfo"] = $addinfo;
        $this->latest["update"] = time();

        if ($terminating) {
            $this->latest["ended"] = time();
            foreach ($this->preserved as $p)
                $this->latest["addinfo"] .= " : " . $p;
        }

        if ($preserve)
            $this->preserved[] = $addinfo;

        $exp = $terminating ? 20 : 120;
        self::$redis->setex($this->key,$exp,serialize($this->latest));

        return true;
    }

    /**
     * Returns all available top data
     *
     * @access public
     * @return array
     */
    public function ReadLatest() {
        $topdata = array();
        $keys = self::$redis->keys(self::PREFIX . '*');
        $values = self::$redis->mget($keys);
        $array = array_combine($keys, $values);
        foreach ($array as $key => $value) {
            if ($value === false)
                continue;
            $key = explode('|',$key);
            if (!array_key_exists($key[1], $topdata))
                $topdata[$key[1]] = array();
            if (!array_key_exists($key[2], $topdata[$key[1]]))
                $topdata[$key[1]][$key[2]] = array();
            $topdata[$key[1]][$key[2]][$key[3]] = unserialize($value);
        }

        return $topdata;
    }

    /**
     * Cleans up data collected so far
     *
     * @param boolean $all (optional) if set all data independently from the age is removed
     *
     * @access public
     * @return boolean status
     */
    public function ClearLatest($all = false) {
        if($all)
            self::$redis->del(self::$redis->keys(self::PREFIX.'*'));
        return true;
    }

    /**
     * Sets a different UserAgent for this connection
     *
     * @param string $agent
     *
     * @access public
     * @return boolean
     */
    public function SetUserAgent($agent) {
        $this->latest["devagent"] = $agent;

        return true;
    }

    /**
     * Marks this process as push connection
     *
     * @param string $agent
     *
     * @access public
     * @return boolean
     */
    public function SetAsPushConnection() {
        $this->latest["push"] = true;

        return true;
    }
}
