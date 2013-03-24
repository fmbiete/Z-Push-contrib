<?php
/***********************************************
* File      :   carddav.php
* Project   :   Z-Push
* Descr     :   This backend is for carddav servers.
*
* Created   :   16.03.2013
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

// config file
require_once("backend/carddav/config.php");

include_once('lib/default/diffbackend/diffbackend.php');
include_once('include/z_carddav.php');

class BackendCardDAV extends BackendDiff implements ISearchProvider {

    private $domain = '';
    private $username = '';
    private $url = null;
    private $server = null;

    // Android only supports synchronizing 1 AddressBook per account
    private $foldername = "contacts";
    
    private $changessinkinit = false;
    private $contactsetag;

    /**
     * Constructor
     *
     */
    public function BackendCardDAV() {
        if (!function_exists("curl_init")) {
            throw new FatalException("BackendCardDAV(): php-curl is not found", 0, null, LOGLEVEL_FATAL);
        }

        $this->contactsetag = array();
    }
    
    /**
     * Authenticates the user - NOT EFFECTIVELY IMPLEMENTED
     * Normally some kind of password check would be done here.
     * Alternatively, the password could be ignored and an Apache
     * authentication via mod_auth_* could be done
     *
     * @param string        $username
     * @param string        $domain
     * @param string        $password
     *
     * @access public
     * @return boolean
     */
    public function Logon($username, $domain, $password) {
        $url = CARDDAV_PROTOCOL . '://' . CARDDAV_SERVER . ':' . CARDDAV_PORT . str_replace("%d", $domain, str_replace("%u", $username, CARDDAV_PATH));
        $this->server = new carddav_backend($url);
        $this->server->set_auth($username, $password);
        
        if (($connected = $this->server->check_connection())) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->Logon(): User '%s' is authenticated on '%s'", $username, $url));
            $this->url = $url;
            $this->username = $username;
            $this->domain = $domain;
        }
        else {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->Logon(): User '%s' failed to authenticate on '%s': %s", $username, $url));
            $this->server = null;
            //TODO: get error message
        }
        
        return $connected;
    }

    /**
     * Logs off
     *
     * @access public
     * @return boolean
     */
    public function Logoff() {
        $this->server = null;
        
        $this->SaveStorages();
        
        unset($this->contactsetag);
        
        return true;
    }

    /**
     * Sends an e-mail
     * Not implemented here
     *
     * @param SyncSendMail  $sm     SyncSendMail object
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function SendMail($sm) {
        return false;
    }

    /**
     * Returns the waste basket
     * Not implemented here
     *
     * @access public
     * @return string
     */
    public function GetWasteBasket() {
        return false;
    }

    /**
     * Returns the content of the named attachment as stream
     * Not implemented here
     *
     * @param string        $attname
     *
     * @access public
     * @return SyncItemOperationsAttachment
     * @throws StatusException
     */
    public function GetAttachmentData($attname) {
        return false;
    }
    
    /**
     * Indicates if the backend has a ChangesSink.
     * A sink is an active notification mechanism which does not need polling.
     * The CardDAV backend simulates a sink by polling revision dates from the vcards
     *
     * @access public
     * @return boolean
     */
    public function HasChangesSink() {
        return true;
    }

    /**
     * The folder should be considered by the sink.
     * Folders which were not initialized should not result in a notification
     * of IBackend->ChangesSink().
     *
     * @param string        $folderid
     *
     * @access public
     * @return boolean      false if found can not be found
     */
    public function ChangesSinkInitialize($folderid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->ChangesSinkInitialize(): folderid '%s'", $folderid));
        
        $this->changessinkinit = true;

        // We don't need the actual cards, we only need to get the changes since this moment
        //FIXME: we need to get the changes since the last actual sync
        
        $vcards = false;
        try {
            $vcards = $this->server->do_sync(true, false);
        }
        catch (Exception $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->ChangesSinkInitialize - Error doing the initial sync: %s", $ex->getMessage()));
        }
        
        if ($vcards === false) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->ChangesSinkInitialize - Error initializing the sink"));
            return false;
        }
        
        unset($vcards);
        
        return true;
    }

    /**
     * The actual ChangesSink.
     * For max. the $timeout value this method should block and if no changes
     * are available return an empty array.
     * If changes are available a list of folderids is expected.
     *
     * @param int           $timeout        max. amount of seconds to block
     *
     * @access public
     * @return array
     */
    public function ChangesSink($timeout = 30) {
        $notifications = array();
        $stopat = time() + $timeout - 1;
        $changed = false;

        //We can get here and the ChangesSink not be initialized yet
        if (!$this->changessinkinit) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->ChangesSink - Not initialized ChangesSink, exiting"));
            return $notifications;
        }
        
        while($stopat > time() && empty($notifications)) {
            $vcards = false;
            try {
                $vcards = $this->server->do_sync(false, false);
            }
            catch (Exception $ex) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->ChangesSink - Error resyncing vcards: %s", $ex->getMessage()));
            }
            
            if ($vcards === false) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->ChangesSink - Error getting the changes"));
                return false;
            }
            else {
                $xml_vcards = new SimpleXMLElement($vcards);
                unset($vcards);
                
                if (count($xml_vcards->element) > 0) {
                    $changed = true;
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->ChangesSink - Changes detected"));
                }
                unset($xml_vcards);
            }
            
            if ($changed) {
                $notifications[] = $this->foldername;
            }

            if (empty($notifications))
                sleep(5);
        }

        return $notifications;
    }    

    /**----------------------------------------------------------------------------------------------------------
     * implemented DiffBackend methods
     */

    /**
     * Returns a list (array) of folders.
     * In simple implementations like this one, probably just one folder is returned.
     *
     * @access public
     * @return array
     */
    public function GetFolderList() {
        ZLog::Write(LOGLEVEL_DEBUG, 'BackendCardDAV::GetFolderList()');
        
        //TODO: support multiple addressbooks, autodiscover thems
        $addressbooks = array();
        $addressbook = $this->StatFolder($this->foldername);
        $addressbooks[] = $addressbook;

        return $addressbooks;
    }

    /**
     * Returns an actual SyncFolder object
     *
     * @param string        $id           id of the folder
     *
     * @access public
     * @return object       SyncFolder with information
     */
    public function GetFolder($id) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV::GetFolder('%s')", $id));
        
        $addressbook = false;
        
        if ($id == $this->foldername) {
            $addressbook = new SyncFolder();
            $addressbook->serverid = $id;
            $addressbook->parentid = "0";
            $addressbook->displayname = str_replace("%d", $this->domain, str_replace("%u", $this->username, CARDDAV_CONTACTS_FOLDER_NAME));
            $addressbook->type = SYNC_FOLDER_TYPE_CONTACT;
        }

        return $addressbook;
    }

    /**
     * Returns folder stats. An associative array with properties is expected.
     *
     * @param string        $id             id of the folder
     *
     * @access public
     * @return array
     */
    public function StatFolder($id) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV::StatFolder('%s')", $id));
        
        $addressbook = $this->GetFolder($id);

        $stat = array();
        $stat["id"] = $id;
        $stat["parent"] = $addressbook->parentid;
        $stat["mod"] = $addressbook->displayname;

        return $stat;
    }

    /**
     * Creates or modifies a folder
     * Not implemented here
     *
     * @param string        $folderid       id of the parent folder
     * @param string        $oldid          if empty -> new folder created, else folder is to be renamed
     * @param string        $displayname    new folder name (to be created, or to be renamed to)
     * @param int           $type           folder type
     *
     * @access public
     * @return boolean                      status
     * @throws StatusException              could throw specific SYNC_FSSTATUS_* exceptions
     *
     */
    public function ChangeFolder($folderid, $oldid, $displayname, $type){
        return false;
    }

    /**
     * Deletes a folder
     * Not implemented here
     *
     * @param string        $id
     * @param string        $parent         is normally false
     *
     * @access public
     * @return boolean                      status - false if e.g. does not exist
     * @throws StatusException              could throw specific SYNC_FSSTATUS_* exceptions
     *
     */
    public function DeleteFolder($id, $parentid){
        return false;
    }

    /**
     * Returns a list (array) of messages
     *
     * @param string        $folderid       id of the parent folder
     * @param long          $cutoffdate     timestamp in the past from which on messages should be returned
     *
     * @access public
     * @return array/false  array with messages or false if folder is not available
     */
    public function GetMessageList($folderid, $cutoffdate) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->GetMessageList('%s', '%s')", $folderid, $cutoffdate));
        
        $messages = array();
        
        $vcards = false;
        try {
            // We don't need the actual vcards here, we only need a list of all them
            //$vcards = $this->server->get_list_vcards();
            $vcards = $this->server->do_sync(true, false);
        }
        catch (Exception $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->GetMessageList - Error getting the vcards: %s", $ex->getMessage()));
        }
        
        if ($vcards === false) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->GetMessageList - Error getting the vcards"));
        }
        else {
            $xml_vcards = new SimpleXMLElement($vcards);
            foreach ($xml_vcards->element as $vcard) {
                $id = $vcard->id->__toString();
                $this->contactsetag[$id] = $vcard->etag->__toString();
                $messages[] = $this->StatMessage($folderid, $id);
            }
        }

        return $messages;
    }

    /**
     * Returns the actual SyncXXX object type.
     *
     * @param string            $folderid           id of the parent folder
     * @param string            $id                 id of the message
     * @param ContentParameters $contentparameters  parameters of the requested message (truncation, mimesupport etc)
     *
     * @access public
     * @return object/false     false if the message could not be retrieved
     */
    public function GetMessage($folderid, $id, $contentparameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->GetMessage('%s', '%s')", $folderid, $id));
        
        $message = false;
        
        //TODO: change folderid
        $xml_vcard = false;
        try {
            $xml_vcard = $this->server->get_xml_vcard($id);
        }
        catch (Exception $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->GetMessage - Error getting vcard: %s", $ex->getMessage()));
        }
        
        if ($xml_vcard === false) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->GetMessage(): getting vCard"));
        }
        else {
            $truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());
            $xml_data = new SimpleXMLElement($xml_vcard);
            $message = $this->ParseFromVCard($xml_data->element[0]->vcard->__toString(), $truncsize);
        }
        
        return $message;
    }
    

    /**
     * Returns message stats, analogous to the folder stats from StatFolder().
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     *
     * @access public
     * @return array
     */
    public function StatMessage($folderid, $id) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->StatMessage('%s', '%s')", $folderid, $id));

        //TODO: change to folderid
        
        $message = array();
        $message["mod"] = $this->contactsetag[$id];
        $message["id"] = $id;
        $message["flags"] = 1;
        $message["star"] = 0;

        return $message;
    }

    /**
     * Called when a message has been changed on the mobile.
     * This functionality is not available for emails.
     *
     * @param string              $folderid            id of the folder
     * @param string              $id                  id of the message
     * @param SyncXXX             $message             the SyncObject containing a message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return array                        same return value as StatMessage()
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function ChangeMessage($folderid, $id, $message, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->ChangeMessage('%s', '%s')", $folderid, $id));

        $vcard_text = $this->ParseToVCard($message);
        
        if ($vcard_text === false) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->ChangeMessage - Error converting message to vCard"));
        }
        else {
            ZLog::Write(LOGLEVEL_WBXML, sprintf("BackendCardDAV->ChangeMessage - vCard\n%s\n", $vcard_text));
            
            $updated = false;
            if (strlen($id) == 0) {
                //no id, new vcard
                try {
                    $updated = $this->server->add($vcard_text);
                    if ($updated !== false) {
                        $id = $updated;
                    }
                }
                catch (Exception $ex) {
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->ChangeMessage - Error adding vcard '%s' : %s", $id, $ex->getMessage()));
                }
            }
            else {
                //id, update vcard
                try {
                    $updated = $this->server->update($vcard_text, $id);
                }
                catch (Exception $ex) {
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->ChangeMessage - Error updating vcard '%s' : %s", $id, $ex->getMessage()));
                }
            }
            
            if ($updated !== false) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->ChangeMessage - vCard updated"));
            }
            else {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->ChangeMessage - vCard not updated"));
            }
        }
        
        return $this->StatMessage($folderid, $id);
    }   

    /**
     * Changes the 'read' flag of a message on disk
     * Not implemented here
     *
     * @param string              $folderid            id of the folder
     * @param string              $id                  id of the message
     * @param int                 $flags               read flag of the message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function SetReadFlag($folderid, $id, $flags, $contentParameters) {
        return false;
    }

    /**
     * Changes the 'star' flag of a message on disk
     * Not implemented here
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     * @param int           $flags          star flag of the message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function SetStarFlag($folderid, $id, $flags, $contentParameters) {
        return false;
    }

    /**
     * Called when the user has requested to delete (really delete) a message
     *
     * @param string              $folderid             id of the folder
     * @param string              $id                   id of the message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function DeleteMessage($folderid, $id, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->DeleteMessage('%s', '%s')", $folderid, $id));
        
        $deleted = false;
        try {
            $deleted = $this->server->delete($id);
        }
        catch (Exception $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->DeleteMessage - Error deleting vcard: %s", $ex->getMessage()));
        }
        
        if ($deleted) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->DeleteMessage - vCard deleted"));
        } 
        else {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->DeleteMessage - cannot delete vCard"));
        }
        
        return $deleted;
    }

    /**
     * Called when the user moves an item on the PDA from one folder to another
     * Not implemented here
     *
     * @param string              $folderid            id of the source folder
     * @param string              $id                  id of the message
     * @param string              $newfolderid         id of the destination folder
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_MOVEITEMSSTATUS_* exceptions
     */
    public function MoveMessage($folderid, $id, $newfolderid, $contentParameters) {
        return false;
    }

    
    /**
     * Indicates which AS version is supported by the backend.
     *
     * @access public
     * @return string       AS version constant
     */
    public function GetSupportedASVersion() {
        return ZPush::ASV_14;
    }


    /**
     * Returns the BackendCardDAV as it implements the ISearchProvider interface
     * This could be overwritten by the global configuration
     *
     * @access public
     * @return object       Implementation of ISearchProvider
     */
    public function GetSearchProvider() {
        return $this;
    }


    /**----------------------------------------------------------------------------------------------------------
     * public ISearchProvider methods
     */

    /**
     * Indicates if a search type is supported by this SearchProvider
     * Currently only the type ISearchProvider::SEARCH_GAL (Global Address List) is implemented
     *
     * @param string        $searchtype
     *
     * @access public
     * @return boolean
     */
    public function SupportsType($searchtype) {
        return ($searchtype == ISearchProvider::SEARCH_GAL);
    }


    /**
     * Queries the CardDAV backend
     *
     * @param string        $searchquery        string to be searched for
     * @param string        $searchrange        specified searchrange
     *
     * @access public
     * @return array        search results
     */
    public function GetGALSearchResults($searchquery, $searchrange) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->GetGALSearchResults(%s, %s)", $searchquery, $searchrange));
        if (isset($this->server) && $this->server !== false) {
            if (strlen($searchquery) < 5) {
                return false;
            }

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->GetGALSearchResults searching: %s", $this->url));
            try {
                $this->server->enable_debug();
                $vcards = $this->server->search_vcards(str_replace("<", "", str_replace(">", "", $searchquery)), 15, true, false);
            }
            catch (Exception $e) {
                $vcards = false;
                ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->GetGALSearchResults : Error in search %s", $e->getMessage()));
            }
            var_dump($this->server->get_debug());
            if ($vcards === false) {
                ZLog::Write(LOGLEVEL_ERROR, "BackendCardDAV->GetGALSearchResults : Error in search query. Search aborted");
                return false;
            }
            
            $xml_vcards = new SimpleXMLElement($vcards);
            unset($vcards);
            
            // range for the search results, default symbian range end is 50, wm 99,
            // so we'll use that of nokia
            $rangestart = 0;
            $rangeend = 50;

            if ($searchrange != '0') {
                $pos = strpos($searchrange, '-');
                $rangestart = substr($searchrange, 0, $pos);
                $rangeend = substr($searchrange, ($pos + 1));
            }
            $items = array();

            // TODO the limiting of the searchresults could be refactored into Utils as it's probably used more than once
            $querycnt = $xml_vcards->count();
            //do not return more results as requested in range
            $querylimit = (($rangeend + 1) < $querycnt) ? ($rangeend + 1) : $querycnt == 0 ? 1 : $querycnt;
            $items['range'] = $rangestart.'-'.($querylimit - 1);
            $items['searchtotal'] = $querycnt;
            
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->GetGALSearchResults : %s entries found, returning %s to %s", $querycnt, $rangestart, $querylimit));
            
            $i = 0;
            $rc = 0;
            foreach ($xml_vcards->element as $xml_vcard) {
                if ($i >= $rangestart && $i < $querylimit) {
                    $contact = $this->ParseFromVCard($xml_vcard->vcard->__toString());
                    if ($contact === false) {
                        ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV->GetGALSearchResults : error converting vCard to AS contact\n%s\n", $xml_vcard->vcard->__toString()));
                    }
                    else {
                        $items[$rc][SYNC_GAL_EMAILADDRESS] = $contact->email1address;
                        if (isset($contact->firstname) || isset($contact->middlename) || isset($contact->lastname)) {
                            $items[$rc][SYNC_GAL_DISPLAYNAME] = $contact->firstname . " " . $contact->middlename . " " . $contact->lastname;
                        }
                        else {
                            $items[$rc][SYNC_GAL_DISPLAYNAME] = $contact->email1address;
                        }
                        if (isset($contact->firstname)) {
                            $items[$rc][SYNC_GAL_FIRSTNAME] = $contact->firstname;
                        }
                        else {
                            $items[$rc][SYNC_GAL_FIRSTNAME] = "";
                        }
                        if (isset($contact->lastname)) {
                            $items[$rc][SYNC_GAL_LASTNAME] = $contact->lastname;
                        }
                        else {
                            $items[$rc][SYNC_GAL_LASTNAME] = "";
                        }
                        if (isset($contact->business2phonenumber)) {
                            $items[$rc][SYNC_GAL_PHONE] = $contact->business2phonenumber;
                        }
                        if (isset($contact->home2phonenumber)) {
                            $items[$rc][SYNC_GAL_HOMEPHONE] = $contact->home2phonenumber;
                        }
                        if (isset($contact->mobilephonenumber)) {
                            $items[$rc][SYNC_GAL_MOBILEPHONE] = $contact->mobilephonenumber;
                        }
                        if (isset($contact->title)) {
                            $items[$rc][SYNC_GAL_TITLE] = $contact->title;
                        }
                        else {
                            $items[$rc][SYNC_GAL_TITLE] = "";
                        }
                        if (isset($contact->companyname)) {
                            $items[$rc][SYNC_GAL_COMPANY] = $contact->companyname;
                        }
                        if (isset($contact->department)) {
                            $items[$rc][SYNC_GAL_OFFICE] = $contact->department;
                        }
                        else {
                            $items[$rc][SYNC_GAL_COMPANY] = '';
                        }
                        if (isset($contact->nickname)) {
                            $items[$rc][SYNC_GAL_ALIAS] = $contact->nickname;
                        }
                        unset($contact);
                        $rc++;
                    }
                }
                $i++;
            }
            
            unset($xml_vcards);
            return $items;
        }
        else {
            unset($xml_vcards);
            return false;
        }
    }

    /**
     * Searches for the emails on the server
     *
     * @param ContentParameter $cpo
     *
     * @return array
     */
    public function GetMailboxSearchResults($cpo) {
        return false;
    }

    /**
    * Terminates a search for a given PID
    *
    * @param int $pid
    *
    * @return boolean
    */
    public function TerminateSearch($pid) {
        return true;
    }

    /**
     * Disconnects from CardDAV
     *
     * @access public
     * @return boolean
     */
    public function Disconnect() {
        return true;
    }
    

    /**----------------------------------------------------------------------------------------------------------
     * private vcard-specific internals
     */


    /**
     * Escapes a string
     *
     * @param string        $data           string to be escaped
     *
     * @access private
     * @return string
     */
    private function escape($data){
        if (is_array($data)) {
            foreach ($data as $key => $val) {
                $data[$key] = $this->escape($val);
            }
            return $data;
        }
        $data = str_replace("\r\n", "\n", $data);
        $data = str_replace("\r", "\n", $data);
        $data = str_replace(array('\\', ';', ',', "\n"), array('\\\\', '\\;', '\\,', '\\n'), $data);
        return $data;
    }

    /**
     * Un-escapes a string
     *
     * @param string        $data           string to be un-escaped
     *
     * @access private
     * @return string
     */
    private function unescape($data){
        $data = str_replace(array('\\\\', '\\;', '\\,', '\\n','\\N'),array('\\', ';', ',', "\n", "\n"),$data);
        return $data;
    }
    
    /**
     * Converts the vCard into SyncContact
     *
     * @param string        $data           string with the vcard
     * @param int           $truncsize      truncate size requested
     * @return SyncContact
     */
    private function ParseFromVCard($data, $truncsize = -1) {
        ZLog::Write(LOGLEVEL_WBXML, sprintf("BackendCardDAV->ParseFromVCard : vCard\n%s\n", $data));
        
        $types = array ('dom' => 'type', 'intl' => 'type', 'postal' => 'type', 'parcel' => 'type', 'home' => 'type', 'work' => 'type',
            'pref' => 'type', 'voice' => 'type', 'fax' => 'type', 'msg' => 'type', 'cell' => 'type', 'pager' => 'type',
            'bbs' => 'type', 'modem' => 'type', 'car' => 'type', 'isdn' => 'type', 'video' => 'type',
            'aol' => 'type', 'applelink' => 'type', 'attmail' => 'type', 'cis' => 'type', 'eworld' => 'type',
            'internet' => 'type', 'ibmmail' => 'type', 'mcimail' => 'type',
            'powershare' => 'type', 'prodigy' => 'type', 'tlx' => 'type', 'x400' => 'type',
            'gif' => 'type', 'cgm' => 'type', 'wmf' => 'type', 'bmp' => 'type', 'met' => 'type', 'pmb' => 'type', 'dib' => 'type',
            'pict' => 'type', 'tiff' => 'type', 'pdf' => 'type', 'ps' => 'type', 'jpeg' => 'type', 'qtime' => 'type',
            'mpeg' => 'type', 'mpeg2' => 'type', 'avi' => 'type',
            'wave' => 'type', 'aiff' => 'type', 'pcm' => 'type',
            'x509' => 'type', 'pgp' => 'type', 'text' => 'value', 'inline' => 'value', 'url' => 'value', 'cid' => 'value', 'content-id' => 'value',
            '7bit' => 'encoding', '8bit' => 'encoding', 'quoted-printable' => 'encoding', 'base64' => 'encoding',
        );

        // Parse the vcard
        $message = new SyncContact();

        $data = str_replace("\x00", '', $data);
        $data = str_replace("\r\n", "\n", $data);
        $data = str_replace("\r", "\n", $data);
        $data = preg_replace('/(\n)([ \t])/i', '', $data);

        $lines = explode("\n", $data);

        $vcard = array();
        foreach($lines as $line) {
            if (trim($line) == '')
                continue;
            $pos = strpos($line, ':');
            if ($pos === false)
                continue;

            $field = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos+1));

            $fieldparts = preg_split('/(?<!\\\\)(\;)/i', $field, -1, PREG_SPLIT_NO_EMPTY);

            $type = strtolower(array_shift($fieldparts));

            $fieldvalue = array();

            foreach ($fieldparts as $fieldpart) {
                if(preg_match('/([^=]+)=(.+)/', $fieldpart, $matches)){
                    if(!in_array(strtolower($matches[1]),array('value','type','encoding','language')))
                        continue;
                    if(isset($fieldvalue[strtolower($matches[1])]) && is_array($fieldvalue[strtolower($matches[1])])){
                        $fieldvalue[strtolower($matches[1])] = array_merge($fieldvalue[strtolower($matches[1])], preg_split('/(?<!\\\\)(\,)/i', $matches[2], -1, PREG_SPLIT_NO_EMPTY));
                    }else{
                        $fieldvalue[strtolower($matches[1])] = preg_split('/(?<!\\\\)(\,)/i', $matches[2], -1, PREG_SPLIT_NO_EMPTY);
                    }
                }else{
                    if(!isset($types[strtolower($fieldpart)]))
                        continue;
                    $fieldvalue[$types[strtolower($fieldpart)]][] = $fieldpart;
                }
            }
            //
            switch ($type) {
                case 'categories':
                    //case 'nickname':
                    $val = preg_split('/(?<!\\\\)(\,)/i', $value);
                    break;
                default:
                    $val = preg_split('/(?<!\\\\)(\;)/i', $value);
                    break;
            }
            if(isset($fieldvalue['encoding'][0])){
                switch(strtolower($fieldvalue['encoding'][0])){
                    case 'q':
                    case 'quoted-printable':
                        foreach($val as $i => $v){
                            $val[$i] = quoted_printable_decode($v);
                        }
                        break;
                    case 'b':
                    case 'base64':
                        foreach($val as $i => $v){
                            $val[$i] = base64_decode($v);
                        }
                        break;
                }
            }else{
                foreach($val as $i => $v){
                    $val[$i] = $this->unescape($v);
                }
            }
            $fieldvalue['val'] = $val;
            $vcard[$type][] = $fieldvalue;
        }

        if(isset($vcard['email'][0]['val'][0]))
            $message->email1address = $vcard['email'][0]['val'][0];
        if(isset($vcard['email'][1]['val'][0]))
            $message->email2address = $vcard['email'][1]['val'][0];
        if(isset($vcard['email'][2]['val'][0]))
            $message->email3address = $vcard['email'][2]['val'][0];

        if(isset($vcard['tel'])){
            foreach($vcard['tel'] as $tel) {
                if(!isset($tel['type'])){
                    $tel['type'] = array();
                }
                if(in_array('car', $tel['type'])){
                    $message->carphonenumber = $tel['val'][0];
                }elseif(in_array('pager', $tel['type'])){
                    $message->pagernumber = $tel['val'][0];
                }elseif(in_array('cell', $tel['type'])){
                    $message->mobilephonenumber = $tel['val'][0];
                }elseif(in_array('home', $tel['type'])){
                    if(in_array('fax', $tel['type'])){
                        $message->homefaxnumber = $tel['val'][0];
                    }elseif(empty($message->homephonenumber)){
                        $message->homephonenumber = $tel['val'][0];
                    }else{
                        $message->home2phonenumber = $tel['val'][0];
                    }
                }elseif(in_array('work', $tel['type'])){
                    if(in_array('fax', $tel['type'])){
                        $message->businessfaxnumber = $tel['val'][0];
                    }elseif(empty($message->businessphonenumber)){
                        $message->businessphonenumber = $tel['val'][0];
                    }else{
                        $message->business2phonenumber = $tel['val'][0];
                    }
                }elseif(empty($message->homephonenumber)){
                    $message->homephonenumber = $tel['val'][0];
                }elseif(empty($message->home2phonenumber)){
                    $message->home2phonenumber = $tel['val'][0];
                }else{
                    $message->radiophonenumber = $tel['val'][0];
                }
            }
        }
        //;;street;city;state;postalcode;country
        if(isset($vcard['adr'])){
            foreach($vcard['adr'] as $adr) {
                if(empty($adr['type'])){
                    $a = 'other';
                }elseif(in_array('home', $adr['type'])){
                    $a = 'home';
                }elseif(in_array('work', $adr['type'])){
                    $a = 'business';
                }else{
                    $a = 'other';
                }
                if(!empty($adr['val'][2])){
                    $b=$a.'street';
                    $message->$b = $adr['val'][2];
                }
                if(!empty($adr['val'][3])){
                    $b=$a.'city';
                    $message->$b = $adr['val'][3];
                }
                if(!empty($adr['val'][4])){
                    $b=$a.'state';
                    $message->$b = $adr['val'][4];
                }
                if(!empty($adr['val'][5])){
                    $b=$a.'postalcode';
                    $message->$b = $adr['val'][5];
                }
                if(!empty($adr['val'][6])){
                    $b=$a.'country';
                    $message->$b = $adr['val'][6];
                }
            }
        }

        if(!empty($vcard['fn'][0]['val'][0]))
            $message->fileas = $vcard['fn'][0]['val'][0];
        if(!empty($vcard['n'][0]['val'][0]))
            $message->lastname = $vcard['n'][0]['val'][0];
        if(!empty($vcard['n'][0]['val'][1]))
            $message->firstname = $vcard['n'][0]['val'][1];
        if(!empty($vcard['n'][0]['val'][2]))
            $message->middlename = $vcard['n'][0]['val'][2];
        if(!empty($vcard['n'][0]['val'][3]))
            $message->title = $vcard['n'][0]['val'][3];
        if(!empty($vcard['n'][0]['val'][4]))
            $message->suffix = $vcard['n'][0]['val'][4];
        if(!empty($vcard['bday'][0]['val'][0])){
            $tz = date_default_timezone_get();
            date_default_timezone_set('UTC');
            $message->birthday = strtotime($vcard['bday'][0]['val'][0]);
            date_default_timezone_set($tz);
        }
        if(!empty($vcard['org'][0]['val'][0]))
            $message->companyname = $vcard['org'][0]['val'][0];
        if(!empty($vcard['note'][0]['val'][0])){
            if (Request::GetProtocolVersion() >= 12.0) {
                $message->asbody = new SyncBaseBody();
                $message->asbody->type = SYNC_BODYPREFERENCE_PLAIN;
                $message->asbody->data = $vcard['note'][0]['val'][0];
                if ($truncsize > 0 && $truncsize < strlen($message->asbody->data)) {
                    $message->asbody->truncated = 1;
                    $message->asbody->data = Utils::Utf8_truncate($message->asbody->data, $truncsize);
                }
                else {
                    $message->asbody->truncated = 0;
                }
                
                $message->asbody->estimatedDataSize = strlen($message->asbody->data);                
            }
            else {
                $message->body = $vcard['note'][0]['val'][0];
                if ($truncsize > 0 && $truncsize < strlen($message->body)) {
                    $message->bodytruncated = 1;
                    $message->body = Utils::Utf8_truncate($message->body, $truncsize);
                }
                else {
                    $message->bodytruncated = 0;
                }
                $message->bodysize = strlen($message->body);
            }
        }
        if(!empty($vcard['role'][0]['val'][0]))
            $message->jobtitle = $vcard['role'][0]['val'][0];//$vcard['title'][0]['val'][0]
        if(!empty($vcard['url'][0]['val'][0]))
            $message->webpage = $vcard['url'][0]['val'][0];
        if(!empty($vcard['categories'][0]['val']))
            $message->categories = $vcard['categories'][0]['val'];

        if(!empty($vcard['photo'][0]['val'][0]))
            $message->picture = base64_encode($vcard['photo'][0]['val'][0]);

        return $message;
    }
    
    /**
     * Convert a SyncObject into vCard.
     *
     * @param SyncContact           $message        AS Contact
     * @return string               vcard text
     */
    private function ParseToVCard($message) {       
        $mapping = array(
            'fileas' => 'FN',
            'lastname;firstname;middlename;title;suffix' => 'N',
            'email1address' => 'EMAIL;INTERNET',
            'email2address' => 'EMAIL;INTERNET',
            'email3address' => 'EMAIL;INTERNET',
            'businessphonenumber' => 'TEL;WORK',
            'business2phonenumber' => 'TEL;WORK',
            'businessfaxnumber' => 'TEL;WORK;FAX',
            'homephonenumber' => 'TEL;HOME',
            'home2phonenumber' => 'TEL;HOME',
            'homefaxnumber' => 'TEL;HOME;FAX',
            'mobilephonenumber' => 'TEL;CELL',
            'carphonenumber' => 'TEL;CAR',
            'pagernumber' => 'TEL;PAGER',
            ';;businessstreet;businesscity;businessstate;businesspostalcode;businesscountry' => 'ADR;WORK',
            ';;homestreet;homecity;homestate;homepostalcode;homecountry' => 'ADR;HOME',
            ';;otherstreet;othercity;otherstate;otherpostalcode;othercountry' => 'ADR',
            'companyname' => 'ORG',
            'body' => 'NOTE',
            'jobtitle' => 'ROLE',
            'webpage' => 'URL',
        );
        
        $data = "BEGIN:VCARD\nVERSION:2.1\nPRODID:Z-Push\n";
        foreach($mapping as $k => $v){
            $val = '';
            $ks = explode(';', $k);
            foreach($ks as $i){
                if(!empty($message->$i))
                    $val .= $this->escape($message->$i);
                $val.=';';
            }
            if(empty($val))
                continue;
            $val = substr($val,0,-1);
            if(strlen($val)>50){
                $data .= $v.":\n\t".substr(chunk_split($val, 50, "\n\t"), 0, -1);
            }else{
                $data .= $v.':'.$val."\n";
            }
        }
        if(!empty($message->categories))
            $data .= 'CATEGORIES:'.implode(',', $this->escape($message->categories))."\n";
        if(!empty($message->picture))
            $data .= 'PHOTO;ENCODING=BASE64;TYPE=JPEG:'."\n\t".substr(chunk_split($message->picture, 50, "\n\t"), 0, -1);
        if(isset($message->birthday))
            $data .= 'BDAY:'.date('Y-m-d', $message->birthday)."\n";
        $data .= "END:VCARD";

// not supported: anniversary, assistantname, assistnamephonenumber, children, department, officelocation, radiophonenumber, spouse, rtf

        return $data;
    }
    
};
?>