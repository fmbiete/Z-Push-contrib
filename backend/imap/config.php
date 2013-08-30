<?php
/***********************************************
* File      :   config.php
* Project   :   Z-Push
* Descr     :   IMAP backend configuration file
*
* Created   :   27.11.2012
*
* Copyright 2007 - 2013 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

// ************************
//  BackendIMAP settings
// ************************

// Defines the server to which we want to connect
// define('IMAP_SERVER', 'localhost');

// connecting to default port (143)
// define('IMAP_PORT', 143);

// best cross-platform compatibility (see http://php.net/imap_open for options)
// define('IMAP_OPTIONS', '/notls/norsh');

// overwrite the "from" header if it isn't set when sending emails
// options: 'username'    - the username will be set (usefull if your login is equal to your emailaddress)
//        'domain'    - the value of the "domain" field is used
//        '@mydomain.com' - the username is used and the given string will be appended
// define('IMAP_DEFAULTFROM', '');

// copy outgoing mail to this folder. If not set z-push will try the default folders
// define('IMAP_SENTFOLDER', '');

// forward messages inline (default true - inlined)
// define('IMAP_INLINE_FORWARD', true);

// list of folders we want to exclude from sync. Names, or part of it, separated by |
// example: dovecot.sieve|archive|spam
define('IMAP_EXCLUDED_FOLDERS', '');


// Method used for sending mail
// mail => mail() php function
// sendmail => sendmail executable
// smtp => direct connection against SMTP. REQUIRES Net_SMTP
define('IMAP_SMTP_METHOD', 'mail');

// SMTP Parameters
//      mail : no params
$imap_smtp_params = array();
//      sendmail
//$imap_smtp_params = array('sendmail_path' => '/usr/bin/sendmail', 'sendmail_args' => '-i');
//      smtp
//          "host"          - The server to connect. Default is localhost.
//          "port"          - The port to connect. Default is 25.
//          "auth"          - Whether or not to use SMTP authentication. Default is FALSE.
//          "username"      - The username to use for SMTP authentication. "imap_username" for using the same username as the imap server
//          "password"      - The password to use for SMTP authentication. "imap_password" for using the same password as the imap server
//          "localhost"     - The value to give when sending EHLO or HELO. Default is localhost
//          "timeout"       - The SMTP connection timeout. Default is NULL (no timeout).
//          "verp"          - Whether to use VERP or not. Default is FALSE.
//          "debug"         - Whether to enable SMTP debug mode or not. Default is FALSE.
//          "persist"       - Indicates whether or not the SMTP connection should persist over multiple calls to the send() method.
//          "pipelining"    - Indicates whether or not the SMTP commands pipelining should be used.
//$imap_smtp_params = array('host' => 'localhost', 'port' => 25, 'auth' => false);

?>
