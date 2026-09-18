<?php

$s = '';
for ($i = 0; $i < 300000; $i++) {
    $s .= 'item'.$i.',';
}
$parts = explode(',', $s);
$count = 0;
foreach ($parts as $p) {
    if (str_starts_with($p, 'item1')) {
        $count++;
    }
}
echo strlen($s).' '.$count.' '.strpos($s, 'item299999').' '.strlen(implode(';', $parts)), "\n";
