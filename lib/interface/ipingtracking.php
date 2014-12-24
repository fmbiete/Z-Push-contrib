<?php

interface IPingTracking {
    /**
     * Checks if there are newer ping requests for the same device & user so
     * the current process could be terminated
     *
     * @access public
     * @return boolean true if the current process is obsolete
     */
    public function DoForcePingTimeout();
}
