<?php

$mbox = imap_open("{imap.zpush.org:143/notls/norsh}INBOX", "username", "password");

$MC = imap_check($mbox);

date_default_timezone_set("UTC");

$limit = time() - 7 * 24 * 60 * 60;

$result = imap_fetch_overview($mbox,"1:{$MC->Nmsgs}",0);
foreach ($result as $overview) {
    echo "#{$overview->msgno} ({$overview->date}) - From: {$overview->from} {$overview->subject}\n";
    if (inside_cutoffdate($limit, $overview->uid, $mbox))
        echo "INSIDE\n";
    else
        echo "OUTSIDE\n";
}
imap_close($mbox);

function inside_cutoffdate($cutoffdate, $id, $mbox) {
    printf("Checking if the messages is withing the cutoffdate %d, %s", $cutoffdate, $id);
    $is_inside = false;

    if ($cutoffdate == 0) {
        // No cutoffdate, all the messages are in range
        $is_inside = true;
        printf("No cutoffdate, all the messages are in range");
    }
    else {
        $overview = imap_fetch_overview($mbox, $id, FT_UID);
        if (is_array($overview)) {
            if (isset($overview[0]->date)) {
                $epoch_sent = strtotime($overview[0]->date);
                $is_inside = ($cutoffdate <= $epoch_sent);
            }
            else {
                // No sent date defined, that's a buggy message but we will think that the message is in range
                $is_inside = true;
                printf("No sent date defined, that's a buggy message but we will think that the message is in range");
            }
        }
        else {
            // No overview, maybe the message is no longer there
            $is_inside = false;
            printf("No overview, maybe the message is no longer there");
        }
    }

    return $is_inside;
}