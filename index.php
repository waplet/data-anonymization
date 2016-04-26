<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/config.php';
global $capsules;

$manager = new \Maris\Manager(new \Illuminate\Database\Capsule\Manager());
$manager->setPrimary($capsules['base']);
$manager->setDestination($capsules['destination']);
$manager->setInformationSchema($capsules['information_schema']);

$manager->init()
    ->table('base', function ($anonymizer) {
        return;
    })
    ->prepareTable();