<?php

require_once 'vendor/autoload.php';

define('LOGLEVEL', LOGLEVEL_DEBUG);
define('LOGUSERLEVEL', LOGLEVEL_DEVICEID);

date_default_timezone_set('Europe/Madrid');

function testing_get_method($filename) {
    $body = file_get_contents($filename);

    $ical = new iCalComponent();
    $ical->ParseFrom($body);

    $props = $ical->GetPropertiesByPath("VCALENDAR/METHOD");
    if (count($props) > 0) {
        printf("METHOD %s\n", $props[0]->Value());
    }
}


testing_get_method('testing/samples/meeting_request.txt');
testing_get_method('testing/samples/meeting_request_rim.txt');
testing_get_method('testing/samples/meeting_reply_rim.txt');