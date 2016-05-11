<?php

namespace Maris\Anonymizer;

use Maris\Anonymizer;
use Maris\Anonymizer\RowModifier;
use Maris\Helper;
use Maris\Manager;

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
     * @param bool $forceChunked
     * @return $this
     */
    public function shuffleUnique($forceChunked = false)
    {
        $currentColumnName = $this->currentColumn['name'];
        $this->currentColumn['callbacks']['prepare'][] = function (RowModifier $row) use ($currentColumnName, $forceChunked) {
            $model = Manager::getCapsule()
                ->getConnection('base')
                ->table($this->table)
                ->select($currentColumnName)
                ->distinct();
            if($forceChunked && $this->getChunkSize()) {
                $model->limit($this->getChunkSize())
                    ->offset($this->getOffset());
            }
            $row->columnData[$currentColumnName] = $model->pluck($currentColumnName);
            shuffle($row->columnData[$currentColumnName]);
        };

        $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = function (RowModifier $column){
            $count = count($column->columnData[$column->getCurrentColumn()]);
            $column->setValue($column->columnData[$column->getCurrentColumn()][mt_rand(0, $count - 1)]);
        };

        return $this;
    }

    /**
     * Prepares all values into array and shuffles O(n)
     * When row is callbacked() it just pop last value of array which takes O(1) for each row.
     * Shuffle all is easily shuffled with chunks
     * @return $this
     */
    public function shuffleAll()
    {
        $currentColumnName = $this->currentColumn['name'];
        $prepareType = $this->getChunkSize() ? 'prepareChunked' : 'prepare';
        $this->currentColumn['callbacks'][$prepareType][] = function (RowModifier $row) use ($currentColumnName) {
            $model = Manager::getCapsule()
                ->getConnection('base')
                ->table($this->table)
                ->select($currentColumnName);
            if($this->getChunkSize()) {
                $model->limit($this->getChunkSize())
                    ->offset($this->getOffset());
            }
            $row->columnData[$currentColumnName] = $model->pluck($currentColumnName);
            shuffle($row->columnData[$currentColumnName]);
        };

        $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = function (RowModifier $column) use ($currentColumnName) {
            $column->setValue(array_pop($column->columnData[$currentColumnName]));
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
     * @param array $columns
     * @param bool $shuffle
     * @return $this
     */
    public function setUniqueConstraints(array $columns, $shuffle = true)
    {
        $currentColumnName = $this->currentColumn['name'];
        $prepareType = $this->getChunkSize() ? 'prepareChunked' : 'prepare';
        $constraint = array_merge(array($currentColumnName), $columns);
        $this->currentColumn['callbacks'][$prepareType][] = function(RowModifier $row) use ($constraint, $currentColumnName, $shuffle) {
            $model = Manager::getCapsule()
                ->getConnection('base')
                ->table($this->table)
                ->select($constraint);
            if($this->getChunkSize()) {
                $model->limit($this->getChunkSize())
                    ->offset($this->getOffset());
            }
                $rows = $model->get();

            if($shuffle) {
                shuffle($rows);
            } else {
                /**
                 * If no shuffling, so to maintain order with array_pop'ing involved later
                 * We should reverse the array now
                 */
                $rows = array_reverse($rows);
            }

            /**
             * Init empty columns for constraint
             */
            foreach($constraint as $column) {
                $row->columnData[$column] = []; // init
            }

            /**
             * Populate columnData with shuffled value but still maintaining constraint
             */
            foreach($rows as $item) {
                foreach($constraint as $column) {
                    $row->columnData[$column][] = $item[$column];
                }
            }

            /**
             * Remove all calculation which may affect constraint
             * except current ones which makes constraint possible
             */
            foreach($constraint as $column) {
                if(!array_key_exists($column, $row->callbacks['column']) || $column == $currentColumnName) {
                    continue;
                }
                unset($row->callbacks['column'][$column]);
            }
        };

        $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = function(RowModifier $row) use ($constraint) {
            foreach ($constraint as $column) {
                $row->setColumnValue($column, array_pop($row->columnData[$column]));
            }
        };

        return $this;
    }
}