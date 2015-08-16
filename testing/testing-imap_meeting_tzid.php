<?php

require_once 'vendor/autoload.php';

define('LOGLEVEL', LOGLEVEL_DEBUG);
define('LOGUSERLEVEL', LOGLEVEL_DEVICEID);

date_default_timezone_set('Europe/Madrid');

$body = file_get_contents('testing/samples/meeting_request.txt');

$ical = new iCalComponent();
$ical->ParseFrom($body);

$props = $ical->GetPropertiesByPath("VTIMEZONE/TZID");
if (count($props) > 0) {
    $tzid = $props[0]->Value();
    printf("TZID %s\n", $props[0]->Value());
}
print_r(TimezoneUtil::GetFullTZFromTZName($tzid));


$body = file_get_contents('testing/samples/meeting_request_rim.txt');

$ical = new iCalComponent();
$ical->ParseFrom($body);

$props = $ical->GetPropertiesByPath("VTIMEZONE/TZID");
if (count($props) > 0) {
    $tzid = $props[0]->Value();
    printf("TZID %s\n", $props[0]->Value());
}
print_r(TimezoneUtil::GetFullTZFromTZName($tzid));