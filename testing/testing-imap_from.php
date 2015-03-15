<?php

define('IMAP_FROM_LDAP_SERVER', 'xxxxxxxx');
define('IMAP_FROM_LDAP_SERVER_PORT', '389');
define('IMAP_FROM_LDAP_USER', 'cn=admin,dc=zpush,dc=org');
define('IMAP_FROM_LDAP_PASSWORD', 'xxxxx');
define('IMAP_FROM_LDAP_BASE', 'dc=zpush,dc=org');
define('IMAP_FROM_LDAP_QUERY', '(mail=#username@#domain)');
define('IMAP_FROM_LDAP_FIELDS', serialize(array('givenname', 'sn', 'mail')));
define('IMAP_FROM_LDAP_FROM', '#givenname #sn <#mail>');

function get_from_ldap($username, $domain) {
    $from = $username;

    $ldap_conn = null;
    try {
        $ldap_conn = ldap_connect(IMAP_FROM_LDAP_SERVER, IMAP_FROM_LDAP_SERVER_PORT);
        if ($ldap_conn) {
            printf("Connected to LDAP");
            ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);
            $ldap_bind = ldap_bind($ldap_conn, IMAP_FROM_LDAP_USER, IMAP_FROM_LDAP_PASSWORD);

            if ($ldap_bind) {
                printf("Authenticated in LDAP");
                $filter = str_replace('#username', $username, str_replace('#domain', $domain, IMAP_FROM_LDAP_QUERY));
                printf("Searching From with filter: %s", $filter);
                $search = ldap_search($ldap_conn, IMAP_FROM_LDAP_BASE, $filter, unserialize(IMAP_FROM_LDAP_FIELDS));
                $items = ldap_get_entries($ldap_conn, $search);
                if ($items['count'] > 0) {
                    printf("Found entry in LDAP. Generating From");
                    $from = IMAP_FROM_LDAP_FROM;
                    // We get the first object. It's your responsability to make the query unique
                    foreach (unserialize(IMAP_FROM_LDAP_FIELDS) as $field) {
                        $from = str_replace('#'.$field, $items[0][$field][0], $from);
                    }
                }
                else {
                    printf("No entry found in LDAP");
                }
            }
            else {
                printf("Not authenticated in LDAP server");
            }
        }
        else {
            printf("Not connected to LDAP server");
        }
    }
    catch(Exception $ex) {
        printf("Error getting From value from LDAP server: %s", $ex);
    }

    ldap_close($ldap_conn);

    return $from;
}

$from = get_from_ldap('fmbiete', 'zpush.org');
printf("%s\n", $from);


define('IMAP_FROM_SQL_DSN', 'mysql:host=xxxxxxx;port=3306;dbname=xxxxxxx');
define('IMAP_FROM_SQL_USER', 'xxxxxxx');
define('IMAP_FROM_SQL_PASSWORD', 'xxxxxxx');
define('IMAP_FROM_SQL_OPTIONS', serialize(array(PDO::ATTR_PERSISTENT => false)));
define('IMAP_FROM_SQL_QUERY', 'select role, sede, email from usuarios where email = "#username@#domain"');
define('IMAP_FROM_SQL_FIELDS', serialize(array('role', 'sede', 'email')));
define('IMAP_FROM_SQL_FROM', '#role #sede <#email>');

function get_from_sql($username, $domain) {
    $from = $username;

    $dbh = $sth = $record = null;
    try {
        $dbh = new PDO(IMAP_FROM_SQL_DSN, IMAP_FROM_SQL_USER, IMAP_FROM_SQL_PASSWORD, unserialize(IMAP_FROM_SQL_OPTIONS));
        printf("Connected to SQL Database");

        $sql = str_replace('#username', $username, str_replace('#domain', $domain, IMAP_FROM_SQL_QUERY));
        printf("Searching From with filter: %s", $sql);
        $sth = $dbh->prepare($sql);
        $sth->execute();
        $record = $sth->fetch(PDO::FETCH_ASSOC);
        if ($record) {
            printf("Found entry in SQL Database. Generating From");
            $from = IMAP_FROM_SQL_FROM;
            foreach (unserialize(IMAP_FROM_SQL_FIELDS) as $field) {
                $from = str_replace('#'.$field, $record[$field], $from);
            }
        }
        else {
            printf("No entry found in SQL Database");
        }
    }
    catch(PDOException $ex) {
        printf("Error getting From value from SQL Database: %s", $ex);
    }

    $dbh = $sth = $record = null;

    return $from;
}

function encoding_from($from) {
    $items = explode("<", $from);
    $name = trim($items[0]);
    return "=?UTF-8?B?" . base64_encode($name) . "?= <" . $items[1];
}

$from = get_from_sql('fmbiete', 'zpush.org');
printf("%s\n", $from);

$from = "Francisco Miguel Biete Bañón <fmbiete@zpush.org>";
$encoded_from = encoding_from($from);
printf("%s\n", $encoded_from);


function parseAddr($ad) {
    $addr_string = "";
    if (isset($ad) && is_array($ad)) {
        foreach($ad as $addr) {
            if ($addr_string) $addr_string .= ",";
                $addr_string .= $addr->mailbox . "@" . $addr->host;
        }
    }
    return $addr_string;
}

include_once('include/z_RFC822.php');

$Mail_RFC822 = new Mail_RFC822();
$fromaddr = parseAddr($Mail_RFC822->parseAddressList($encoded_from));
printf("%s\n", $fromaddr);