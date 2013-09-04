<?php
/***********************************************
* File      :   sqlstatemachine.php
* Project   :   Z-Push
* Descr     :   This class handles state requests;
*               Each Import/Export mechanism can
*               store its own state information,
*               which is stored through the
*               state machine.
*
* Created   :   25.08.2013
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

class SqlStateMachine implements IStateMachine {
    const SUPPORTED_STATE_VERSION = IStateMachine::STATEVERSION_02;
    const VERSION = "version";



    private $dbh;
    private $options;

    /**
     * Constructor
     *
     * Performs some basic checks and initilizes the state directory
     *
     * @access public
     * @throws FatalMisconfigurationException
     */
    public function SqlStateMachine() {
        ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine(): init");

        if (!defined('STATE_SQL_DSN') || !defined('STATE_SQL_USER') || !defined('STATE_SQL_PASSWORD')) {
            throw new FatalMisconfigurationException("No configuration for the state sql database available.");
        }

        $this->options = array();
        if (defined('STATE_SQL_OPTIONS')) {
            $this->options = unserialize(STATE_SQL_OPTIONS);
        }

        try {
            $this->dbh = new PDO(STATE_SQL_DSN, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);
        }
        catch(PDOException $ex) {
            throw new FatalMisconfigurationException(sprintf("Not possible to connect to the state database: %s", $ex->getMessage()));
        }

        $this->clearConnection($this->dbh);
    }

    /**
     * Gets a hash value indicating the latest dataset of the named
     * state with a specified key and counter.
     * If the state is changed between two calls of this method
     * the returned hash should be different
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param string    $counter            (opt)
     *
     * @access public
     * @return string
     * @throws StateNotFoundException, StateInvalidException
     */
    public function GetStateHash($devid, $type, $key = false, $counter = false) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetStateHash(): '%s', '%s', '%s', '%s'", $devid, $type, $key, $counter));

        $sql = "select updated_at from states where devid = :devid";
        $params = array(":devid" => $devid);
        $this->getStateWhereConditions($sql, $params, $type, $key, $counter);

        $hash = null;
        $sth = null;
        $record = null;
        try {
            $this->dbh = new PDO(STATE_SQL_DSN, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                $this->clearConnection($this->dbh, $sth, $record);
                throw new StateNotFoundException(sprintf("SqlStateMachine->GetStateHash(): Could not locate state '%s'", $sql));
            }
            else {
                // datetime->format("U") returns EPOCH
                $datetime = new DateTime($record["updated_at"]);
                $hash = $datetime->format("U");
            }
        }
        catch(PDOException $ex) {
            $this->clearConnection($this->dbh, $sth, $record);
            throw new StateNotFoundException(sprintf("SqlStateMachine->GetStateHash(): Could not locate state '%s': %s", $sql, $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetStateHash(): return '%s'", $hash));

        return $hash;
    }

    /**
     * Gets a state for a specified key and counter.
     * This method sould call IStateMachine->CleanStates()
     * to remove older states (same key, previous counters)
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param string    $counter            (opt)
     * @param string    $cleanstates        (opt)
     *
     * @access public
     * @return mixed
     * @throws StateNotFoundException, StateInvalidException
     */
    public function GetState($devid, $type, $key = false, $counter = false, $cleanstates = true) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetState(): '%s', '%s', '%s', '%s', '%s'", $devid, $type, $key, $counter, $cleanstates));
        if ($counter && $cleanstates)
            $this->CleanStates($devid, $type, $key, $counter);

        $sql = "select data from states where devid = :devid";
        $params = array(":devid" => $devid);
        $this->getStateWhereConditions($sql, $params, $type, $key, $counter);

        $data = null;
        $sth = null;
        $record = null;
        try {
            $this->dbh = new PDO(STATE_SQL_DSN, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                $this->clearConnection($this->dbh, $sth, $record);
                throw new StateNotFoundException(sprintf("SqlStateMachine->GetState(): Could not locate state '%s'", $sql));
            }
            // throw an exception on all other states, but not FAILSAVE as it's most of the times not there by default
            else if ($type !== IStateMachine::FAILSAVE) {
                $data = unserialize($record["data"]);
            }
        }
        catch(PDOException $ex) {
            $this->clearConnection($this->dbh, $sth, $record);
            throw new StateNotFoundException(sprintf("SqlStateMachine->GetState(): Could not locate state '%s': %s", $sql, $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        return $data;
    }

    /**
     * Writes ta state to for a key and counter
     *
     * @param mixed     $state
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param int       $counter            (opt)
     *
     * @access public
     * @return boolean
     * @throws StateInvalidException
     */
    public function SetState($state, $devid, $type, $key = false, $counter = false) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->SetState(): '%s', '%s', '%s', '%s'", $devid, $type, $key, $counter));

        $sql = "select data from states where devid = :devid";
        $params = array(":devid" => $devid);
        $this->getStateWhereConditions($sql, $params, $type, $key, $counter);

        $sth = null;
        $record = null;
        $bytes = 0;

        try {
            $this->dbh = new PDO(STATE_SQL_DSN, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);

            $params[":data"] = serialize($state);
            $now = new DateTime("NOW");
            $now = $now->format("Y-m-d H:i:s");
            $params[":updated_at"] = $now;

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                // New record
                $sql = "insert into states (devid, type, key_value, counter, data, created_at, updated_at) values (:devid, :type, :key_value, :counter, :data, :created_at, :updated_at)";
                $params[":created_at"] = $now;
                if (!isset($params[":type"])) {
                    $params[":type"] = null;
                }
                if (!isset($params[":key_value"])) {
                    $params[":key_value"] = null;
                }
                if (!isset($params[":counter"])) {
                    $params[":counter"] = null;
                }

                $sth = $this->dbh->prepare($sql);
            }
            else {
                // Existing record, we update it
                $sql = "update states set data = :data, updated_at = :updated_at where devid = :devid";
                $this->getStateWhereConditions($sql, $params, $type, $key, $counter);

                $sth = $this->dbh->prepare($sql);
            }

            if (!$sth->execute($params) ) {
                $this->clearConnection($this->dbh, $sth);
                throw new StateNotFoundException(sprintf("SqlStateMachine->SetState(): Could not write state '%s'", $sql));
            }
            else {
                $bytes = strlen($params[":data"]);
            }
        }
        catch(PDOException $ex) {
            $this->clearConnection($this->dbh, $sth);
            throw new StateNotFoundException(sprintf("SqlStateMachine->SetState(): Could not write state '%s': %s", $sql, $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        return $bytes;
    }

    /**
     * Cleans up all older states
     * If called with a $counter, all states previous state counter can be removed
     * If called without $counter, all keys (independently from the counter) can be removed
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key
     * @param string    $counter            (opt)
     *
     * @access public
     * @return
     * @throws StateInvalidException
     */
    public function CleanStates($devid, $type, $key, $counter = false) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->CleanStates(): '%s', '%s', '%s', '%s'", $devid, $type, $key, $counter));

        $sql = "delete from states where devid = :devid";
        $params = array(":devid" => $devid);
        $this->getStateWhereConditions($sql, $params, $type, $key, false);
        if ($counter !== false) {
            $sql .= " and counter < :counter";
            $params[":counter"] = $counter;
        }

        $sth = null;
        try {
            $this->dbh = new PDO(STATE_SQL_DSN, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->CleanStates(): Deleting states: '%s'", $sql));
            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->CleanStates(): Error deleting states: '%s' %s", $sql, $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);
    }

    /**
     * Links a user to a device
     *
     * @param string    $username
     * @param string    $devid
     *
     * @access public
     * @return boolean     indicating if the user was added or not (existed already)
     */
    public function LinkUserDevice($username, $devid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->LinkUserDevice(): '%s', '%s'", $username, $devid));

        $sth = null;
        $record = null;
        $changed = false;
        try {
            $this->dbh = new PDO(STATE_SQL_DSN, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sql = "select username from users where username = :username and devid = :devid";
            $params = array(":username" => $username, ":devid" => $devid);
            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if ($record) {
                ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->LinkUserDevice(): nothing changed");
            }
            else {
                $sth = null;
                $sql = "insert into users (username, devid, created_at, updated_at) values (:username, :devid, :created_at, :updated_at)";
                $now = new DateTime("NOW");
                $now = $now->format("Y-m-d H:i:s");
                $params[":created_at"] = $now;
                $params[":updated_at"] = $now;
                $sth = $this->dbh->prepare($sql);
                if ($sth->execute($params)) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->LinkUserDevice(): Linked user-device: '%s' '%s'", $username, $devid));
                    $changed = true;
                }
                else {
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->LinkUserDevice(): Unable to link user-device: '%s'", $sql));
                }
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->LinkUserDevice(): Error linking user-device: '%s' %s", $sql, $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        return $changed;
    }

   /**
     * Unlinks a device from a user
     *
     * @param string    $username
     * @param string    $devid
     *
     * @access public
     * @return boolean
     */
    public function UnLinkUserDevice($username, $devid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->UnLinkUserDevice(): '%s', '%s'", $username, $devid));

        $sth = null;
        $changed = false;
        try {
            $this->dbh = new PDO(STATE_SQL_DSN, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sql = "delete from users where username = :username and devid = :devid";
            $params = array(":username" => $username, ":devid" => $devid);
            $sth = $this->dbh->prepare($sql);
            if ($sth->execute($params)) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->UnLinkUserDevice(): Unlinked user-device: '%s' '%s'", $username, $devid));
                $changed = true;
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->UnLinkUserDevice(): nothing changed");
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->UnLinkUserDevice(): Error unlinking user-device: '%s' %s", $sql, $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth);

        return $changed;
    }

    /**
     * Returns an array with all device ids for a user.
     * If no user is set, all device ids should be returned
     *
     * @param string    $username   (opt)
     *
     * @access public
     * @return array
     */
    public function GetAllDevices($username = false) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetAllDevices(): '%s'", $username));

        $sth = null;
        $record = null;
        $out = array();
        try {
            $this->dbh = new PDO(STATE_SQL_DSN, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            if  ($username === false) {
                $sql = "select distinct(devid) from users order by devid";
                $params = array();
            }
            else {
                $sql = "select devid from users where username = :username order by devid";
                $params = array(":username" => $username);
            }
            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);

            while ($record = $sth->fetch(PDO::FETCH_ASSOC)) {
                $out[] = $record["devid"];
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->GetAllDevices(): Error listing devices: '%s' %s", $sql, $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        return $out;
    }

    /**
     * Returns the current version of the state files
     *
     * @access public
     * @return int
     */
    public function GetStateVersion() {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetStateVersion()"));

        $sth = null;
        $record = null;
        $version = IStateMachine::STATEVERSION_01;
        try {
            $this->dbh = new PDO(STATE_SQL_DSN, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sql = "select value from settings where key_name = :key_name";
            $params = array(":key_name" => self::VERSION);

            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if ($record) {
                $version = $record["value"];
            }
            else {
                $this->SetStateVersion(self::SUPPORTED_STATE_VERSION);
                $version = self::SUPPORTED_STATE_VERSION;
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->GetStateVersion(): Error getting state version: '%s' %s", $sql, $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        return $version;
    }

    /**
     * Sets the current version of the state files
     *
     * @param int       $version            the new supported version
     *
     * @access public
     * @return boolean
     */
    public function SetStateVersion($version) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->SetStateVersion(): '%s'", $version));

        $sth = null;
        $record = null;
        $status = false;
        try {
            $now = new DateTime("NOW");
            $now = $now->format("Y-m-d H:i:s");

            $this->dbh = new PDO(STATE_SQL_DSN, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sql = "select value from settings where key_name = :key_name";
            $params = array(":key_name" => self::VERSION);

            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if ($record) {
                $sth = null;
                $sql = "update settings set value = :value, updated_at = :updated_at where key_name = :key_name";
                $params[":value"] = $version;
                $params[":updated_at"] = $now;

                $sth = $this->dbh->prepare($sql);
                if ($sth->execute($params)) {
                    $status = true;
                }
            }
            else {
                $sth = null;
                $sql = "insert into settings (key_name, value, created_at, updated_at) values (:key_name, :value, :created_at, :updated_at)";
                $params[":value"] = $version;
                $params[":updated_at"] = $now;
                $params[":created_at"] = $now;

                $sth = $this->dbh->prepare($sql);
                if ($sth->execute($params)) {
                    $status = true;
                }
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->SetStateVersion(): Error saving state version: '%s' %s", $sql, $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        return $status;
    }

    /**
     * Returns all available states for a device id
     *
     * @param string    $devid              the device id
     *
     * @access public
     * @return array(mixed)
     */
    public function GetAllStatesForDevice($devid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetAllStatesForDevice(): '%s'", $devid));

        $sth = null;
        $record = null;
        $out = array();
        try {
            $this->dbh = new PDO(STATE_SQL_DSN, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sql = "select * from states where devid = :devid";
            $params = array(":devid" => $devid);

            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);

            while ($record = $sth->fetch(PDO::FETCH_ASSOC)) {
                $state = array('type' => false, 'counter' => false, 'uuid' => false);
                if ($record["type"] !== null && strlen($record["type"]) > 0) {
                    $state["type"] = $record["type"];
                }
                else {
                    if ($record["counter"] !== null && is_numeric($record["counter"])) {
                        $state["type"] = "";
                    }
                }
                if ($record["counter"] !== null && strlen($record["counter"]) > 0) {
                    $state["counter"] = $record["counter"];
                }
                if ($record["key_value"] !== null && strlen($record["key_value"]) > 0) {
                    $state["uuid"] = $record["key_value"];
                }
                $out[] = $state;
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->GetAllStatesForDevice(): Error listing states: '%s' %s", $sql, $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        return $out;
    }


    /**----------------------------------------------------------------------------------------------------------
     * Private SqlStateMachine stuff
     */

    private function getStateWhereConditions(&$sql, &$params, $type, $key, $counter) {
        if ($type !== "") {
            $sql .= " and type = :type";
            $params[":type"] = $type;
        }
        if ($key !== false) {
            $sql .= " and key_value = :key_value";
            $params[":key_value"] = $key;
        }
        if ($counter !== false) {
            $sql .= " and counter = :counter";
            $params[":counter"] = $counter;
        }
    }

    private function clearConnection(&$dbh, &$sth = null, &$record = null) {
        if ($record != null) {
            $record = null;
        }
        if ($sth != null) {
            $sth = null;
        }
        if ($dbh != null) {
            $dbh = null;
        }
    }

}
?>