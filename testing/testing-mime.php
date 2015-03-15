<?php

// Recreate a message
// This code is used in BackendIMAP:GetMessage - for MIME format (iOS devices), and will try to fix the encoding

// RUN FROM Z-Push-contrib ROOT FOLDER (NOT FROM TESTING FOLDER)

require_once 'vendor/autoload.php';

define('IMAP_MBCONVERT', "UTF-16, UTF-8, ISO-8859-15, ISO-8859-1, Windows-1252");

$mail = file_get_contents('testing/samples/messages/m0017.txt');
$mobj = new Mail_mimeDecode($mail);
$message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));


function addSubPart(&$email, $part) {
    //http://tools.ietf.org/html/rfc4021
    $new_part = null;
    $params = array();
    if (isset($part)) {
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

function fixCharsetAndAddSubParts(&$email, $part) {
    if (isset($part)) {
        $new_part = null;
        if (isset($part->ctype_parameters['charset'])) {
            $part->ctype_parameters['charset'] = 'UTF-8';
            $new_part = addSubPart($email, $part);
        }
        else {
            $new_part = addSubPart($email, $part);
        }

        if (isset($part->parts)) {
            foreach ($part->parts as $subpart) {
                fixCharsetAndAddSubParts($new_part, $subpart);
            }
        }
    }
}


$boundary = '=_' . md5(rand() . microtime());
$mimeHeaders = Array();
$mimeHeaders['headers'] = Array();
$is_mime = false;
foreach ($message->headers as $key => $value) {
    switch($key) {
        case 'content-type':
            $new_value = $message->ctype_primary . "/" . $message->ctype_secondary;
            $is_mime = (strcasecmp($message->ctype_primary, 'multipart') == 0);

            foreach ($message->ctype_parameters as $ckey => $cvalue) {
                switch($ckey) {
                    case 'charset':
                        $new_value .= '; charset="UTF-8"';
                        break;
                    case 'boundary':
                        // Do nothing, we are encoding also the headers
                        break;
                    default:
                        $new_value .= '; ' . $ckey . '="' . $cvalue . '"';
                        break;
                }
            }

            $mimeHeaders['content_type'] = $new_value;
            break;
        case 'content-transfer-encoding':
            if (strcasecmp($value, "base64") == 0 || strcasecmp($value, "binary") == 0) {
                $mimeHeaders['encoding'] = "base64";
            }
            else {
                $mimeHeaders['encoding'] = "8bit";
            }
            break;
        case 'content-id':
            $mimeHeaders['cid'] = $value;
            break;
        case 'content-location':
            $mimeHeaders['location'] = $value;
            break;
        case 'content-disposition':
            $mimeHeaders['disposition'] = $value;
            break;
        case 'content-description':
            $mimeHeaders['description'] = $value;
            break;
        default:
            if (is_array($value)) {
                foreach($value as $v) {
                    $mimeHeaders['headers'][$key] = $v;
                }
            }
            else {
                $mimeHeaders['headers'][$key] = $value;
            }
            break;
    }
}

$finalEmail = new Mail_mimePart(isset($message->body) ? $message->body : "", $mimeHeaders);
if (isset($message->parts)) {
    foreach ($message->parts as $part) {
        fixCharsetAndAddSubParts($finalEmail, $part);
    }
}
$finalEmail = $finalEmail->encode($boundary);
$headers = "";
foreach ($finalEmail['headers'] as $key => $value) {
    $headers .= "$key: $value\n";
}

if ($is_mime) {
    echo "$headers\nThis is a multi-part message in MIME format.\n".$finalEmail['body'];
}
else {
    echo "$headers\n".$finalEmail['body'];
}

unset($headers);
unset($mimeHeaders);
unset($finalEmail);
unset($message);
unset($mobj);
unset($mail);