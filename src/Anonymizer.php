<?php

namespace Maris;

use Faker\Factory;
use Illuminate\Database\Capsule\Manager as Capsule;
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
     * @var bool
     */
    protected $truncateDestination = true;

    /**
     * @var Capsule
     */
    protected static $capsule;
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
        'prepare' => [],
        //'beforeColumn' => [],
        'column' => [],
        //'after'  => []
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
    public function column($name) {
        if($this->currentColumn) {
            $this->populateColumns();
        }

        $this->currentColumn = [
            'name' => $name,
            'callbacks' => [],
        ];

        return $this;
    }

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
        if($this->currentColumn) {
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
        if(is_string($key)) {
            $key = [$key];
        }
        $this->primaryKey = $key;


        if(count($this->primaryKey) > 1) {
            $this->column(array_pop(array_reverse($this->primaryKey)))->setUniqueConstraints($this->primaryKey, false);
        }
        return $this;
    }

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

        return $this;
    }

    public function setTruncateDestinationTable($bool = true)
    {
        $this->truncateDestination = $bool;
        return $this;
    }

    public function isTruncateDestinationTable()
    {
        return $this->truncateDestination;
    }
}