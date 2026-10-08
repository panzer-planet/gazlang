<?php

class Vec
{
    public $x;

    public $y;

    public function __construct($x, $y)
    {
        $this->x = $x;
        $this->y = $y;
    }

    public function add($o)
    {
        return new Vec($this->x + $o->x, $this->y + $o->y);
    }

    public function dot($o)
    {
        return $this->x * $o->x + $this->y * $o->y;
    }
}
$acc = new Vec(0, 0);
$d = 0;
for ($i = 0; $i < 500000; $i++) {
    $v = new Vec($i, $i % 13);
    $acc = $acc->add($v);
    $d += $v->dot($acc) % 1000;
}
echo $acc->x.' '.$acc->y.' '.$d, "\n";
