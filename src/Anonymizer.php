<?php

namespace Maris;

class Anonymizer
{
    protected $callback;
    protected $table;

    public function __construct($table, callable $callback)
    {
        $this->table = $table;
        $this->callback = $callback;
    }
}