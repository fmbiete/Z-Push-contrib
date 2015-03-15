<?php

// $server = "imap.example.org";
// $username = "username";
// $password = "password";

$mbox = imap_open("{".$server."}", $username, $password, OP_HALFOPEN)
      or die("unable to connect: " . imap_last_error());

//$list = imap_list($mbox, "{".$server."}", "*");
$list = imap_subscribed($mbox, "{".$server."}", "*");
if (is_array($list)) {
    foreach ($list as $val) {
        echo imap_utf7_decode($val) . "\n";
    }
} else {
    echo "imap_list failed: " . imap_last_error() . "\n";
}

imap_close($mbox);