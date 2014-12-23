<?php

// TODO Win1252/UTF8 functions are deprecated and will be removed sometime
//if the ICS backend is loaded in CombinedBackend and Zarafa > 7
//STORE_SUPPORTS_UNICODE is true and the convertion will not be done
//for other backends.
function utf8_to_windows1252($string, $option = "", $force_convert = false) {
    //if the store supports unicode return the string without converting it
    if (!$force_convert && defined('STORE_SUPPORTS_UNICODE') && STORE_SUPPORTS_UNICODE == true) return $string;

    if (function_exists("iconv")){
        return @iconv("UTF-8", "Windows-1252" . $option, $string);
    }else{
        return utf8_decode($string); // no euro support here
    }
}

function windows1252_to_utf8($string, $option = "", $force_convert = false) {
    //if the store supports unicode return the string without converting it
    if (!$force_convert && defined('STORE_SUPPORTS_UNICODE') && STORE_SUPPORTS_UNICODE == true) return $string;

    if (function_exists("iconv")){
        return @iconv("Windows-1252", "UTF-8" . $option, $string);
    }else{
        return utf8_encode($string); // no euro support here
    }
}

function w2u($string) { return windows1252_to_utf8($string); }
function u2w($string) { return utf8_to_windows1252($string); }

function w2ui($string) { return windows1252_to_utf8($string, "//TRANSLIT"); }
function u2wi($string) { return utf8_to_windows1252($string, "//TRANSLIT"); }

/**
 * @param string $message
 * @deprecated
 */
function debugLog($message) {
    ZLog::Write(LOGLEVEL_DEBUG, $message);
}

// TODO review error handler
function zarafa_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
    $bt = debug_backtrace();
    switch ($errno) {
    case 8192:      // E_DEPRECATED since PHP 5.3.0
        // do not handle this message
        break;

    case E_NOTICE:
    case E_WARNING:
        // TODO check if there is a better way to avoid these messages
        if (stripos($errfile,'interprocessdata') !== false && stripos($errstr,'shm_get_var()') !== false)
            break;
            ZLog::Write(LOGLEVEL_WARN, "$errfile:$errline $errstr ($errno)");
            break;

    default:
        ZLog::Write(LOGLEVEL_ERROR, "trace error: $errfile:$errline $errstr ($errno) - backtrace: ". (count($bt)-1) . " steps");
        for($i = 1, $bt_length = count($bt); $i < $bt_length; $i++) {
            $file = $line = "unknown";
            if (isset($bt[$i]['file'])) $file = $bt[$i]['file'];
            if (isset($bt[$i]['line'])) $line = $bt[$i]['line'];
            ZLog::Write(LOGLEVEL_ERROR, "trace: $i:". $file . ":" . $line. " - " . ((isset($bt[$i]['class']))? $bt[$i]['class'] . $bt[$i]['type']:""). $bt[$i]['function']. "()");
        }
        //throw new Exception("An error occured.");
        break;
    }
}

error_reporting(E_ALL);
set_error_handler("zarafa_error_handler");

//from lib/core/paddingfilter.php
stream_filter_register("padding.*", "padding_filter");

//from stringstreamwrapper.php
stream_wrapper_register(StringStreamWrapper::PROTOCOL, "StringStreamWrapper");
