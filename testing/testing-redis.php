<?php

define('IPC_REDIS_IP', 'zpushredis');
define('IPC_REDIS_PORT', 6379);

$redis = new Redis();
$connected = $redis->connect(IPC_REDIS_IP, IPC_REDIS_PORT);
printf("Connected? %s\n", $connected);

$keys = $redis->keys("ZP_TOP|" . "*");
print_r($keys);
printf("Keys is_array? %d, Count %d\n", is_array($keys), count($keys));

$values = $redis->mGet($keys);
print_r($values);
printf("Values is_array? %d, Count %d\n", is_array($values), count($values));
