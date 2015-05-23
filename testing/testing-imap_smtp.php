<?php

require_once 'vendor/autoload.php';

define('LOGLEVEL', LOGLEVEL_DEBUG);
define('LOGUSERLEVEL', LOGLEVEL_DEVICEID);


//      smtp
//          "host"          - The server to connect. Default is localhost.
//          "port"          - The port to connect. Default is 25.
//          "auth"          - Whether or not to use SMTP authentication. Default is FALSE.
//          "username"      - The username to use for SMTP authentication. "imap_username" for using the same username as the imap server
//          "password"      - The password to use for SMTP authentication. "imap_password" for using the same password as the imap server
//          "localhost"     - The value to give when sending EHLO or HELO. Default is localhost
//          "timeout"       - The SMTP connection timeout. Default is NULL (no timeout).
//          "verp"          - Whether to use VERP or not. Default is FALSE.
//          "debug"         - Whether to enable SMTP debug mode or not. Default is FALSE.
//          "persist"       - Indicates whether or not the SMTP connection should persist over multiple calls to the send() method.
//          "pipelining"    - Indicates whether or not the SMTP commands pipelining should be used.
$imap_smtp_params = array('host' => 'smtp.zpush.org', 'port' => 25, 'auth' => true, "username" => "fmbiete", "password" => "password_account", "debug" => true, "pipelining" => true);
$toaddr = "fmbiete@zpush.org";
$headers = array('Subject' => 'Testing SMTP', 'From' => 'fmbiete@zpush.org', 'Return-Path' => 'fmbiete@zpush.org', 'To' => 'fmbiete@zpush.org', 'Cc' => 'fmbiete@zpush.org,fmbiete@zpush.net', 'Bcc' => array('fmbiete2@zpush.org', 'fmbiete2@zpush.net', 'fmbiete@zpush.org'));
$body = "This is a test";


if (is_array($toaddr)) {
    $recipients = $toaddr;
}
else {
    $recipients = array($toaddr);
}

// Cc and Bcc headers are sent, but we need to make sure that the recipient list contains them
foreach (array("CC", "cc", "Cc", "BCC", "Bcc", "bcc") as $key) {
    if (!empty($headers[$key])) {
        if (is_array($headers[$key])) {
            $recipients = array_merge($recipients, $headers[$key]);
        }
        else {
            $recipients[] = $headers[$key];
        }
    }
}

ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->sendMessage(): SendingMail with %s", "smtp"));
$mail =& Mail::factory("smtp", $imap_smtp_params);
$send = $mail->send($recipients, $headers, $body);
ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->sendMessage(): send return value %s", $send));

if ($send !== true) {
    ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): The email could not be sent"));
}