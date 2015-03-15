<?php

require_once 'vendor/autoload.php';

$mailtext = file_get_contents("testing/samples/messages/smime001.txt");
$decoder = new Mail_mimeDecode($mailtext);
$parts =  $decoder->getSendArray();
if ($parts === false) {
    printf("ERROR splitting message\n");
}
else {
    list($recipents,$headers,$body) = $parts;
    printf("RECIPIENTS\n");
    print_r($recipents);
    printf("\nHEADERS\n");
    print_r($headers);
    printf("\nBODY\n");
    print_r($body);
    printf("\n");
    //$mail = Mail::factory('smtp');
    //$mail->send($recipents,$headers,$body);
}