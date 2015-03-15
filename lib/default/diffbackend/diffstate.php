<?php
/***********************************************
* File      :   diffstate.php
* Project   :   Z-Push
* Descr     :   This is the differential engine.
*               We do a standard differential
*               change detection by sorting both
*               lists of items by their unique id,
*               and then traversing both arrays
*               of items at once. Changes can be
*               detected by comparing items at
*               the same position in both arrays.
*
* Created   :   02.01.2012
*
* Copyright 2007 - 2013 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

class DiffState implements IChanges {
    protected $syncstate;
    protected $backend;
    protected $flags;
    protected $contentparameters;
    protected $cutoffdate;

    /**
     * Initializes the state
     *
     * @param string        $state
     * @param int           $flags
     *
     * @access public
     * @return boolean status flag
     * @throws StatusException
     */
    public function Config($state, $flags = 0) {
        if ($state == "")
            $state = array();

        if (!is_array($state))
            throw new StatusException("Invalid state", SYNC_FSSTATUS_CODEUNKNOWN);

        $this->syncstate = $state;
        $this->flags = $flags;
        return true;
    }

    /**
     * Configures additional parameters used for content synchronization
     *
     * @param ContentParameters         $contentparameters
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function ConfigContentParameters($contentparameters) {
        $this->contentparameters = $contentparameters;

        $filtertype = $contentparameters->GetFilterType();
        switch($contentparameters->GetContentClass()) {
            case "Email":
            case "Calendar":
                $this->cutoffdate = ($filtertype === false) ? 0 : Utils::GetCutOffDate($filtertype);
                break;
            case "Contacts":
            case "Tasks":
            default:
                $this->cutoffdate = 0;
                break;
        }
    }

    /**
     * Returns state
     *
     * @access public
     * @return string
     * @throws StatusException
     */
    public function GetState() {
        if (!isset($this->syncstate) || !is_array($this->syncstate))
            throw new StatusException("DiffState->GetState(): Error, state not available", SYNC_FSSTATUS_CODEUNKNOWN, null, LOGLEVEL_WARN);

        return $this->syncstate;
    }


    /**----------------------------------------------------------------------------------------------------------
     * DiffState specific stuff
     */

    /**
     * Comparing function used for sorting of the differential engine
     *
     * @param array        $a
     * @param array        $b
     *
     * @access public
     * @return boolean
     */
    static public function RowCmp($a, $b) {
        if (is_numeric($a["id"]) && is_numeric($b["id"])) {
            return $a["id"] < $b["id"] ? 1 : -1;
        }
        else {
            return strcmp($a["id"], $b["id"]) < 0 ? 1 : -1;
        }
    }

    /**
     * Differential mechanism
     * Compares the current syncstate to the sent $new
     *
     * @param array        $new
     *
     * @access protected
     * @return array
     */
    protected function getDiffTo($new) {
        $changes = array();

        // Sort both arrays in the same way by ID
        usort($this->syncstate, array("DiffState", "RowCmp"));
        usort($new, array("DiffState", "RowCmp"));

        $inew = 0;
        $iold = 0;

        // Get changes by comparing our list of messages with
        // our previous state
        while(1) {
            $change = array();

            if($iold >= count($this->syncstate) || $inew >= count($new))
                break;

            if($this->syncstate[$iold]["id"] == $new[$inew]["id"]) {
                // Both messages are still available, compare flags, star and mod
                if(isset($this->syncstate[$iold]["flags"]) && isset($new[$inew]["flags"]) && $this->syncstate[$iold]["flags"] != $new[$inew]["flags"]) {
                    // Flags changed
                    $change["type"] = "flags";
                    $change["id"] = $new[$inew]["id"];
                    $change["flags"] = $new[$inew]["flags"];
                    $changes[] = $change;
                }

                if(isset($this->syncstate[$iold]["star"]) && isset($new[$inew]["star"]) && $this->syncstate[$iold]["star"] != $new[$inew]["star"]) {
                    // Star changed
                    $change["type"] = "star";
                    $change["id"] = $new[$inew]["id"];
                    $change["star"] = $new[$inew]["star"];
                    $changes[] = $change;
                }

                if($this->syncstate[$iold]["mod"] != $new[$inew]["mod"]) {
                    $change["type"] = "change";
                    $change["id"] = $new[$inew]["id"];
                    $changes[] = $change;
                }

                $inew++;
                $iold++;
            } else {
                if($this->syncstate[$iold]["id"] > $new[$inew]["id"]) {
                    // Message in state seems to have disappeared (delete)
                    $change["type"] = "delete";
                    $change["id"] = $this->syncstate[$iold]["id"];
                    $changes[] = $change;
                    $iold++;
                } else {
                    // Message in new seems to be new (add)
                    $change["type"] = "change";
                    $change["flags"] = SYNC_NEWMESSAGE;
                    $change["star"] = SYNC_NEWMESSAGE;
                    $change["id"] = $new[$inew]["id"];
                    $changes[] = $change;
                    $inew++;
                }
            }
        }

        while($iold < count($this->syncstate)) {
            // All data left in 'syncstate' have been deleted
            $change["type"] = "delete";
            $change["id"] = $this->syncstate[$iold]["id"];
            $changes[] = $change;
            $iold++;
        }

        while($inew < count($new)) {
            // All data left in new have been added
            $change["type"] = "change";
            $change["flags"] = SYNC_NEWMESSAGE;
            $change["star"] = SYNC_NEWMESSAGE;
            $change["id"] = $new[$inew]["id"];
            $changes[] = $change;
            $inew++;
        }

        return $changes;
    }

    /**
     * Update the state to reflect changes
     *
     * @param string        $type of change
     * @param array         $change
     *
     *
     * @access protected
     * @return
     */
    protected function updateState($type, $change) {
        // Change can be a change or an add
        if($type == "change") {
            $this->syncstate[] = $change;
        } else {
            $change_id = $change['id'];
            foreach ($this->syncstate as $i => &$state) {
                if ($state['id'] == $change_id) {
                    switch ($type) {
                        case 'change':
                            $state = $change;
                            break;
                        case 'flags':
                            $state['flags'] = $change['flags'];
                            break;
                        case 'star':
                            $state['star'] = $change['star'];
                            break;
                        case 'delete':
                            array_splice($this->syncstate, $i, 1);
                            break;
                        default:
                            throw new Exception("updateState: type '$type' is not supported");
                    }
                    return;
                }
            }

            $flags = empty($change['flags']) ? "<no flags>" : $change['flags'];
            $star = empty($change['star']) ? "<no star>" : $change['star'];
            $mod = empty($change['mod']) ? "<no mod>" : $change['mod'];
            ZLog::Write(LOGLEVEL_WARN, sprintf("updateState: no state modification found!!! %s|%s|%s|%s|%s", $type, $change_id, $flags, $star, $mod));
        }
    }

    /**
     * Returns TRUE if the given ID conflicts with the given operation. This is only true in the following situations:
     *   - Changed here and changed there
     *   - Changed here and deleted there
     *   - Deleted here and changed there
     * Any other combination of operations can be done (e.g. change flags & move or move & delete)
     *
     * @param string        $type of change
     * @param string        $folderid
     * @param string        $id
     *
     * @access protected
     * @return
     */
    protected function isConflict($type, $folderid, $id) {
        $stat = $this->backend->StatMessage($folderid, $id);

        if(!$stat) {
            // Message is gone
            if($type == "change")
                return true; // deleted here, but changed there
            else
                return false; // all other remote changes still result in a delete (no conflict)
        }

        foreach($this->syncstate as $state) {
            if($state["id"] == $id) {
                $oldstat = $state;
                break;
            }
        }

        if(!isset($oldstat)) {
            // New message, can never conflict
            return false;
        }

        if($stat["mod"] != $oldstat["mod"]) {
            // Changed here
            if($type == "delete" || $type == "change")
                return true; // changed here, but deleted there -> conflict, or changed here and changed there -> conflict
            else
                return false; // changed here, and other remote changes (move or flags)
        }
    }

}
