<?php

namespace Maris;


class Helper
{
    /**
     * TODO: Remake to just to array to array
     * @param $array
     * @param $columnName
     * @param array $primaryKey
     * @return array
     */
    public static function arrayToPrimarizedArray($array, $columnName, $primaryKey = [])
    {
        $result = array();

        foreach($array as $key => $val) {
            if($primaryKey) {
                $result[self::compact($primaryKey)] = $val->{$columnName};
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
    public static function compact(array $values)
    {
        return implode("_", $values);
    }
}