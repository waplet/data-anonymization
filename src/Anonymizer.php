<?php

namespace Maris;

use Faker\Factory;
use Illuminate\Database\Capsule\Manager as Capsule;

class Anonymizer
{
    protected $callback;
    /**
     * @var Capsule
     */
    protected $capsule;
    public $table;
    protected $currentColumn = null;
    protected $columnCallbacks = [];
    protected $prepareCallbacks = [];
    public $columnData = [];
    protected $primaryKey = [];

    /**
     * Generator object (e.g faker).
     *
     * @var mixed
     */
    protected $generator;

    public function __construct($table, callable $callback, $generator = null)
    {
        $this->table = $table;
        $this->callback = $callback;
        $this->setGenerator($generator);
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
    public function setPrimary($key = array('id'))
    {
        if(is_string($key)) {
            $key = [$key];
        }
        $this->primaryKey = $key;
    }

    public function setGenerator($generator)
    {
        if ($generator !== null) {
            $this->generator = $generator;
        }
        if (class_exists('\Faker\Factory')) {
            $this->generator = Factory::create();
        }
        return $this;
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


    public function run()
    {
        $callback = $this->callback;
        $callback($this);

        return $this;
    }

    public function runPrepare()
    {
        foreach($this->prepareCallbacks as $prepare) {
            $prepare();
        }
    }

    /**
     * All the possible functions which creates callbacks
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
                $value = call_user_func($replace, $this->generator);
            };
        } else {
            $this->currentColumn['callbacks'][] = function (&$value) use ($replace) {
                $value = $replace;
            };
        }

        return $this;
    }
    public function shuffleUnique()
    {
        $currentColumnName = $this->currentColumn['name'];
        $this->currentColumn['prepare_callbacks'][] = function () use ($currentColumnName) {
            $this->columnData[$currentColumnName] = $this->objectArrayToArray($this->capsule->getConnection('base')->table($this->table)->select($currentColumnName)->distinct()->get(), $currentColumnName);
        };

        $this->currentColumn['callbacks'][] = function (&$value) use ($currentColumnName) {
            $count = count($this->columnData[$currentColumnName]);
            $value = $this->columnData[$currentColumnName][rand(0, $count - 1)];
        };

        return $this;
    }

    public function shuffleAll()
    {
        $currentColumnName = $this->currentColumn['name'];
        $this->currentColumn['prepare_callbacks'][] = function () use ($currentColumnName) {

            $this->columnData[$currentColumnName] = $this->objectArrayToArray($this->capsule->getConnection('base')->table($this->table)->select($currentColumnName)->get(), $currentColumnName);
            shuffle($this->columnData[$currentColumnName]);
        };

        $this->currentColumn['callbacks'][] = function (&$value) use ($currentColumnName) {
            $value = array_pop($this->columnData[$currentColumnName]); // O(1)
        };

        return $this;
    }


    protected function objectArrayToArray($array, $columnName, $primaryKey = []) {
        $result = array();

        foreach($array as $key => $val) {
            if($primaryKey) {
                $result[$this->compact($primaryKey)] = $val->{$columnName};
            } else {
                $result[] = $val->{$columnName};
            }
        }

        return $result;
    }

    /**
     * @param array $values
     * @return string of concatenated values
     */
    protected function compact(array $values)
    {
        return implode("_", $values);
    }
}