<?php

// Runs every entry of vm/passing.txt and every candidate on the C VM (the sanitized build, or
// the one GAZVM names) and says which print something other than tests/expected records.
//   php vm/progress.php            report
//   php vm/progress.php --update   also add the new candidates to vm/passing.txt, drop the entries
//                                  whose file or snippet is gone, record what every entry prints
//                                  in tests/expected, and remove what nothing records: review
//                                  that diff, since it is what the VM is held to
//   php vm/progress.php FILTER     only the entries containing FILTER, showing the differences
// A candidate is new when the compiler built in accepts it (a file it refuses tests the
// compiler, whose corpora cover that) and it runs without leaking.

require __DIR__.'/../vendor/autoload.php';

use GazLang\Tests\CVM;

$args = array_slice($_SERVER['argv'], 1);
$update = in_array('--update', $args, true);
$filter = array_values(array_filter($args, fn ($a) => $a !== '--update'))[0] ?? null;
$wanted = fn (string $entry) => $filter === null || str_contains($entry, $filter);

CVM::build();
// Entries whose test changed or went, so there is nothing left to run
$gone = CVM::gone();
foreach (array_filter($gone, $wanted) as $entry) {
    echo "GONE {$entry}".($update ? ': removed' : ': --update removes it')."\n";
}
$passing = array_values(array_filter(array_diff(CVM::passing(), $gone), $wanted));
$candidates = array_values(array_filter(array_diff(CVM::candidates(), CVM::passing()), $wanted));
// Which candidates compile; a .gzb one is bytecode already
$compile = [];
$stdin = [];
foreach ($candidates as $entry) {
    if (str_starts_with($entry, 'snippet:')) {
        $stdin[$entry] = 'vm/build/snippets/'.substr($entry, 8).'.gaz';
        @mkdir(CVM::ROOT.'/vm/build/snippets', 0777, true);
        file_put_contents(CVM::ROOT.'/'.$stdin[$entry], CVM::snippets()[substr($entry, 8)]);
        $compile[$entry] = [CVM::ROOT.'/bin/gazlang', '-c'];
    } elseif (! str_ends_with($entry, '.gzb')) {
        $compile[$entry] = [CVM::ROOT.'/bin/gazlang', '-c', '-f', $entry];
    }
}
$compiled = CVM::processes($compile, [], $stdin);
$candidates = array_values(array_filter($candidates, fn ($entry) => ($compiled[$entry][2] ?? 0) === 0));

[$same, $differ, $new] = [[], [], []];
foreach (CVM::runC([...$passing, ...$candidates]) as $entry => $c) {
    $leak = CVM::leak($c);
    if (! in_array($entry, $passing, true)) {
        if ($leak === null) {
            $new[$entry] = $c;
        }

        continue;
    }
    $expected = CVM::expected($entry);
    if ($expected === [CVM::portable($c[0]), CVM::portable($c[1]), $c[2]] && $leak === null) {
        $same[$entry] = $c;

        continue;
    }
    $differ[$entry] = $c;
    echo "DIFFERS {$entry}".($expected === null ? ': nothing recorded' : '').($leak === null ? '' : ": {$leak}")."\n";
    if ($filter !== null && $expected !== null) {
        foreach (['stdout', 'stderr', 'exit code'] as $i => $what) {
            $printed = $i === 2 ? $c[2] : CVM::portable($c[$i]);
            if ($expected[$i] !== $printed) {
                echo "  {$what}:\n    expected: ".json_encode($expected[$i])."\n    printed:  ".json_encode($printed)."\n";
            }
        }
    }
}

printf("%d of %d entries print what is recorded; %d new\n", count($same), count($same) + count($differ), count($new));
if ($update) {
    $all = array_values(array_unique([...array_diff(CVM::passing(), $gone), ...array_keys($new)]));
    sort($all);
    file_put_contents(__DIR__.'/passing.txt', implode("\n", $all)."\n");
    printf("vm/passing.txt: %d entries\n", count($all));

    foreach ([...$same, ...$differ, ...$new] as $entry => $c) {
        if (CVM::leak($c) === null) {
            CVM::record($entry, $c);
        } else {
            echo "not recorded, since it leaked: {$entry}\n";
        }
    }
    if ($filter === null) {
        $kept = array_flip(array_map(CVM::expectedPath(...), $all));
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CVM::ROOT.'/tests/expected', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $path) {
            if (! isset($kept[preg_replace('/\.(stdout|stderr|exit)$/', '', (string) $path)])) {
                unlink((string) $path);
                echo 'removed '.substr((string) $path, strlen(CVM::ROOT) + 1)."\n";
            }
        }
    }
}
