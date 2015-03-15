<?php
/***********************************************
* File      :   index.php
* Project   :   Z-Push
* Descr     :   This is the entry point
*               through which all requests
*               are processed.
*
* Created   :   01.10.2007
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

ob_start(null, 1048576);

// ignore user abortions because this can lead to weird errors - see ZP-239
ignore_user_abort(true);

require_once 'vendor/autoload.php';
require_once 'config.php';

    // Attempt to set maximum execution time
    ini_set('max_execution_time', SCRIPT_TIMEOUT);
    set_time_limit(SCRIPT_TIMEOUT);

    try {
        // check config & initialize the basics
        ZPush::CheckConfig();
        Request::Initialize();
        ZLog::Initialize();

        $autenticationInfo = Request::AuthenticationInfo();
        $GETUser = Request::GetGETUser();

        ZLog::Write(LOGLEVEL_DEBUG,"-------- Start");
        ZLog::Write(LOGLEVEL_INFO,
                    sprintf("Version='%s' method='%s' from='%s' cmd='%s' getUser='%s' devId='%s' devType='%s'",
                                    @constant('ZPUSH_VERSION'), Request::GetMethod(), Request::GetRemoteAddr(),
                                    Request::GetCommand(), $GETUser, Request::GetDeviceID(), Request::GetDeviceType()));

        // Stop here if this is an OPTIONS request
        if (Request::IsMethodOPTIONS()) {
            if (!$autenticationInfo || !$GETUser) {
                throw new AuthenticationRequiredException("Access denied. Please send authorisation information");
            }
            else {
                throw new NoPostRequestException("Options request", NoPostRequestException::OPTIONS_REQUEST);
            }
        }

        ZPush::CheckAdvancedConfig();

        // Process request headers and look for AS headers
        Request::ProcessHeaders();

        // Check required GET parameters
        if(Request::IsMethodPOST() && (Request::GetCommandCode() === false || !Request::GetDeviceID() || !Request::GetDeviceType()))
            throw new FatalException("Requested the Z-Push URL without the required GET parameters");


        // This won't be useful with Zarafa, but it will be with standalone Z-Push
        if (defined('PRE_AUTHORIZE_USERS') && PRE_AUTHORIZE_USERS === true) {
            if (!Request::IsMethodGET()) {
                // Check if User/Device are authorized
                if (ZPush::GetDeviceManager()->GetUserDevicePermission($GETUser, Request::GetDeviceID()) != SYNC_COMMONSTATUS_SUCCESS) {
                    throw new AuthenticationRequiredException("Access denied. Username and Device not authorized");
                }
            }
        }

        // Load the backend
        $backend = ZPush::GetBackend();

        // always request the authorization header
        if (!$autenticationInfo || !$GETUser)
            throw new AuthenticationRequiredException("Access denied. Please send authorisation information");

        // check the provisioning information
        if (PROVISIONING === true && Request::IsMethodPOST() && ZPush::CommandNeedsProvisioning(Request::GetCommandCode()) &&
            ((Request::WasPolicyKeySent() && Request::GetPolicyKey() == 0) || ZPush::GetDeviceManager()->ProvisioningRequired(Request::GetPolicyKey())) &&
            (LOOSE_PROVISIONING === false ||
            (LOOSE_PROVISIONING === true && Request::WasPolicyKeySent())))
            //TODO for AS 14 send a wbxml response
            throw new ProvisioningRequiredException();

        // most commands require an authenticated user
        if (ZPush::CommandNeedsAuthentication(Request::GetCommandCode()))
            RequestProcessor::Authenticate();

        // Do the actual processing of the request
        if (Request::IsMethodGET())
            throw new NoPostRequestException("This is the Z-Push location and can only be accessed by Microsoft ActiveSync-capable devices", NoPostRequestException::GET_REQUEST);

        // Do the actual request
        header(ZPush::GetServerHeader());

        // announce the supported AS versions (if not already sent to device)
        if (ZPush::GetDeviceManager()->AnnounceASVersion()) {
            $versions = ZPush::GetSupportedProtocolVersions(true);
            ZLog::Write(LOGLEVEL_INFO, sprintf("Announcing latest AS version to device: %s", $versions));
            header("X-MS-RP: ". $versions);
        }

        RequestProcessor::Initialize();
        if(!RequestProcessor::HandleRequest())
            throw new WBXMLException(ZLog::GetWBXMLDebugInfo());

        // eventually the RequestProcessor wants to send other headers to the mobile
        foreach (RequestProcessor::GetSpecialHeaders() as $header)
            header($header);

        $len = ob_get_length();

        // log amount of data transferred
        // TODO check $len when streaming more data (e.g. Attachments), as the data will be send chunked
        ZPush::GetDeviceManager()->SentData($len);

        // Unfortunately, even though Z-Push can stream the data to the client
        // with a chunked encoding, using chunked encoding breaks the progress bar
        // on the PDA. So the data is de-chunk here, written a content-length header and
        // data send as a 'normal' packet. If the output packet exceeds 1MB (see ob_start)
        // then it will be sent as a chunked packet anyway because PHP will have to flush
        // the buffer.
        if(!headers_sent())
            header("Content-Length: $len");

        // unset content type header if there is no content
        // otherwise text/html content type is added which might break some devices
        if (!headers_sent() && $len === 0)
            header("Content-Type:");

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Sending %d, headers already sent? %s", $len, headers_sent()));

        if (!ob_end_flush())
            ZLog::Write(LOGLEVEL_ERROR, "Unable to flush buffer!?");

        // destruct backend after all data is on the stream
        ZPush::GetBackend()->Logoff();
    }

    catch (NoPostRequestException $nopostex) {
        $len = ob_get_length();
        if ($len) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("Cleaning %d octets of data", $len));
            ob_clean();
        }

        if ($nopostex->getCode() == NoPostRequestException::OPTIONS_REQUEST) {
            header(ZPush::GetServerHeader());
            header(ZPush::GetSupportedProtocolVersions());
            header(ZPush::GetSupportedCommands());
            ZLog::Write(LOGLEVEL_INFO, $nopostex->getMessage());
        }
        else if ($nopostex->getCode() == NoPostRequestException::GET_REQUEST) {
            if (Request::GetUserAgent())
                ZLog::Write(LOGLEVEL_INFO, sprintf("User-agent: '%s'", Request::GetUserAgent()));
            if (!headers_sent() && $nopostex->showLegalNotice())
                ZPush::PrintZPushLegal('GET not supported', $nopostex->getMessage());
        }

        $len = ob_get_length();
        if ($len !== false) {
            if (!headers_sent()) {
                header("Content-Length: $len");
                if ($len == 0)
                    header("Content-Type:");
            }
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("Flushing %d, headers already sent? %s", $len, headers_sent()));
            ob_end_flush();
        }
    }

    catch (Exception $ex) {
        $len = ob_get_length();
        if ($len) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("Cleaning %d octets of data", $len));
            ob_clean();
        }

        if (Request::GetUserAgent())
            ZLog::Write(LOGLEVEL_INFO, sprintf("User-agent: '%s'", Request::GetUserAgent()));
        $exclass = get_class($ex);

        if(!headers_sent()) {
            if ($ex instanceof ZPushException) {
                header('HTTP/1.1 '. $ex->getHTTPCodeString());
                foreach ($ex->getHTTPHeaders() as $h)
                    header($h);
            }
            // something really unexpected happened!
            else
                header('HTTP/1.1 500 Internal Server Error');
        }
        else
            ZLog::Write(LOGLEVEL_FATAL, "Exception: ($exclass) - headers were already sent. Message: ". $ex->getMessage());

        if ($ex instanceof AuthenticationRequiredException) {
            ZPush::PrintZPushLegal($exclass, sprintf('<pre>%s</pre>',$ex->getMessage()));

            // log the failed login attemt e.g. for fail2ban
            if (defined('LOGAUTHFAIL') && LOGAUTHFAIL != false)
                ZLog::Write(LOGLEVEL_WARN, sprintf("IP: %s failed to authenticate user '%s'",  Request::GetRemoteAddr(), Request::GetAuthUser()? Request::GetAuthUser(): Request::GetGETUser() ));
        }

        // This could be a WBXML problem.. try to get the complete request
        else if ($ex instanceof WBXMLException) {
            ZLog::Write(LOGLEVEL_FATAL, "Request could not be processed correctly due to a WBXMLException. Please report this.");
        }

        // Try to output some kind of error information. This is only possible if
        // the output had not started yet. If it has started already, we can't show the user the error, and
        // the device will give its own (useless) error message.
        else if (!($ex instanceof ZPushException) || $ex->showLegalNotice()) {
            $cmdinfo = (Request::GetCommand())? sprintf(" processing command <i>%s</i>", Request::GetCommand()): "";
            $extrace = $ex->getTrace();
            $trace = (!empty($extrace))? "\n\nTrace:\n". print_r($extrace,1):"";
            ZPush::PrintZPushLegal($exclass . $cmdinfo, sprintf('<pre>%s</pre>',$ex->getMessage() . $trace));
        }

        // Announce exception to process loop detection
        if (ZPush::GetDeviceManager(false))
            ZPush::GetDeviceManager()->AnnounceProcessException($ex);

        // Announce exception if the TopCollector if available
        ZPush::GetTopCollector()->AnnounceInformation(get_class($ex), true);

        $len = ob_get_length();
        if ($len !== false) {
            if (!headers_sent()) {
                header("Content-Length: $len");
                if ($len == 0)
                    header("Content-Type:");
            }
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("Flushing %d, headers already sent? %s", $len, headers_sent()));
            ob_end_flush();
        }
    }

    // save device data if the DeviceManager is available
    if (ZPush::GetDeviceManager(false))
        ZPush::GetDeviceManager()->Save();

    // end gracefully
    ZLog::Write(LOGLEVEL_DEBUG, '-------- End - max mem: '.memory_get_peak_usage(false).'/'.memory_get_peak_usage(true) .' - time: '.number_format(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"],4).' - code: '.http_response_code());
