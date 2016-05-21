<?php

namespace Maris;


use Illuminate\Database\Query\JoinClause;
use Maris\Anonymizer\Checker;
use Maris\Anonymizer\RowModifier;

class Manager
{
    /**
     * @var \Illuminate\Database\Capsule\Manager
     */
    protected static $capsule = null;

    /**
     * @var array
     */
    protected $capsuleConfig = [
        'base' => null,
        'destination' => null,
        'information_schema' => null
    ];

    /**
     * @var Anonymizer[]
     */
    protected $tableChanges = [];

    /**
     * @var int
     */
    private $timeStart = 0;

    /**
     * @var int
     */
    private $timeSpent = 0;

    /**
     * @var Checker
     */
    protected $checker = null;

    /**
     * Manager constructor.
     * @param \Illuminate\Database\Capsule\Manager $capsule
     */
    public function __construct(\Illuminate\Database\Capsule\Manager $capsule)
    {
        self::setCapsule($capsule);
    }

    /**
     * @param array $connection
     * @return $this
     */
    public function setPrimary(array $connection)
    {
        $this->capsuleConfig['base'] = $connection;

        return $this;
    }

    /**
     * @param array $connection
     * @return $this
     */
    public function setDestination(array $connection)
    {
        $this->capsuleConfig['destination'] = $connection;

        return $this;
    }

    /**
     * @param array $connection
     * @return $this
     */
    public function setInformationSchema(array $connection)
    {
        $this->capsuleConfig['information_schema'] = $connection;

        return $this;
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

    /**
     * @param \Illuminate\Database\Capsule\Manager $capsule
     */
    public static function setCapsule(\Illuminate\Database\Capsule\Manager  $capsule)
    {
        if(!self::$capsule) {
            self::$capsule = $capsule;
        }
    }

    /**
     * @return $this
     * @throws \ErrorException
     */
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
        self::$capsule->connection('base')->enableQueryLog();
        return $this;
    }

    /**
     * @param $name
     * @param callable $callback
     * @return $this
     */
    public function table($name, callable $callback)
    {
        $anonymizer = new Anonymizer($name);
        $this->tableChanges[$name] = $anonymizer->init($callback);

        return $this;
    }

    /**
     * Creates missing tables in destination schema
     * TODO: Add different DBMS supports (PostgreSQL, MSSQL, SQLite, ..)
     * Currently works only on mysql
     * @return $this
     */
    protected function prepareTables()
    {
        foreach($this->tableChanges as $tableName => $_) {
            /**
             * If there is no base table, skip it.
             */
            if(!self::getCapsule()->schema('base')->hasTable($tableName)) {
                unset($this->tableChanges[$tableName]);
                continue;
            }

            if(self::getCapsule()->schema('destination')->hasTable($tableName)) {
                /**
                 * If base and destination columns do not match
                 */
                $tableDiff = self::getCapsule('information_schema')
                    ->table('COLUMNS as c')
                    ->selectRaw('SUM(IF(c2.COLUMN_NAME IS NULL, 1, 0)) as diff')
                    ->join('COLUMNS as c2', function(JoinClause $join) use ($tableName) {
                        $join->type = 'LEFT';
                        $join->on('c2.COLUMN_NAME', '=', 'c.COLUMN_NAME');
                        $join->where('c2.TABLE_NAME', '=', self::getCapsule('destination')->getTablePrefix() . $tableName);
                    })
                    ->where('c.TABLE_NAME', '=', self::getCapsule('base')->getTablePrefix() . $tableName)
                    ->value('diff');
                if(!$tableDiff) {
                    continue;
                }

                /**
                 * Dropping old table which are not correct
                 */
                self::getCapsule('destination')->getSchemaBuilder()->drop($tableName);
            }

            /**
             * Creating new table based on real one
             */
            $createTableSql = self::getCapsule('base')
                ->selectOne('SHOW CREATE TABLE `' . self::getCapsule('base')->getTablePrefix() . $tableName . '`')['Create Table'];
            $createTableSql = str_replace("CREATE TABLE `" . self::getCapsule('base')->getTablePrefix() . $tableName . "`", "CREATE TABLE `" . self::getCapsule('destination')->getTablePrefix() . $tableName . "`", $createTableSql);
            self::getCapsule('destination')->statement($createTableSql);
        }

        return $this;
    }


    /**
     * @return $this
     */
    public function run()
    {
        $this->startTime();
        try {
            /**
             * Prepares destination table for data anonymization
             */
            $this->prepareTables();

            foreach($this->tableChanges as $table => $anonymizer) {
                $this->applyChanges($anonymizer);

                if($anonymizer->isCheckTable() && $columnsForChecker = $anonymizer->getColumnsForChecker()) {
                    $this->getChecker()
                        ->setTableName($table)
                        ->setComparableColumns($columnsForChecker)
                        ->check()
                        ->printResults();
                }
            }
        }
        catch (\Exception $ex)
        {
            print("Something went wrong - " . $ex->getMessage());
            pr($ex->getTraceAsString());
            error_log($ex->getMessage());
        }
        $this->endTime();
        return $this;
    }

    /**
     * @param Anonymizer $anonymizer
     * @throws \ErrorException
     */
    protected function applyChanges(Anonymizer $anonymizer)
    {
        // Column Callbacks for each row
        $callbacks = $anonymizer->getCallbacks();

        // Run callbacks which prepare data for columns
        $rowModifier = new RowModifier($callbacks);
        $rowModifier->runPrepareCallbacks();

        /**
         * Truncation
         */
        if($anonymizer->isTruncateDestinationTable()) {
            self::getCapsule()->table($anonymizer->table, 'destination')->truncate();
        }

        do {

            $table = $anonymizer->prepareBaseTable();
            $data = $table->get();

            if(!$data) {
                break;
            }

            $rowModifier->runPrepareChunkedCallbacks();

            // Does all the anonymization as specified in Anonymizer
            // This is the bottleneck
            /**
             * @var array $row
             */
            //pr("Data before callbacking");
            $rowCount = 0;
            foreach($data as &$row) {
                $row = $rowModifier->setRow($row)
                    ->run()
                    ->getRow();
                $rowCount++;
            }
            //pr($data);

            /**
             * Database related changes
             */
            if($anonymizer->isInsert()) {
                $this->doInsert($anonymizer, $data);
            } else {
                $this->doUpdate($anonymizer, $data);
            }

            $anonymizer->incrementOffset($rowCount);
        } while (
            $data   // has data
            && ($anonymizer->getCount() || $anonymizer->getChunkSize()) // and has count or chunksize
            && (!$anonymizer->getCount() || $anonymizer->getCount() && $anonymizer->getCount() > $anonymizer->getOffset()) // and don't have count or count is greater than offset
        );
    }

    /**
     * @param Anonymizer $anonymizer
     * @param $data
     * @throws \ErrorException
     */
    protected function doInsert(Anonymizer $anonymizer, $data)
    {
        //return;
        //insert
        //1) isTruncateTable -> legit;
        //2) !isTruncateTable ->
        //    2.a) count primary > 1 throw new exception
        //    2.b)  unset primary
        if(!$anonymizer->isTruncateDestinationTable()) {
            if(count($anonymizer->getPrimary()) > 1) {
                throw new \ErrorException("Primary can't be constraint if no table truncation");
            } else {
                $singlePrimaryKey = array_pop($anonymizer->getPrimary());
                if($singlePrimaryKey) {
                    foreach($data as &$row) {
                        unset($row[$singlePrimaryKey]);
                    }
                }
            }
        }

        self::getCapsule('destination')
            ->table($anonymizer->table)
            ->insert($data);
    }

    /**
     * @param Anonymizer $anonymizer
     * @param $data
     * @throws \ErrorException
     * @throws \Exception
     * @throws \Throwable
     */
    protected function doUpdate(Anonymizer $anonymizer, $data)
    {
        //update
        //1) isTruncate -> insert
        //2) !isTruncate -> update by primary <=> updateOrInsert
        if($anonymizer->isTruncateDestinationTable()) {
            /**
             * May delete primary keys, but not necessary
             */
            /**
             * Comment out and remove if doInsert does not fit;
             */
            //self::getCapsule('destination')
            //    ->table($anonymizer->table)
            //    ->insert($data);
            $this->doInsert($anonymizer, $data); // will avoid whole if in insert
        } else {
            /**
             * https://laracasts.com/forum/?p=649-bulk-insert-update/p1#p3308
             */
            self::getCapsule('destination')->transaction(function () use ($data, $anonymizer) {
                /**
                 * @var array $row
                 */
                foreach($data as &$row) {
                    $table = self::getCapsule()->table($anonymizer->table, 'destination');
                    $attributes = [];
                    foreach($anonymizer->getPrimary() as $key) {
                        $attributes[$key] = $row[$key];
                        unset($row[$key]);
                    }
                    $table->updateOrInsert($attributes, $row);
                }
            });
        }
    }

    /**
     * Sets start time
     */
    private function startTime()
    {
        $this->timeStart = microtime(true);
    }

    /**
     * Calculates time spent
     */
    private function endTime()
    {
        $this->timeSpent = microtime(true) - $this->timeStart;
    }

    /**
     * Prints calculated time
     */
    public function printTime()
    {
        print("Total time spent: " . round($this->timeSpent,6) . " ms");
        $this->timeSpent = 0;
    }

    /**
     * @return Checker
     */
    public function getChecker()
    {
        if(!$this->checker) {
            $this->checker = new Checker();
        }

        return $this->checker;
    }
}