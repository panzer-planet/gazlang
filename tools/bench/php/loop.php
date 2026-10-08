<?php

$sum = 0;
for ($i = 0; $i < 10000000; $i++) {
    $sum += $i * $i % 7;
}
echo $sum, "\n";
