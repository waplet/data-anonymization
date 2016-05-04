<?php

namespace Maris;


use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Schema\Blueprint;
use Maris\Anonymizer\RowModifier;

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
        $anonymizer = new Anonymizer($name);
        $this->tableChanges[$name] = $anonymizer->init($callback);

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
             * If there is no base table, skip it.
             */
            if(!$this->capsule->schema('base')->hasTable($tableName)) {
                unset($this->tableChanges[$tableName]);
                continue;
            }

            if($this->capsule->schema('destination')->hasTable($tableName)) {
                /*
                 * If base and destination columns do not fit
                 */
                $tableDiff = $this->getCapsule('information_schema')
                    ->table('COLUMNS as c')
                    ->selectRaw('SUM(IF(c2.COLUMN_NAME IS NULL, 1, 0)) as diff')
                    ->join('COLUMNS as c2', function(JoinClause $join) use ($tableName) {
                        $join->type = 'LEFT';
                        $join->on('c2.COLUMN_NAME', '=', 'c.COLUMN_NAME');
                        $join->where('c2.TABLE_NAME', '=', $this->getCapsule('destination')->getTablePrefix() . $tableName);
                    })
                    ->where('c.TABLE_NAME', '=', $this->getCapsule('base')->getTablePrefix() . $tableName)
                    ->value('diff');
                if(!$tableDiff) {
                    continue;
                }

                /**
                 * Dropping old table which are not correct
                 */
                $this->getCapsule('destination')->getSchemaBuilder()->drop($tableName);
            }

            /**
             * Creating new table based on real one
             */
            $createTableSql = $this->getCapsule('base')
                ->selectOne('SHOW CREATE TABLE ' . $this->getCapsule('base')->getTablePrefix() . $tableName)['Create Table'];
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
        $callbacks = $anonymizer->getCallbacks();

        //prd($callbacks['column']['number'][0]);
        // Run callbacks which prepare data for columns
        $rowModifier = new RowModifier($callbacks);
        $rowModifier->runPrepareCallbacks();

        // Gets data from base tables
        $data = $this->capsule->table($anonymizer->table, 'base')->get();

        // Does all the anonymization as specified in Anonymizer
        // This is the bottleneck
        /**
         * @var array $row
         */
        foreach($data as &$row) {
            $row = $rowModifier->setRow($row)->run()->getRow();
        }

        // Converts array of objects to array of arrays
        pr($data);
        if($anonymizer->getTruncateDestinationTable()) {
            $this->capsule->table($anonymizer->table, 'destination')->truncate();
        }
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