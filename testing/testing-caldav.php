<?php

// Test CalDAV server
// This code will do an initial sync and a second sync.

include_once('include/z_caldav.php');

include_once('lib/utils/utils.php');
include_once('lib/core/zpushdefs.php');
include_once('lib/core/zlog.php');

define('LOGLEVEL', LOGLEVEL_DEBUG);
define('LOGUSERLEVEL', LOGLEVEL_DEVICEID);

// $username = "sogo1";
// $password = "sogo1";
//
// define('CALDAV_SERVER', 'http://sogo-demo.inverse.ca');
// define('CALDAV_PORT', '80');
// define('CALDAV_PATH', '/SOGo/dav/%u/Calendar/');
// define('CALDAV_PERSONAL', 'personal');

$caldav_path = str_replace('%u', $username, CALDAV_PATH);
$caldav = new CalDAVClient(CALDAV_SERVER . ":" . CALDAV_PORT . $caldav_path, $username, $password);

// Show options supported by server
$options = $caldav->DoOptionsRequest();
print_r($options);

// Initial sync
$results = $caldav->GetSync('http://calendar.domain.com/caldav.php/username/calendar/', true, false);
print_r($results);

sleep(600);

$results = $caldav->GetSync('http://calendar.domain.com/caldav.php/username/calendar/', false, false);
print_r($results);

?>