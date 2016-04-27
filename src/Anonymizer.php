<?php

namespace Maris;

use Faker\Factory;
use Illuminate\Database\Capsule\Manager as Capsule;

class Anonymizer
{
    /**
     * Table's name on which happening anonymization
     * @var string
     */
    public $table;

    /**
     * @var Capsule
     */
    protected $capsule;
    protected $currentColumn = null;
    protected $columnCallbacks = [];
    /**
     * Callback for preparing data
     * @var array
     */
    protected $prepareCallbacks = [];
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

    public function setCapsule(Capsule $capsule)
    {
        $this->capsule = $capsule;
        return $this;
    }

    public function getCapsule()
    {
        return $this->capsule;
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
            'prepare_callbacks' => []
        ];

        return $this;
    }

    public function populateColumns()
    {
        $this->columnCallbacks[$this->currentColumn['name']] = $this->currentColumn['callbacks'];
        $this->prepareCallbacks = array_merge($this->prepareCallbacks, $this->currentColumn['prepare_callbacks']);
        $this->currentColumn = null;
        return $this;
    }

    /**
     * Get all callbacks
     * @return array
     */
    public function getColumnCallbacks()
    {
        /**
         * If last column still left as column, populate it to overall columns
         */
        if($this->currentColumn) {
            $this->populateColumns();
        }

        return $this->columnCallbacks;
    }

    /**
     * Sets primary key constraint
     * @param array $key
     * @return $this
     */
    public function setPrimary($key = array('id'))
    {
        if(is_string($key)) {
            $key = [$key];
        }
        $this->primaryKey = $key;

        // TODO: Add afterPrepare callbacks to remove column callback which are as a constraint
        // call smthing like $this->setConstraint($this->primaryKey);
        return $this;
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

    public function runPrepare()
    {
        foreach($this->prepareCallbacks as $prepare) {
            $prepare();
        }

        return $this;
    }

    /**
     * All the possible functions which creates callbacks
     */

    /**
     * Conversion that does nothing
     * @return $this
     */
    public function doNothing()
    {
        return $this;
    }

    /**
     * @param $replace
     * @return $this
     */
    public function replaceWith($replace)
    {
        if(is_callable($replace)) {
            $this->currentColumn['callbacks'][] = function (&$value) use ($replace) {
                $value = call_user_func($replace, $this->faker);
            };
        } else {
            $this->currentColumn['callbacks'][] = function (&$value) use ($replace) {
                $value = $replace;
            };
        }

        return $this;
    }

    /**
     * Prepares unique values of column values and then takes randomly selected value for each row
     * @return $this
     */
    public function shuffleUnique()
    {
        $currentColumnName = $this->currentColumn['name'];
        $this->currentColumn['prepare_callbacks'][] = function () use ($currentColumnName) {
            $this->columnData[$currentColumnName] = Helper::arrayToPrimarizedArray(
                $this->getCapsule()
                    ->getConnection('base')
                    ->table($this->table)
                    ->select($currentColumnName)
                    ->distinct()
                    ->get(),
                $currentColumnName);
        };

        $this->currentColumn['callbacks'][] = function (&$value) use ($currentColumnName) {
            $count = count($this->columnData[$currentColumnName]);
            $value = $this->columnData[$currentColumnName][rand(0, $count - 1)];
        };

        return $this;
    }

    /**
     * Prepares all values into array and shuffles O(n)
     * When row is callbacked() it just pop last value of array which takes O(1) for each row.
     * @return $this
     */
    public function shuffleAll()
    {
        $currentColumnName = $this->currentColumn['name'];
        $this->currentColumn['prepare_callbacks'][] = function () use ($currentColumnName) {
            // TODO: remake
            $this->columnData[$currentColumnName] = Helper::arrayToPrimarizedArray(
                $this->getCapsule()
                    ->getConnection('base')
                    ->table($this->table)
                    ->select($currentColumnName)
                    ->get(),
                $currentColumnName);
            shuffle($this->columnData[$currentColumnName]);
        };

        $this->currentColumn['callbacks'][] = function (&$value) use ($currentColumnName) {
            $value = array_pop($this->columnData[$currentColumnName]);
        };

        return $this;
    }

    /**
     * TODO: implement usage of manually defined function
     * @param callable $function
     * @return $this
     */
    public function shuffleCallable(callable $function)
    {
        return $this;
    }

    /**
     * Add some noise to integer values
     * @param $amplitude
     * @return $this
     */
    public function noise($amplitude)
    {
        $currentColumnName = $this->currentColumn['name'];
        $this->currentColumn['callbacks'][] = function (&$value) use ($currentColumnName, $amplitude) {
            $value += mt_rand(0, $amplitude * 2) - $amplitude; // +- amplitude
        };

        return $this;
    }

    /**
     * Add relative noise to integer values
     * @param float $percents
     * @return $this
     */
    public function relativeNoise($percents)
    {
        if($percents < 0 || $percents > 1) {
            $percents = 0.0;
        }

        $currentColumnName = $this->currentColumn['name'];

        $this->currentColumn['callbacks'][] = function (&$value) use ($currentColumnName, $percents) {
                $value += mt_rand(0, 1) ? ($value * (1.0 + $percents)) : ($value * (1.0 - $percents));
        };

        return $this;
    }

    public function setConstraints(array $constraints)
    {
        // TODO Implement feature of setting constraint which cannot change during iteration
        return $this;
    }
}