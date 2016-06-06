<?php

namespace Maris;


class Helper
{
    public static function distributedRandom($amplitude, $distribution = null)
    {
        if(!$distribution) {
            $distribution = $amplitude;
        }

        if($amplitude <= 0) {
            return 0;
        }

        if($distribution < 1) {
            $distribution = 1;
        }

        return (mt_rand(0, $distribution * 2) - $distribution) * $amplitude/$distribution;
    }

    public static function array_shuffle(&$array){
        // shuffle using Fisher-Yates
        $i = count($array);

        while(--$i){
            $j = mt_rand(0,$i);
            if($i != $j){
                // swap items
                $tmp = $array[$j];
                $array[$j] = $array[$i];
                $array[$i] = $tmp;
            }
        }
        return $array;
    }
}