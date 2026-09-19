<?php

// Runs every candidate program on the PHP VM and the C VM and says which match.
//   php vm/progress.php            report
//   php vm/progress.php --update   also add the newly passing ones to vm/passing.txt, and record
//                                  what every matching one prints in tests/expected (CVMTest
//                                  checks the C VM against that), removing what nothing records
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
        $pass[$entry] = $php;
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
    $all = array_values(array_unique([...$passing, ...array_keys($pass)]));
    sort($all);
    file_put_contents(__DIR__.'/passing.txt', implode("\n", $all)."\n");
    printf("vm/passing.txt: %d entries\n", count($all));

    // The drivers on big inputs aren't recorded: CVMTest runs them on the PHP VM each time
    $recorded = array_filter(array_keys($pass), fn ($entry) => ! str_contains($entry, ' '));
    foreach ($recorded as $entry) {
        CVM::record($entry, $pass[$entry]);
    }
    printf("tests/expected: %d entries recorded\n", count($recorded));
    if ($filter === null) {
        $wanted = array_flip(array_map(CVM::expectedPath(...), $all));
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CVM::ROOT.'/tests/expected', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $path) {
            if (! isset($wanted[preg_replace('/\.(stdout|stderr|exit)$/', '', (string) $path)])) {
                unlink((string) $path);
                echo 'removed '.substr((string) $path, strlen(CVM::ROOT) + 1)."\n";
            }
        }
    }
}
