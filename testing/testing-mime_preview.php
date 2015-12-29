<?php

// Test MIME preview
// This code will extract the preview text for a message

require_once('vendor/autoload.php');

define('LOGLEVEL', LOGLEVEL_DEBUG);
define('LOGUSERLEVEL', LOGLEVEL_DEVICEID);

$file = "testing/samples/messages/zpush-html-preview-bug.txt";

$mobj = new Mail_mimeDecode(file_get_contents($file));
$message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'rfc_822bodies' => true, 'charset' => 'utf-8'));
unset($mobj);

$previewText = "";
Mail_mimeDecode::getBodyRecursive($message, "plain", $previewText, true);
if (strlen($previewText) == 0) {
    printf("No Plain part found\n");
    Mail_mimeDecode::getBodyRecursive($message, "html", $previewText, true);
    $previewText = Utils::ConvertHtmlToText($previewText);
}
printf("%s\n", Utils::Utf8_truncate($previewText, 250));