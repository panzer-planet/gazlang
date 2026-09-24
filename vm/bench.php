<?php

// Times gazlang against the same program written in PHP and Python:
//   php vm/bench.php [ROUNDS = 5] [FILTER]
// Most vm/bench/gaz/NAME.gaz have a php/NAME.php and a python/NAME.py doing the same work; the
// real workloads (the self-hosted compiler, football.gaz) run on gazlang only. Python is the
// newest python3.11 or later on the path, or PYTHON: 3.11 made CPython much faster, so an older
// one would flatter gazlang, and without one the column is left out. Every run is a whole
// process, timed in CPU seconds (user + system), and the runs are interleaved, so a machine
// that gets busier halfway slows all of them alike; the best of the rounds is reported. PHP
// runs with its JIT on (opcache.jit=1235, the fastest setting for this kind of code).

chdir(dirname(__DIR__));
require 'vendor/autoload.php';

$rounds = (int) ($argv[1] ?? 5);
$filter = $argv[2] ?? null;
passthru('make -s -C vm', $code);
if ($code !== 0) {
    exit(1);
}

$php = [PHP_BINARY, '-d', 'pcov.enabled=0', '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit_buffer_size=16M', '-d', 'opcache.jit=1235'];
$compile = function (string $file, string $gzb): void {
    exec(implode(' ', array_map('escapeshellarg', ['bin/gazlang', '-c', '-f', $file])).' > '.escapeshellarg($gzb), $out, $code);
    if ($code !== 0) {
        throw new RuntimeException("{$file} does not compile");
    }
};

$python = getenv('PYTHON') ?: null;
foreach (['python3.13', 'python3.12', 'python3.11', 'python3'] as $candidate) {
    $found = trim((string) shell_exec('command -v '.$candidate.' 2>/dev/null'));
    if ($python === null && $found !== '' && trim((string) shell_exec(escapeshellarg($found)." -c 'import sys; print(sys.version_info >= (3, 11))'")) === 'True') {
        $python = $found;
    }
}

@mkdir('vm/build/bench', 0777, true);
$cases = [];
foreach (glob('vm/bench/gaz/*.gaz') as $file) {
    $name = basename($file, '.gaz');
    $gzb = "vm/build/bench/{$name}.gzb";
    $compile($file, $gzb);
    $cases[$name] = ['c' => ['bin/gazlang', '-f', $gzb]];
    // The same program in PHP, for the ones that have one
    if (is_file("vm/bench/php/{$name}.php")) {
        $cases[$name]['php'] = [...$php, "vm/bench/php/{$name}.php"];
    }
    if ($python !== null && is_file("vm/bench/python/{$name}.py")) {
        $cases[$name]['py'] = [$python, "vm/bench/python/{$name}.py"];
    }
}
foreach (['compiler/gazlang.gaz code tests/programs/football.gaz', 'compiler/gazlang.gaz ast compiler/codegen.gaz', 'compiler/gazlang.gaz tokens compiler/codegen.gaz', 'tests/programs/football.gaz'] as $workload) {
    $args = explode(' ', $workload);
    $file = array_shift($args);
    $gzb = 'vm/build/bench/'.basename($file, '.gaz').'.gzb';
    $compile($file, $gzb);
    $cases[$workload] = ['c' => ['bin/gazlang', '-f', $gzb, '--', ...$args]];
}
if ($filter !== null) {
    $cases = array_filter($cases, fn ($name) => str_contains($name, $filter), ARRAY_FILTER_USE_KEY);
}

// The CPU time a command takes, with its output thrown away
$time = function (array $command): float {
    $before = getrusage(1);
    $process = proc_open($command, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    proc_close($process);
    $after = getrusage(1);
    $seconds = fn ($r) => $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6 + $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6;

    return $seconds($after) - $seconds($before);
};

$best = [];
for ($round = 0; $round < $rounds; $round++) {
    foreach ($cases as $name => $runners) {
        foreach ($runners as $runner => $command) {
            $t = $time($command);
            $best[$name][$runner] = min($best[$name][$runner] ?? INF, $t);
        }
    }
}

if ($python !== null) {
    echo 'Python: '.trim((string) shell_exec(escapeshellarg($python).' --version'))."\n";
}
$seconds = fn (?float $t) => $t === null ? '' : sprintf('%.3fs', $t);
$ratio = fn (float $a, ?float $b) => $b === null ? '' : sprintf('%.1fx', $a / $b);
printf("%-48s %9s %9s %9s %9s %9s\n", '', 'gazlang', 'PHP', 'Python', 'gaz / PHP', 'Py / gaz');
foreach ($best as $name => $t) {
    printf("%-48s %9s %9s %9s %9s %9s\n", $name, $seconds($t['c']), $seconds($t['php'] ?? null), $seconds($t['py'] ?? null),
        $ratio($t['c'], $t['php'] ?? null), isset($t['py']) ? $ratio($t['py'], $t['c']) : '');
}
