<?php
$l = [];
for ($i = 0; $i < 200000; $i++) { $l[] = $i; }
$sq = array_map(fn ($x) => $x * $x, $l);
$even = array_values(array_filter($sq, fn ($x) => $x % 2 == 0));
$sum = array_reduce($even, fn ($a, $b) => $a + $b, 0);
$s = array_slice($l, 0, 20000);
usort($s, fn ($a, $b) => $b <=> $a);
echo $sum . " " . $s[0], "\n";
