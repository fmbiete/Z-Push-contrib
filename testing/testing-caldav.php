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

// Show options supported by server
$options = $caldav->DoOptionsRequest();
print_r($options);

$calendars = $caldav->FindCalendars();
print_r($calendars);

$path = $caldav_path . CALDAV_PERSONAL . "/";
$val = $caldav->GetCalendarDetails($path);
print_r($val);

$begin = gmdate("Ymd\THis\Z", time() - 24*7*60*60);
$finish = gmdate("Ymd\THis\Z", 2147483647);
$msgs = $caldav->GetEvents($begin, $finish, $path);
print_r($msgs);

// Initial sync
$results = $caldav->GetSync($path, true, CALDAV_SUPPORTS_SYNC);
print_r($results);

sleep(60);

$results = $caldav->GetSync($path, false, CALDAV_SUPPORTS_SYNC);
print_r($results);
