<?php
/***********************************************
* File      :   caldav.php
* Project   :   PHP-Push
* Descr     :   This backend is based on
*               'BackendDiff' and implements an
*               CalDAV interface
*
* Created   :   29.03.2012
*
* Copyright 2012 Jean-Louis Dupond
************************************************/

// config file
require_once("backend/caldav/config.php");

include_once('lib/default/diffbackend/diffbackend.php');
include_once('include/caldav.php');
include_once('include/z_RTF.php');
include_once('include/iCalendar.php');

class BackendCalDAV extends BackendDiff {

	private $_caldav;
	private $_caldav_path;
	private $_collection = array();
	private $_username;

	/**
	 * Login to the CalDAV backend
	 * @see IBackend::Logon()
	 */
	public function Logon($username, $domain, $password)
	{
		$this->_username = $username;
		$this->_caldav_path = str_replace('%u', $username, CALDAV_PATH);
		$this->_caldav = new CalDAVClient(CALDAV_SERVER . ":" . CALDAV_PORT . $this->_caldav_path, $username, $password);
		$options = $this->_caldav->DoOptionsRequest();
		if (isset($options["PROPFIND"]))
		{
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->Logon(): User '%s' is authenticated on CalDAV", $username));
			return true;
		}
		else
		{
			ZLog::Write(LOGLEVEL_WARN, sprintf("BackendCalDAV->Logon(): User '%s' is not authenticated on CalDAV", $username));
			return false;
		}
	}

	/**
	 * The connections to CalDAV are always directly closed. So nothing special needs to happen here.
	 * @see IBackend::Logoff()
	 */
	public function Logoff()
	{
		return true;
	}

	/**
	 * CalDAV doesn't need to handle SendMail
	 * @see IBackend::SendMail()
	 */
	public function SendMail($sm)
	{
		return false;
	}

	/**
	 * No attachments in CalDAV
	 * @see IBackend::GetAttachmentData()
	 */
	public function GetAttachmentData($attname)
	{
		return false;
	}

	/**
	 * Deletes are always permanent deletes. Messages doesn't get moved.
	 * @see IBackend::GetWasteBasket()
	 */
	public function GetWasteBasket()
	{
		return false;
	}

	/**
	 * Get a list of all the folders we are going to sync.
	 * Each caldav calendar can contain tasks (prefix T) and events (prefix C), so duplicate each calendar found.
	 * @see BackendDiff::GetFolderList()
	 */
	public function GetFolderList()
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->GetFolderList(): Getting all folders."));
		$folders = array();
		$calendars = $this->_caldav->FindCalendars();
		foreach ($calendars as $val)
		{
			$folder = array();
			$fpath = explode("/", $val->url, -1);
			if (is_array($fpath))
			{
				$folderid = array_pop($fpath);
				$id = "C" . $folderid;
				$folders[] = $this->StatFolder($id);
				$id = "T" . $folderid;
				$folders[] = $this->StatFolder($id);
			}
		}
		return $folders;
	}

	/**
	 * Returning a SyncFolder
	 * @see BackendDiff::GetFolder()
	 */
	public function GetFolder($id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->GetFolder('%s')", $id));
		$val = $this->_caldav->GetCalendarDetails($this->_caldav_path . substr($id, 1) .  "/");
		$folder = new SyncFolder();
		$folder->parentid = "0";
		$folder->displayname = $val->displayname;
		$folder->serverid = $id;
		if ($id[0] == "C")
		{
			if (defined(CALDAV_PERSONAL) && strtolower(substr($id, 1) == CALDAV_PERSONAL))
			{
				$folder->type = SYNC_FOLDER_TYPE_USER_APPOINTMENT;
			}
			else
			{
				$folder->type = SYNC_FOLDER_TYPE_APPOINTMENT;
			}
		}
		else
		{
			if (defined(CALDAV_PERSONAL) && strtolower(substr($id, 1) == CALDAV_PERSONAL))
			{
				$folder->type = SYNC_FOLDER_TYPE_USER_TASK;
			}
			else
			{
				$folder->type = SYNC_FOLDER_TYPE_TASK;
			}
		}
		return $folder;
	}

	/**
	 * Returns information on the folder.
	 * @see BackendDiff::StatFolder()
	 */
	public function StatFolder($id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->StatFolder('%s')", $id));
		$val = $this->GetFolder($id);
		$folder = array();
		$folder["id"] = $id;
		$folder["parent"] = $val->parentid;
		$folder["mod"] = $val->serverid;
		return $folder;
	}

	/**
	 * ChangeFolder is not supported under CalDAV
	 * @see BackendDiff::ChangeFolder()
	 */
	public function ChangeFolder($folderid, $oldid, $displayname, $type)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->ChangeFolder('%s','%s','%s','%s')", $folderid, $oldid, $displayname, $type));
		return false;
	}

	/**
	 * DeleteFolder is not supported under CalDAV
	 * @see BackendDiff::DeleteFolder()
	 */
	public function DeleteFolder($id, $parentid)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->DeleteFolder('%s','%s')", $id, $parentid));
		return false;
	}

	/**
	 * Get a list of all the messages.
	 * @see BackendDiff::GetMessageList()
	 */
	public function GetMessageList($folderid, $cutoffdate)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->GetMessageList('%s','%s')", $folderid, $cutoffdate));

		/* Calculating the range of events we want to sync */
		$begin = gmdate("Ymd\THis\Z", $cutoffdate);
		$finish = gmdate("Ymd\THis\Z", 2147483647);

		$path = $this->_caldav_path . substr($folderid, 1) . "/";
		if ($folderid[0] == "C")
		{
			$msgs = $this->_caldav->GetEvents($begin, $finish, $path);
		}
		else
		{
			$msgs = $this->_caldav->GetTodos($begin, $finish, false, false, $path);
		}

		$messages = array();
		foreach ($msgs as $e)
		{
			$id = $e['href'];
			$this->_collection[$id] = $e;
			$messages[] = $this->StatMessage($folderid, $id);
		}
		return $messages;
	}

	/**
	 * Get a SyncObject by its ID
	 * @see BackendDiff::GetMessage()
	 */
	public function GetMessage($folderid, $id, $contentparameters)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->GetMessage('%s','%s')", $folderid,  $id));
		$data = $this->_collection[$id]['data'];

		if ($folderid[0] == "C")
		{
			return $this->_ParseVEventToAS($data, $contentparameters);
		}
		if ($folderid[0] == "T")
		{
			return $this->_ParseVTodoToAS($data, $contentparameters);
		}
		return false;
	}

	/**
	 * Return id, flags and mod of a messageid
	 * @see BackendDiff::StatMessage()
	 */
	public function StatMessage($folderid, $id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->StatMessage('%s','%s')", $folderid,  $id));
		$type = "VEVENT";
		if ($folderid[0] == "T")
		{
			$type = "VTODO";
		}
		$data = null;
		if (array_key_exists($id, $this->_collection))
		{
			$data = $this->_collection[$id];
		}
		else
		{
			$path = $this->_caldav_path . substr($folderid, 1) . "/";
			$e = $this->_caldav->GetEntryByUid(substr($id, 0, strlen($id)-4), $path, $type);
			if ($e == null && count($e) <= 0)
				return;
			$data = $e[0];
		}
		$message = array();
		$message['id'] = $data['href'];
		$message['flags'] = "1";
		$message['mod'] = $data['etag'];
		return $message;
	}

	/**
	 * Change/Add a message with contents received from ActiveSync
	 * @see BackendDiff::ChangeMessage()
	 */
	public function ChangeMessage($folderid, $id, $message, $contentParameters)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->ChangeMessage('%s','%s')", $folderid,  $id));

		if ($id)
		{
			$mod = $this->StatMessage($folderid, $id);
			$etag = $mod['mod'];
		}
		else
		{
			$etag = "*";
			$date = gmdate("Ymd\THis\Z");
			$random = hash("md5", microtime());
			$id = $date . "-" . $random . ".ics";
		}

		$data = $this->_ParseASToVCalendar($message, $folderid, substr($id, 0, strlen($id)-4));

		$url = $this->_caldav_path . substr($folderid, 1) . "/" . $id;
		$etag_new = $this->_caldav->DoPUTRequest($url, $data, $etag);

		$item = array();
		$item['href'] = $id;
		$item['etag'] = $etag_new;
		$item['data'] = $data;
		$this->_collection[$id] = $item;

		return $this->StatMessage($folderid, $id);
	}

	/**
	 * Change the read flag is not supported.
	 * @see BackendDiff::SetReadFlag()
	 */
	public function SetReadFlag($folderid, $id, $flags, $contentParameters)
	{
		return false;
	}

    /**
     * Changes the 'star' flag of a message on disk
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     * @param int           $flags          star flag of the message
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function SetStarFlag($folderid, $id, $flags, $contentParameters)
	{
        return false;
    }

	/**
	 * Delete a message from the CalDAV server.
	 * @see BackendDiff::DeleteMessage()
	 */
	public function DeleteMessage($folderid, $id, $contentParameters)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->DeleteMessage('%s','%s')", $folderid,  $id));
		$url = $this->_caldav_path . substr($folderid, 1) . "/" . $id;
		$http_status_code = $this->_caldav->DoDELETERequest($url);
		if ($http_status_code == "204") {
			return true;
		}
		return false;
	}

	/**
	 * Move a message is not supported by CalDAV.
	 * @see BackendDiff::MoveMessage()
	 */
	public function MoveMessage($folderid, $id, $newfolderid, $contentParameters)
	{
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
	 * Convert a iCAL VEvent to ActiveSync format
	 * @param ical_vevent $data
	 * @param ContentParameters $contentparameters
	 * @return SyncAppointment
	 */
	private function _ParseVEventToAS($data, $contentparameters)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->_ParseVEventToAS(): Parsing VEvent"));
		$truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());
		$message = new SyncAppointment();

		$ical = new iCalComponent($data);
		$timezones = $ical->GetComponents("VTIMEZONE");
		$timezone = "";
		if (count($timezones) > 0)
		{
			$timezone = $this->_ParseTimezone($timezones[0]->GetPValue("TZID"));
		}
		if (!$timezone)
		{
			$timezone = date_default_timezone_get();
		}
		$message->timezone = $this->_GetTimezoneString($timezone);

		$vevents = $ical->GetComponents("VTIMEZONE", false);
		foreach ($vevents as $event)
		{
			$rec = $event->GetProperties("RECURRENCE-ID");
			if (count($rec) > 0)
			{
				$recurrence_id = reset($rec);
				$exception = new SyncAppointmentException();
				$tzid = $this->_ParseTimezone($recurrence_id->GetParameterValue("TZID"));
				if (!$tzid)
				{
					$tzid = $timezone;
				}
				$exception->exceptionstarttime = $this->_MakeUTCDate($recurrence_id->Value(), $tzid);
				$exception->deleted = "0";
				$exception = $this->_ParseVEventToSyncObject($event, $exception, $truncsize);
				if (!isset($message->exceptions))
				{
					$message->exceptions = array();
				}
				$message->exceptions[] = $exception;
			}
			else
			{
				$message = $this->_ParseVEventToSyncObject($event, $message, $truncsize);
			}
		}
		return $message;
	}

	/**
	 * Parse 1 VEvent
	 * @param ical_vevent $event
	 * @param SyncAppointment(Exception) $message
	 * @param int $truncsize
	 */
	private function _ParseVEventToSyncObject($event, $message, $truncsize)
	{
		//Defaults
		$message->busystatus = "2";

		$properties = $event->GetProperties();
		foreach ($properties as $property)
		{
			switch ($property->Name())
			{
				case "LAST-MODIFIED":
					$message->dtstamp = $this->_MakeUTCDate($property->Value());
					break;

				case "DTSTART":
					$message->starttime = $this->_MakeUTCDate($property->Value(), $this->_ParseTimezone($property->GetParameterValue("TZID")));
					if (strlen($property->Value()) == 8)
					{
						$message->alldayevent = "1";
					}
					break;

				case "SUMMARY":
					$message->subject = $property->Value();
					break;

				case "UID":
					$message->uid = $property->Value();
					break;

				case "ORGANIZER":
					$org_mail = str_ireplace("MAILTO:", "", $property->Value());
					$message->organizeremail = $org_mail;
					$org_cn = $property->GetParameterValue("CN");
					if ($org_cn)
					{
						$message->organizername = $org_cn;
					}
					break;

				case "LOCATION":
					$message->location = $property->Value();
					break;

				case "DTEND":
					$message->endtime = $this->_MakeUTCDate($property->Value(), $this->_ParseTimezone($property->GetParameterValue("TZID")));
					if (strlen($property->Value()) == 8)
					{
						$message->alldayevent = "1";
					}
					break;

                case "DURATION":
                    if (!isset($message->endtime))
                    {
                        $start = date_create("@" . $message->starttime);
                        $val = str_replace("+", "", $property->Value());
                        $interval = new DateInterval($val);
                        $message->endtime = date_timestamp_get(date_add($start, $interval));
                    }
                break;

				case "RRULE":
					$message->recurrence = $this->_ParseRecurrence($property->Value(), "vevent");
					break;

				case "CLASS":
					switch ($property->Value())
					{
						case "PUBLIC":
							$message->sensitivity = "0";
							break;
						case "PRIVATE":
							$message->sensitivity = "2";
							break;
						case "CONFIDENTIAL":
							$message->sensitivity = "3";
							break;
					}
					break;

				case "TRANSP":
					switch ($property->Value())
					{
						case "TRANSPARENT":
							$message->busystatus = "0";
							break;
						case "OPAQUE":
							$message->busystatus = "2";
							break;
					}
					break;

				case "STATUS":
					switch ($property->Value())
					{
						case "TENTATIVE":
							$message->meetingstatus = "1";
							break;
						case "CONFIRMED":
							$message->meetingstatus = "3";
							break;
						case "CANCELLED":
							$message->meetingstatus = "5";
							break;
					}
					break;

				case "ATTENDEE":
					$attendee = new SyncAttendee();
					$att_email = str_ireplace("MAILTO:", "", $property->Value());
					$attendee->email = $att_email;
					$att_cn = $property->GetParameterValue("CN");
					if ($att_cn)
					{
						$attendee->name = $att_cn;
					}
					if (isset($message->attendees) && is_array($message->attendees))
					{
						$message->attendees[] = $attendee;
					}
					else
					{
						$message->attendees = array($attendee);
					}
					break;

				case "DESCRIPTION":
                    if (Request::GetProtocolVersion() >= 12.0) {
                        $message->asbody = new SyncBaseBody();
                        $message->asbody->data = str_replace("\n","\r\n", str_replace("\r","",Utils::ConvertHtmlToText($property->Value())));
                        // truncate body, if requested
                        if (strlen($message->asbody->data) > $truncsize) {
                            $message->asbody->truncated = 1;
                            $message->asbody->data = Utils::Utf8_truncate($message->asbody->data, $truncsize);
                        }
                        else {
                            $message->asbody->truncated = 0;
                        }
                        $message->nativebodytype = SYNC_BODYPREFERENCE_PLAIN;
                    }
                    else {
                        $body = $property->Value();
                        // truncate body, if requested
                        if(strlen($body) > $truncsize) {
                            $body = Utils::Utf8_truncate($body, $truncsize);
                            $message->bodytruncated = 1;
                        } else {
                            $body = $body;
                            $message->bodytruncated = 0;
                        }
                        $body = str_replace("\n","\r\n", str_replace("\r","",$body));
                        $message->body = $body;
                    }
					break;

				case "CATEGORIES":
					$categories = explode(",", $property->Value());
					$message->categories = $categories;
					break;

				case "EXDATE":
					$exception = new SyncAppointmentException();
					$exception->deleted = "1";
					$exception->exceptionstarttime = $this->_MakeUTCDate($property->Value());
					if (!isset($message->exceptions))
					{
						$message->exceptions = array();
					}
					$message->exceptions[] = $exception;
					break;

				//We can ignore the following
				case "PRIORITY":
				case "SEQUENCE":
				case "CREATED":
				case "DTSTAMP":
				case "X-MOZ-GENERATION":
				case "X-MOZ-LASTACK":
				case "X-LIC-ERROR":
				case "RECURRENCE-ID":
					break;

				default:
					ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->_ParseVEventToSyncObject(): '%s' is not yet supported.", $property->Name()));
			}
		}

		$valarm = current($event->GetComponents("VALARM"));
		if ($valarm)
		{
			$properties = $valarm->GetProperties();
			foreach ($properties as $property)
			{
				if ($property->Name() == "TRIGGER")
				{
					$parameters = $property->Parameters();
					if (array_key_exists("VALUE", $parameters) && $parameters["VALUE"] == "DATE-TIME")
					{
						$trigger = date_create("@" . $this->_MakeUTCDate($property->Value()));
						$begin = date_create("@" . $message->starttime);
						$interval = date_diff($begin, $trigger);
						$message->reminder = $interval->format("%i") + $interval->format("%h") * 60 + $interval->format("%a") * 60 * 24;
					}
					elseif (!array_key_exists("VALUE", $parameters) || $parameters["VALUE"] == "DURATION")
					{
						$val = str_replace("-", "", $property->Value());
						$interval = new DateInterval($val);
						$message->reminder = $interval->format("%i") + $interval->format("%h") * 60 + $interval->format("%a") * 60 * 24;
					}
				}
			}
		}

		return $message;
	}

	/**
	 * Parse a RRULE
	 * @param string $rrulestr
	 */
	private function _ParseRecurrence($rrulestr, $type)
	{
		$recurrence = new SyncRecurrence();
		if ($type == "vtodo")
		{
			$recurrence = new SyncTaskRecurrence();
		}
		$rrules = explode(";", $rrulestr);
		foreach ($rrules as $rrule)
		{
			$rule = explode("=", $rrule);
			switch ($rule[0])
			{
				case "FREQ":
					switch ($rule[1])
					{
						case "DAILY":
							$recurrence->type = "0";
							break;
						case "WEEKLY":
							$recurrence->type = "1";
							break;
						case "MONTHLY":
							$recurrence->type = "2";
							break;
						case "YEARLY":
							$recurrence->type = "5";
					}
					break;

				case "UNTIL":
					$recurrence->until = $this->_MakeUTCDate($rule[1]);
					break;

				case "COUNT":
					$recurrence->occurrences = $rule[1];
					break;

				case "INTERVAL":
					$recurrence->interval = $rule[1];
					break;

				case "BYDAY":
					$dval = 0;
					$days = explode(",", $rule[1]);
					foreach ($days as $day)
					{
						if ($recurrence->type == "2")
						{
							if (strlen($day)>2)
							{
								$recurrence->weekofmonth = intval($day);
								$day = substr($day,-2);
							}
							else
							{
								$recurrence->weekofmonth = 1;
							}
							$recurrence->type = "3";
						}
						switch ($day)
						{
							//   1 = Sunday
							//   2 = Monday
							//   4 = Tuesday
							//   8 = Wednesday
							//  16 = Thursday
							//  32 = Friday
							//  62 = Weekdays  // not in spec: daily weekday recurrence
							//  64 = Saturday
							case "SU":
								$dval += 1;
								break;
							case "MO":
								$dval += 2;
								break;
							case "TU":
								$dval += 4;
								break;
							case "WE":
								$dval += 8;
								break;
							case "TH":
								$dval += 16;
								break;
							case "FR":
								$dval += 32;
								break;
							case "SA":
								$dval += 64;
								break;
						}
					}
					$recurrence->dayofweek = $dval;
					break;

					//Only 1 BYMONTHDAY is supported, so BYMONTHDAY=2,3 will only include 2
				case "BYMONTHDAY":
					$days = explode(",", $rule[1]);
					$recurrence->dayofmonth = $days[0];
					break;

				case "BYMONTH":
					$recurrence->monthofyear = $rule[1];
					break;

				default:
					ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->_ParseRecurrence(): '%s' is not yet supported.", $rule[0]));
			}
		}
		return $recurrence;
	}

	/**
	 * Generate a iCAL VCalendar from ActiveSync object.
	 * @param string $data
	 * @param string $folderid
	 * @param string $id
	 */
	private function _ParseASToVCalendar($data, $folderid, $id)
	{
		$ical = new iCalComponent();
		$ical->SetType("VCALENDAR");
		$ical->AddProperty("VERSION", "2.0");
		$ical->AddProperty("PRODID", "-//php-push//NONSGML PHP-Push Calendar//EN");
		$ical->AddProperty("CALSCALE", "GREGORIAN");

		if ($folderid[0] == "C")
		{
			$vevent = $this->_ParseASEventToVEvent($data, $id);
			$vevent->AddProperty("UID", $id);
			$ical->AddComponent($vevent);
			if (isset($data->exceptions) && is_array($data->exceptions))
			{
				foreach ($data->exceptions as $ex)
				{
					$exception = $this->_ParseASEventToVEvent($ex, $id);
					if ($data->alldayevent == 1)
					{
						$exception->AddProperty("RECURRENCE-ID", $this->_GetDateFromUTC("Ymd", $ex->exceptionstarttime, $data->timezone), array("VALUE" => "DATE"));
					}
					else
					{
						$exception->AddProperty("RECURRENCE-ID", gmdate("Ymd\THis\Z", $ex->exceptionstarttime));
					}
					$exception->AddProperty("UID", $id);
					$ical->AddComponent($exception);
				}
			}
		}
		if ($folderid[0] == "T")
		{
			$vtodo = $this->_ParseASTaskToVTodo($data, $id);
			$vtodo->AddProperty("UID", $id);
			$vtodo->AddProperty("DTSTAMP", gmdate("Ymd\THis\Z"));
			$ical->AddComponent($vtodo);
		}

		return $ical->Render();
	}

	/**
	 * Generate a VEVENT from a SyncAppointment(Exception).
	 * @param string $data
	 * @param string $id
	 * @return iCalComponent
	 */
	private function _ParseASEventToVEvent($data, $id)
	{
		$vevent = new iCalComponent();
		$vevent->SetType("VEVENT");

		if (isset($data->dtstamp))
		{
			$vevent->AddProperty("DTSTAMP", gmdate("Ymd\THis\Z", $data->dtstamp));
			$vevent->AddProperty("LAST-MODIFIED", gmdate("Ymd\THis\Z", $data->dtstamp));
		}
		if (isset($data->starttime))
		{
			if ($data->alldayevent == 1)
			{
				$vevent->AddProperty("DTSTART", $this->_GetDateFromUTC("Ymd", $data->starttime, $data->timezone), array("VALUE" => "DATE"));
			}
			else
			{
				$vevent->AddProperty("DTSTART", gmdate("Ymd\THis\Z", $data->starttime));
			}
		}
		if (isset($data->subject))
		{
			$vevent->AddProperty("SUMMARY", $data->subject);
		}
		if (isset($data->organizeremail))
		{
			if (isset($data->organizername))
			{
				$vevent->AddProperty("ORGANIZER", sprintf("MAILTO:%s", $data->organizeremail), array("CN" => $data->organizername));
			}
			else
			{
				$vevent->AddProperty("ORGANIZER", sprintf("MAILTO:%s", $data->organizeremail));
			}
		}
		if (isset($data->location))
		{
			$vevent->AddProperty("LOCATION", $data->location);
		}
		if (isset($data->endtime))
		{
			if ($data->alldayevent == 1)
			{
				$vevent->AddProperty("DTEND", $this->_GetDateFromUTC("Ymd", $data->endtime, $data->timezone), array("VALUE" => "DATE"));
			}
			else
			{
				$vevent->AddProperty("DTEND", gmdate("Ymd\THis\Z", $data->endtime));
			}
		}
		if (isset($data->recurrence))
		{
			$vevent->AddProperty("RRULE", $this->_GenerateRecurrence($data->recurrence));
		}
		if (isset($data->sensitivity))
		{
			switch ($data->sensitivity)
			{
				case "0":
					$vevent->AddProperty("CLASS", "PUBLIC");
					break;
				case "2":
					$vevent->AddProperty("CLASS", "PRIVATE");
					break;
				case "3":
					$vevent->AddProperty("CLASS", "CONFIDENTIAL");
					break;
			}
		}
		if (isset($data->busystatus))
		{
			switch ($data->busystatus)
			{
				case "0":
				case "1":
					$vevent->AddProperty("TRANSP", "TRANSPARENT");
					break;
				case "2":
				case "3":
					$vevent->AddProperty("TRANSP", "OPAQUE");
					break;
			}
		}
		if (isset($data->reminder))
		{
			$valarm = new iCalComponent();
			$valarm->SetType("VALARM");
			$valarm->AddProperty("ACTION", "DISPLAY");
			$trigger = "-PT" . $data->reminder . "M";
			$valarm->AddProperty("TRIGGER", $trigger);
			$vevent->AddComponent($valarm);
		}
		if (isset($data->rtf))
		{
			$rtfparser = new rtf();
			$rtfparser->loadrtf(base64_decode($data->rtf));
			$rtfparser->output("ascii");
			$rtfparser->parse();
			$vevent->AddProperty("DESCRIPTION", $rtfparser->out);
		}
		if (isset($data->meetingstatus))
		{
			switch ($data->meetingstatus)
			{
				case "1":
					$vevent->AddProperty("STATUS", "TENTATIVE");
					break;
				case "3":
					$vevent->AddProperty("STATUS", "CONFIRMED");
					break;
				case "5":
				case "7":
					$vevent->AddProperty("STATUS", "CANCELLED");
					break;
			}
		}
		if (isset($data->attendees) && is_array($data->attendees))
		{
			//If there are attendees, we need to set ORGANIZER
			//Some phones doesn't send the organizeremail, so we gotto get it somewhere else.
			//Lets use the login here ($username)
			if (!isset($data->organizeremail))
			{
				$vevent->AddProperty("ORGANIZER", sprintf("MAILTO:%s", $this->_username));
			}
			foreach ($data->attendees as $att)
			{
				$att_str = sprintf("MAILTO:%s", $att->email);
				$vevent->AddProperty("ATTENDEE", $att_str, array("CN" => $att->name));
			}
		}
		if (isset($data->body))
		{
			$vevent->AddProperty("DESCRIPTION", $data->body);
		}
		if (isset($data->asbody->data)) {
            $vevent->AddProperty("DESCRIPTION", $data->asbody->data);
        }
		if (isset($data->categories) && is_array($data->categories))
		{
			$vevent->AddProperty("CATEGORIES", implode(",", $data->categories));
		}

		return $vevent;
	}

	/**
	 * Generate Recurrence
	 * @param string $rec
	 */
	private function _GenerateRecurrence($rec)
	{
		$rrule = array();
		if (isset($rec->type))
		{
			$freq = "";
			switch ($rec->type)
			{
				case "0":
					$freq = "DAILY";
					break;
				case "1":
					$freq = "WEEKLY";
					break;
				case "2":
				case "3":
					$freq = "MONTHLY";
					break;
				case "5":
					$freq = "YEARLY";
					break;
			}
			$rrule[] = "FREQ=" . $freq;
		}
		if (isset($rec->until))
		{
			$rrule[] = "UNTIL=" . gmdate("Ymd\THis\Z", $rec->until);
		}
		if (isset($rec->occurrences))
		{
			$rrule[] = "COUNT=" . $rec->occurrences;
		}
		if (isset($rec->interval))
		{
			$rrule[] = "INTERVAL=" . $rec->interval;
		}
		if (isset($rec->dayofweek))
		{
			$week = '';
			if (isset($rec->weekofmonth))
			{
				$week = $rec->weekofmonth;
			}
			$days = array();
			if (($rec->dayofweek & 1) == 1)
			{
				if (empty($week))
				{
					$days[] = "SU";
				}
				else
				{
					$days[] = $week . "SU";
				}
			}
			if (($rec->dayofweek & 2) == 2)
			{
				if (empty($week))
				{
					$days[] = "MO";
				}
				else
				{
					$days[] = $week . "MO";
				}
			}
			if (($rec->dayofweek & 4) == 4)
			{
				if (empty($week))
				{
					$days[] = "TU";
				}
				else
				{
					$days[] = $week . "TU";
				}
			}
			if (($rec->dayofweek & 8) == 8)
			{
				if (empty($week))
				{
					$days[] = "WE";
				}
				else
				{
					$days[] = $week . "WE";
				}
			}
			if (($rec->dayofweek & 16) == 16)
			{
				if (empty($week))
				{
					$days[] = "TH";
				}
				else
				{
					$days[] = $week . "TH";
				}
			}
			if (($rec->dayofweek & 32) == 32)
			{
				if (empty($week))
				{
					$days[] = "FR";
				}
				else
				{
					$days[] = $week . "FR";
				}
			}
			if (($rec->dayofweek & 64) == 64)
			{
				if (empty($week))
				{
					$days[] = "SA";
				}
				else
				{
					$days[] = $week . "SA";
				}
			}
			$rrule[] = "BYDAY=" . implode(",", $days);
		}
		if (isset($rec->dayofmonth))
		{
			$rrule[] = "BYMONTHDAY=" . $rec->dayofmonth;
		}
		if (isset($rec->monthofyear))
		{
			$rrule[] = "BYMONTH=" . $rec->monthofyear;
		}
		return implode(";", $rrule);
	}

	/**
	 * Convert a iCAL VTodo to ActiveSync format
	 * @param string $data
	 * @param ContentParameters $contentparameters
	 */
	private function _ParseVTodoToAS($data, $contentparameters)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->_ParseVTodoToAS(): Parsing VTodo"));
		$truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());

		$message = new SyncTask();
		$ical = new iCalComponent($data);

		$vtodos = $ical->GetComponents("VTODO");
		//Should only loop once
		foreach ($vtodos as $vtodo)
		{
			$message = $this->_ParseVTodoToSyncObject($vtodo, $message, $truncsize);
		}
		return $message;
	}

	/**
	 * Parse 1 VEvent
	 * @param ical_vtodo $vtodo
	 * @param SyncAppointment(Exception) $message
	 * @param int $truncsize
	 */
	private function _ParseVTodoToSyncObject($vtodo, $message, $truncsize)
	{
		//Default
		$message->reminderset = "0";
		$message->importance = "1";
		$message->complete = "0";

		$properties = $vtodo->GetProperties();
		foreach ($properties as $property)
		{
			switch ($property->Name())
			{
				case "SUMMARY":
					$message->subject = $property->Value();
					break;

				case "STATUS":
					switch ($property->Value())
					{
						case "NEEDS-ACTION":
						case "IN-PROCESS":
							$message->complete = "0";
							break;
						case "COMPLETED":
						case "CANCELLED":
							$message->complete = "1";
							break;
					}
					break;

				case "COMPLETED":
					$message->datecompleted = $this->_MakeUTCDate($property->Value());
					break;

				case "DUE":
					$message->utcduedate = $this->_MakeUTCDate($property->Value());
					break;

				case "PRIORITY":
					$priority = $property->Value();
					if ($priority <= 3)
						$message->importance = "0";
					if ($priority <= 6)
						$message->importance = "1";
					if ($priority > 6)
						$message->importance = "2";
					break;

				case "RRULE":
					$message->recurrence = $this->_ParseRecurrence($property->Value(), "vtodo");
					break;

				case "CLASS":
					switch ($property->Value())
					{
						case "PUBLIC":
							$message->sensitivity = "0";
							break;
						case "PRIVATE":
							$message->sensitivity = "2";
							break;
						case "CONFIDENTIAL":
							$message->sensitivity = "3";
							break;
					}
					break;

				case "DTSTART":
					$message->utcstartdate = $this->_MakeUTCDate($property->Value());
					break;

				case "SUMMARY":
					$message->subject = $property->Value();
					break;

				case "CATEGORIES":
					$categories = explode(",", $property->Value());
					$message->categories = $categories;
					break;
			}
		}

		if (isset($message->recurrence))
		{
			$message->recurrence->start = $message->utcstartdate;
		}

		$valarm = current($vtodo->GetComponents("VALARM"));
		if ($valarm)
		{
			$properties = $valarm->GetProperties();
			foreach ($properties as $property)
			{
				if ($property->Name() == "TRIGGER")
				{
					$parameters = $property->Parameters();
					if (array_key_exists("VALUE", $parameters) && $parameters["VALUE"] == "DATE-TIME")
					{
						$message->remindertime = $this->_MakeUTCDate($property->Value());
						$message->reminderset = "1";
					}
					elseif (!array_key_exists("VALUE", $parameters) || $parameters["VALUE"] == "DURATION")
					{
						$val = str_replace("-", "", $property->Value());
						$interval = new DateInterval($val);
						$start = date_create("@" . $message->utcstartdate);
						$message->remindertime = date_timestamp_get(date_sub($start, $interval));
						$message->reminderset = "1";
					}
				}
			}
		}
		return $message;
	}

	/**
	 * Generate a VTODO from a SyncAppointment(Exception)
	 * @param string $data
	 * @param string $id
	 * @return iCalComponent
	 */
	private function _ParseASTaskToVTodo($data, $id)
	{
		$vtodo = new iCalComponent();
		$vtodo->SetType("VTODO");

		if (isset($data->body))
		{
			$vtodo->AddProperty("DESCRIPTION", $data->body);
		}
		if (isset($data->asbody->data)) {
            if (isset($data->nativebodytype) && $data->nativebodytype == SYNC_BODYPREFERENCE_RTF) {
                $rtfparser = new rtf();
                $rtfparser->loadrtf(base64_decode($data->asbody->data));
                $rtfparser->output("ascii");
                $rtfparser->parse();
                $vtodo->AddProperty("DESCRIPTION", $rtfparser->out);
            }
            else {
                $vtodo->AddProperty("DESCRIPTION", $data->asbody->data);
            }
        }
		if (isset($data->complete))
		{
			if ($data->complete == "0")
			{
				$vtodo->AddProperty("STATUS", "NEEDS-ACTION");
			}
			else
			{
				$vtodo->AddProperty("STATUS", "COMPLETED");
			}
		}
		if (isset($data->datecompleted))
		{
			$vtodo->AddProperty("COMPLETED", gmdate("Ymd\THis\Z", $data->datecompleted));
		}
		if ($data->utcduedate)
		{
			$vtodo->AddProperty("DUE", gmdate("Ymd\THis\Z", $data->utcduedate));
		}
		if (isset($data->importance))
		{
			if ($data->importance == "1")
			{
				$vtodo->AddProperty("PRIORITY", 6);
			}
			elseif ($data->importance == "2")
			{
				$vtodo->AddProperty("PRIORITY", 9);
			}
			else
			{
				$vtodo->AddProperty("PRIORITY", 1);
			}
		}
		if (isset($data->recurrence))
		{
			$vtodo->AddProperty("RRULE", $this->_GenerateRecurrence($data->recurrence));
		}
		if ($data->reminderset && $data->remindertime)
		{
			$valarm = new iCalComponent();
			$valarm->SetType("VALARM");
			$valarm->AddProperty("ACTION", "DISPLAY");
			$valarm->AddProperty("TRIGGER;VALUE=DATE-TIME", gmdate("Ymd\THis\Z", $data->remindertime));
			$vtodo->AddComponent($valarm);
		}
		if (isset($data->sensitivity))
		{
			switch ($data->sensitivity)
			{
				case "0":
					$vtodo->AddProperty("CLASS", "PUBLIC");
					break;

				case "2":
					$vtodo->AddProperty("CLASS", "PRIVATE");
					break;

				case "3":
					$vtodo->AddProperty("CLASS", "CONFIDENTIAL");
					break;
			}
		}
		if (isset($data->utcstartdate))
		{
			$vtodo->AddProperty("DTSTART", gmdate("Ymd\THis\Z", $data->utcstartdate));
		}
		if (isset($data->subject))
		{
			$vtodo->AddProperty("SUMMARY", $data->subject);
		}
		if (isset($data->rtf))
		{
			$rtfparser = new rtf();
			$rtfparser->loadrtf(base64_decode($data->rtf));
			$rtfparser->output("ascii");
			$rtfparser->parse();
			$vtodo->AddProperty("DESCRIPTION", $rtfparser->out);
		}
		if (isset($data->categories) && is_array($data->categories))
		{
			$vtodo->AddProperty("CATEGORIES", implode(",", $data->categories));
		}

		return $vtodo;
	}

	/**
	 * Generate date object from string and timezone.
	 * @param string $value
	 * @param string $timezone
	 */
	private function _MakeUTCDate($value, $timezone = null)
	{
		$tz = null;
		if ($timezone)
		{
			$tz = timezone_open($timezone);
		}
		if (!$tz)
		{
			//If there is no timezone set, we use the default timezone
			$tz = timezone_open(date_default_timezone_get());
		}
		//20110930T090000Z
		$date = date_create_from_format('Ymd\THis\Z', $value, timezone_open("UTC"));
		if (!$date)
		{
			//20110930T090000
			$date = date_create_from_format('Ymd\THis', $value, $tz);
		}
		if (!$date)
		{
			//20110930 (Append T000000Z to the date, so it starts at midnight)
			$date = date_create_from_format('Ymd\THis\Z', $value . "T000000Z", $tz);
		}
		return date_timestamp_get($date);
	}

	private function _GetDateFromUTC($format, $date, $tz_str)
	{
		$timezone = $this->_GetTimezoneFromString($tz_str);
		$dt = date_create('@' . $date);
		date_timezone_set($dt, timezone_open($timezone));
		return date_format($dt, $format);
	}

	/**
	 * Generate a tzid from various formats
	 * @param str $timezone
	 * @return timezone id
	 */
	private function _ParseTimezone($timezone)
	{
		//(GMT+01.00) Amsterdam / Berlin / Bern / Rome / Stockholm / Vienna
		if (preg_match('/GMT(\\+|\\-)0(\d)/', $timezone, $matches))
		{
			return "Etc/GMT" . $matches[1] . $matches[2];
		}
		//(GMT+10.00) XXX / XXX / XXX / XXX
		if (preg_match('/GMT(\\+|\\-)1(\d)/', $timezone, $matches))
		{
			return "Etc/GMT" . $matches[1] . "1" . $matches[2];
		}
		///inverse.ca/20101018_1/Europe/Amsterdam or /inverse.ca/20101018_1/America/Argentina/Buenos_Aires
		if (preg_match('/\/[.[:word:]]+\/\w+\/(\w+)\/([\w\/]+)/', $timezone, $matches))
		{
			return $matches[1] . "/" . $matches[2];
		}
		return trim($timezone, '"');
	}

	//This returns a timezone that matches the timezonestring.
	//We can't be sure this is the one you chose, as multiple timezones have same timezonestring
	private function _GetTimezoneFromString($tz_string)
	{
		//Get a list of all timezones
		$identifiers = DateTimeZone::listIdentifiers();
		//Try the default timezone first
		array_unshift($identifiers, date_default_timezone_get());
		foreach ($identifiers as $tz)
		{
			$str = $this->_GetTimezoneString($tz, false);
			if ($str == $tz_string)
			{
				ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->_GetTimezoneFromString(): Found timezone: '%s'.", $tz));
				return $tz;
			}
		}
		return date_default_timezone_get();
	}

	/**
	 * Generate ActiveSync Timezone Packed String.
	 * @param string $timezone
	 * @param string $with_names
	 * @throws Exception
	 */
	private function _GetTimezoneString($timezone, $with_names = true)
	{
		// UTC needs special handling
		if ($timezone == "UTC")
			return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
		try {
			//Generate a timezone string (PHP 5.3 needed for this)
			$timezone = new DateTimeZone($timezone);
			$trans = $timezone->getTransitions(time());
			$stdTime = null;
			$dstTime = null;
			if (count($trans) < 3)
			{
				throw new Exception();
			}
			if ($trans[1]['isdst'] == 1)
			{
				$dstTime = $trans[1];
				$stdTime = $trans[2];
			}
			else
			{
				$dstTime = $trans[2];
				$stdTime = $trans[1];
			}
			$stdTimeO = new DateTime($stdTime['time']);
			$stdFirst = new DateTime(sprintf("first sun of %s %s", $stdTimeO->format('F'), $stdTimeO->format('Y')), timezone_open("UTC"));
			$stdBias = $stdTime['offset'] / -60;
			$stdName = $stdTime['abbr'];
			$stdYear = 0;
			$stdMonth = $stdTimeO->format('n');
			$stdWeek = floor(($stdTimeO->format("j")-$stdFirst->format("j"))/7)+1;
			$stdDay = $stdTimeO->format('w');
			$stdHour = $stdTimeO->format('H');
			$stdMinute = $stdTimeO->format('i');
			$stdTimeO->add(new DateInterval('P7D'));
			if ($stdTimeO->format('n') != $stdMonth)
			{
				$stdWeek = 5;
			}
			$dstTimeO = new DateTime($dstTime['time']);
			$dstFirst = new DateTime(sprintf("first sun of %s %s", $dstTimeO->format('F'), $dstTimeO->format('Y')), timezone_open("UTC"));
			$dstName = $dstTime['abbr'];
			$dstYear = 0;
			$dstMonth = $dstTimeO->format('n');
			$dstWeek = floor(($dstTimeO->format("j")-$dstFirst->format("j"))/7)+1;
			$dstDay = $dstTimeO->format('w');
			$dstHour = $dstTimeO->format('H');
			$dstMinute = $dstTimeO->format('i');
			$dstTimeO->add(new DateInterval('P7D'));
			if ($dstTimeO->format('n') != $dstMonth)
			{
				$dstWeek = 5;
			}
			$dstBias = ($dstTime['offset'] - $stdTime['offset']) / -60;
			if ($with_names)
			{
				return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', $stdBias, $stdName, 0, $stdMonth, $stdDay, $stdWeek, $stdHour, $stdMinute, 0, 0, 0, $dstName, 0, $dstMonth, $dstDay, $dstWeek, $dstHour, $dstMinute, 0, 0, $dstBias));
			}
			else
			{
				return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', $stdBias, '', 0, $stdMonth, $stdDay, $stdWeek, $stdHour, $stdMinute, 0, 0, 0, '', 0, $dstMonth, $dstDay, $dstWeek, $dstHour, $dstMinute, 0, 0, $dstBias));
			}
		}
		catch (Exception $e) {
			// If invalid timezone is given, we return UTC
			return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
		}
		return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
	}
}

?>
