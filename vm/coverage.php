<?php

// Which lines of the C VM the harness never runs: builds vm/build/gazvm-cov with clang's
// source-based coverage, runs every entry of vm/passing.txt on it, and prints the summary.
//   php vm/coverage.php           the summary per file
//   php vm/coverage.php load.c    also every line of load.c that never ran
// Run vm/progress.php first: it writes the bytecode files this runs.

require __DIR__.'/../vendor/autoload.php';

use GazLang\Tests\CVM;

$root = dirname(__DIR__);
$dir = sys_get_temp_dir().'/gazvm-coverage';
exec('rm -rf '.escapeshellarg($dir).' && mkdir -p '.escapeshellarg($dir));
passthru("make -s -C {$root}/vm build/gazvm-cov", $code);
if ($code !== 0) {
    exit(1);
}
foreach (CVM::passing() as $i => $entry) {
    $args = preg_split('/\s+/', $entry);
    $file = array_shift($args);
    $gzb = match (true) {
        str_starts_with($file, 'snippet:') => 'vm/build/gzb/snippets/'.substr($file, 8).'.gzb',
        str_ends_with($file, '.gzb') => $file,
        default => "vm/build/gzb/{$file}.gzb",
    };
    $command = sprintf('cd %s && LLVM_PROFILE_FILE=%s vm/build/gazvm-cov %s %s < /dev/null > /dev/null 2>&1',
        escapeshellarg($root), escapeshellarg("{$dir}/{$i}.profraw"), escapeshellarg($gzb), implode(' ', array_map('escapeshellarg', $args)));
    exec($command);
}
exec("xcrun llvm-profdata merge -o {$dir}/all.profdata {$dir}/*.profraw");
passthru("xcrun llvm-cov report {$root}/vm/build/gazvm-cov -instr-profile={$dir}/all.profdata");
if (isset($argv[1])) {
    exec("xcrun llvm-cov show {$root}/vm/build/gazvm-cov -instr-profile={$dir}/all.profdata {$root}/vm/{$argv[1]}", $lines);
    foreach ($lines as $line) {
        if (preg_match('/^\s*\d+\|\s*0\|/', $line)) {
            echo $line, "\n";
        }
    }
}
