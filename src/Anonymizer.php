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
    protected $isTruncateDestination;

    /**
     * @var Capsule
     */
    protected static $capsule;
    protected $currentColumn = null;
    protected $columnCallbacks = [];
    /**
     * Callback for preparing data
     * @var array
     */
    protected $prepareCallbacks = [];

    /**
     * TODO: Refactor callbacks
     */
    protected $callbacks = [
        'prepare' => [],
        //'beforeColumn' => [],
        'column' => [],
        //'after'  => []
    ];
    /**
     * Place where goes prepared data
     * @var array
     */
    protected $columnData = [];

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

        $this->column(array_pop(array_reverse($this->primaryKey)))->setUniqueConstraints($this->primaryKey);
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

    public function truncateDestinationTable($bool = false)
    {
        $this->isTruncateDestination = $bool;
        return $this;
    }

    public function getTruncateDestinationTable()
    {
        return $this->isTruncateDestination;
    }
}