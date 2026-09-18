<?php

// Runs every candidate program on the PHP VM and the C VM and says which match.
//   php vm/progress.php            report
//   php vm/progress.php --update   also add the newly passing ones to vm/passing.txt
//   php vm/progress.php FILTER     only the entries containing FILTER, showing the differences

require __DIR__.'/../vendor/autoload.php';

use GazLang\Tests\CVM;

$update = in_array('--update', $argv, true);
$filter = array_values(array_filter(array_slice($argv, 1), fn ($a) => $a !== '--update'))[0] ?? null;

CVM::build();
$passing = CVM::passing();
[$pass, $fail, $skipped] = [[], [], 0];
$entries = array_values(array_filter(CVM::candidates(), fn ($entry) => $filter === null || str_contains($entry, $filter)));
foreach (CVM::runAll($entries) as $entry => $result) {
    if ($result === null) {
        $skipped++;

        continue;
    }
    [$php, $c] = $result;
    $leak = CVM::leak($c);
    if ($php === array_slice($c, 0, 3) && $leak === null) {
        $pass[] = $entry;
    } else {
        $fail[] = $entry;
        $known = in_array($entry, $passing, true) ? ' (REGRESSION: in passing.txt)' : '';
        echo "FAIL {$entry}{$known}".($leak === null ? '' : ": {$leak}")."\n";
        if ($filter !== null) {
            foreach (['stdout', 'stderr', 'exit code'] as $i => $what) {
                if ($php[$i] !== $c[$i]) {
                    echo "  {$what}:\n    php: ".json_encode($php[$i])."\n    c:   ".json_encode($c[$i])."\n";
                }
            }
            if ($leak !== null) {
                echo "  leak: {$leak}\n";
            }
        }
    }
}

printf("%d of %d match (%d refused by the compiler)\n", count($pass), count($pass) + count($fail), $skipped);
if ($update) {
    $all = array_values(array_unique([...$passing, ...$pass]));
    sort($all);
    file_put_contents(__DIR__.'/passing.txt', implode("\n", $all)."\n");
    printf("vm/passing.txt: %d entries\n", count($all));
}
