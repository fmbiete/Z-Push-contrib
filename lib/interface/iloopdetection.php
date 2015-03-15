<?php

interface ILoopDetection {

    /**
     * Adds the process entry to the process stack
     *
     * @access public
     * @return boolean
     */
    public function ProcessLoopDetectionInit();

    /**
     * Marks the process entry as termineted successfully on the process stack
     *
     * @access public
     * @return boolean
     */
    public function ProcessLoopDetectionTerminate();

    /**
     * Adds an Exceptions to the process tracking
     *
     * @param Exception     $exception
     *
     * @access public
     * @return boolean
     */
    public function ProcessLoopDetectionAddException($exception);

    /**
     * Adds a folderid and connected status code to the process tracking
     *
     * @param string    $folderid
     * @param int       $status
     *
     * @access public
     * @return boolean
     */
    public function ProcessLoopDetectionAddStatus($folderid, $status);

    /**
     * Marks the current process as a PUSH connection
     *
     * @access public
     * @return boolean
     */
    public function ProcessLoopDetectionSetAsPush();

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
    public function ProcessLoopDetectionIsHierarchyResyncRequired();

    /**
     * Indicates if a previous process could not be terminated
     *
     * Checks if there is an end time for the last entry on the stack
     *
     * @access public
     * @return boolean
     *
     */
    public function ProcessLoopDetectionPreviousConnectionFailed();

    /**
     * Gets the PID of an outdated search process
     *
     * Returns false if there isn't any process
     *
     * @access public
     * @return boolean
     *
     */
    public function ProcessLoopDetectionGetOutdatedSearchPID();

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
     * @param string    $folderid   the parent folder of the message
     * @param string    $id         the id of the message
     *
     * @access public
     * @return boolean
     */
    public function SetBrokenMessage($folderid, $id);

    /**
     * Gets a list of all ids of a folder which were tracked and which were
     * accepted by the device from the last sync.
     *
     * @param string    $folderid   the parent folder of the message
     * @param string    $id         the id of the message
     *
     * @access public
     * @return array
     */
    public function GetSyncedButBeforeIgnoredMessages($folderid);

    /**
     * Marks a SyncState as "already used", e.g. when an import process started.
     * This is most critical for DiffBackends, as an imported message would be exported again
     * in the heartbeat if the notification is triggered before the import is complete.
     *
     * @param string $folderid          folder id
     * @param string $uuid              synkkey
     * @param string $counter           synckey counter
     *
     * @access public
     * @return boolean
     */
    public function SetSyncStateUsage($folderid, $uuid, $counter);

    /**
     * Checks if the given counter for a certain uuid+folderid was exported before.
     * Returns also true if the counter are the same but previously there were
     * changes to be exported.
     *
     * @param string $folderid          folder id
     * @param string $uuid              synkkey
     * @param string $counter           synckey counter
     *
     * @access public
     * @return boolean                  indicating if an uuid+counter were exported (with changes) before
     */
    public function IsSyncStateObsolete($folderid, $uuid, $counter);

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
     * @param string $folderid          the current folder id to be worked on
     * @param string $type              the type of that folder (Email, Calendar, Contact, Task)
     * @param string $uuid              the synkkey
     * @param string $counter           the synckey counter
     * @param string $maxItems          the current amount of items to be sent to the mobile
     * @param string $queuedMessages    the amount of messages which were found by the exporter
     *
     * @access public
     * @return boolean      when returning true if a loop has been identified
     */
    public function Detect($folderid, $type, $uuid, $counter, $maxItems, $queuedMessages);

    /**
     * Indicates if the next messages should be ignored (not be sent to the mobile!)
     *
     * @param string  $messageid        (opt) id of the message which is to be exported next
     * @param string  $folderid         (opt) parent id of the message
     * @param boolean $markAsIgnored    (opt) to peek without setting the next message to be
     *                                  ignored, set this value to false
     * @access public
     * @return boolean
     */
    public function IgnoreNextMessage($markAsIgnored = true, $messageid = false, $folderid = false);

    /**
     * Clears loop detection data
     *
     * @param string    $user           (opt) user which data should be removed - user can not be specified without
     * @param string    $devid          (opt) device id which data to be removed
     *
     * @return boolean
     * @access public
     */
    public function ClearData($user = false, $devid = false);

    /**
     * Returns loop detection data for a user and device
     *
     * @param string    $user
     * @param string    $devid
     *
     * @return array/boolean    returns false if data not available
     * @access public
     */
    public function GetCachedData($user, $devid);
}
