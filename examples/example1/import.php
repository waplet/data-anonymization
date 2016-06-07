<?php
header('Content-Type: text/html; charset=utf-8');
ini_set("max_execution_time", "0");

use Maris\Anonymizer;
use Maris\Manager;

require __DIR__ . './../../vendor/autoload.php';
require __DIR__ . './../../config/config.php';
global $capsules;

$manager = new \Maris\Manager(new \Illuminate\Database\Capsule\Manager());
$manager->setPrimary($capsules['base']);
$manager->setDestination($capsules['destination']);
$manager->setInformationSchema($capsules['information_schema']);
$manager->init();
$filePath = 'birthnames.csv';

$table = Manager::getCapsule('base')->table('jaundzimusie');

$skipFirst = true;

$csv = [];
foreach(file($filePath) as $row)
{
    if($skipFirst) {
        $skipFirst = false;
        continue;
    }
    $row = explode(";", trim($row));
    if(!$row[4] || !$row[7]) {
        // incorrect dates
         continue;
    }
    $insert = [
        'id' => $row[0],
        'reg_place' => $row[1],
        'first_name' => $row[2],
        'other_names' => $row[3],
        'birth_date' => DateTime::createFromFormat('d.m.Y', $row[4])->format('Y-m-d'),
        'sex' => $row[5] ? 'Sieviete' : 'Vīrietis',
        'district_id' => $row[6],
        'active_date' => DateTime::createFromFormat('d.m.Y', $row[7])->format('Y-m-d'),
    ];
    $table->insert($insert);
}