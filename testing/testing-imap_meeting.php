<?php

require_once 'vendor/autoload.php';

$body = file_get_contents('testing/samples/meeting_request.txt');

$ical = new iCalComponent();
$ical->ParseFrom($body);


$props = $ical->GetPropertiesByPath('!VTIMEZONE/ATTENDEE');
if (count($props) == 1) {
    if (isset($props[0]->Parameters()["PARTSTAT"])) {
        printf("DOES THIS CAUSE ERROR? %s\n", $props[0]->Parameters()["PARTSTAT"]);
    }
}

// MODIFICATIONS
    // METHOD
$ical->SetPValue("METHOD", "REPLY");
    //ATTENDEE
$ical->SetCPParameterValue("VEVENT", "ATTENDEE", "PARTSTAT", "ACCEPTED");

printf("%s\n", $ical->Render());


$mail = new Mail_mimepart();
$headers = array("MIME-version" => "1.0",
                "From" => $mail->encodeHeader("from", "Pedro Picapiedra <pedro.picapiedra@zpush.org>", "UTF-8"),
                "To" => $mail->encodeHeader("to", "Pablo Marmol <pablo.marmol@zpush.org>", "UTF-8"),
                "Date" => gmdate("D, d M Y H:i:s", time())." GMT",
                "Subject" => $mail->encodeHeader("subject", "This is a subject", "UTF-8"),
                "Content-class" => "urn:content-classes:calendarmessage",
                "Content-transfer-encoding" => "8BIT");
$mail = new Mail_mimepart($ical->Render(), array("content_type" => "text/calendar; method=REPLY; charset=UTF-8", "headers" => $headers));

$message = "";
$encoded_mail = $mail->encode();
foreach ($encoded_mail["headers"] as $k => $v) {
    $message .= $k . ": " . $v . "\r\n";
}
$message .= "\r\n" . $encoded_mail["body"] . "\r\n";

printf("%s\n", $message);


define('LOGLEVEL', LOGLEVEL_DEBUG);
define('LOGUSERLEVEL', LOGLEVEL_DEVICEID);

$props = $ical->GetPropertiesByPath("VTIMEZONE/TZID");
if (count($props) > 0) {
    $tzid = $props[0]->Value();
//     printf("TZID %s\n", $props[0]->Value());
}
// print_r(TimezoneUtil::GetFullTZFromTZName($tzid));