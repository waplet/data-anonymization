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
    //->table('kursa_darbs', function (Anonymizer $anonymizer) {
    ->table('base', function (Anonymizer $anonymizer) {
        $anonymizer->setTruncateDestinationTable(true)
            ->setInsert(false)
            ->setChunkSize(4);
            //->setOffset(0)
        ;
        $anonymizer->setPrimary(['id']);
        //$anonymizer->setPrimary(['id', 'name']);
        //$anonymizer->column('name')->nullify(false);
        //$anonymizer->column('name')->shuffleUnique();
        //$anonymizer->column('number')->shuffleAll();
        $anonymizer->column('number')->shuffleAll();
        //$anonymizer->column('name')->replaceWith('test');
        //$anonymizer->column('name')->replaceWith(function (\Faker\Generator $faker) {
        //    return $faker->email;
        //});
        //$anonymizer->column('number')->noise(10);
        //$anonymizer->column('number')->relativeNoise(0.35);
        //$anonymizer->column('name')->setUniqueConstraints(array('number'));
        //$anonymizer->column('number')->setUniqueConstraints(array('name'));
        //$anonymizer->column('number')->shuffleUnique();
    })
    ->prepareTable()
    ->run()
    ->printTime();