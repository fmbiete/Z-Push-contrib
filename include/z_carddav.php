<?php

/**
 * CardDAV PHP
 *
 * Simple CardDAV query
 * --------------------
 * $carddav = new carddav_backend('https://davical.example.com/user/contacts/');
 * $carddav->set_auth('username', 'password');
 * echo $carddav->get();
 *
 *
 * Simple vCard query
 * ------------------
 * $carddav = new carddav_backend('https://davical.example.com/user/contacts/');
 * $carddav->set_auth('username', 'password');
 * echo $carddav->get_vcard('0126FFB4-2EB74D0A-302EA17F');
 *
 *
 * XML vCard query
 * ------------------
 * $carddav = new carddav_backend('https://davical.example.com/user/contacts/');
 * $carddav->set_auth('username', 'password');
 * echo $carddav->get_xml_vcard('0126FFB4-2EB74D0A-302EA17F');
 *
 *
 * Check CardDAV server connection
 * -------------------------------
 * $carddav = new carddav_backend('https://davical.example.com/user/contacts/');
 * $carddav->set_auth('username', 'password');
 * var_dump($carddav->check_connection());
 *
 *
 * CardDAV delete query
 * --------------------
 * $carddav = new carddav_backend('https://davical.example.com/user/contacts/');
 * $carddav->set_auth('username', 'password');
 * $carddav->delete('0126FFB4-2EB74D0A-302EA17F');
 *
 *
 * CardDAV add query
 * --------------------
 * $vcard = 'BEGIN:VCARD
 * VERSION:3.0
 * UID:1f5ea45f-b28a-4b96-25as-ed4f10edf57b
 * FN:Christian Putzke
 * N:Christian;Putzke;;;
 * EMAIL;TYPE=OTHER:christian.putzke@graviox.de
 * END:VCARD';
 *
 * $carddav = new carddav_backend('https://davical.example.com/user/contacts/');
 * $carddav->set_auth('username', 'password');
 * $vcard_id = $carddav->add($vcard);
 *
 *
 * CardDAV update query
 * --------------------
 * $vcard = 'BEGIN:VCARD
 * VERSION:3.0
 * UID:1f5ea45f-b28a-4b96-25as-ed4f10edf57b
 * FN:Christian Putzke
 * N:Christian;Putzke;;;
 * EMAIL;TYPE=OTHER:christian.putzke@graviox.de
 * END:VCARD';
 *
 * $carddav = new carddav_backend('https://davical.example.com/user/contacts/');
 * $carddav->set_auth('username', 'password');
 * $carddav->update($vcard, '0126FFB4-2EB74D0A-302EA17F');
 *
 *
 * CardDAV debug
 * -------------
 * $carddav = new carddav_backend('https://davical.example.com/user/contacts/');
 * $carddav->enable_debug();
 * $carddav->set_auth('username', 'password');
 * $carddav->get();
 * var_dump($carddav->get_debug());
 *
 *
 * CardDAV server list
 * -------------------
 * DAViCal:						https://example.com/{resource|principal|username}/{collection}/
 * Apple Addressbook Server:	https://example.com/addressbooks/users/{resource|principal|username}/{collection}/
 * memotoo:						https://sync.memotoo.com/cardDAV/
 * SabreDAV:					https://example.com/addressbooks/{resource|principal|username}/{collection}/
 * ownCloud:					https://example.com/apps/contacts/carddav.php/addressbooks/{resource|principal|username}/{collection}/
 * SOGo:						https://example.com/SOGo/dav/{resource|principal|username}/Contacts/{collection}/
 *
 *
 * @author Christian Putzke <christian.putzke@graviox.de>
 * @copyright Christian Putzke
 * @link http://www.graviox.de/
 * @link https://twitter.com/cputzke/
 * @since 20.07.2011
 * @version 0.6
 * @license http://www.gnu.org/licenses/agpl.html GNU AGPL v3 or later
 *
 */

class carddav_backend
{
    /**
     * CardDAV PHP Version
     *
     * @constant	string
     */
    const VERSION = '0.6';

    /**
     * User agent displayed in http requests
     *
     * @constant	string
     */
    const USERAGENT = 'CardDAV PHP/';

    /**
     * CardDAV server url
     *
     * @var	string
     */
    private $url = null;

    /**
     * CardDAV server url_parts
     *
     * @var	array
     */
    private $url_parts = null;

    /**
     * CardDAV server folder
     *
     * @var string
     */
    private $folder = '';
    
    /**
     * Authentication string
     *
     * @var	string
     */
    private $auth = null;

    /**
     * Authentication: username
     *
     * @var	string
     */
    private $username = null;

    /**
     * Authentication: password
     *
     * @var	string
     */
    private $password = null;

    /**
     * Characters used for vCard id generation
     *
     * @var	array
     */
    private $vcard_id_chars = array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 'A', 'B', 'C', 'D', 'E', 'F');

    /**
     * CardDAV server connection (curl handle)
     *
     * @var	resource
     */
    private $curl;

    /**
     * Debug on or off
     *
     * @var	boolean
     */
    private $debug = false;

    /**
     * All available debug information
     *
     * @var	array
     */
    private $debug_information = array();
    
    
    /**
     * Sync-token for sync-collection operations.
     *
     * @var string
     */
    private $synctoken = "";

    /**
     * Exception codes
     */
    const EXCEPTION_WRONG_HTTP_STATUS_CODE_GET				= 1000;
    const EXCEPTION_WRONG_HTTP_STATUS_CODE_GET_VCARD		= 1001;
    const EXCEPTION_WRONG_HTTP_STATUS_CODE_GET_XML_VCARD	= 1002;
    const EXCEPTION_WRONG_HTTP_STATUS_CODE_DELETE			= 1003;
    const EXCEPTION_WRONG_HTTP_STATUS_CODE_ADD				= 1004;
    const EXCEPTION_WRONG_HTTP_STATUS_CODE_UPDATE			= 1005;
    const EXCEPTION_MALFORMED_XML_RESPONSE					= 1006;
    const EXCEPTION_COULD_NOT_GENERATE_NEW_VCARD_ID			= 1007;


    /**
     * Constructor
     * Sets the CardDAV server url
     *
     * @param	string	$url	CardDAV server url
     */
    public function __construct($url = null)
    {
        if ($url !== null)
        {
            $this->set_url($url);
        }
    }

    /**
     * Sets debug information
     *
     * @param	array	$debug_information		Debug information
     * @return	void
     */
    public function set_debug(array $debug_information)
    {
        $this->debug_information[] = $debug_information;
    }

    /**
     * Sets the CardDAV server url
     *
     * @param	string	$url	CardDAV server url
     * @return	void
     */
    public function set_url($url)
    {
        $this->url = $url;

        if (substr($this->url, -1, 1) !== '/')
        {
            $this->url = $this->url . '/';
        }

        $this->url_parts = parse_url($this->url);
    }

    /**
     * Sets authentication information
     *
     * @param	string	$username	CardDAV server username
     * @param	string	$password	CardDAV server password
     * @return	void
     */
    public function set_auth($username, $password)
    {
        $this->username	= $username;
        $this->password	= $password;
        $this->auth		= $username . ':' . $password;
    }

    /**
     * Gets all available debug information
     *
     * @return	array	$this->debug_information	All available debug information
     */
    public function get_debug()
    {
        return $this->debug_information;
    }
    
    /**
     * Get the sync-token
     *
     * @return string sync-token
     */
    public function get_synctoken()
    {
        return $this->synctoken;
    }
    

    public function set_folder($f)
    {
        $this->folder = $f;
    } 


    private function store_state($data, $token)
    {
        $id = md5($this->url . ':' . $this->username . ':' . $this->folder);
        $d = serialize($data);
        //FIXME: we shouldn't save the file in this level, but in the device folder
        if (!is_dir(STATE_DIR.'/cachesync'))
            mkdir(STATE_DIR.'/cachesync');
            
        file_put_contents(STATE_DIR.'/cachesync/'.$id.'.data', serialize($data));
        file_put_contents(STATE_DIR.'/cachesync/'.$id.'.token', $token);
    }

    private function get_state()
    {
        $id = md5($this->url . ':' . $this->username . ':' . $this->folder);
        //FIXME: we shouldn't save the file in this level, but in the device folder
        if (!is_file(STATE_DIR.'/cachesync/'.$id.'.data') || !is_file(STATE_DIR.'/cachesync/'.$id.'.token'))
            return array(null, '');

        $data = unserialize(file_get_contents(STATE_DIR.'/cachesync/'.$id.'.data'));
        $token = file_get_contents(STATE_DIR.'/cachesync/'.$id.'.token');
        
        return array($data, $token);
    }

    /**
     * Gets all vCards including additional information from the CardDAV server
     *
     * @param	boolean	$include_vcards		Include vCards within the response (simplified only)
     * @param	boolean	$raw				Get response raw or simplified
     * @return	string						Raw or simplified XML response
     */
    public function get($include_vcards = true, $raw = false)
    {
        $result = $this->query($this->url, 'PROPFIND');

        switch ($result['http_code'])
        {
            case 200:
            case 207:
                if ($raw === true)
                {
                    return $result['response'];
                }
                else
                {
                    return $this->simplify($result['response'], $include_vcards);
                }
            break;

            default:
                throw new Exception('Woops, something\'s gone wrong! The CardDAV server returned the http status code ' . $result['http_code'] . '.', self::EXCEPTION_WRONG_HTTP_STATUS_CODE_GET);
            break;
        }
    }
    
    /**
     * Get all vcards matching a full name or mail.
     *
     * @param   string $pattern             Pattern to search
     * @param   integer $limit              Return only N vcards
     * @param   boolean $include_vcards     Include vCards within the response (simplified only)
     * @param   boolean $raw                Get response raw or simplified
     * @return  string                      Raw or simplified XML response
     */
    public function search_vcards($pattern, $limit, $include_vcards = true, $raw = false)
    {
        $xml = <<<EOFCONTENTSEARCH
<?xml version="1.0" encoding="utf-8" ?>
<C:addressbook-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">
    <D:prop>
        <D:getetag/>
        <C:address-data>
            <C:allprop/>
        </C:address-data>
    </D:prop>
    <C:filter test="anyof">
        <C:prop-filter name="FN">
            <C:text-match collation="i;unicode-casemap" negate-condition="no" match-type="contains">$pattern</C:text-match>
        </C:prop-filter>
    </C:filter>
    <C:limit>
        <C:nresults>$limit</C:nresults>
    </C:limit>
</C:addressbook-query>
EOFCONTENTSEARCH;

        return $this->do_query_report($xml, $include_vcards, $raw);
    }
    
    /**
     * Get all vcards or changes since the last sync.
     *
     * @param boolean $initial              If the sync should be full
     * @param boolean $include_vcards       If the vCards should be included within the response
     * @param boolean $support_carddav_sync If the cardDAV server supports sync-collection operations (DAViCal supports it)
     * @return string                       Simplified XML response
     */
    public function do_sync($initial = true, $include_vcards = false, $support_carddav_sync = false)
    {
        if ($support_carddav_sync) {
            $token = $this->synctoken;
            if ($initial)
            {
                $token = "";
            }
            
            $xml = <<<EOFXMLINITIALSYNC
<?xml version="1.0" encoding="utf-8"?>
<D:sync-collection xmlns:D="DAV:">
    <D:sync-token>$token</D:sync-token>
    <D:sync-level>1</D:sync-level>
    <D:prop>
        <D:getetag/>
        <D:getlastmodified/>
    </D:prop>
</D:sync-collection>
EOFXMLINITIALSYNC;

            return $this->do_query_report($xml, $include_vcards, false);
        }
        else
        {
            $response = $this->clean_response($this->get(false, true));
            try
            {
                $xml = new SimpleXMLElement($response);
            }
            catch(Exception $e)
            {
                throw new Exception('The XML response seems to be malformed and can\'t be simplified!', self::EXCEPTION_MALFORMED_XML_RESPONSE, $e);
            }
         
            $simplified_xml = new XMLWriter();
            $simplified_xml->openMemory();
            $simplified_xml->setIndent(4);
            $simplified_xml->startDocument('1.0', 'utf-8');
            $simplified_xml->startElement('response');
 
            if (!empty($xml->response))
            {
                foreach ($xml->response as $response)
                {
                    if (!(preg_match('/vcard/', $response->propstat->prop->getcontenttype) || preg_match('/vcf/', $response->href))
                            && !(preg_match('/unix-directory/', $response->propstat->prop->getcontenttype)))
                    {
                        $save_url = $this->url;
                        if (isset($response->propstat->prop->href))
                        {
                            $href = $response->propstat->prop->href;
                        }
                        else if (isset($response->href))
                        {
                            $href = $response->href;
                        }
                        else
                        {
                            $href = null;
                            continue;
                        }
                        $new_url = str_replace($this->url_parts['path'], null, $this->url) . $href;
                        if (!preg_match('/'.$this->folder.'.?$/', $new_url)) 
                            continue;
                        $this->url = $new_url;
                        if ($save_url !== $this->url)
                        {
                            $resp = $this->get(false, true);
                            $tmp = $this->simplify($resp, $include_vcards, true);
                            $simplified_xml->writeRaw($tmp->outputMemory());
                            unset($tmp);
                        }
                        $this->url = $save_url;
                    }
                }
            }

            $simplified_xml->endElement();
            $simplified_xml->endDocument();

            $ret = $simplified_xml->outputMemory();
            unset($simplified_xml);
            list ($cache, $token) = $this->get_state();

            $t = md5($ret);
            $this->synctoken = $t;
            if ($t !== $token)
            {
                $val_return = $this->do_diff($ret, $cache, $include_vcards);
                $this->store_state($ret, $t);
            }
            else
            {
                if ($initial)
                {
                    $val_return = $ret;
                }
                else
                {
                    $tmp = new XMLWriter();
                    $tmp->openMemory();
                    $tmp->setIndent(4);
                    $tmp->startDocument('1.0', 'utf-8');
                    $tmp->startElement('response');
                    $tmp->endElement();
                    $tmp->endDocument();
                    $val_return = $tmp->outputMemory();
                    unset($tmp);
                }
            }

            return $val_return; 
        }
    }
    
    
    /**
     * Compare 2 vcards to get differences.
     *
     * @param string $x1
     * @param string $x2
     * @param boolean $include_vcards
     * @return string
     */
    private function do_diff($x1, $x2, $include_vcards = false)
    {
        if ($x2 === null)
            return $x1;
        
        $ret = new XMLWriter();
        $ret->openMemory();
        $ret->setIndent(4);
        $ret->startDocument('1.0', 'utf-8');
        $ret->startElement('response');

        $xml1 = new SimpleXMLElement($x1);
        $xml2 = new SimpleXMLElement($x2);
        foreach ($xml1->element as $vc1)
        {
            foreach ($xml2->element as $vc2)
            {
                if ($xml1->id === $xml2->id)
                {
                    if ($xml1->last_modified !== $xml2->last_modified)
                    {
                        $ret->startElement('element');
                        $ret->writeElement('id', $xml1->id);
                        $ret->writeElement('etag', $xml1->id);
                        $ret->writeElement('last_modified', $xml1->last_modified);

                        if ($include_vcards === true)
                        {
                            $ret->writeElement('vcard', $xml1->vcard);
                        }
                        $ret->endElement();
                    }
                }
            }
        }
        unset($xml1);
        unset($xml2);

        $ret->endElement();
        $ret->endDocument();

        return $ret->outputMemory();
    }

    
    /**
     * Do a REPORT query against the server
     *
     * @param string $xml               XML body request
     * @param boolean $include_vcards   If the vCards should be included within the response
     * @param boolean $raw              If the response should be raw or XML simplified
     * @return string
     */
    private function do_query_report($xml, $include_vcards = true, $raw = false)
    {
        $result = $this->query($this->url, 'REPORT', $xml, 'text/xml');

        switch ($result['http_code'])
        {
            case 200:
            case 207:
                if ($raw === true)
                {
                    return $result['response'];
                }
                else
                {
                    return $this->simplify($result['response'], $include_vcards);
                }
            break;

            default:
                throw new Exception('Woops, something\'s gone wrong! The CardDAV server returned the http status code ' . $result['http_code'] . '.', self::EXCEPTION_WRONG_HTTP_STATUS_CODE_GET);
            break;
        }
    }

    /**
     * Gets a clean vCard from the CardDAV server
     *
     * @param	string	$vcard_id	vCard id on the CardDAV server
     * @return	string				vCard (text/vcard)
     */
    public function get_vcard($vcard_id)
    {
        $vcard_id = str_replace('.vcf', null, $vcard_id);
        $result = $this->query($this->url .'/' . $this->folder . '/' . $vcard_id . '.vcf', 'GET');
  

        switch ($result['http_code'])
        {
            case 404:
                $result = $this->query($this->url . $vcard_id, 'GET');
            case 200:
            case 207:
                return $result['response'];
            break;

            default:
                throw new Exception('Woops, something\'s gone wrong! The CardDAV server returned the http status code ' . $result['http_code'] . '.', self::EXCEPTION_WRONG_HTTP_STATUS_CODE_GET_VCARD);
            break;
        }
    }

    /**
     * Gets a vCard + XML from the CardDAV Server
     *
     * @param	string		$vcard_id	vCard id on the CardDAV Server
     * @return	string					Raw or simplified vCard (text/xml)
     */
    public function get_xml_vcard($vcard_id)
    {
        $href = $this->url_parts['path'] . '/' . $this->folder . '/' . str_replace('.vcf', null, $vcard_id) . '.vcf';
 
        
        $xml = <<<EOFXMLGETXMLVCARD
<?xml version="1.0" encoding="utf-8" ?>
<C:addressbook-multiget xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">
    <D:prop>
        <D:getetag/>
        <D:getlastmodified/>
    </D:prop>
    <D:href>$href</D:href>
</C:addressbook-multiget>
EOFXMLGETXMLVCARD;

        return $this->do_query_report($xml);
    }

    /**
     * Enables the debug mode
     *
     * @return	void
     */
    public function enable_debug()
    {
        $this->debug = true;
    }

    /**
     * Checks if the CardDAV server is reachable
     *
     * @return	boolean
     */
    public function check_connection()
    {
        $result = $this->query($this->url, 'OPTIONS');

        if ($result['http_code'] === 200)
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    /**
     * Cleans the vCard
     *
     * @param	string	$vcard	vCard
     * @return	string	$vcard	vCard
     */
    private function clean_vcard($vcard)
    {
        $vcard = str_replace("\t", null, $vcard);

        return $vcard;
    }

    /**
     * Deletes an entry from the CardDAV server
     *
     * @param	string	$vcard_id	vCard id on the CardDAV server
     * @return	boolean
     */
    public function delete($vcard_id)
    {
        $result = $this->query($this->url . '/' . $this->folder . '/' . $vcard_id . '.vcf', 'DELETE');


        switch ($result['http_code'])
        {
            case 204:
                return true;
            break;

            default:
                throw new Exception('Woops, something\'s gone wrong! The CardDAV server returned the http status code ' . $result['http_code'] . '.', self::EXCEPTION_WRONG_HTTP_STATUS_CODE_DELETE);
            break;
        }
    }

    /**
     * Adds an entry to the CardDAV server
     *
     * @param	string	$vcard		vCard
     * @param	string	$vcard_id	vCard id on the CardDAV server
     * @return	string			The new vCard id
     */
    public function add($vcard, $vcard_id = null)
    {
        if ($vcard_id === null)
        {
            $vcard_id	= $this->generate_vcard_id();
        }

        $vcard = str_replace("\nEND:VCARD","\nUID:" . $vcard_id . "\r\nEND:VCARD", $vcard);
        $result = $this->query($this->url . '/' . $this->folder . '/' . $vcard_id . '.vcf', 'PUT', $vcard, 'text/vcard');


        switch($result['http_code'])
        {
            case 201:
            case 204:
                return $vcard_id;
            break;

            default:
                throw new Exception('Woops, something\'s gone wrong! The CardDAV server returned the http status code ' . $result['http_code'] . '.', self::EXCEPTION_WRONG_HTTP_STATUS_CODE_ADD);
            break;
        }
    }

    /**
     * Updates an entry to the CardDAV server
     *
     * @param	string	$vcard		vCard
     * @param	string	$vcard_id	vCard id on the CardDAV server
     * @return	boolean
     */
    public function update($vcard, $vcard_id)
    {
        try
        {
            return $this->add($vcard, $vcard_id);
        }
        catch (Exception $e)
        {
            throw new Exception($e->getMessage(), self::EXCEPTION_WRONG_HTTP_STATUS_CODE_UPDATE);
        }
    }

    /**
     * Simplify CardDAV XML response
     *
     * @param	string	$response			CardDAV XML response
     * @param	boolean	$include_vcards		Include vCards or not
     * @param   boolean $recursive          is it a recursive call 
     * @return	string						Simplified CardDAV XML response
     */
    private function simplify($response, $include_vcards = true, $recursive = false)
    {
        $response = $this->clean_response($response);

        try
        {
            $xml = new SimpleXMLElement($response);
        }
        catch(Exception $e)
        {
            throw new Exception('The XML response seems to be malformed and can\'t be simplified!', self::EXCEPTION_MALFORMED_XML_RESPONSE, $e);
        }
        
        if (!empty($xml->{'sync-token'})) 
        {
            $this->synctoken = $xml->{'sync-token'};
        }

        $simplified_xml = new XMLWriter();
        $simplified_xml->openMemory();
        $simplified_xml->setIndent(4);

        if (!$recursive)
        {
            $simplified_xml->startDocument('1.0', 'utf-8');
            $simplified_xml->startElement('response');
        } 

        if (!empty($xml->response))
        {
            foreach ($xml->response as $response)
            {
                if (preg_match('/vcard/', $response->propstat->prop->getcontenttype) || preg_match('/vcf/', $response->href))
                {
                    $id = basename($response->href);
                    $id = str_replace('.vcf', null, $id);

                    if (!empty($id))
                    {
                        $simplified_xml->startElement('element');
                        $simplified_xml->writeElement('id', $id);
                        $simplified_xml->writeElement('etag', str_replace('"', null, $response->propstat->prop->getetag));
                        $simplified_xml->writeElement('last_modified', $response->propstat->prop->getlastmodified);

                        if ($include_vcards === true)
                        {
                            $simplified_xml->writeElement('vcard', $this->get_vcard($id));
                        }
                        $simplified_xml->endElement();
                    }
                }
                else if (preg_match('/unix-directory/', $response->propstat->prop->getcontenttype))
                {
                    if (isset($response->propstat->prop->href))
                    {
                        $href = $response->propstat->prop->href;
                    }
                    else if (isset($response->href))
                    {
                        $href = $response->href;
                    }
                    else
                    {
                        $href = null;
                    }

                    $url = str_replace($this->url_parts['path'], null, $this->url) . $href;
                    $simplified_xml->startElement('addressbook_element');
                    $simplified_xml->writeElement('display_name', $response->propstat->prop->displayname);
                    $simplified_xml->writeElement('url', $url);
                    $simplified_xml->writeElement('last_modified', $response->propstat->prop->getlastmodified);
                    $simplified_xml->endElement();
                }
            }
        }

        if (!$recursive)
        {
            $simplified_xml->endElement();
            $simplified_xml->endDocument();
            return $simplified_xml->outputMemory();
        }
        else
        {
            return $simplified_xml;
        }
    }

    /**
     * Cleans CardDAV XML response
     *
     * @param	string	$response	CardDAV XML response
     * @return	string	$response	Cleaned CardDAV XML response
     */
    private function clean_response($response)
    {
        $response = utf8_encode($response);
        $response = str_replace('D:', null, $response);
        $response = str_replace('d:', null, $response);
        $response = str_replace('C:', null, $response);
        $response = str_replace('c:', null, $response);

        return $response;
    }

    /**
     * Curl initialization
     *
     * @return void
     */
    public function curl_init()
    {
        if (empty($this->curl))
        {
            $this->curl = curl_init();
            curl_setopt($this->curl, CURLOPT_HEADER, true);
            curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->curl, CURLOPT_USERAGENT, self::USERAGENT.self::VERSION);

            if ($this->auth !== null)
            {
                curl_setopt($this->curl, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
                curl_setopt($this->curl, CURLOPT_USERPWD, $this->auth);
            }
        }
    }

    /**
     * Query the CardDAV server via curl and returns the response
     *
     * @param	string	$url				CardDAV server URL
     * @param	string	$method				HTTP method like (OPTIONS, GET, HEAD, POST, PUT, DELETE, TRACE, COPY, MOVE)
     * @param	string	$content			Content for CardDAV queries
     * @param	string	$content_type		Set content type
     * @return	array						Raw CardDAV Response and http status code
     */
    private function query($url, $method, $content = null, $content_type = null)
    {
        $this->curl_init();

        curl_setopt($this->curl, CURLOPT_URL, $url);
        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $method);

        if ($content !== null)
        {
            curl_setopt($this->curl, CURLOPT_POST, true);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $content);
        }
        else
        {
            curl_setopt($this->curl, CURLOPT_POST, false);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, null);
        }

        if ($content_type !== null)
        {
            curl_setopt($this->curl, CURLOPT_HTTPHEADER, array('Content-type: '.$content_type));
        }
        else
        {
            curl_setopt($this->curl, CURLOPT_HTTPHEADER, array());
        }

        $complete_response	= curl_exec($this->curl);
        $header_size		= curl_getinfo($this->curl, CURLINFO_HEADER_SIZE);
        $http_code 			= curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
        $header				= trim(substr($complete_response, 0, $header_size));
        $response			= substr($complete_response, $header_size);

        $return = array(
            'response'		=> $response,
            'http_code'		=> $http_code
        );

        if ($this->debug === true)
        {
            $debug = $return;
            $debug['url']			= $url;
            $debug['method']		= $method;
            $debug['content']		= $content;
            $debug['content_type']	= $content_type;
            $debug['header']		= $header;
            $this->set_debug($debug);
        }

        return $return;
    }

    /**
     * Returns a valid and unused vCard id
     *
     * @return	string	$vcard_id	Valid vCard id
     */
    private function generate_vcard_id()
    {
        $vcard_id = null;

        for ($number = 0; $number <= 25; $number ++)
        {
            if ($number == 8 || $number == 17)
            {
                $vcard_id .= '-';
            }
            else
            {
                $vcard_id .= $this->vcard_id_chars[mt_rand(0, (count($this->vcard_id_chars) - 1))];
            }
        }

        try
        {
            $carddav = new carddav_backend($this->url);
            $carddav->set_auth($this->username, $this->password);

            $result = $carddav->query($this->url . '/' . $this->folder . '/' . $vcard_id . '.vcf', 'GET'); 

            if ($result['http_code'] !== 404)
            {
                $vcard_id = $this->generate_vcard_id();
            }

            return $vcard_id;
        }
        catch (Exception $e)
        {
            throw new Exception($e->getMessage(), self::EXCEPTION_COULD_NOT_GENERATE_NEW_VCARD_ID);
        }
    }

    /**
     * Destructor
     * Close curl connection if it's open
     *
     * @return	void
     */
    public function __destruct()
    {
        if (!empty($this->curl))
        {
            curl_close($this->curl);
        }
    }
}
