<?php

function SystemExtensionMimeTypes() {
    $out = array();
    $mime_file = '/etc/mime.types';
    if (file_exists($mime_file)) {
        $file = fopen('/etc/mime.types', 'r');
        while(($line = fgets($file)) !== false) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if(!$line)
                continue;
            $parts = preg_split('/\s+/', $line);
            if(count($parts) == 1)
                continue;
            $type = array_shift($parts);
            foreach($parts as $part) {
                if (!isset($out[$type])) {
                    $out[$type] = $part;
                }
            }
        }
        fclose($file);
    }

    return $out;
}

$list = SystemExtensionMimeTypes();
//print_r($list);

$mime_type = 'image/png';
$mime_type = 'image/jpeg';
if (isset($list[$mime_type])) {
    echo "Found $list[$mime_type]\n";
}
else {
    echo "NOT FOUND!\n";
}
