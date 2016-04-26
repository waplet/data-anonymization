<?php

namespace Maris;


use Illuminate\Database\Schema\Blueprint;

class Manager
{
    protected $capsule = null;
    protected $capsuleConfig = [
        'base' => null,
        'destination' => null,
        'information_schema' => null
    ];
    protected $tableChanges = array();

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

    public function getCapsule($connection)
    {
        return $this->capsule->getConnection($connection);
    }

    public function init()
    {
        foreach($this->capsuleConfig as $key => $config) {
            if($config == null) {
                throw new \ErrorException("Incorrect state of configs");
            }
            $this->capsule->addConnection($config, $key);
        }

        // So only arrays fetched
        $this->capsule->setFetchMode(\PDO::FETCH_ASSOC);
        $this->capsule->setAsGlobal();
        return $this;
    }

    public function table($name, callable $callback)
    {
        $anonymizer = new Anonymizer($name, $callback);
        $this->tableChanges[$name] = $anonymizer->run();

        return $this;
    }

    /**
     * Creates missing tables in destination schema
     * @return $this
     */
    public function prepareTable()
    {
        // foreach table changes
        // add missing tables to the destination
        foreach($this->tableChanges as $tableName => $_) {
            /*
             * If there is base table, skip it.
             */
            if(!$this->capsule->schema('base')->hasTable($tableName)) {
                unset($this->tableChanges[$tableName]);
                continue;
            }
            if($this->capsule->schema('destination')->hasTable($tableName)) {
                continue;
            }

            $createTableSql = $this->getCapsule('base')->selectOne('SHOW CREATE TABLE ' . $this->getCapsule('base')->getTablePrefix() . $tableName)['Create Table'];
            $createTableSql = str_replace("CREATE TABLE `" . $this->getCapsule('base')->getTablePrefix() . $tableName . "`", "CREATE TABLE `" . $this->getCapsule('destination')->getTablePrefix() . $tableName . "`", $createTableSql);
            $this->getCapsule('destination')->statement($createTableSql);
        }

        return $this;
    }

    public function run()
    {
        foreach($this->tableChanges as $table => $anonymizer) {
            $this->applyChanges($anonymizer);
        }
    }

    protected function applyChanges(Anonymizer $anonymizer)
    {
        // Sets capsule so connection and data gathering could be made
        $anonymizer->setCapsule($this->capsule);
        // Collumn Callbacks for each row
        $columnCallbacks = $anonymizer->getColumnCallbacks();
        // Run callbacks which prepare data for columns
        $anonymizer->runPrepare();

        // Gets data from base tables
        $data = $this->capsule->table($anonymizer->table, 'base')->get();
        //prd($anonymizer->getColumnCallbacks());
        //prd($columnCallbacks);

        // Does all the anonimization as specified in Anonymizer
        foreach($data as &$row) {
            foreach($row as $column => &$value) {
                if(array_key_exists($column, $columnCallbacks)) {
                    foreach($columnCallbacks[$column] as $callback) {
                        $callback($value);
                    }
                }
            }
        }

        // Converts array of objects to array of arrays
        $this->capsule->table($anonymizer->table, 'destination')->truncate();
        $this->capsule->table($anonymizer->table, 'destination')->insert($data);
    }

    protected function dataToArray(&$data) {
        foreach($data as &$value) {
            if(is_object($value)) {
                $value = (array)$value;
            }
        }
    }
}