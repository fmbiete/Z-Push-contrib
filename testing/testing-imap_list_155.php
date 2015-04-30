<?php

// $server = "{imap.example.org:993/ssl/novalidate-cert}";
// $username = "username";
// $password = "password";

$mbox = imap_open($server, $username, $password, OP_HALFOPEN) or die("unable to connect: " . imap_last_error());

$list = imap_getmailboxes($mbox, $server, "*");
if (is_array($list)) {
    foreach ($list as $val) {
        echo imap_utf7_decode($val->name) . "\n";
    }
} else {
    echo "imap_list failed: " . imap_last_error() . "\n";
}

imap_close($mbox);