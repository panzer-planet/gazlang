<?php

// Which lines of the C VM the harness never runs: builds vm/build/gazvm-cov with clang's
// source-based coverage, runs every entry of vm/passing.txt and every invocation of CliTest's
// table on it, and prints the summary. GAZVM_STATS is set, as the harness sets it. Both, since the entries never reach the command line's
// own code (they are all one shape) and the table is where the options are covered.
//   php vm/coverage.php           the summary per file
//   php vm/coverage.php load.c    also every line of load.c that never ran

require __DIR__.'/../vendor/autoload.php';

use GazLang\Tests\CliTest;
use GazLang\Tests\CVM;

$root = dirname(__DIR__);
$dir = sys_get_temp_dir().'/gazvm-coverage';
exec('rm -rf '.escapeshellarg($dir).' && mkdir -p '.escapeshellarg($dir));
passthru("make -s -C {$root}/vm build/gazvm-cov", $code);
if ($code !== 0) {
    exit(1);
}
foreach (CVM::passing() as $i => $entry) {
    // As CVM::runC() runs them: a source or .gzb file by name, a snippet piped in
    $args = preg_split('/\s+/', $entry);
    $file = array_shift($args);
    $input = '/dev/null';
    if (str_starts_with($file, 'snippet:')) {
        $input = "{$dir}/snippet.gaz";
        file_put_contents($input, CVM::snippets()[substr($file, 8)]);
        $run = '';
    } else {
        $run = '-f '.escapeshellarg($file).' -- '.implode(' ', array_map('escapeshellarg', $args));
    }
    $command = sprintf('cd %s && GAZVM_STATS=1 LLVM_PROFILE_FILE=%s vm/build/gazvm-cov %s < %s > /dev/null 2>&1',
        escapeshellarg($root), escapeshellarg("{$dir}/{$i}.profraw"), $run, escapeshellarg($input));
    exec($command);
}
// The command line, which the entries above never exercise: each invocation of CliTest's table,
// in its working directory, with what it pipes in
$i = 0;
foreach (CliTest::CASES as $case) {
    $cwd = $root.'/'.($case[2] ?? 'tests/cli');
    $input = isset($case[1]) ? $cwd.'/'.$case[1] : '/dev/null';
    $command = sprintf('cd %s && GAZVM_STATS=1 LLVM_PROFILE_FILE=%s %s/vm/build/gazvm-cov %s < %s > /dev/null 2>&1',
        escapeshellarg($cwd), escapeshellarg("{$dir}/cli{$i}.profraw"), escapeshellarg($root),
        implode(' ', array_map('escapeshellarg', $case[0])), escapeshellarg($input));
    exec($command);
    $i++;
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
