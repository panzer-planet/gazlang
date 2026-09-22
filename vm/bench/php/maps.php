<?php

$counts = [];
for ($i = 0; $i < 500000; $i++) {
    $word = 'w'.($i * 31 % 5003);
    $counts[$word] ??= 0;
    $counts[$word]++;
}
$total = 0;
foreach ($counts as $w => $c) {
    $total += $c;
}
echo count($counts).' '.$total.' '.$counts['w17'], "\n";
