<?php


// define('IMAP_FOLDER_PREFIX', '[GMAIL]');
// define('IMAP_FOLDER_PREFIX_IN_INBOX', true);
// define('IMAP_FOLDER_INBOX', '');
// define('IMAP_FOLDER_SENT', 'SENT');
// define('IMAP_FOLDER_DRAFT', 'DRAFTS');
// define('IMAP_FOLDER_TRASH', 'TRASH');

define('IMAP_FOLDER_PREFIX', 'INBOX');
define('IMAP_FOLDER_PREFIX_IN_INBOX', false);
define('IMAP_FOLDER_INBOX', 'INBOX');
define('IMAP_FOLDER_SENT', 'SENT');
define('IMAP_FOLDER_DRAFT', 'DRAFTS');
define('IMAP_FOLDER_TRASH', 'TRASH');

// $server = "{imap.example.org:993/ssl/novalidate-cert}";
// $username = "username";
// $password = "password";


$mbox = imap_open($server, $username, $password, OP_HALFOPEN) or die("unable to connect: " . imap_last_error());



// $list = @imap_getsubscribed($mbox, $server, "*");
$list = imap_getmailboxes($mbox, $server, "*");
// print_r($list);
if (is_array($list)) {
    $folders = array();
    // reverse list to obtain folders in right order
    $list = array_reverse($list);

    foreach ($list as $val) {
        $box = array();
        // cut off serverstring
        $imapid = substr($val->name, strlen($server));
        printf("Evaluating %s\n", $imapid);

        GetFolder($imapid);

        $fhir = explode($val->delimiter, $imapid);
        if (count($fhir) > 1) {
            if (defined('IMAP_FOLDER_PREFIX') && strlen(IMAP_FOLDER_PREFIX) > 0) {
                if (strcasecmp($fhir[0], IMAP_FOLDER_PREFIX) == 0) {
//                     printf("Removing prefix\n");
                    // Discard prefix
                    array_shift($fhir);
                }
            }

            if (count($fhir) == 1) {
                $box["mod"] = $fhir[0];
                $box["parent"] = "0";
            }
            else {
//                 printf("Subfolder\n");
                getModAndParentNames($fhir, $box["mod"], $imapparent);
                if ($imapparent === null) {
                    $box["parent"] = "0";
                }
                else {
                    $box["parent"] = $imapparent;
                }
            }
        }
        else {
            $box["mod"] = $imapid;
            $box["parent"] = "0";
        }
        $folders[] = $box;
    }

//     print_r($folders);
}
else {
    printf("imap_list failed: %s\n", imap_last_error());
}

imap_close($mbox);


function getModAndParentNames($fhir, &$displayname, &$parent) {
    // if mod is already set add the previous part to it as it might be a folder which has delimiter in its name
    $displayname = (isset($displayname) && strlen($displayname) > 0) ? $displayname = array_pop($fhir) . getServerDelimiter() . $displayname : array_pop($fhir);
    $parent = implode(getServerDelimiter(), $fhir);

    if (count($fhir) == 1 || checkIfIMAPFolder($parent)) {
            return;
    }
    //recursion magic
    getModAndParentNames($fhir, $displayname, $parent);
}

function getServerDelimiter() {
    $list = @imap_getmailboxes($mbox, $server, "*");
    if (is_array($list) && count($list) > 0) {
        // get the delimiter from the first folder
        $delimiter = $list[0]->delimiter;
    } else {
        // default
        $delimiter = ".";
    }
    return $delimiter;
}

function checkIfIMAPFolder($folderName) {
    $folder_name = $folderName;
    if (defined(IMAP_FOLDER_PREFIX) && strlen(IMAP_FOLDER_PREFIX) > 0) {
        // TODO: We don't care about the inbox exception with the prefix, because we won't check inbox
        $folder_name = IMAP_FOLDER_PREFIX . getServerDelimiter() . $folder_name;
    }
    $list_subfolders = @imap_list($mbox, $server, $folder_name);
    return is_array($list_subfolders);
}


function GetFolder($imapid) {
    $folder = array();

    // explode hierarchy
    $fhir = explode(getServerDelimiter(), $imapid);

    if (strcasecmp($imapid, create_name_folder(IMAP_FOLDER_INBOX)) == 0) {
        $folder["parentid"] = "0";
        $folder["displayname"] = "Inbox";
        $folder["type"] = "SYNC_FOLDER_TYPE_INBOX";
    }
    else if (strcasecmp($imapid, create_name_folder(IMAP_FOLDER_DRAFT)) == 0) {
        $folder["parentid"] = "0";
        $folder["displayname"] = "Drafts";
        $folder["type"] = "SYNC_FOLDER_TYPE_DRAFTS";
    }
    else if (strcasecmp($imapid, create_name_folder(IMAP_FOLDER_SENT)) == 0) {
        $folder["parentid"] = "0";
        $folder["displayname"] = "Sent";
        $folder["type"] = "SYNC_FOLDER_TYPE_SENTMAIL";
    }
    else if (strcasecmp($imapid, create_name_folder(IMAP_FOLDER_TRASH)) == 0) {
        $folder["parentid"] = "0";
        $folder["displayname"] = "Trash";
        $folder["type"] = "SYNC_FOLDER_TYPE_WASTEBASKET";
    }
    else {
        if (defined('IMAP_FOLDER_PREFIX') && strlen(IMAP_FOLDER_PREFIX) > 0) {
            if (strcasecmp($fhir[0], IMAP_FOLDER_PREFIX) == 0) {
                // Discard prefix
                array_shift($fhir);
            }
            else {
                printf("GetFolder('%s'): '%s'; using server delimiter '%s', first part '%s' is not equal to the prefix defined '%s'. Something is wrong with your config.\n", $id, $imapid, getServerDelimiter(), $fhir[0], IMAP_FOLDER_PREFIX);
            }
        }

        if (count($fhir) == 1) {
            $folder["displayname"] = $fhir[0];
            $folder["parentid"] = "0";
        }
        else {
            getModAndParentNames($fhir, $folder["displayname"], $imapparent);
            $folder["displayname"] = $folder["displayname"];
            if ($imapparent === null) {
                printf("GetFolder('%s'): '%s'; we didn't found a valid parent name for the folder, but we should... contact the developers for further info\n", $id, $imapid);
                $folder["parentid"] = "0"; // We put the folder as root folder, so we see it
            }
            else {
                $folder["parentid"] = $imapparent;
            }
        }
        $folder["type"] = "SYNC_FOLDER_TYPE_USER_MAIL";
    }

//     print_r($folder);
}

function create_name_folder($folder_name) {
    $foldername = $folder_name;
    // If we have defined a folder prefix, and it's not empty
    if (defined('IMAP_FOLDER_PREFIX') && IMAP_FOLDER_PREFIX != "") {
        // If inbox uses prefix or we are not evaluating inbox
        if (IMAP_FOLDER_PREFIX_IN_INBOX == true || strcasecmp($foldername, IMAP_FOLDER_INBOX) != 0) {
            $foldername = IMAP_FOLDER_PREFIX . getServerDelimiter() . $foldername;
        }
    }

    return $foldername;
}