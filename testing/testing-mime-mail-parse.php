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

function getBodyRecursive($message, $subtype, &$body) {
    if(!isset($message->ctype_primary)) return;
    if(strcasecmp($message->ctype_primary,"text")==0 && strcasecmp($message->ctype_secondary,$subtype)==0 && isset($message->body))
        $body .= $message->body;

    if(strcasecmp($message->ctype_primary,"multipart")==0 && isset($message->parts) && is_array($message->parts)) {
        foreach($message->parts as $part) {
            // Content-Type: text/plain; charset=us-ascii; name="hareandtoroise.txt" Content-Transfer-Encoding: 7bit Content-Disposition: inline; filename="hareandtoroise.txt"
            // We don't want to show that file, so if we have content-disposition not apply recursive
            if(!isset($part->disposition))  {
                getBodyRecursive($part, $subtype, $body);
            }
        }
    }
}


function testMimeDecode($file, $new_file) {
    require_once('include/ForceUTF8/Encoding.php');
    require_once('include/mimeDecode.php');
    require_once('include/mimePart.php');
    require_once('backend/imap/mime_encode.php');

    printf("TEST MIME DECODE\n");
    $mobj = new Mail_mimeDecode(file_get_contents($file));
    $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));
    $handle = fopen($new_file, "w");
    fwrite($handle, build_mime_message($message));
    fclose($handle);

    foreach ($message->headers as $k => $v) {
        printf("Header <%s> <%s>\n", $k, $v);
    }

    $text = $html = "";
    getBodyRecursive($message, "plain", $text);
    getBodyRecursive($message, "html", $html);

    printf("TEXT Body <%s>\n", $text);

    printf("HTML Body <%s>\n", $html);
}
?>