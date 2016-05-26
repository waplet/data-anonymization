<?php

namespace Maris\Anonymizer;

use Maris\Anonymizer;
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
        if (is_callable($replace)) {
            $this->addCallback('column', function (RowModifier $column) use ($replace) {
                $column->setValue(call_user_func($replace, $this->faker));
            });
        } else {
            $this->addCallback('column', function (RowModifier $column) use ($replace) {
                $column->setValue($replace);
            });
        }

        return $this;
    }

    /**
     * @param array $dataset
     * @return $this
     */
    public function replaceWithOneOf(array $dataset)
    {
        if (empty($dataset)) {
            return $this->replaceWith('');
        }

        $datasetCount = count($dataset);
        $this->addCallback('column', function (RowModifier $column) use ($dataset, $datasetCount) {
            $randomKey = mt_rand(0, $datasetCount - 1);
            $column->setValue($dataset[$randomKey]);
        });

        return $this;
    }

    /**
     * @param array $dataset
     * @param float $probabilityOfFirst
     * @return $this
     */
    public function replaceYesNo(array $dataset = ['Y', 'N'], $probabilityOfFirst = 0.5)
    {
        if (!$probabilityOfFirst) {
            return $this;
        }

        $this->addCallback('column', function (RowModifier $column) use ($dataset, $probabilityOfFirst) {
            $randomNumber = mt_rand(0, 1 / $probabilityOfFirst - 1);
            $value = $dataset[1];
            if ($randomNumber == 0) {
                $value = $dataset[0];
            }
            $column->setValue($value);
        });

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
        $this->addCallback('prepare', function (RowModifier $row) use ($currentColumnName, $forceChunked) {
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
        });

        $this->addCallback('column', function (RowModifier $column) {
            $count = count($column->columnData[$column->getCurrentColumn()]);
            $column->setValue($column->columnData[$column->getCurrentColumn()][mt_rand(0, $count - 1)]);
        });

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
        $this->addCallback($prepareType, function (RowModifier $row) use ($currentColumnName) {
            $model = Manager::getCapsule()
                ->getConnection('base')
                ->table($this->table)
                ->select($currentColumnName);

            $this->prepareTableWithLimits($model);

            $row->columnData[$currentColumnName] = $model->pluck($currentColumnName);
            shuffle($row->columnData[$currentColumnName]);
        });

        $this->addCallback('column', function (RowModifier $column) use ($currentColumnName) {
            $column->setValue(array_pop($column->columnData[$currentColumnName]));
        });

        return $this;
    }

    /**
     * As long as you understand Anonymizer you may write any calculation you want
     * @param callable $callback
     * @return $this
     */
    public function shuffleCallable(callable $callback)
    {
        $this->addCallback('column', $callback);

        return $this;
    }

    /**
     * Add some noise to integer values
     * @param int $amplitude
     * @param int $amplitudeDistribution distribution factor for variance 1 => [+-$amplitude; 0]
     *      2 => +-$amplitude, [+-$amplitude/2; 0] , etc.
     * @return $this
     */
    public function numberVariance($amplitude, $amplitudeDistribution = null)
    {
        if ($amplitude <= 0) {
            return $this;
        }

        $this->addCallback('column', function (RowModifier $column) use ($amplitude, $amplitudeDistribution) {
            $distortion = Helper::distributedRandom($amplitude, $amplitudeDistribution);
            $column->setValue($column->getCurrentValue() + $distortion); // +- amplitude
        });

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
    public function dateTimeFromInterval(\DateTime $dateStart, \DateTime $dateEnd = null, $format = 'Y-m-d H:i:s')
    {
        if (!$dateEnd) {
            $dateEnd = new \DateTime('now');
        }

        if ($dateStart > $dateEnd) {
            return $this;
        }

        $timeStart = $dateStart->getTimestamp();
        $timeEnd = $dateEnd->getTimestamp();

        $this->addCallback('column', function (RowModifier $column) use ($timeStart, $timeEnd, $format) {
            $randomTime = mt_rand($timeStart, $timeEnd);
            $newDateValue = \DateTime::createFromFormat('u', $randomTime)->format($format);
            $column->setValue($newDateValue);
        });

        return $this;
    }

    /**
     * @param string $modifier day|month|week , anything else DateTime might read
     * @param int $amplitude
     * @param int $distribution
     * @param string $format
     * @return $this
     */
    public function dateTimeModifier($modifier = 'day', $amplitude = 1, $distribution = null, $format = 'Y-m-d H:i:s') {
        $this->addCallback('column', function (RowModifier $column) use ($modifier, $amplitude, $distribution, $format) {
            $currentDateTime = \DateTime::createFromFormat($format, $column->getCurrentValue());
            $randomAmplitude = Helper::distributedRandom($amplitude, $distribution);
            $randomSign = mt_rand(0, 1) ? '+' : '-';
            $newModifier = $randomSign . abs($randomAmplitude) . $modifier;
            $currentDateTime->modify($newModifier);
            $column->setValue($currentDateTime->format($format));
        });

        return $this;
    }

    /**
     * Add relative noise to integer values
     * @param float $percents
     * @param int $distribution
     * @return $this
     */
    public function relativeNumberVariance($percents, $distribution = 1)
    {
        if ($percents < 0 || $percents > 1) {
            $percents = 0.0;
        }

        $this->addCallback('column', function (RowModifier $column) use ($percents, $distribution) {
            $amplitude = $percents * 100;
            $distortionPercent = Helper::distributedRandom($amplitude, $distribution) / 100;
            $column->setValue($column->getCurrentValue() * (1.0 + $distortionPercent));
        });

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
        $this->addCallback($prepareType, function (RowModifier $row) use ($constraint, $currentColumnName, $shuffle) {
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
        });

        /**
         * Constraint is being populated back
         * @param RowModifier $row
         */
        $this->addCallback('column', function (RowModifier $row) use ($constraint) {
            foreach ($constraint as $column) {
                $row->setColumnValue($column, array_pop($row->columnData[$column]));
            }
        });

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
        $this->addCallback($prepareType, function (RowModifier $row) use ($currentColumnName) {

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
        });

        $this->addCallback('column', function (RowModifier $column) use ($currentColumnName) {
            $column->setValue($column->columnData[$currentColumnName]);
        });

        return $this;
    }

    /**
     * @param string $mask
     * @param string $maskingSymbol
     * @return $this
     */
    public function mask($mask = "", $maskingSymbol = '*')
    {
        if (empty($mask)) {
            return $this;
        }

        $this->addCallback('column', function (RowModifier $column) use ($mask, $maskingSymbol) {
            $maskedString = "";
            $currentValue = $column->getCurrentValue();
            for ($i = 0; $i < mb_strlen($mask); $i++) {
                if ($mask[$i] == $maskingSymbol) {
                    $maskedString .= $maskingSymbol;
                    continue;
                }

                if (isset($currentValue[$i])) {
                    $maskedString .= $currentValue[$i];
                }
            }

            $column->setValue($maskedString);
        });

        return $this;
    }

    /**
     * @param array $replacement
     * @param string $mask
     * @param string $maskingSymbol
     */
    public function randomMasking($replacement = [], $mask = null, $maskingSymbol = '*')
    {
        if (empty($replacement)) {
            $replacement = [
                "a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l", "m", "n", "o", "p", "q", "r",
                "s", "t", "u", "v", "w", "x", "y", "z", "0", "1", "2", "3", "4", "5", "6", "7", "8", "9"
            ];
        }

        $this->addCallback('column', function (RowModifier $column) use ($replacement, $mask, $maskingSymbol) {
            $count = count($replacement) - 1;
            $currentValue = $column->getCurrentValue();

            $newValue = "";
            for ($i = 0; $i < mb_strlen($currentValue); $i++) {
                // Allows masked random masking
                if ($mask && isset($mask[$i])) {
                    if ($mask[$i] != $maskingSymbol) {
                        $newValue .= $currentValue[$i];
                        continue;
                    }
                }

                $randomKey = mt_rand(0, $count);
                if ($currentValue[$i] == mb_strtoupper($currentValue[$i])) {
                    $newValue .= mb_strtoupper($replacement[$randomKey]);
                } else {
                    $newValue .= $replacement[$randomKey];
                }
            }

            $column->setValue($newValue);
        });
    }
}