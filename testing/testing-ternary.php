<?php

function test_function(&$text) {
    echo "Text is $text\n";
    $text = "Something new";
}

$testing = "Something";
$existing = "Existing";
$nullset = null;

test_function(($testing === null ? $testing : $existing));
test_function(($nullset === null ? $existing : $nullset));

if ($testing === null) {
    test_function($testing);
}
else {
    test_function($existing);
    // $existing should have a new value after this, but it doesn't
}

if ($nullset === null) {
    test_function($existing);
}
else {
    test_function($nullset);
}