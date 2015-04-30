<?php


require_once 'vendor/autoload.php';

define('IMAP_MBCONVERT', "UTF-16, UTF-8, ISO-8859-15, ISO-8859-1, Windows-1252");

$mail = file_get_contents('testing/samples/messages/m0017.txt');
$mobj = new Mail_mimeDecode($mail);
unset($mail);
$message = $mobj->decode(array('decode_headers' => false, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));
unset($mobj);

$finalEmail = new Mail_mimePart('', array('content_type' => 'multipart/mixed'));
printf("%s\n", sprintf("BackendIMAP->SendMail(): is a new message or we are replacing mime"));
addTextPartsMessage($finalEmail, $message);
if (isset($message->parts)) {
    printf("%s\n", sprintf("BackendIMAP->SendMail(): we have extra parts"));
    // We add extra parts from the new message
    addExtraSubParts($finalEmail, $message->parts);
}

// We encode the final message
$boundary = '=_' . md5(rand() . microtime());
$finalEmail = $finalEmail->encode($boundary);

$finalHeaders = array('Mime-Version' => '1.0');
// We copy all the headers, minus content_type
printf("%s\n", sprintf("BackendIMAP->SendMail(): Copying new headers"));
foreach ($message->headers as $k => $v) {
    if (strcasecmp($k, 'content-type') != 0 && strcasecmp($k, 'content-transfer-encoding') != 0 && strcasecmp($k, 'mime-version') != 0) {
        $finalHeaders[ucwords($k)] = $v;
    }
}
foreach ($finalEmail['headers'] as $k => $v) {
    $finalHeaders[$k] = $v;
}

$finalBody = "This is a multi-part message in MIME format.\n" . $finalEmail['body'];

unset($sourceMail);
unset($message);
unset($sourceMessage);
unset($finalEmail);

printf("%s\n", sprintf("BackendIMAP->SendMail(): Final mail to send:"));
foreach ($finalHeaders as $k => $v)
    printf("%s\n", sprintf("%s: %s", $k, $v));
printf("\n");
foreach (preg_split("/((\r)?\n)/", $finalBody) as $bodyline)
    printf("%s\n", sprintf("%s", $bodyline));



    /**
     * Add text parts to a mimepart object
     *
     * @param Mail_mimePart $email reference to the object
     * @param Mail_mimeDecode $message reference to the message
     *
     * @access private
     * @return void
     */
    function addTextPartsMessage(&$email, &$message) {
        $htmlBody = $plainBody = '';
        getBodyRecursive($message, "html", $htmlBody);
        getBodyRecursive($message, "plain", $plainBody);

        $altEmail = new Mail_mimePart('', array('content_type' => 'multipart/alternative'));

        if (strlen($htmlBody) > 0) {
            printf("%s\n", sprintf("BackendIMAP->addTextPartsMessage(): The message has HTML body"));
            $altEmail->addSubPart($htmlBody, array('content_type' => 'text/html; charset=utf-8', 'encoding' => 'base64'));
        }
        if (strlen($plainBody) > 0) {
            printf("%s\n", sprintf("BackendIMAP->addTextPartsMessage(): The message has PLAIN body"));
            $altEmail->addSubPart($plainBody, array('content_type' => 'text/plain; charset=utf-8', 'encoding' => 'base64'));
        }

        $boundary = '=_' . md5(rand() . microtime());
        $altEmail = $altEmail->encode($boundary);

        $email->addSubPart($altEmail['body'], array('content_type' => 'multipart/alternative;'."\n".' boundary="'.$boundary.'"'));

        unset($altEmail);

        unset($htmlBody);
        unset($plainBody);
    }

    /**
     * Get all parts in the message with specified type and concatenate them together, unless the
     * Content-Disposition is 'attachment', in which case the text is apparently an attachment
     *
     * @param string        $message        mimedecode message(part)
     * @param string        $message        message subtype
     * @param string        &$body          body reference
     *
     * @access protected
     * @return
     */
    function getBodyRecursive($message, $subtype, &$body) {
        if(!isset($message->ctype_primary)) return;
        if(strcasecmp($message->ctype_primary,"text")==0 && strcasecmp($message->ctype_secondary,$subtype)==0 && isset($message->body))
            $body .= $message->body;

        if(strcasecmp($message->ctype_primary,"multipart")==0 && isset($message->parts) && is_array($message->parts)) {
            foreach($message->parts as $part) {
                if(!isset($part->disposition) || strcasecmp($part->disposition,"attachment"))  {
                    getBodyRecursive($part, $subtype, $body);
                }
            }
        }
    }

    /**
     * Add extra parts (not text; inlined or attached parts) to a mimepart object.
     *
     * @param Mail_mimePart $email reference to the object
     * @param array $parts array of parts
     *
     * @access private
     * @return void
     */
    function addExtraSubParts(&$email, $parts) {
        if (isset($parts)) {
            foreach ($parts as $part) {
                $new_part = null;
                // Only if it's an attachment we will add the text parts, because all the inline/no disposition have been already added
                if (isset($part->disposition) && $part->disposition == "attachment") {
                    printf("%s\n", sprintf("BackendIMAP->addExtraSubParts(): extraSubPart attachment found"));
                    // it's an attachment
                    $new_part = addSubPart($email, $part);
                }
                else {
                    printf("%s\n", sprintf("BackendIMAP->addExtraSubParts(): extraSubPart no attachment found"));
                    if (isset($part->ctype_primary) && $part->ctype_primary != "text" && $part->ctype_primary != "multipart") {
                        printf("%s\n", sprintf("BackendIMAP->addExtraSubParts(): it's not a text part or a multipart"));
                        // it's not a text part or a multipart
                        $new_part = addSubPart($email, $part);
                    }
                }
                if (isset($part->parts)) {
                    printf("%s\n", sprintf("BackendIMAP->addExtraSubParts(): found subparts to my sub-part. Recursive calling"));
                    // We add sub-parts to the new part, not to the main message
                    addExtraSubParts($new_part === null ? $email : $new_part, $part->parts);
                }
            }
        }
    }

    /**
     * Add a subpart to a mimepart object.
     *
     * @param Mail_mimePart $email reference to the object
     * @param object $part message part
     *
     * @access private
     * @return void
     */
    function addSubPart(&$email, $part) {
        //http://tools.ietf.org/html/rfc4021
        $new_part = null;
        $params = array();
        if (isset($part) && isset($email)) {
            if (isset($part->ctype_primary)) {
                $params['content_type'] = $part->ctype_primary;
            }
            if (isset($part->ctype_secondary)) {
                $params['content_type'] .= '/' . $part->ctype_secondary;
            }
            if (isset($part->ctype_parameters)) {
                foreach ($part->ctype_parameters as $k => $v) {
                    if(strcasecmp($k, 'boundary') != 0) {
                        $params['content_type'] .= '; ' . $k . '=' . $v;
                    }
                }
            }
            if (isset($part->disposition)) {
                $params['disposition'] = $part->disposition;
            }
            //FIXME: dfilename => filename
            if (isset($part->d_parameters)) {
                foreach ($part->d_parameters as $k => $v) {
                    $params[$k] = $v;
                }
            }
            foreach ($part->headers as $k => $v) {
                switch($k) {
                    case "content-description":
                        $params['description'] = $v;
                        break;
                    case "content-type":
                    case "content-disposition":
                    case "content-transfer-encoding":
                        // Do nothing, we already did
                        break;
                    case "content-id":
                        $params['cid'] = str_replace('<', '', str_replace('>', '', $v));
                        break;
                    default:
                        $params[$k] = $v;
                        break;
                }
            }

            // If not exist body, the part will be multipart/alternative, so we don't add encoding
            if (!isset($params['encoding']) && isset($part->body)) {
                $params['encoding'] = 'base64';
            }
            // We could not have body; recursive messages
            $new_part = $email->addSubPart(isset($part->body) ? $part->body : "", $params);
            unset($params);
        }

        // return the new part
        return $new_part;
    }