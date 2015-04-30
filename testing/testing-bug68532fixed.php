<?php

$testString = 'test';
$stream = fopen('php://memory','r+');
fwrite($stream, $testString);
rewind($stream);
$filter = stream_filter_append($stream, 'convert.base64-encode');
$memoryStream = stream_get_contents($stream);


$fileStream = tmpfile();
fwrite($fileStream , $testString);
rewind($fileStream );
$filter = stream_filter_append($fileStream , 'convert.base64-encode');
$fileStream = stream_get_contents($fileStream );

if (strcmp($memoryStream, $fileStream) == 0) {
    printf("Set BUG68532FIXED to true in your config.php\n");
}
else {
    printf("Your PHP suffers Bug 68532, so you should leave BUG68532FIXED as false in your config.php\n");
}