<?php

include_once('include/z_RFC822.php');

$address_string = 'My Group: "Richard" <richard@localhost> (A comment), ted@example.com (Ted Bloggs), Barney;';
$structure = Mail_RFC822::parseAddressList($address_string, 'example.com', true);
// print_r($structure);

$address_string = 'undisclosed-recipients:;';
$structure = Mail_RFC822::parseAddressList($address_string);
print_r($structure);

$address_string = 'fmbiete@zpush.org';
$structure = Mail_RFC822::parseAddressList($address_string);
// print_r($structure);
