<?php

namespace Maris\Anonymizer;

use Maris\Anonymizer;
use Maris\Manager;

trait Functions
{
    /**
     * @param $replace
     * @return $this
     */
    public function replaceWith($replace)
    {
        if (is_callable($replace)) {
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

    public function replaceWithOneOf(array $dataset)
    {
        if(empty($dataset)) {
            return $this->replaceWith('');
        }

        $datasetCount = count($dataset);
        $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = function (RowModifier $column) use ($dataset, $datasetCount) {
            $randomKey = mt_rand(0, $datasetCount - 1);
            $column->setValue($dataset[$randomKey]);
        };

        return $this;
    }

    /**
     * Nullifies string or sets it empty
     * @param bool $null
     * @return $this
     */
    public function nullify($null = true)
    {
        if ($null) {
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
        $this->currentColumn['callbacks']['prepare'][] = function (RowModifier $row) use (
            $currentColumnName,
            $forceChunked
        ) {
            $model = Manager::getCapsule()
                ->getConnection('base')
                ->table($this->table)
                ->select($currentColumnName)
                ->distinct();
            if ($forceChunked && $this->getChunkSize()) {
                $model->limit($this->getChunkSize())
                    ->offset($this->getOffset());
            }
            $row->columnData[$currentColumnName] = $model->pluck($currentColumnName);
            shuffle($row->columnData[$currentColumnName]);
        };

        $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = function (RowModifier $column) {
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

            $this->prepareTableWithLimits($model);

            $row->columnData[$currentColumnName] = $model->pluck($currentColumnName);
            shuffle($row->columnData[$currentColumnName]);
        };

        $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = function (RowModifier $column) use ($currentColumnName) {
            $column->setValue(array_pop($column->columnData[$currentColumnName]));
        };

        return $this;
    }

    /**
     * As long as you understand Anonymizer you may write any calculation you want
     * @param callable $callback
     * @return $this
     */
    public function shuffleCallable(callable $callback)
    {
        $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = $callback;
        return $this;
    }

    /**
     * Add some noise to integer values
     * @param $amplitude
     * @return $this
     */
    public function numberVariance($amplitude)
    {
        $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = function (RowModifier $column) use ($amplitude) {
            $column->setValue($column->getCurrentValue() + mt_rand(0, $amplitude * 2) - $amplitude); // +- amplitude
        };

        return $this;
    }

    /**
     * Change column's value to some random
     * @param \DateTime $dateStart
     * @param \DateTime|null $dateEnd
     * @param string $format
     * @return $this
     * @internal param $modifier
     */
    public function dateTimeVariance(\DateTime $dateStart,\DateTime $dateEnd = null, $format = 'Y-m-d H:i:s')
    {
        if (!$dateEnd) {
            $dateEnd = new \DateTime('now');
        }

        if ($dateStart > $dateEnd) {
            return $this;
        }

        $timeStart = $dateStart->getTimestamp();
        $timeEnd = $dateEnd->getTimestamp();

        $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = function (RowModifier $column) use ($timeStart, $timeEnd, $format) {
            $randomTime = mt_rand($timeStart, $timeEnd);
            $newDateValue = \DateTime::createFromFormat('u', $randomTime)->format($format);
            $column->setValue($newDateValue);
        };

        return $this;
    }

    /**
     * @param string $modifier day|month|week , anything else DateTime might read
     * @param int $amplitude
     * @param string $format
     * @return $this
     */
    public function dateTimeModifier($modifier = 'day', $amplitude = 1, $format = 'Y-m-d H:i:s')
    {
        $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = function (RowModifier $column) use ($modifier, $amplitude, $format) {
            $currentDateTime = \DateTime::createFromFormat($format, $column->getCurrentValue());

            $randomAmplitude = mt_rand(0, $amplitude * 2) - $amplitude;
            $randomSign = mt_rand(0,1) ? '+' : '-';
            $newModifier = $randomSign . $randomAmplitude . $modifier;
            $currentDateTime->modify($newModifier); // +5 day
            $column->setValue($currentDateTime->format($format));
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
        if ($percents < 0 || $percents > 1) {
            $percents = 0.0;
        }

        $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = function (RowModifier $column) use ($percents) {
            $column->setValue(mt_rand(0,
                1) ? ($column->getCurrentValue() * (1.0 + $percents)) : ($column->getCurrentValue() * (1.0 - $percents)));
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
        $this->currentColumn['callbacks'][$prepareType][] = function (RowModifier $row) use (
            $constraint,
            $currentColumnName,
            $shuffle
        ) {
            $model = Manager::getCapsule()
                ->getConnection('base')
                ->table($this->table)
                ->select($constraint);

            $this->prepareTableWithLimits($model);

            $rows = $model->get();

            if ($shuffle) {
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
            foreach ($constraint as $column) {
                $row->columnData[$column] = []; // init
            }

            /**
             * Populate columnData with shuffled value but still maintaining constraint
             */
            foreach ($rows as $item) {
                foreach ($constraint as $column) {
                    $row->columnData[$column][] = $item[$column];
                }
            }

            /**
             * Remove all calculation which may affect constraint
             * except current ones which makes constraint possible
             */
            foreach ($constraint as $column) {
                if (!array_key_exists($column, $row->callbacks['column']) || $column == $currentColumnName) {
                    continue;
                }
                unset($row->callbacks['column'][$column]);
            }
        };

        /**
         * Constraint is being populated back
         * @param RowModifier $row
         */
        $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = function (RowModifier $row) use ($constraint) {
            foreach ($constraint as $column) {
                $row->setColumnValue($column, array_pop($row->columnData[$column]));
            }
        };

        return $this;
    }

    /**
     * replace an observed value with the average computed on a small group of units (small aggregate or micro-aggregate),
     * including the investigated one.
     * The units belonging to the same group will be represented in the released file by the same value..
     * http://neon.vb.cbs.nl/casc/mu.htm
     *
     * @return $this
     */
    public function chunkedAggregation()
    {
        $currentColumnName = $this->currentColumn['name'];
        $prepareType = $this->getChunkSize() ? 'prepareChunked' : 'prepare';
        $this->currentColumn['callbacks'][$prepareType][] = function (RowModifier $row) use ($currentColumnName) {

            if ($this->isChunked()) {
                $subModel = Manager::getCapsule()
                    ->getConnection('base')
                    ->table($this->table);
                $this->prepareTableWithLimits($subModel);

                $model = Manager::getCapsule()
                    ->getConnection('base')
                    ->table(Manager::getCapsule('base')->raw('(' . $subModel->toSql() . ') as sub'))
                    ->selectRaw('AVG(' . $currentColumnName . ') as average');
            } else {
                $model = Manager::getCapsule()
                    ->getConnection('base')
                    ->table($this->table)
                    ->selectRaw('AVG(' . $currentColumnName . ') as average');
            }

            $row->columnData[$currentColumnName] = $model->pluck('average')[0];
        };

        $this->currentColumn['callbacks']['column'][$this->currentColumn['name']][] = function (RowModifier $column) use ($currentColumnName) {
            $column->setValue($column->columnData[$currentColumnName]);
        };

        return $this;
    }


}