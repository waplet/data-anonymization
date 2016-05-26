<?php

namespace Maris\Anonymizer;

use Maris\Manager;

class Checker
{
    protected $tableName = null;
    protected $comparableColumns  = [];
    protected $results = [];
    protected $duplicateCount = 0;

    public function checkTable($tableName)
    {

    }

    /**
     * @param null $tableName
     * @return Checker
     */
    public function setTableName($tableName)
    {
        $this->tableName = $tableName;
        return $this;
    }

    /**
     * @return null
     */
    public function getTableName()
    {
        return $this->tableName;
    }

    /**
     * @param array $comparableColumns
     * @return Checker
     */
    public function setComparableColumns($comparableColumns)
    {
        $this->comparableColumns = $comparableColumns;
        return $this;
    }

    /**
     * @return array
     */
    public function getComparableColumns()
    {
        return $this->comparableColumns;
    }

    /**
     * Different approach would be if join in mysql level
     * @return $this
     * @throws \Exception
     */
    public function check()
    {
        if(!$this->tableName || !$this->comparableColumns) {
            throw new \Exception("Incorrect checker state");
        }

        $this->reset();
        $this->addResult("Starting to check table " . $this->tableName);
        $baseData = $this->loadData('base');
        $destinationData = $this->loadData('destination');

        foreach($baseData as $baseRow) {
            foreach($destinationData as $destinationRow) {
                if($this->isDuplicate($baseRow, $destinationRow)) {
                    $this->incrementDuplicateCount();
                    $this->addResult("Row is duplicate - " . print_r($baseRow, true));
                    $this->addResult(" \t with row - " . print_r($destinationRow, true));
                }
            }
        }

        $this->addResult("Total duplicates found - " . $this->duplicateCount);

        return $this;
    }

    public function printResults()
    {
        foreach($this->results as $message) {
            printf("%s\n", $message);
        }
    }

    protected function loadData($connection)
    {
        return Manager::getCapsule($connection)
            ->table($this->tableName)
            ->get();
    }

    protected function reset($resetAll = false)
    {

        if ($resetAll) {
            $this->tableName = null;
            $this->comparableColumns = [];
        }
        $this->results = [];
        $this->duplicateCount = 0;

        return $this;
    }

    /**
     * Checks whether row is duplicate on shuffled columns
     * @param $baseRow
     * @param $destinationRow
     * @return bool
     */
    protected function isDuplicate($baseRow, $destinationRow)
    {
        foreach($this->comparableColumns as $column) {
            if($baseRow[$column] != $destinationRow[$column]) {
                return false;
            }
        }

        return true;
    }

    protected function addResult($message)
    {
        $this->results[] = $message;
    }

    protected function incrementDuplicateCount()
    {
        $this->duplicateCount++;
    }

    /**
     * @return int
     */
    public function getDuplicateCount()
    {
        return $this->duplicateCount;
    }
}