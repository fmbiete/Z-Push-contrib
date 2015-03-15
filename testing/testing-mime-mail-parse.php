<?php

// Recreate a message
// This code is used in BackendIMAP:GetMessage - for MIME format (iOS devices), and will try to fix the encoding
// Will parse all the sample messages and reencode them, saving the result in /tmp/. The samples dir contains broken messages, feel free to add your samples!!

// RUN FROM Z-Push-contrib ROOT FOLDER (NOT FROM TESTING FOLDER)

// MODIFY THIS IF YOU MUST
$dir = 'testing/samples/messages';
$tmpdir = '/tmp/';


if ($handle = opendir($dir)) {
    while (false !== ($entry = readdir($handle))) {
        if ($entry != "." && $entry != "..") {
            printf("========== TEST FILE: $entry\n");
            testMimeDecode($dir . "/" . $entry, $tmpdir . $entry . ".eml");
        }
    }
    closedir($handle);
}

function testMimeDecode($file, $new_file) {
    require_once('lib/utils/utils.php');
    require_once('lib/core/zpushdefs.php');
    require_once('lib/core/zlog.php');
    require_once('include/mimeDecode.php');
    require_once('include/mimePart.php');
    require_once('backend/imap/mime_encode.php');

    if (!defined('LOGLEVEL'))
        define('LOGLEVEL', LOGLEVEL_DEBUG);
    if (!defined('LOGUSERLEVEL'))
        define('LOGUSERLEVEL', LOGLEVEL_DEVICEID);

    printf("TEST MIME DECODE\n");
    $mobj = new Mail_mimeDecode(file_get_contents($file));
    $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));
    $handle = fopen($new_file, "w");
    fwrite($handle, build_mime_message($message));
    fclose($handle);

    foreach ($message->headers as $k => $v) {
        if (is_array($v)) {
            foreach ($v as $vk => $vv) {
                printf("Header <%s> <%s> <%s>\n", $k, $vk, $vv);
            }
        }
        else {
            printf("Header <%s> <%s>\n", $k, $v);
        }
    }

    $text = $html = "";
    Mail_mimeDecode::getBodyRecursive($message, "plain", $text);
    Mail_mimeDecode::getBodyRecursive($message, "html", $html);

    printf("TEXT Body <%s>\n", $text);

    printf("HTML Body <%s>\n", $html);
}