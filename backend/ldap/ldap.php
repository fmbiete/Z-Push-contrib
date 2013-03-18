<?php
/***********************************************
* File      :   ldap.php
* Project   :   PHP-Push
* Descr     :   This backend is based on
*               'BackendDiff' and implements an
*               (Open)LDAP interface
*
* Created   :   07.04.2012
*
* Copyright 2012 Jean-Louis Dupond
************************************************/

include_once('lib/default/diffbackend/diffbackend.php');

class BackendLDAP extends BackendDiff {
	
	private $ldap_link;
	private $user;
	
	public function Logon($username, $domain, $password)
	{
		$this->user = $username;
		$user_dn = str_replace('%u', $username, LDAP_USER_DN);
		$this->ldap_link = ldap_connect(LDAP_SERVER, LDAP_SERVER_PORT);
		ldap_set_option($this->ldap_link, LDAP_OPT_PROTOCOL_VERSION, 3);
		if (ldap_bind($this->ldap_link, $user_dn, $password))
		{
			ZLog::Write(LOGLEVEL_INFO, sprintf("BackendLDAP->Logon(): User '%s' is authenticated on LDAP", $username));
			return true;
		}
		else
		{
			ZLog::Write(LOGLEVEL_INFO, sprintf("BackendLDAP->Logon(): User '%s' is not authenticated on LDAP. Error: ", $username, ldap_error($this->ldap_link)));
			return false;
		}
	}
	
	public function Logoff()
	{
		if (ldap_unbind($this->ldap_link))
		{
			ZLog::Write(LOGLEVEL_INFO, sprintf("BackendLDAP->Logoff(): Disconnection successfull."));
		}
		else
		{
			ZLog::Write(LOGLEVEL_INFO, sprintf("BackendLDAP->Logoff(): Disconnection failed. Error: %s", ldap_error($this->ldap_link)));
		}
		return true;
	}
	
	public function SendMail($sm)
	{
		return false;
	}
	
	public function GetAttachmentData($attname)
	{
		return false;
	}
	
	public function GetWasteBasket()
	{
		return false;
	}
	
	public function GetFolderList()
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->GetFolderList(): Getting all folders."));
		$contacts = array();
		$dns = explode("|", LDAP_BASE_DNS);
		foreach ($dns as $dn)
		{
			$name = explode(":", $dn);
			$folder = $this->StatFolder($name[0]);
			$contacts[] = $folder;
		}
		return $contacts;
	}
	
	public function GetFolder($id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->GetFolder('%s')", $id));
		$folder = new SyncFolder();
		$folder->serverid = $id;
		$folder->parentid = "0";
		$folder->displayname = $id;
		$folder->type = SYNC_FOLDER_TYPE_CONTACT;
		return $folder;
	}
	
	public function StatFolder($id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->StatFolder('%s')", $id));
		$folder = $this->GetFolder($id);
		$stat = array();
		$stat["id"] = $id;
		$stat["parent"] = $folder->parentid;
		$stat["mod"] = $folder->displayname;
		return $stat;
	}
	
	public function ChangeFolder($folderid, $oldid, $displayname, $type)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->ChangeFolder('%s','%s','%s','%s')", $folderid, $oldid, $displayname, $type));
		return false;
	}
	
	public function DeleteFolder($id, $parentid)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->DeleteFolder('%s','%s')", $id, $parentid));
		return false;
	}
	
	public function GetMessageList($folderid, $cutoffdate)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->GetMessageList('%s','%s')", $folderid, $cutoffdate));
		
		$cutoff = date("YmdHis\Z", $cutoffdate);
		$filter = sprintf('(modifyTimestamp>=%s)', $cutoff);
		$attributes = array("entryUUID", "modifyTimestamp");
		$messages = array();

		$base_dns = explode("|", LDAP_BASE_DNS);
		foreach ($base_dns as $base_dn)
		{
			$folder = explode(":", $base_dn);
			if ($folder[0] == $folderid)
			{
				$base_dn = str_replace('%u', $this->user, $folder[1]);
				$results = ldap_list($this->ldap_link, $base_dn, $filter, $attributes);
				ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->GetMessageList(): Got %s contacts in base_dn '%s'.", ldap_count_entries($this->ldap_link, $results), $base_dn));
				$entries = ldap_get_entries($this->ldap_link, $results);
				for ($i = 0; $i < $entries["count"]; $i++)
				{
					$message = array();
					$message["id"] = $entries[$i]["entryuuid"][0];
					$message["mod"] = $entries[$i]["modifytimestamp"][0];
					$message["flags"] = "1";
					$messages[] = $message;
				}
			}
		}
		return $messages;
	}
	
	public function GetMessage($folderid, $id, $contentparameters)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->GetMessage('%s','%s')", $folderid, $id));
		$base_dns = explode("|", LDAP_BASE_DNS);
		foreach ($base_dns as $base_dn)
		{
			$folder = explode(":", $base_dn);
			if ($folder[0] == $folderid)
			{
				$base_dn = str_replace('%u', $this->user, $folder[1]);
				$result_id = ldap_list($this->ldap_link, $base_dn, "(entryUUID=".$id.")");
				if ($result_id)
				{
					$entry_id = ldap_first_entry($this->ldap_link, $result_id);
					if ($entry_id)
					{
						return $this->_ParseLDAPMessage($result_id, $entry_id);
					}
				}
			}
		}
	}
	
	private function _ParseLDAPMessage($result_id, $entry_id)
	{
		$contact = new SyncContact();
		
		$values = ldap_get_attributes($this->ldap_link, $entry_id);
		for ($i = 0; $i < $values["count"]; $i++)
		{
			$name = $values[$i];
			$value = $values[$name][0];
			
			switch ($name)
			{
				//person
				case "cn":
					$contact->fileas = $value;
					break;
				case "sn":
					$contact->lastname = $value;
					break;
				//inetOrgPerson
				case "departmentNumber":
					$contact->department = $value;
					break;
				case "givenName":
					$contact->firstname = $value;
					break;
				case "homePhone":
					$contact->homephonenumber = $value;
					break;
				case "jpegPhoto":
					$contact->picture = base64_encode($value);
					break;
				case "labeledURI":
					$contact->webpage = $value;
					break;
				case "mail":
					$contact->email1address = $value;
					if ($values[$name]["count"] >= 2)
					{
						$contact->email2address = $values[$name][1];
					}
					if ($values[$name]["count"] >= 3)
					{
						$contact->email3address = $values[$name][2];
					}
					break;
				case "mobile":
					$contact->mobilephonenumber = $value;
					break;
				case "o":
					$contact->companyname = $value;
					break;
				case "pager":
					$contact->pagernumber = $value;
					break;
				case "secretary":
					$contact->assistantname = $value;
					break;
				//organizationalPerson					
				case "l":
					$contact->businesscity = $value;
					break;
				case "ou":
					$contact->department = $value;
					break;
				case "physicalDeliveryOfficeName":
					$contact->officelocation = $value;
					break;
				case "postalCode":
					$contact->businesspostalcode = $value;
					break;
				case "st":
					$contact->businessstate = $value;	
					break;
				case "street":
					$contact->businessstreet = $value;
					break;
				case "telephoneNumber":
					$contact->businessphonenumber = $value;
					if ($values[$name]["count"] >= 2)
					{
						$contact->business2phonenumber = $values[$name][1];
					}
					break;
				case "title":
					$contact->title = $value;
					break;
				case "description":
					$contact->body = $value;
					$contact->bodysize = strlen($value);
					$contact->bodytruncated = "0";
					break;
			}
		}
		return $contact;
	}
	
	public function StatMessage($folderid, $id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->StatMessage('%s','%s')", $folderid, $id));
		$base_dns = explode("|", LDAP_BASE_DNS);
		foreach ($base_dns as $base_dn)
		{
			$folder = explode(":", $base_dn);
			if ($folder[0] == $folderid)
			{
				$base_dn = str_replace('%u', $this->user, $folder[1]);
				$result_id = ldap_list($this->ldap_link, $base_dn, "(entryUUID=".$id.")", array("modifyTimestamp"));
				if ($result_id)
				{
					$entry_id = ldap_first_entry($this->ldap_link, $result_id);
					if ($entry_id)
					{
						$mod = ldap_get_values($this->ldap_link, $entry_id, "modifyTimestamp");
						$message = array();
						$message["id"] = $id;
						$message["mod"] = $mod[0];
						$message["flags"] = "1";
						return $message;
					}
				}
			}
		}
	}
	
	public function ChangeMessage($folderid, $id, $message)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->ChangeMessage('%s','%s')", $folderid, $id));
		$base_dns = explode("|", LDAP_BASE_DNS);
		foreach ($base_dns as $base_dn)
		{
			$folder = explode(":", $base_dn);
			if ($folder[0] == $folderid)
			{
				$base_dn = str_replace('%u', $this->user, $folder[1]);
				$ldap_attributes = $this->_GenerateLDAPArray($message);
				$result_id = ldap_list($this->ldap_link, $base_dn, "(entryUUID=".$id.")", array("modifyTimestamp"));
				if ($result_id)
				{
					$entry_id = ldap_first_entry($this->ldap_link, $result_id);
					if ($entry_id)
					{
						$dn = ldap_get_dn($this->ldap_link, $entry_id);
						$mod = ldap_modify($this->ldap_link, $dn, $ldap_attributes);
						if (!$mod)
						{
							return false;
						}
						return $this->StatMessage($folderid, $id);
					}
					else
					{
						$uid = time() . mt_rand(100000, 999999);
						$dn = "uid=" . $uid . "," . $base_dn;
						$add = ldap_add($this->ldap_link, $dn, $ldap_attributes);
						if (!$add)
						{
							return false;
						}
						$result = ldap_read($this->ldap_link, $dn, "objectClass=*", array("entryUUID"));
						$entry = ldap_first_entry($this->ldap_link, $result);
						$values = ldap_get_values($this->ldap_link, $entry, "entryUUID");
						$entryuuid = $values[0];
						return $this->StatMessage($folderid, $entryuuid);
					}
				}
			}
		}
		return false;
	}

	private function _GenerateLDAPArray($message)
	{
		$ldap = array();
		//Set the Object Class
		$ldap["objectClass"] = "inetOrgPerson";
		//Parse Data
		if ($message->fileas)
		{
			$ldap["cn"] = $message->fileas;
		}
		if ($message->lastname)
		{
			$ldap["sn"] = $message->lastname;
		}
		if ($message->department)
		{
			$ldap["departmentNumber"] = $message->department;
		}
		if ($message->firstname)
		{
			$ldap["givenName"] = $message->firstname;
		}
		if ($message->homephonenumber)
		{
			$ldap["homePhone"] = $message->homephonenumber;
		}
		if ($message->picture)
		{
			$ldap["jpegPhoto"] = base64_decode($message->picture);
		}
		if ($message->webpage)
		{
			$ldap["labeledURI"] = $message->webpage;
		}
		if ($message->email1address)
		{
			$ldap["mail"][] = $message->email1address;
		}
		if ($message->email2address)
		{
			$ldap["mail"][] = $message->email2address;
		}
		if ($message->email3address)
		{
			$ldap["mail"][] = $message->email3address;
		}
		if ($message->mobilephonenumber)
		{
			$ldap["mobile"] = $message->mobilephonenumber;
		}
		if ($message->companyname)
		{
			$ldap["o"] = $message->companyname;
		}
		if ($message->pagernumber)
		{
			$ldap["pager"] = $message->pagernumber;
		}
		if ($message->assistantname)
		{
			$ldap["secretary"] = $message->assistantname;
		}
		if ($message->businesscity)
		{
			$ldap["l"] = $message->businesscity;
		}
		if ($message->department)
		{
			$ldap["ou"] = $message->department;
		}
		if ($message->officelocation)
		{
			$ldap["physicalDeliveryOfficeName"] = $message->officelocation;
		}
		if ($message->businesspostalcode)
		{
			$ldap["postalCode"] = $message->businesspostalcode;
		}
		if ($message->businessstate)
		{
			$ldap["st"] = $message->businessstate;
		}
		if ($message->businessstreet)
		{
			$ldap["street"] = $message->businessstreet;
		}
		if ($message->businessphonenumber)
		{
			$ldap["telephoneNumber"][] = $message->businessphonenumber;
		}
		if ($message->business2phonenumber)
		{
			$ldap["telephoneNumber"][] = $message->business2phonenumber;
		}
		if ($message->title)
		{
			$ldap["title"] = $message->title;
		}
		if ($message->body)
		{
			$ldap["description"] = $message->body;
		}
		return $ldap;
	}
	
	public function SetReadFlag($folderid, $id, $flags)
	{
		return false;
	}
	
	public function DeleteMessage($folderid, $id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->DeleteMessage('%s','%s')", $folderid, $id));
		$base_dns = explode("|", LDAP_BASE_DNS);
		foreach ($base_dns as $base_dn)
		{
			$folder = explode(":", $base_dn);
			if ($folder[0] == $folderid)
			{
				$base_dn = str_replace('%u', $this->user, $folder[1]);
				$result_id = ldap_list($this->ldap_link, $base_dn, "(entryUUID=".$id.")", array("entryUUID"));
				if ($result_id)
				{
					$entry_id = ldap_first_entry($this->ldap_link, $result_id);
					if ($entry_id)
					{
						$dn = ldap_get_dn($this->ldap_link, $entry_id);
						return ldap_delete($this->ldap_link, $dn);
					}
				}
			}
		}
		return false;
	}
	
	public function MoveMessage($folderid, $id, $newfolderid)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->MoveMessage('%s','%s', '%s')", $folderid, $id, $newfolderid));
		$base_dns = explode("|", LDAP_BASE_DNS);
		$old = "";
		$new = "";
		foreach ($base_dns as $base_dn)
		{
			$folder = explode(":", $base_dn);
			if ($folder[0] == $folderid)
			{
				$old = str_replace('%u', $this->user, $folder[1]);
			}
			if ($folder[0] == $newfolderid)
			{
				$new = str_replace('%u', $this->user, $folder[1]);
			}
		}
		$result_id = ldap_list($this->ldap_link, $old, "(entryUUID=".$id.")", array("entryUUID"));
		if ($result_id)
		{
			$entry_id = ldap_first_entry($this->ldap_link, $result_id);
			if ($entry_id)
			{
				$dn = ldap_get_dn($this->ldap_link, $entry_id);
				$newdn = ldap_explode_dn($dn, 0);
				return ldap_rename($this->ldap_link, $dn, $newdn[0], true);
			}
		}
		return false;
	}
}
?>
