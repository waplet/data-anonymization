<?php

namespace Maris\Anonymizer;

use Maris\Anonymizer\RowModifier;
use Maris\Helper;

trait Functions
{
    /**
     * @param $replace
     * @return $this
     */
    public function replaceWith($replace)
    {
        if(is_callable($replace)) {
            $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = function (RowModifier $column) use ($replace) {
                $column->setValue(call_user_func($replace, $this->faker));
            };
        } else {
            $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = function (RowModifier $column) use ($replace) {
                $column->setValue($replace);
            };
        }

        return $this;
    }

    /**
     * Nullifies string or sets it empty
     * @param bool $null
     * @return $this
     */
    public function nullify($null = true)
    {
        if($null) {
            $this->replaceWith(null);
        } else {
            $this->replaceWith('');
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
        $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = function (RowModifier $column) use ($amplitude) {
            $column->setValue($column->getCurrentValue() + mt_rand(0, $amplitude * 2) - $amplitude); // +- amplitude
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

        $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = function (RowModifier $column) use ($percents) {
            $column->setValue(mt_rand(0, 1) ? ($column->getCurrentValue() * (1.0 + $percents)) : ($column->getCurrentValue() * (1.0 - $percents)));
        };

        return $this;
    }

    /**
     * @param array $constraints
     * @return $this
     */
    public function setConstraints(array $constraints)
    {
        // TODO Implement feature of setting constraint which cannot change during iteration
        $currentColumnName = $this->currentColumn['name'];
        $constraintName = Helper::compact(array($this->currentColumn['name'], $constraints));
        $this->currentColumn['callbacks']['prepare'][] = function() use ($constraintName, $currentColumnName) {
            // TODO: implement this sh'it
        };

        $this->currentColumn['callbacks']['column'][] = function(&$value, RowModifier $row) use ($constraintName, $constraints, $currentColumnName) {
            $value = $this->columnData[$constraintName][$currentColumnName];

            foreach($constraints as $constraintColumn) {
                $row->setColumnValue($constraintName, $this->columnData[$constraintName][$constraintColumn]);
            }
        };

        return $this;
    }
}