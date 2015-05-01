<?php

abstract class InterProcessStorage {
    static protected $devid;
    static protected $pid;
    static protected $user;
    static protected $start;
    
    /**
     * Indicates if the inter-process mechanism is active
     *
     * @access public
     * @return boolean
     */
    abstract public function IsActive();

    /**
     * Initializes internal parameters
     *
     * @access public
     * @return boolean
     */
    public function InitializeParams() {
        if (!isset(self::$devid)) {
            self::$devid = Request::GetDeviceID();
            self::$pid = @getmypid();
            self::$user = Request::GetAuthUser();
            self::$start = time();
        }
        return true;
    }

    /**
     * Reinitializes inter-process data storage
     *
     * @access public
     * @return boolean
     */
    abstract public function ReInitSharedMem();
}
