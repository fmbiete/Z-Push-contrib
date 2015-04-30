<?php

// Test CardDAV server
// This code will do an addressbook discover, an initial sync and will get a vcard.

require_once 'vendor/autoload.php';

define('CARDDAV_PROTOCOL', 'http');
define('CARDDAV_SERVER', 'sogo-demo.inverse.ca');
define('CARDDAV_PORT', '80');
define('CARDDAV_PATH', '/SOGo/dav/%u/Contacts/');
define('CARDDAV_DEFAULT_PATH', CARDDAV_PATH . 'personal/');

$username = "sogo1";
$password = "sogo1";
$domain = "";

$url = CARDDAV_PROTOCOL . '://' . CARDDAV_SERVER . ':' . CARDDAV_PORT . str_replace("%d", $domain, str_replace("%u", $username, CARDDAV_PATH));
$default_url = CARDDAV_PROTOCOL . '://' . CARDDAV_SERVER . ':' . CARDDAV_PORT . str_replace("%d", $domain, str_replace("%u", $username, CARDDAV_DEFAULT_PATH));
if (defined('CARDDAV_GAL_PATH')) {
    $gal_url = CARDDAV_PROTOCOL . '://' . CARDDAV_SERVER . ':' . CARDDAV_PORT . str_replace("%d", $domain, str_replace("%u", $username, CARDDAV_GAL_PATH));
}
else {
    $gal_url = false;
}
echo "$url\n";
echo "$default_url\n";
echo "$gal_url\n";

$server = new carddav_backend($url);
$server->set_auth($username, $password);
//$server->enable_debug();
$raw = $server->get(false, false, true);
echo "$raw\n";
//var_dump($server->get_debug());

if ($raw !== false) {
    $xml = new SimpleXMLElement($raw);
    foreach($xml->addressbook_element as $response) {
        if ($gal_url !== false) {
            if (strcmp(urldecode($response->url), $gal_url) == 0) {
                echo sprintf("BackendCardDAV::discoverAddressbooks() Ignoring GAL addressbook '%s'\n", $this->gal_url);
                continue;
            }
        }

        echo sprintf("BackendCardDAV::discoverAddressbooks() Found addressbook '%s'\n", urldecode($response->url));
    }
    unset($xml);
}

//$server->enable_debug();
$server->set_url($default_url);
$vcards = $server->do_sync(true, false, false);
//var_dump($server->get_debug());
echo "$vcards\n";

echo "-----------\n";
//$server->enable_debug();
// TODO: set to an existing vcard ID (you will get a list with the do_sync operation
$xml = $server->get_xml_vcard('131-52C19B00-7-7A512880');
//var_dump($server->get_debug());
echo "$xml\n";