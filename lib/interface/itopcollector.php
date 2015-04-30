<?php

interface ITopCollector
{
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
    public function CollectData($stop = false);

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
    public function AnnounceInformation($addinfo, $preserve = false, $terminating = false);

    /**
     * Returns all available top data
     *
     * @access public
     * @return array
     */
    public function ReadLatest();

    /**
     * Cleans up data collected so far
     *
     * @param boolean $all (optional) if set all data independently from the age is removed
     *
     * @access public
     * @return boolean status
     */
    public function ClearLatest($all = false);

    /**
     * Sets a different UserAgent for this connection
     *
     * @param string $agent
     *
     * @access public
     * @return boolean
     */
    public function SetUserAgent($agent);

    /**
     * Marks this process as push connection
     *
     * @param string $agent
     *
     * @access public
     * @return boolean
     */
    public function SetAsPushConnection();
}
