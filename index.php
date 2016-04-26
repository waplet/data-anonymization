<?php

use Maris\Anonymizer;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/config.php';
global $capsules;

$manager = new \Maris\Manager(new \Illuminate\Database\Capsule\Manager());
$manager->setPrimary($capsules['base']);
$manager->setDestination($capsules['destination']);
$manager->setInformationSchema($capsules['information_schema']);

$manager->init()
    ->table('base', function (Anonymizer $anonymizer) {

        $anonymizer->setPrimary('id');
        $anonymizer->column('name')->replaceWith('test');
        //$anonymizer->column('name')->replaceWith(function (\Faker\Generator $generator) {
        //    return $generator->email;
        //});
    })
    ->table('test_not_working', function(Anonymizer $anonymizer) {
        return;
    })
    ->prepareTable()
    ->run();