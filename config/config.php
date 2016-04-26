<?php
$capsules = [];
$capsules['base'] = [
    'driver' => 'mysql',
    'host' => 'localhost',
    'database' => 'bd_base',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8',
    'collation' => 'utf8_general_ci',
    'prefix' => '',
];

$capsules['destination'] = [
    'driver' => 'mysql',
    'host' => 'localhost',
    'database' => 'bd_base',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8',
    'collation' => 'utf8_general_ci',
    'prefix' => 'dest_',
];

$capsules['information_schema'] = [
    'driver' => 'mysql',
    'host' => 'localhost',
    'database' => 'information_schema',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8',
    'collation' => 'utf8_general_ci',
    'prefix' => '',
];