<?php
/***********************************************
* File      :   zlog.php
* Project   :   Z-Push
* Descr     :   Debug and logging
*
* Created   :   01.10.2007
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

class ZLog {
    static private $devid = '';
    static private $user = '';
    static private $authUser = false;
    static private $pidstr;
    static private $wbxmlDebug = '';
    static private $lastLogs = array();
    static private $userLog = false;
    static private $unAuthCache = array();

    /**
     * Initializes the logging
     *
     * @access public
     * @return boolean
     */
    static public function Initialize() {
        global $specialLogUsers;

        // define some constants for the logging
        if (!defined('LOGUSERLEVEL'))
            define('LOGUSERLEVEL', LOGLEVEL_OFF);

        if (!defined('LOGLEVEL'))
            define('LOGLEVEL', LOGLEVEL_OFF);

        list($user,) = Utils::SplitDomainUser(strtolower(Request::GetGETUser()));
        self::$userLog = in_array($user, $specialLogUsers);
        if (!defined('WBXML_DEBUG') && $user) {
            // define the WBXML_DEBUG mode on user basis depending on the configurations
            if (LOGLEVEL >= LOGLEVEL_WBXML || (LOGUSERLEVEL >= LOGLEVEL_WBXML && self::$userLog))
                define('WBXML_DEBUG', true);
            else
                define('WBXML_DEBUG', false);
        }

        if ($user)
            self::$user = '['. $user .'] ';
        else
            self::$user = '';

        // log the device id if the global loglevel is set to log devid or the user is in  and has the right log level
        if (Request::GetDeviceID() != "" && (LOGLEVEL >= LOGLEVEL_DEVICEID || (LOGUSERLEVEL >= LOGLEVEL_DEVICEID && self::$userLog)))
            self::$devid = '['. Request::GetDeviceID() .'] ';
        else
            self::$devid = '';

        return true;
    }

    /**
     * Writes a log line
     *
     * @param int       $loglevel           one of the defined LOGLEVELS
     * @param string    $message
     * @param boolean   $truncate           indicate if the message should be truncated, default true
     *
     * @access public
     * @return
     */
    static public function Write($loglevel, $message, $truncate = true) {
        // truncate messages longer than 10 KB
        $messagesize = strlen($message);
        if ($truncate && $messagesize > 10240)
            $message = substr($message, 0, 10240) . sprintf(" <log message with %d bytes truncated>", $messagesize);

        self::$lastLogs[$loglevel] = $message;
        $data = self::buildLogString($loglevel) . $message . "\n";

        if ($loglevel <= LOGLEVEL) {
            if(@file_put_contents(LOGFILE, $data, FILE_APPEND) === false) {
                error_log(sprintf("Unable to write in %s", LOGFILE));
                error_log($data);
            }
        }

        // should we write this into the user log?
        if ($loglevel <= LOGUSERLEVEL && self::$userLog) {
            // padd level for better reading
            $data = str_replace(self::getLogLevelString($loglevel), self::getLogLevelString($loglevel,true), $data);

            // is the user authenticated?
            if (self::logToUserFile()) {
                // something was logged before the user was authenticated, write this to the log
                if (!empty(self::$unAuthCache)) {
                    if(@file_put_contents(LOGFILEDIR . self::logToUserFile() . ".log", implode('', self::$unAuthCache), FILE_APPEND) === false) {
                        error_log("Unable to write in ".LOGFILEDIR . self::logToUserFile() . ".log");
                        error_log($data);
                    }
                    self::$unAuthCache = array();
                }
                // only use plain old a-z characters for the generic log file
                if(@file_put_contents(LOGFILEDIR . self::logToUserFile() . ".log", $data, FILE_APPEND) === false) {
                    error_log(sprintf("Unable to write in %s%s.log", LOGFILEDIR, self::logToUserFile()));
                    error_log($data);
                }
            }
            // the user is not authenticated yet, we save the log into memory for now
            else {
                self::$unAuthCache[] = $data;
            }
        }

        if (($loglevel & LOGLEVEL_FATAL) || ($loglevel & LOGLEVEL_ERROR)) {
            if(@file_put_contents(LOGERRORFILE, $data, FILE_APPEND) === false) {
                error_log(sprintf("Unable to write in %s", LOGERRORFILE));
                error_log($data);
            }
        }

        if ($loglevel & LOGLEVEL_WBXMLSTACK) {
            self::$wbxmlDebug .= $message. "\n";
        }
    }

    /**
     * Returns logged information about the WBXML stack
     *
     * @access public
     * @return string
     */
    static public function GetWBXMLDebugInfo() {
        return trim(self::$wbxmlDebug);
    }

    /**
     * Returns the last message logged for a log level
     *
     * @param int       $loglevel           one of the defined LOGLEVELS
     *
     * @access public
     * @return string/false     returns false if there was no message logged in that level
     */
    static public function GetLastMessage($loglevel) {
        return (isset(self::$lastLogs[$loglevel]))?self::$lastLogs[$loglevel]:false;
    }

    /**----------------------------------------------------------------------------------------------------------
     * private log stuff
     */

    /**
     * Returns the filename logs for a WBXML debug log user should be saved to
     *
     * @access private
     * @return string
     */
    static private function logToUserFile() {
        global $specialLogUsers;

        if (self::$authUser === false) {
            if (RequestProcessor::isUserAuthenticated()) {
                $authuser = Request::GetAuthUser();
                if ($authuser && in_array($authuser, $specialLogUsers))
                    self::$authUser = preg_replace('/[^a-z0-9]/', '_', strtolower($authuser));
            }
        }
        return self::$authUser;
    }

    /**
     * Returns the string to be logged
     *
     * @access private
     * @return string
     */
    static private function buildLogString($loglevel) {
        if (!isset(self::$pidstr))
            self::$pidstr = '[' . str_pad(@getmypid(),5," ",STR_PAD_LEFT) . '] ';

        if (!isset(self::$user))
            self::$user = '';

        if (!isset(self::$devid))
            self::$devid = '';

        return Utils::GetFormattedTime() ." ". self::$pidstr . self::getLogLevelString($loglevel, (LOGLEVEL > LOGLEVEL_INFO)) ." ". self::$user . self::$devid;
    }

    /**
     * Returns the string representation of the LOGLEVEL.
     * String can be padded
     *
     * @param int       $loglevel           one of the LOGLEVELs
     * @param boolean   $pad
     *
     * @access private
     * @return string
     */
    static private function getLogLevelString($loglevel, $pad = false) {
        if ($pad) $s = " ";
        else      $s = "";
        switch($loglevel) {
            case LOGLEVEL_OFF:   return ""; break;
            case LOGLEVEL_FATAL: return "[FATAL]"; break;
            case LOGLEVEL_ERROR: return "[ERROR]"; break;
            case LOGLEVEL_WARN:  return "[".$s."WARN]"; break;
            case LOGLEVEL_INFO:  return "[".$s."INFO]"; break;
            case LOGLEVEL_DEBUG: return "[DEBUG]"; break;
            case LOGLEVEL_WBXML: return "[WBXML]"; break;
            case LOGLEVEL_DEVICEID: return "[DEVICEID]"; break;
            case LOGLEVEL_WBXMLSTACK: return "[WBXMLSTACK]"; break;
        }
    }
}
