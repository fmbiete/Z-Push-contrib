<?php

class LoopDetectionRedis extends InterProcessRedis
{
    const TTL = 86400;
    private static $keystack;
    private static $keybroken;
    private static $keyfolder;
    private static $processentry;
    private $ignore_messageid;
    private $broken_message_uuid;
    private $broken_message_counter;

    public function __construct()
    {
        parent::__construct();
        if (!self::$keystack) {
            $devuser = Request::GetDeviceID().'|'.Request::GetAuthUser();
            self::$keystack = "ZP-LOOP-STACK|".$devuser;
            self::$keybroken = "ZP-LOOP-BROKEN|".$devuser.'|';
            self::$keyfolder = "ZP-LOOP-FOLDER|".$devuser.'|';
            self::$processentry = array();
            self::$processentry['pid'] = getmypid();
            self::$processentry['time'] = $_SERVER['REQUEST_TIME_FLOAT'];
            self::$processentry['id'] = self::$processentry['pid'].'|'.self::$processentry['time'];
            self::$processentry['cc'] = Request::GetCommandCode();
        }
    }

    /**
     * PROCESS LOOP DETECTION
     */

    /**
     * Adds the process entry to the process stack
     *
     * @access public
     * @return boolean
     */
    public function ProcessLoopDetectionInit()
    {
        $this->updateProcessStack();
    }

    /**
     * Marks the process entry as termineted successfully on the process stack
     *
     * @access public
     * @return boolean
     */
    public function ProcessLoopDetectionTerminate()
    {
        self::$processentry['end'] = time();
        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->ProcessLoopDetectionTerminate()");
        $this->updateProcessStack();

        return true;
    }

    /**
     * Adds an Exceptions to the process tracking
     *
     * @param Exception $exception
     *
     * @access public
     * @return boolean
     */
    public function ProcessLoopDetectionAddException($exception)
    {
        if (!isset(self::$processentry['stat']))
            self::$processentry['stat'] = array();
        self::$processentry['stat'][get_class($exception)] = $exception->getCode();
        $this->updateProcessStack();

        return true;
    }

    /**
     * Adds a folderid and connected status code to the process tracking
     *
     * @param string $folderid
     * @param int    $status
     *
     * @access public
     * @return boolean
     */
    public function ProcessLoopDetectionAddStatus($folderid, $status)
    {
        if ($folderid === false)
            $folderid = "hierarchy";
        if (!isset(self::$processentry['stat']))
            self::$processentry['stat'] = array();
        self::$processentry['stat'][$folderid] = $status;
        $this->updateProcessStack();

        return true;
    }

    /**
     * Marks the current process as a PUSH connection
     *
     * @access public
     * @return boolean
     */
    public function ProcessLoopDetectionSetAsPush()
    {
        self::$processentry['push'] = true;
        $this->updateProcessStack();

        return true;
    }

    /**
     * Indicates if a full Hierarchy Resync is necessary
     *
     * In some occasions the mobile tries to sync a folder with an invalid/not-existing ID.
     * In these cases a status exception like SYNC_STATUS_FOLDERHIERARCHYCHANGED is returned
     * so the mobile executes a FolderSync expecting that some action is taken on that folder (e.g. remove).
     *
     * If the FolderSync is not doing anything relevant, then the Sync is attempted again
     * resulting in the same error and looping between these two processes.
     *
     * This method checks if in the last process stack a Sync and FolderSync were triggered to
     * catch the loop at the 2nd interaction (Sync->FolderSync->Sync->FolderSync => ReSync)
     * Ticket: https://jira.zarafa.com/browse/ZP-5
     *
     * @access public
     * @return boolean
     *
     */
    public function ProcessLoopDetectionIsHierarchyResyncRequired()
    {
        $seenFailed = array();
        $seenFolderSync = false;

        $lookback = self::$processentry['time'] - 600; // look at the last 5 min
        foreach ($this->getProcessStack() as $se) {
            if ($se['time'] > $lookback && $se['time'] < (self::$processentry['time']-1)) {
                // look for sync command
                if (isset($se['stat']) && ($se['cc'] == ZPush::COMMAND_SYNC || $se['cc'] == ZPush::COMMAND_PING)) {
                    foreach ($se['stat'] as $key => $value) {
                        if (!isset($seenFailed[$key]))
                            $seenFailed[$key] = 0;
                        $seenFailed[$key]++;
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->ProcessLoopDetectionIsHierarchyResyncRequired(): seen command with Exception or folderid '%s' and code '%s'", $key, $value ));
                    }
                }
                // look for FolderSync command with previous failed commands
                if ($se['cc'] == ZPush::COMMAND_FOLDERSYNC && !empty($seenFailed) && $se['id'] != self::$processentry['id']) {
                    // a full folderresync was already triggered
                    if (isset($se['stat']) && isset($se['stat']['hierarchy']) && $se['stat']['hierarchy'] == SYNC_FSSTATUS_SYNCKEYERROR) {
                        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->ProcessLoopDetectionIsHierarchyResyncRequired(): a full FolderReSync was already requested. Resetting fail counter.");
                        $seenFailed = array();
                    } else {
                        $seenFolderSync = true;
                        if (!empty($seenFailed))
                            ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->ProcessLoopDetectionIsHierarchyResyncRequired(): seen FolderSync after other failing command");
                    }
                }
            }
        }
        $filtered = array();
        foreach ($seenFailed as $k => $count) {
            if ($count>1)
                $filtered[] = $k;
        }
        if ($seenFolderSync && !empty($filtered)) {
            ZLog::Write(LOGLEVEL_INFO, "LoopDetection->ProcessLoopDetectionIsHierarchyResyncRequired(): Potential loop detected. Full hierarchysync indicated.");

            return true;
        }

        return false;
    }

    /**
     * Indicates if a previous process could not be terminated
     *
     * Checks if there is an end time for the last entry on the stack
     *
     * @access public
     * @return boolean
     *
     */
    public function ProcessLoopDetectionPreviousConnectionFailed()
    {
        $stack = $this->getProcessStack();
        if (count($stack) > 1) {
            $se = $stack[0];
            if (!isset($se['end']) && $se['cc'] != ZPush::COMMAND_PING && !isset($se['push']) ) {
                // there is no end time
                ZLog::Write(LOGLEVEL_ERROR, sprintf("LoopDetection->ProcessLoopDetectionPreviousConnectionFailed(): Command '%s' at %s with pid '%d' terminated unexpectedly or is still running.", Utils::GetCommandFromCode($se['cc']), Utils::GetFormattedTime($se['time']), $se['pid']));
                ZLog::Write(LOGLEVEL_ERROR, "Please check your logs for this PID and errors like PHP-Fatals or Apache segmentation faults and report your results to the Z-Push dev team.");
            }
        }
    }

    /**
     * Gets the PID of an outdated search process
     *
     * Returns false if there isn't any process
     *
     * @access public
     * @return boolean
     *
     */
    public function ProcessLoopDetectionGetOutdatedSearchPID()
    {
        $stack = $this->getProcessStack();
        if (count($stack) > 1) {
            $se = $stack[0];
            if ($se['cc'] == ZPush::COMMAND_SEARCH) {
                return $se['pid'];
            }
        }

        return false;
    }

    /**
     * Inserts or updates the current process entry on the stack
     *
     * @access private
     * @return boolean
     */
    private function updateProcessStack()
    {
        while (true) {
            self::$redis->watch(self::$keystack);
            $stack = self::$redis->get(self::$keystack);
            if ($stack === false) {
                $stack = array();
            } else {
                $stack = unserialize($stack);
            }

            // insert/update current process entry
            $nstack = array();
            $found = false;
            foreach ($stack as $entry) {
                if ($entry['id'] != self::$processentry['id']) {
                    $nstack[] = $entry;
                } else {
                    $nstack[] = self::$processentry;
                    $found = true;
                }
            }
            if (!$found)
                $nstack[] = self::$processentry;
            if (count($nstack) > 10)
                $nstack = array_slice($nstack, -10, 10);
            // update loop data
            if(self::$redis->multi()
                ->setex(self::$keystack,self::TTL,serialize($nstack))
                ->exec()) {
                return true;
            } else {
                ZLog::Write(LOGLEVEL_WARN, "updateProcessStack(): setex just failed (too much concurrency), retrying");
            }
        }
    }

    /**
     * Returns the current process stack
     *
     * @access private
     * @return array
     */
    private function getProcessStack()
    {
        $stack = self::$redis->get(self::$keystack);
        if ($stack === false) {
            return array();
        } else {
            return unserialize($stack);
        }
    }

    /**
     * TRACKING OF BROKEN MESSAGES
     * if a previousily ignored message is streamed again to the device it's tracked here
     *
     * There are two outcomes:
     * - next uuid counter is higher than current -> message is fixed and successfully synchronized
     * - next uuid counter is the same or uuid changed -> message is still broken
     */

    /**
     * Adds a message to the tracking of broken messages
     * Being tracked means that a broken message was streamed to the device.
     * We save the latest uuid and counter so if on the next sync the counter is higher
     * the message was accepted by the device.
     *
     * @param string $folderid the parent folder of the message
     * @param string $id       the id of the message
     *
     * @access public
     * @return boolean
     */
    public function SetBrokenMessage($folderid, $id)
    {
        if ($folderid == false || !isset($this->broken_message_uuid) || !isset($this->broken_message_counter) || $this->broken_message_uuid == false || $this->broken_message_counter == false)
            return false;

        $brokenmsg = array('uuid' => $this->broken_message_uuid, 'counter' => $this->broken_message_counter);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->SetBrokenMessage('%s', '%s'): tracking broken message", $folderid, $id));
        self::$redis->multi()
            ->hSet(self::$keybroken.$folderid, $id, serialize($brokenmsg))
            ->expire(self::$keybroken.$folderid, self::TTL)
            ->exec();
    }

    private function RemoveBrokenMessage($folderid, $id)
    {
        $exec = self::$redis->multi()
            ->hDel(self::$keybroken.$folderid, $id)
            ->expire(self::$keybroken.$folderid, self::TTL)
            ->exec();

        return $exec[0] === 1;
    }

    /**
     * Gets a list of all ids of a folder which were tracked and which were
     * accepted by the device from the last sync.
     *
     * @param string $folderid the parent folder of the message
     * @param string $id       the id of the message
     *
     * @access public
     * @return array
     */
    public function GetSyncedButBeforeIgnoredMessages($folderid)
    {
        if ($folderid == false || !isset($this->broken_message_uuid) || !isset($this->broken_message_counter) || $this->broken_message_uuid == false || $this->broken_message_counter == false)
            return array();

        $removeIds = array();
        $okIds = array();
        $brokenmsgs = self::$redis->hGetAll(self::$keybroken.$folderid);
        if (!empty($brokenmsgs)) {
            foreach ($brokenmsgs as $id => $data) {
                $data = unserialize($data);
                // previously broken message was sucessfully synced!
                if ($data['uuid'] == $this->broken_message_uuid && $data['counter'] < $this->broken_message_counter) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->GetSyncedButBeforeIgnoredMessages('%s'): message '%s' was successfully synchronized", $folderid, $id));
                    $okIds[] = $id;
                }
                // if the uuid has changed this is old data which should also be removed
                if ($data['uuid'] != $this->broken_message_uuid) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->GetSyncedButBeforeIgnoredMessages('%s'): stored message id '%s' for uuid '%s' is obsolete", $folderid, $id, $data['uuid']));
                    $removeIds[] = $id;
                }
            }
            // remove data
            $arg = array_merge(array(self::$keybroken.$folderid),$okIds,$removeIds);
            if (count($arg) > 1)
                call_user_func_array(array(self::$redis, 'hDel'), $arg);

            //we want to increase the ttl and test if the hash is still there
            if (!self::$redis->expire(self::$keybroken.$folderid, self::TTL)) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->GetSyncedButBeforeIgnoredMessages('%s'): removed folder from tracking of ignored messages", $folderid));
            }
        }

        return $okIds;
    }

    /**
     * Marks a SyncState as "already used", e.g. when an import process started.
     * This is most critical for DiffBackends, as an imported message would be exported again
     * in the heartbeat if the notification is triggered before the import is complete.
     *
     * @param string $folderid folder id
     * @param string $uuid     synkkey
     * @param string $counter  synckey counter
     *
     * @access public
     * @return boolean
     */
    public function SetSyncStateUsage($folderid, $uuid, $counter)
    {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->SetSyncStateUsage(): uuid: %s  counter: %d", $uuid, $counter));

        return self::$redis->hSet(self::$keyfolder.$folderid, 'usage', $counter) !== false;
    }

    /**
     * Checks if the given counter for a certain uuid+folderid was exported before.
     * Returns also true if the counter are the same but previously there were
     * changes to be exported.
     *
     * @param string $folderid folder id
     * @param string $uuid     synkkey
     * @param string $counter  synckey counter
     *
     * @access public
     * @return boolean indicating if an uuid+counter were exported (with changes) before
     */
    public function IsSyncStateObsolete($folderid, $uuid, $counter)
    {
        $current = self::$redis->hGetAll(self::$keyfolder.$folderid);
        if (empty($current))
            return false;
        $current = unserialize($current);
        if (!empty($current)) {
            if (!isset($current["uuid"]) || $current["uuid"] != $uuid) {
                ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->IsSyncStateObsolete(): yes, uuid changed or not set");

                return true;
            } else {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->IsSyncStateObsolete(): check uuid counter: %d - last known counter: %d with %d queued objects", $counter, $current["count"], $current["queued"]));

                if ($current["uuid"] == $uuid && ($current["count"] > $counter || ($current["count"] == $counter && $current["queued"] > 0) || (isset($current["usage"]) && $current["usage"] >= $counter))) {
                    $usage = isset($current["usage"]) ? sprintf(" - counter %d already expired",$current["usage"]) : "";
                    ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->IsSyncStateObsolete(): yes, counter already processed". $usage);

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * MESSAGE LOOP DETECTION
     */

    /**
     * Loop detection mechanism
     *
     *    1. request counter is higher than the previous counter (somehow default)
     *      1.1)   standard situation                                   -> do nothing
     *      1.2)   loop information exists
     *      1.2.1) request counter < maxCounter AND no ignored data     -> continue in loop mode
     *      1.2.2) request counter < maxCounter AND ignored data        -> we have already encountered issue, return to normal
     *
     *    2. request counter is the same as the previous, but no data was sent on the last request (standard situation)
     *
     *    3. request counter is the same as the previous and last time objects were sent (loop!)
     *      3.1)   no loop was detected before, entereing loop mode     -> save loop data, loopcount = 1
     *      3.2)   loop was detected before, but are gone               -> loop resolved
     *      3.3)   loop was detected before, continuing in loop mode    -> this is probably the broken element,loopcount++,
     *      3.3.1) item identified, loopcount >= 3                      -> ignore item, set ignoredata flag
     *
     * @param string $folderid       the current folder id to be worked on
     * @param string $type           the type of that folder (Email, Calendar, Contact, Task)
     * @param string $uuid           the synkkey
     * @param string $counter        the synckey counter
     * @param string $maxItems       the current amount of items to be sent to the mobile
     * @param string $queuedMessages the amount of messages which were found by the exporter
     *
     * @access public
     * @return boolean when returning true if a loop has been identified
     */
    public function Detect($folderid, $type, $uuid, $counter, $maxItems, $queuedMessages)
    {
        $this->broken_message_uuid = $uuid;
        $this->broken_message_counter = $counter;

        // if an incoming loop is already detected, do nothing
        if ($maxItems === 0 && $queuedMessages > 0) {
            ZPush::GetTopCollector()->AnnounceInformation("Incoming loop!", true);

            return true;
        }

        $loop = false;

        while (true) {
            self::$redis->watch(self::$keyfolder.$folderid);
            $current = self::$redis->hGetAll(self::$keyfolder.$folderid);

            // completely new/unknown UUID
            if (empty($current))
                $current = array("type" => $type, "uuid" => $uuid, "count" => $counter-1, "queued" => $queuedMessages);
            // old UUID in cache - the device requested a new state!!
            elseif (isset($current['type']) && $current['type'] == $type && isset($current['uuid']) && $current['uuid'] != $uuid ) {
                ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): UUID changed for folder");
                // some devices (iPhones) may request new UUIDs after broken items were sent several times
                if (isset($current['queued']) && $current['queued'] > 0 &&
                    (isset($current['maxCount']) && $current['count']+1 < $current['maxCount'] || $counter == 1)) {
                        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): UUID changed and while items where sent to device - forcing loop mode");
                        $loop = true; // force loop mode
                        $current['queued'] = $queuedMessages;
                } else {
                    $current['queued'] = 0;
                }
                // set new data, unset old loop information
                $current["uuid"] = $uuid;
                $current['count'] = $counter;
                unset($current['loopcount']);
                unset($current['ignored']);
                unset($current['maxCount']);
                unset($current['potential']);
            }

            // see if there are values
            if (isset($current['uuid']) && $current['uuid'] == $uuid &&
                isset($current['type']) && $current['type'] == $type &&
                isset($current['count'])) {

                // case 1 - standard, during loop-resolving & resolving
                if ($current['count'] < $counter) {

                    // case 1.1
                    $current['count'] = $counter;
                    $current['queued'] = $queuedMessages;
                    if (isset($current["usage"]) && $current["usage"] < $current['count'])
                        unset($current["usage"]);
                    // case 1.2
                    if (isset($current['maxCount'])) {
                        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 1.2 detected");
                        // case 1.2.1
                        // broken item not identified yet
                        if (!isset($current['ignored']) && $counter < $current['maxCount']) {
                            $loop = true; // continue in loop-resolving
                            ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 1.2.1 detected");
                        }
                        // case 1.2.2 - if there were any broken items they should be gone, return to normal
                        else {
                            ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 1.2.2 detected");
                            unset($current['loopcount']);
                            unset($current['ignored']);
                            unset($current['maxCount']);
                            unset($current['potential']);
                        }
                    }
                }
                // case 2 - same counter, but there were no changes before and are there now
                elseif ($current['count'] == $counter && $current['queued'] == 0 && $queuedMessages > 0) {
                    $current['queued'] = $queuedMessages;
                    if (isset($current["usage"]) && $current["usage"] < $current['count'])
                        unset($current["usage"]);
                }
                // case 3 - same counter, changes sent before, hanging loop and ignoring
                elseif ($current['count'] == $counter && $current['queued'] > 0) {
                    if (!isset($current['loopcount'])) {
                        // case 3.1) we have just encountered a loop!
                        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 3.1 detected - loop detected, init loop mode");
                        $current['loopcount'] = 1;
                        // the MaxCount is the max number of messages exported before
                        $current['maxCount'] = $counter + (($maxItems < $queuedMessages) ? $maxItems : $queuedMessages);
                        $loop = true;   // loop mode!!
                    } elseif ($queuedMessages == 0) {
                        // case 3.2) there was a loop before but now the changes are GONE
                        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 3.2 detected - changes gone - clearing loop data");
                        $current['queued'] = 0;
                        unset($current['loopcount']);
                        unset($current['ignored']);
                        unset($current['maxCount']);
                        unset($current['potential']);
                    } else {
                        // case 3.3) still looping the same message! Increase counter
                        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 3.3 detected - in loop mode, increase loop counter");
                        $current['loopcount']++;
                        // case 3.3.1 - we got our broken item!
                        if ($current['loopcount'] >= 3 && isset($current['potential'])) {
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->Detect(): case 3.3.1 detected - broken item should be next, attempt to ignore it - id '%s'", $current['potential']));
                            $this->ignore_messageid = $current['potential'];
                        }
                        $current['maxCount'] = $counter + $queuedMessages;
                        $loop = true;   // loop mode!!
                    }
                }
            }
            if (isset($current['loopcount']))
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->Detect(): loop data: loopcount(%d), maxCount(%d), queued(%d), ignored(%s)", $current['loopcount'], $current['maxCount'], $current['queued'], (isset($current['ignored']) ? $current['ignored'] : 'false')));

            $exec = self::$redis->multi()
                ->del(self::$keyfolder.$folderid)
                ->hMset(self::$keyfolder.$folderid, $current)
                ->expire(self::$keyfolder.$folderid, self::TTL)
                ->exec();
            if ($exec[1]) {
                break;
            } else {
                ZLog::Write(LOGLEVEL_WARN, "Detect($folderid, $type, $uuid, $counter, $maxItems, $queuedMessages): setex just failed (too much concurrency), retrying");
            }
        }

        if ($loop == true && $this->ignore_messageid == false) {
            ZPush::GetTopCollector()->AnnounceInformation("Loop detection", true);
        }

        return $loop;
    }

    /**
     * Indicates if the next messages should be ignored (not be sent to the mobile!)
     *
     * @param  string  $messageid     (opt) id of the message which is to be exported next
     * @param  string  $folderid      (opt) parent id of the message
     * @param  boolean $markAsIgnored (opt) to peek without setting the next message to be
     *                                ignored, set this value to false
     * @access public
     * @return boolean
     */
    public function IgnoreNextMessage($markAsIgnored = true, $messageid = false, $folderid = false)
    {
        // as the next message id is not available at all point this method is called, we use different indicators.
        // potentialbroken indicates that we know that the broken message should be exported next,
        // alltho we do not know for sure as it's export message orders can change
        // if the $messageid is available and matches then we are sure and only then really ignore it

        $potentialBroken = false;
        $realBroken = false;
        if (Request::GetCommandCode() == ZPush::COMMAND_SYNC && $this->ignore_messageid !== false)
            $potentialBroken = true;

        if ($messageid !== false && $this->ignore_messageid == $messageid)
            $realBroken = true;

        // this call is just to know what should be happening
        // no further actions necessary
        if ($markAsIgnored === false) {
            return $potentialBroken;
        }

        // we should really do something here

        // first we check if we are in the loop mode, if so,
        // we update the potential broken id message so we loop count the same message

        // we found our broken message!
        if ($realBroken) {
            $this->ignore_messageid = false;
            self::$redis->hSet(self::$keyfolder.$folderid, 'ignored', $messageid);
            // check if this message was broken before - here we know that it still is and remove it from the tracking
            if($this->RemoveBrokenMessage($folderid, $messageid))
                ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->IgnoreNextMessage(): previously broken message '$messageid' is still broken and will not be tracked anymore (folder = $folderid)");
            ZPush::GetTopCollector()->AnnounceInformation("Broken message ignored", true);
        }
        // not the broken message yet
        else {
            // update potential id if looping on an item
            if (self::$redis->hGet(self::$keyfolder.$folderid, 'loopcount') !== false) {
                // this message should be the broken one, but is not!!
                // we should reset the loop count because this is certainly not the broken one
                if ($potentialBroken) {
                    self::$redis->hMset(self::$keyfolder.$folderid, array('potential' => $messageid, 'loopcount' => 1));
                    ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->IgnoreNextMessage(): this should be the broken one, but is not! Resetting loop count.");
                } else {
                    self::$redis->hSet(self::$keyfolder.$folderid, 'potential', $messageid);
                }
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->IgnoreNextMessage(): Loop mode, potential broken message id '%s'", $messageid));
            }
        }

        return $realBroken;
    }

    /**
     * Clears loop detection data
     *
     * @param string $user  (opt) user which data should be removed
     * @param string $devid (opt) device id which data to be removed
     *
     * @return boolean
     * @access public
     */
    public function ClearData($user = false, $devid = false)
    {
        if ($user == false && $devid == false)
            self::$redis->del(self::$redis->keys('ZP-LOOP*'));
        elseif ($user == false && $devid != false)
            self::$redis->del(self::$redis->keys('ZP-LOOP[^|]*|'.$devid.'|*'));
        elseif ($user != false && $devid != false)
            self::$redis->del(self::$redis->keys('ZP-LOOP[^|]*|'.$devid.'|'.$user.'*'));
        elseif ($user != false && $devid == false) {
            self::$redis->del(self::$redis->keys('ZP-LOOP[^|]*|[^|]*|'.$user.'*'));
        }

        return true;
    }

    /**
     * Returns loop detection data for a user and device
     *
     * @param string $user
     * @param string $devid
     *
     * @return array/boolean returns false if data not available
     * @access public
     */
    public function GetCachedData($user, $devid)
    {
        //nobody really use it ...
        return false;
    }
}
