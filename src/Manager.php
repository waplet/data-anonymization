<?php

namespace Maris;


class Manager
{
    protected $capsule = null;
    protected $capsuleConfig = [
        'base' => null,
        'destination' => null,
        'information_schema' => null
    ];
    public function __construct(\Illuminate\Database\Capsule\Manager $capsule)
    {
        $this->capsule = $capsule;
    }

    public function setPrimary(array $connection)
    {
        $this->capsuleConfig['base'] = $connection;
    }

    public function setDestination(array $connection)
    {
        $this->capsuleConfig['destination'] = $connection;
    }

    public function setInformationSchema(array $connection)
    {
        $this->capsuleConfig['information_schema'] = $connection;
    }

    public function init()
    {
        foreach($this->capsuleConfig as $key => $config) {
            if($config == null) {
                throw new \ErrorException("Incorrect state of configs");
            }
            $this->capsule->addConnection($config, $key);
        }
    }
}