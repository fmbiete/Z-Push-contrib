<?php

$mbox = imap_open("{imap.zpush.org:143/notls/norsh}INBOX", "username", "password");

date_default_timezone_set("UTC");

$result = imap_fetch_overview($mbox, "1:*", 0);
foreach ($result as $overview) {
    if (isset($overview->date)) {
        printf("%s\n", $overview->date);
        printf("%s\n", cleanupDate($overview->date));
    }
    if (isset($overview->udate)) {
        printf("%s\n", $overview->udate);
    }
}
imap_close($mbox);


function cleanupDate($receiveddate) {
    if (is_array($receiveddate)) {
        // Header Date could be repeated in the message, we only check the first
        $receiveddate = $receiveddate[0];
    }
    printf("%s\n", preg_replace("/\(.*\)/", "", $receiveddate));
    printf("%s\n", preg_replace('/\(.*\)/', "", $receiveddate));
    printf("%s\n", preg_replace("/\\(.*\\)/", "", $receiveddate));
    $receiveddate = strtotime(preg_replace("/\(.*\)/", "", $receiveddate));
    if ($receiveddate == false || $receiveddate == -1) {
        printf("cleanupDate() : Received date is false. Message might be broken.");
        return null;
    }

    return $receiveddate;
}