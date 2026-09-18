<?php

// Times the C VM against the PHP VM and against the same program written in PHP:
//   php vm/bench.php [ROUNDS = 5] [FILTER]
// Most vm/bench/NAME.gaz have a NAME.php doing the same work; the real workloads (the
// self-hosted compiler, football.gaz) run on the two VMs only. Every run is a whole process,
// timed in CPU seconds (user + system), and the runs are interleaved, so a machine that gets
// busier halfway slows all of them alike; the best of the rounds is reported. PHP runs with the
// JIT settings bin/gazlang gives itself.

chdir(dirname(__DIR__));
require 'vendor/autoload.php';

$rounds = (int) ($argv[1] ?? 5);
$filter = $argv[2] ?? null;
passthru('make -s -C vm gazvm', $code);
if ($code !== 0) {
    exit(1);
}

$php = [PHP_BINARY, '-d', 'pcov.enabled=0', '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit_buffer_size=16M', '-d', 'opcache.jit=1235'];
$compile = function (string $file, string $gzb): void {
    exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, 'bin/gazlang', '-c', '-f', $file])).' > '.escapeshellarg($gzb), $out, $code);
    if ($code !== 0) {
        throw new RuntimeException("{$file} does not compile");
    }
};

@mkdir('vm/build/bench', 0777, true);
$cases = [];
foreach (glob('vm/bench/*.gaz') as $file) {
    $name = basename($file, '.gaz');
    $gzb = "vm/build/bench/{$name}.gzb";
    $compile($file, $gzb);
    $cases[$name] = ['c' => ['vm/gazvm', '-f', $gzb], 'vm' => [...$php, 'bin/gazlang', '-f', $gzb]];
    // The same program in PHP, for the ones that have one
    if (is_file("vm/bench/{$name}.php")) {
        $cases[$name]['php'] = [...$php, "vm/bench/{$name}.php"];
    }
}
foreach (['selfhost/gazlang.gaz code examples/football.gaz', 'selfhost/gazlang.gaz ast selfhost/codegen.gaz', 'selfhost/gazlang.gaz tokens selfhost/codegen.gaz', 'examples/football.gaz'] as $workload) {
    $args = explode(' ', $workload);
    $file = array_shift($args);
    $gzb = 'vm/build/bench/'.basename($file, '.gaz').'.gzb';
    $compile($file, $gzb);
    $cases[$workload] = ['c' => ['vm/gazvm', '-f', $gzb, '--', ...$args], 'vm' => [...$php, 'bin/gazlang', '-f', $gzb, '--', ...$args]];
}
if ($filter !== null) {
    $cases = array_filter($cases, fn ($name) => str_contains($name, $filter), ARRAY_FILTER_USE_KEY);
}

// The CPU time a command takes, with its output thrown away
$time = function (array $command): float {
    $before = getrusage(1);
    $process = proc_open($command, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, null, ['GAZLANG_RESTARTED' => '1'] + getenv());
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

printf("%-42s %9s %9s %9s %11s %11s\n", '', 'C VM', 'PHP VM', 'PHP', 'PHP VM / C', 'C / PHP');
foreach ($best as $name => $t) {
    printf("%-42s %8.3fs %8.3fs %9s %10.1fx %11s\n", $name, $t['c'], $t['vm'], isset($t['php']) ? sprintf('%.3fs', $t['php']) : '', $t['vm'] / $t['c'],
        isset($t['php']) ? sprintf('%.1fx', $t['c'] / $t['php']) : '');
}
