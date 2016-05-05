<?php

namespace Maris;


use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Schema\Blueprint;
use Maris\Anonymizer\RowModifier;

class Manager
{
    /**
     * @var \Illuminate\Database\Capsule\Manager
     */
    protected static $capsule = null;
    protected $capsuleConfig = [
        'base' => null,
        'destination' => null,
        'information_schema' => null
    ];
    protected $tableChanges = array();

    protected $timeStart = null;
    protected $timeSpent = 0;

    public function __construct(\Illuminate\Database\Capsule\Manager $capsule)
    {
        self::setCapsule($capsule);
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

    /**
     * @param $connection
     * @return \Illuminate\Database\Connection|\Illuminate\Database\Capsule\Manager
     */
    public static function getCapsule($connection = null)
    {
        if(!$connection) {
            return self::$capsule;
        }
        return self::$capsule->getConnection($connection);
    }

    public static function setCapsule(\Illuminate\Database\Capsule\Manager  $capsule)
    {
        if(!self::$capsule) {
            self::$capsule = $capsule;
        }
    }

    public function init()
    {
        foreach($this->capsuleConfig as $key => $config) {
            if($config == null) {
                throw new \ErrorException("Incorrect state of configs");
            }
            self::$capsule->addConnection($config, $key);
        }

        // So only arrays fetched
        self::$capsule->setFetchMode(\PDO::FETCH_ASSOC);
        self::$capsule->setAsGlobal();
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
            if(!self::getCapsule()->schema('base')->hasTable($tableName)) {
                unset($this->tableChanges[$tableName]);
                continue;
            }

            if(self::getCapsule()->schema('destination')->hasTable($tableName)) {
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
                ->selectOne('SHOW CREATE TABLE ' . self::getCapsule('base')->getTablePrefix() . $tableName)['Create Table'];
            $createTableSql = str_replace("CREATE TABLE `" . self::getCapsule('base')->getTablePrefix() . $tableName . "`", "CREATE TABLE `" . self::getCapsule('destination')->getTablePrefix() . $tableName . "`", $createTableSql);
            $this->getCapsule('destination')->statement($createTableSql);
        }

        return $this;
    }

    public function run()
    {
        $this->startTime();
        foreach($this->tableChanges as $table => $anonymizer) {
            $this->applyChanges($anonymizer);
        }
        $this->endTime();
        return $this;
    }

    protected function applyChanges(Anonymizer $anonymizer)
    {
        // Column Callbacks for each row
        $callbacks = $anonymizer->getCallbacks();

        // Run callbacks which prepare data for columns
        $rowModifier = new RowModifier($callbacks);
        $rowModifier->runPrepareCallbacks();

        // Gets data from base tables
        $data = self::getCapsule()->table($anonymizer->table, 'base')->get();
        try
        {
            // Does all the anonymization as specified in Anonymizer
            // This is the bottleneck
            foreach($data as &$row) {
                $row = $rowModifier->setRow($row)->run()->getRow();
                if(!$anonymizer->getTruncateDestinationTable()) {
                    if(count($anonymizer->getPrimary()) > 1) {
                        throw new \ErrorException("Primary can't be constraint if no table truncation");
                    } else {
                        unset($row[array_pop($anonymizer->getPrimary())]);
                    }
                }
            }

            // Converts array of objects to array of arrays
            if($anonymizer->getTruncateDestinationTable()) {
                self::getCapsule()->table($anonymizer->table, 'destination')->truncate();
            }
            self::getCapsule()->table($anonymizer->table, 'destination')->insert($data);
        }
        catch (\Exception $ex)
        {
            print("Something went wrong - " . $ex->getMessage());
            error_log($ex->getMessage());
            die;
        }
    }

    protected function dataToArray(&$data) {
        foreach($data as &$value) {
            if(is_object($value)) {
                $value = (array)$value;
            }
        }
    }

    private function startTime()
    {
        $this->timeStart = microtime(true);
    }

    private function endTime()
    {
        $this->timeSpent = microtime(true) - $this->timeStart;
        $this->startTime();
    }

    public function printTime()
    {
        print("Total time spent: " . round($this->timeSpent,6) . " ms");
    }
}