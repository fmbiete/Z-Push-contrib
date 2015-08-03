<?php

// Test CalDAV server
// This code will do an initial sync and a second sync.

require_once('vendor/autoload.php');

define('LOGLEVEL', LOGLEVEL_DEBUG);
define('LOGUSERLEVEL', LOGLEVEL_DEVICEID);

// $username = "sogo1";
// $password = "sogo1";
//
// define('CALDAV_SERVER', 'http://sogo-demo.inverse.ca');
// define('CALDAV_PORT', '80');
// define('CALDAV_PATH', '/SOGo/dav/%u/Calendar/');
// define('CALDAV_PERSONAL', 'personal');
// define('CALDAV_SUPPORTS_SYNC', true);

$caldav_path = str_replace('%u', $username, CALDAV_PATH);
$caldav = new CalDAVClient(CALDAV_SERVER . ":" . CALDAV_PORT . $caldav_path, $username, $password);

printf("Connected %d\n", $caldav->CheckConnection());

$path = $caldav_path . CALDAV_PERSONAL . "/";

$filter =<<<EOFFILTER
  <C:filter>
    <C:comp-filter name="VCALENDAR">
          <C:comp-filter name="VEVENT">
                <C:prop-filter name="UID">
                        <C:text-match>040000008200E00074C5B7101A82E00800000000B72BEB1EF3CCD00100000000000000001000000067299FC1A8990B4EB88510710DB15426</C:text-match>
                </C:prop-filter>
          </C:comp-filter>
    </C:comp-filter>
  </C:filter>
EOFFILTER;
$val = $caldav->DoCalendarQuery($filter, $path);
print_r($val);
