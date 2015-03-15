<?php

function testing($text) {
	print_r(preg_split('/(\s)*(\\\)?\,(\s)*/i', $text));
//	print_r(preg_split('/(?<!\\\\)(\,)/i', $text));
}

testing('Test A,Test');
testing('Test A , Test');
testing('Test A   , Test   B,   Test C');
testing('Test A \, Test B');