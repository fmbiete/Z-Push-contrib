<?php

include_once('lib/utils/utils.php');
include_once('lib/core/zpushdefs.php');
include_once('lib/core/zlog.php');

include_once('include/z_RFC822.php');
include_once('include/mimeDecode.php');


define('LOGLEVEL', LOGLEVEL_DEBUG);
define('LOGUSERLEVEL', LOGLEVEL_DEVICEID);


$encoded_from = '=?utf-8?Q?"Jo=C3=ABl_P."?= <joel@a.domain.fr>';

printf("Encoded from [%s]\n", $encoded_from);

$mimeDecode = new Mail_mimeDecode();
$decoded_from = $mimeDecode->_decodeHeader($encoded_from);
printf("Decoded from [%s]\n", $decoded_from);

$Mail_RFC822 = new Mail_RFC822();
$parsed = $Mail_RFC822->parseAddressList($decoded_from);

printf("Empty if wrong email address\n");
print_r($parsed);