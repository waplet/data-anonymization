<?php

namespace Maris;

use Faker\Factory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use Maris\Anonymizer\Functions;

class Anonymizer
{
    use Functions;
    /**
     * Table's name on which happening anonymization
     * @var string
     */
    public $table;
    /**
     * Offset and count related variables for row reading
     */

    /**
     * Size chunk as limit
     * Capsule's limit is checked for values > 0
     * @var int
     */
    protected $chunkSize = 0;
    /**
     * Defined total count of rows to read
     * @var int
     */
    protected $count = 0;
    /**
     * Offset for each chunk iteration
     * @var int
     */
    protected $offset = 0;

    /**
     * @var bool
     */
    protected $truncateDestination = true;

    /**
     * Insert or Update database
     * @var bool
     */
    protected $insert = true;

    /**
     * Whether or not the table should be checked
     * "unshuffled" data
     * @var bool
     */
    protected $checkTable = false;
    /**
     * Data about current managed column
     * @var array
     */
    protected $currentColumn = null;

    /**
     * All the callbacks
     * @var array
     */
    protected $callbacks = [
        'prepare'        => [],
        'prepareChunked' => [],
        'column'         => [],
        //'beforeColumn'   => [],
        //'after'          => []
    ];

    /**
     * Primary key constraint
     * @var array
     */
    protected $primaryKey = [];

    /**
     * Faker object (e.g faker).
     *
     * @var mixed
     */
    protected $faker;

    /**
     * Size of maximum size, before splitting in chunks.
     * May be changed based on Allowed memory size
     */
    const MAX_COUNT_SIZE = 10000;

    /**
     * Anonymizer constructor.
     * @param $table
     * @param null $faker
     */
    public function __construct($table, $faker = null)
    {
        $this->table = $table;
        $this->setFaker($faker);
    }

    /**
     * Sets Faker object
     * @param $faker
     * @return $this
     */
    public function setFaker($faker)
    {
        if ($faker !== null) {
            $this->faker = $faker;
        }
        if (class_exists('\Faker\Factory')) {
            $this->faker = Factory::create();
        }
        return $this;
    }

    /**
     * Starts to define properties for specific column
     * @param $name
     * @return $this
     */
    public function column($name)
    {
        if ($this->currentColumn) {
            $this->populateColumns();
        }

        $this->currentColumn = [
            'name'      => $name,
            'callbacks' => [],
        ];

        return $this;
    }

    /**
     * @return $this
     */
    public function populateColumns()
    {
        $this->callbacks = array_merge_recursive($this->callbacks, $this->currentColumn['callbacks']);
        $this->currentColumn = null;
        return $this;
    }

    /**
     * Get all callbacks
     * @return array
     */
    public function getCallbacks()
    {
        /**
         * If last column still left as column, populate it to overall columns
         */
        if ($this->currentColumn) {
            $this->populateColumns();
        }

        return $this->callbacks;
    }

    /**
     * Sets primary key constraint
     * @param array $key
     * @return $this
     */
    public function setPrimary($key = ['id'])
    {
        if (is_string($key)) {
            $key = [$key];
        }
        $this->primaryKey = $key;


        if (count($this->primaryKey) > 1) {
            $this->column(array_pop(array_reverse($this->primaryKey)))->setUniqueConstraints($this->primaryKey, false);
        }
        return $this;
    }

    /**
     * @return array
     */
    public function getPrimary()
    {
        return $this->primaryKey;
    }

    /**
     * Run the callback on self
     * where is defined all the anonimization functionality
     * @param callable $callback
     * @return $this
     */
    public function init(callable $callback)
    {
        $callback($this);
        $this->fixCount();

        return $this;
    }

    /**
     * @param bool $bool
     * @return $this
     */
    public function setTruncateDestinationTable($bool = true)
    {
        $this->truncateDestination = $bool;
        return $this;
    }

    /**
     * @return bool
     */
    public function isTruncateDestinationTable()
    {
        return $this->truncateDestination;
    }

    /**
     * @param bool $insert
     * @return Anonymizer
     */
    public function setInsert($insert = true)
    {
        $this->insert = $insert;
        return $this;
    }

    /**
     * @return boolean
     */
    public function isInsert()
    {
        return $this->insert;
    }

    /**
     * @param int $chunkSize
     * @return Anonymizer
     */
    public function setChunkSize($chunkSize)
    {
        $this->chunkSize = $chunkSize;
        return $this;
    }

    /**
     * Last chunk size must fit count if defined
     * @return int
     */
    public function getChunkSize()
    {
        return $this->chunkSize;
    }

    /**
     * @return bool
     */
    public function isChunked()
    {
        return (bool)$this->chunkSize;
    }

    /**
     * @param int $count
     * @return Anonymizer
     */
    public function setCount($count)
    {
        $this->count = $count;
        if($this->count > self::MAX_COUNT_SIZE) {
            $this->setChunkSize(self::MAX_COUNT_SIZE);
        }

        if (!$this->isChunked()) {
            $this->setChunkSize($this->count);
        }

        return $this;
    }

    /**
     * @return int
     */
    public function getCount()
    {
        return $this->count;
    }

    /**
     * @param int $offset
     * @return Anonymizer
     */
    public function setOffset($offset)
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * @return int
     */
    public function getOffset()
    {
        return $this->offset;
    }

    /**
     * @param int $number
     */
    public function incrementOffset($number = null)
    {
        if ($this->isChunked()) {
            $this->offset += $this->chunkSize;
        } else {
            $this->offset += $number;
        }
    }

    /**
     * @return Builder
     */
    public function prepareBaseTable()
    {
        $table = Manager::getCapsule('base')
            ->table($this->table);

        $this->prepareTableWithLimits($table);

        return $table;
    }

    /**
     * Add limit and offset for queries
     * @param Builder $table
     */
    public function prepareTableWithLimits(Builder $table)
    {

        if ($this->isChunked()) {
            $chunkSize = $this->chunkSize;
            $offset = $this->offset;

            if ($this->count) {
                if ($chunkSize + $offset > $this->count) {
                    $chunkSize = $this->count - ($offset);
                }
            }

            $table->limit($chunkSize)
                ->offset($offset);
        }
    }

    /**
     * This fixes beginning count with offset
     * Being run when Anonymizer is initiated
     * return $this
     */
    public function fixCount()
    {
        $this->setCount($this->offset + $this->count);
        return $this;
    }

    /**
     * @param boolean $checkTable
     * @return Anonymizer
     */
    public function setCheckTable($checkTable)
    {
        $this->checkTable = $checkTable;
        return $this;
    }

    /**
     * @return boolean
     */
    public function isCheckTable()
    {
        return $this->checkTable;
    }

    /**
     * @return array
     */
    public function getColumnsForChecker()
    {
        $columns = [];
        foreach ($this->callbacks['column'] as $column => $_) {
            $columns[] = $column;
        }

        $columns = array_diff($columns, $this->primaryKey);
        return $columns;
    }

    /**
     * @param string $type
     * @param callable $callback
     * @return $this
     * @throws \Exception
     */
    public function addCallback($type, callable $callback)
    {
        if ($type == 'column') {
            if (!$this->currentColumn) {
                throw new \Exception("Cannot add column callback without column");
            }

            $this->currentColumn['callbacks'][$type][$this->currentColumn['name']][] = $callback;
        } else {
            $this->currentColumn['callbacks'][$type][] = $callback;
        }

        return $this;
    }
}