<?php

// CHANGE THIS TO YOUR FOLDER NAME
$imapid = "#Users/user@domain.at/INBOX";


$serverdelimiter = "/";

$fhir = explode($serverdelimiter, $imapid);
getModAndParentNames($fhir, $displayname, $imapparent);
printf("original: %s\ndisplayname: %s\nimapparent: %s\n\n", $imapid, $displayname, $imapparent);

getModAndParentNamesWithSharedSupport($fhir, $displayname2, $imapparent2);
printf("original: %s\ndisplayname: %s\nimapparent: %s\n\n", $imapid, $displayname2, $imapparent2);


function getModAndParentNames($fhir, &$displayname, &$parent) {
    $serverdelimiter = "/";

    // if mod is already set add the previous part to it as it might be a folder which has
    // delimiter in its name
    $displayname = (isset($displayname) && strlen($displayname) > 0) ? $displayname = array_pop($fhir).$serverdelimiter.$displayname : array_pop($fhir);
    $parent = implode($serverdelimiter, $fhir);

    if (count($fhir) == 1 || checkIfIMAPFolder($parent)) {
        return;
    }
    //recursion magic
    getModAndParentNames($fhir, $displayname, $parent);
}

function getModAndParentNamesWithSharedSupport($fhir, &$displayname, &$parent) {
    // PUT IN HERE YOUR SHARED FOLDER PREFIXES
    $shared_prefix = [ "#Users", "#Public" ];

    $serverdelimiter = "/";

    if (!isset($displayname) || strlen($displayname) == 0) {
        if (count($fhir) > 1) {
            foreach($shared_prefix as $prefix) {
                if (strcasecmp($fhir[0], $prefix) == 0) {
                    printf("Found shared prefix\n");
                    // Remove first element, it's the shared prefix
                    array_shift($fhir);
                    $displayname = "SHARED " . $fhir[count($fhir) - 1];
                    $parent = implode($serverdelimiter, $fhir);
                    return;
                }
            }
        }
    }

    printf("displayname is: %s\n", $displayname);
    // if mod is already set add the previous part to it as it might be a folder which has
    // delimiter in its name
    $displayname = (isset($displayname) && strlen($displayname) > 0) ? $displayname = array_pop($fhir).$serverdelimiter.$displayname : array_pop($fhir);
    $parent = implode($serverdelimiter, $fhir);

    printf("displayname after is: %s\n", $displayname);

    if (count($fhir) == 1 || checkIfIMAPFolder($parent)) {
        return;
    }
    //recursion magic
    getModAndParentNames($fhir, $displayname, $parent);
}

function checkIfIMAPFolder($folderName) {
    return true;
}