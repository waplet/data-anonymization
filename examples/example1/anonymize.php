<?php

ini_set("max_execution_time", "0");

use Maris\Anonymizer;

require __DIR__ . './../../vendor/autoload.php';
require __DIR__ . './../../config/config.php';
global $capsules;

$manager = new \Maris\Manager(new \Illuminate\Database\Capsule\Manager());
$manager->setPrimary($capsules['base']);
$manager->setDestination($capsules['destination']);
$manager->setInformationSchema($capsules['information_schema']);

$manager->init()
    ->table('jaundzimusie', function (Anonymizer $anonymizer) {
        $anonymizer->setPrimary('id');
        
        $anonymizer->column('reg_place')->setUniqueConstraints(['district_id']);
        $anonymizer->column('first_name')->setUniqueConstraints(['sex']);
        $anonymizer->column('other_names')->nullify(false);
        $anonymizer->column('birth_date')->dateTimeModifier('week', 10, null, 'Y-m-d');
        $anonymizer->column('active_date')->dateTimeFromInterval(new DateTime('2015-01-01'), new DateTime('2016-03-01'), 'Y-m-d');
    })
    ->run();