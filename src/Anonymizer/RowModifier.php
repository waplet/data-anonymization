<?php

namespace Maris\Anonymizer;

class RowModifier
{
    /**
     * @var array
     */
    public $columnData = null;

    /**
     * @var array|null
     */
    public $callbacks = null;

    /**
     * @var string
     */
    public $currentColumn = null;

    /**
     * @var array
    */
    public $row = null;

    public function __construct(array $callbacks = [])
    {
        $this->callbacks = $callbacks;
        return $this;
    }

    public function setRow(array $row)
    {
        $this->row = $row;
        return $this;
    }

    public function getRow()
    {
        return $this->row;
    }

    public function run()
    {
        if(!$this->row) {
            throw new \ErrorException("Incorrect Row modifier state - no row present");
        }

        foreach($this->row as $column => $item) {
            $this->setCurrentColumn($column);
            $this->_executeCallbacks('column', $this->currentColumn);
        }

        $this->_executeCallbacks('after');
        return $this;
    }

    public function setCurrentColumn($column)
    {
        $this->currentColumn = $column;
        return $this;
    }

    public function getCurrentColumn()
    {
        return $this->currentColumn;
    }

    public function setColumnValue($column, $value)
    {
        $this->row[$column] = $value;
        return $this;
    }

    public function setValue($value)
    {
        $this->setColumnValue($this->currentColumn, $value);
        return $this;
    }

    public function getCurrentValue()
    {
        return $this->row[$this->currentColumn];
    }

    public function runPrepareCallbacks()
    {
        foreach($this->callbacks['prepare'] as $prepare) {
            $prepare($this);
        }

        return $this;
    }

    private function _executeCallbacks($type, $column = null)
    {
        if(array_key_exists($type, $this->callbacks)) {
            if($column) {
                if(array_key_exists($column, $this->callbacks[$type])) {
                    $callbacks = $this->callbacks[$type][$column];
                    foreach($callbacks as $callback) {
                        call_user_func($callback, $this);
                    }
                }
            } else {
                $callbacks = $this->callbacks[$type];
                foreach ($callbacks as $callback) {
                    $callback();
                }
            }
        }
    }
}