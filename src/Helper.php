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
}