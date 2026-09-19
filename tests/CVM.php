<?php

namespace GazLang\Tests;

use GazLang\CodeGenerator\CodeGenerator;
use GazLang\CodeGenerator\Program;
use GazLang\GazLangError;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use GazLang\Runtime\Builtins;
use GazLang\Runtime\ExitSignal;
use GazLang\VM\VM;
use Throwable;

/**
 * Runs programs on the C VM (bin/gazlang), and on the PHP VM for vm/progress.php, and gives what
 * each printed
 *
 * An entry is a path relative to the project root, optionally followed by the program's
 * arguments after spaces; "snippet:<id>", a snippet the PHP tests run (see snippets()); or a .gzb
 * file under tests/bytecode_corpus, run as it is, which tests the loaders on files no compiler
 * writes (the ones named error_* must be refused). The C VM runs a source file from its source,
 * as `gazlang -f FILE` does, compiling it with the self-hosted compiler built into it, and a
 * snippet piped in from the project root; the PHP VM runs what the PHP compiler writes to
 * vm/build/gzb/<path>.gzb, loaded as if it were next to its source (a snippet's as if it were in
 * the project root). vm/passing.txt lists the entries the C VM must print what tests/expected
 * records for (CVMTest), and vm/progress.php finds the ones it has started to match the PHP VM on.
 */
final class CVM
{
    public const ROOT = __DIR__.'/..';

    /**
     * The C VM as the tests build it, with AddressSanitizer and UndefinedBehaviorSanitizer
     */
    public const BINARY = 'vm/build/gazvm-test';

    /**
     * The binary to run: GAZVM names another, like a build that collects cycles at every chance
     */
    private static function binary(): string
    {
        return getenv('GAZVM') ?: self::BINARY;
    }

    /**
     * How long a program may run, in seconds: the sanitizers make the C VM slower, and a build
     * GAZVM names can be far slower (the stress build takes over 20 minutes alone to compile the
     * self-hosted compiler, and over an hour with the rest of a run beside it), so it gets two hours
     */
    private static function timeLimit(): int
    {
        return getenv('GAZVM') ? 7200 : 60;
    }

    /**
     * Build the C VM, the tested build and the optimised one, failing loudly if it doesn't compile
     */
    public static function build(): void
    {
        exec('make -s -C '.escapeshellarg(self::ROOT.'/vm').' all test 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException("Building the C VM failed:\n".implode("\n", $output));
        }
    }

    /**
     * The entries the C VM must match, from vm/passing.txt
     *
     * @return list<string>
     */
    public static function passing(): array
    {
        $lines = file(self::ROOT.'/vm/passing.txt', FILE_IGNORE_NEW_LINES) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== '' && $line[0] !== '#'));
    }

    /**
     * Every entry worth trying: every .gaz file under the directories that hold programs, the
     * snippets and the hand-written bytecode
     *
     * @return list<string>
     */
    public static function candidates(): array
    {
        $cwd = getcwd();
        chdir(self::ROOT);
        try {
            $files = [];
            foreach (['examples', 'lib', 'selfhost', 'tests/gaz', 'tests/fixtures', 'tests/codegen_corpus', 'tests/parser_corpus', 'tests/lexer_corpus', 'tests/vm_corpus'] as $dir) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $path) {
                    if (str_ends_with((string) $path, '.gaz')) {
                        $files[] = (string) $path;
                    }
                }
            }
            $files = [...$files, ...glob('tests/bytecode_corpus/*.gzb')];
            sort($files);
            foreach (array_keys(self::snippets()) as $id) {
                $files[] = "snippet:{$id}";
            }
        } finally {
            chdir($cwd);
        }

        return $files;
    }

    /**
     * Run entries on both VMs: the C VM many at a time, since most of its cost is waiting for a
     * sanitized process to start (about 70ms on macOS, mostly idle), and the PHP VM in-process
     *
     * @param  list<string>  $entries
     * @param  bool  $isolated  Whether to run the PHP side in processes too, with the time limit, for programs that may never end
     * @return array<string, array{0: array{0: string, 1: string, 2: int}, 1: array{0: string, 1: string, 2: int, 3: string|null}}|null>
     *                                                                                                                                   By entry: [PHP, C], each [stdout, stderr, exit code], and C's GAZVM_STATS line (see leaks());
     *                                                                                                                                   null when the PHP compiler refuses the program
     */
    public static function runAll(array $entries, bool $isolated = false): array
    {
        $jobs = [];
        $results = [];
        foreach ($entries as $entry) {
            $args = preg_split('/\s+/', trim($entry));
            $file = array_shift($args);
            $gzb = match (true) {
                str_starts_with($file, 'snippet:') => self::compileSnippet(substr($file, 8)),
                // Bytecode written by hand, mostly broken on purpose, for the loaders
                str_ends_with($file, '.gzb') => $file,
                default => self::compile($file),
            };
            $results[$entry] = null;
            if ($gzb !== null) {
                // A source file runs from its source on both: the C VM compiles it with the
                // compiler built into it, and the PHP VM runs it as `gazlang -f` does
                $jobs[$entry] = [$gzb, $args, str_ends_with($file, '.gaz') ? $file : null];
            }
        }
        if ($isolated) {
            $php = self::processes(array_map(fn ($job) => [PHP_BINARY, '-d', 'pcov.enabled=0', 'bin/gazlang-php', '-f', $job[2] ?? $job[0], '--', ...$job[1]], $jobs), ['GAZLANG_RESTARTED' => '1']);
            $c = self::runC(array_keys($jobs));
            foreach ($jobs as $entry => $_) {
                $results[$entry] = [$php[$entry], $c[$entry]];
            }

            return $results;
        }

        // The PHP side runs in this process while the C side's processes run
        $php = [];
        $pending = $jobs;
        $c = self::runC(array_keys($jobs), function () use (&$pending, &$php) {
            if ($pending === []) {
                return false;
            }
            $entry = array_key_first($pending);
            [$gzb, $args, $source] = $pending[$entry];
            unset($pending[$entry]);
            $php[$entry] = self::runPhp($gzb, $args, $source);

            return true;
        });
        foreach ($pending as $entry => [$gzb, $args, $source]) {
            $php[$entry] = self::runPhp($gzb, $args, $source);
        }
        foreach ($jobs as $entry => $_) {
            $results[$entry] = [$php[$entry], $c[$entry]];
        }

        return $results;
    }

    /**
     * Run entries on the C VM alone, many at a time, as `gazlang` would run them: a source file
     * from its source, a .gzb file as it is, and a snippet, which has no file, piped in from the
     * project root, as executeCode() runs it with no file
     *
     * @param  list<string>  $entries
     * @param  callable|null  $between  Other work to do while they run (see processes())
     * @return array<string, array{0: string, 1: string, 2: int, 3: string|null}> By entry: stdout, stderr, exit code and the GAZVM_STATS line (see leaks())
     */
    public static function runC(array $entries, ?callable $between = null): array
    {
        $commands = [];
        $stdin = [];
        foreach ($entries as $entry) {
            $args = preg_split('/\s+/', trim($entry));
            $file = array_shift($args);
            if (str_starts_with($file, 'snippet:')) {
                $id = substr($file, 8);
                $stdin[$entry] = "vm/build/snippets/{$id}.gaz";
                @mkdir(self::ROOT.'/vm/build/snippets', 0777, true);
                file_put_contents(self::ROOT.'/'.$stdin[$entry], self::snippets()[$id] ?? throw new \RuntimeException("{$entry}: not in tests/vm_snippets.txt"));
                $commands[$entry] = [self::binary()];
            } else {
                $commands[$entry] = [self::binary(), '-f', $file, '--', ...$args];
            }
        }

        return array_map(self::leaks(...), self::processes($commands, ['GAZVM_STATS' => '1'], $between, $stdin));
    }

    /**
     * What an entry must print, as recorded in tests/expected by vm/progress.php --update, or
     * null when nothing is recorded
     *
     * @return array{0: string, 1: string, 2: int}|null stdout, stderr and the exit code
     */
    public static function expected(string $entry): ?array
    {
        $base = self::expectedPath($entry);
        if (! is_file("{$base}.stdout")) {
            return null;
        }
        $read = fn ($suffix) => is_file("{$base}.{$suffix}") ? (string) file_get_contents("{$base}.{$suffix}") : '';

        return [$read('stdout'), $read('stderr'), (int) ($read('exit') ?: 0)];
    }

    /**
     * Record what an entry printed as what it must print: stdout always, stderr and the exit
     * code only when there is one, so most entries are one file
     *
     * @param  array{0: string, 1: string, 2: int}  $result
     */
    public static function record(string $entry, array $result): void
    {
        $base = self::expectedPath($entry);
        @mkdir(dirname($base), 0777, true);
        file_put_contents("{$base}.stdout", self::portable($result[0]));
        foreach (['stderr' => self::portable($result[1]), 'exit' => $result[2] === 0 ? '' : "{$result[2]}\n"] as $suffix => $text) {
            if ($text === '') {
                @unlink("{$base}.{$suffix}");
            } else {
                file_put_contents("{$base}.{$suffix}", $text);
            }
        }
    }

    /**
     * Where an entry's expected output is kept, without the suffix: tests/expected/ then the
     * entry's path, or snippets/<id>
     */
    public static function expectedPath(string $entry): string
    {
        return self::ROOT.'/tests/expected/'.(str_starts_with($entry, 'snippet:') ? 'snippets/'.substr($entry, 8) : $entry);
    }

    /**
     * Output with the project's own path replaced by <root>, since cwd() and real_path() print
     * it and it differs from one checkout to another
     */
    public static function portable(string $text): string
    {
        return str_replace((string) realpath(self::ROOT), '<root>', $text);
    }

    /**
     * Run the self-hosted front end (selfhost/gazlang.gaz) in a mode on each file, from the
     * project root, on the optimised C VM, many at once: the ports' harnesses, which check the
     * ports rather than the VM (CVMTest checks the VM, and runs the driver under the sanitizers)
     *
     * @param  string  $mode  code, tokens or ast
     * @param  list<string>  $files
     * @param  bool  $piped  Whether to give it each file on standard input rather than by name
     * @param  string  $cwd  The working directory, which the files are relative to
     * @return array<string, array{0: string, 1: int}> By file: what it printed, standard output then standard error, and its exit code
     */
    public static function driver(string $mode, array $files, bool $piped = false, string $cwd = self::ROOT): array
    {
        // Built and compiled once per run, since the harnesses call this many times
        static $gzb = null;
        if ($gzb === null) {
            self::build();
            $gzb = self::ROOT.'/'.(self::compile(self::DRIVER) ?? throw new \RuntimeException(self::DRIVER." doesn't compile"));
        }
        $commands = array_combine($files, array_map(fn ($file) => [self::ROOT.'/bin/gazlang', '-f', $gzb, '--', $mode, ...($piped ? [] : [$file])], $files));
        $results = self::processes($commands, [], null, $piped ? array_combine($files, $files) : [], $cwd);

        return array_map(fn ($result) => [$result[0].$result[1], $result[2]], $results);
    }

    /**
     * The self-hosted front end: gazlang -f selfhost/gazlang.gaz -- code|tokens|ast [FILE]
     */
    public const DRIVER = 'selfhost/gazlang.gaz';

    /**
     * Take the line GAZVM_STATS adds out of the C VM's standard error, and add what it said as a
     * fourth element: "0 values leaked, at most ..." or "leaks not checked: ...", or null when
     * the line is missing (the VM crashed or was killed)
     *
     * @param  array{0: string, 1: string, 2: int}  $result
     * @return array{0: string, 1: string, 2: int, 3: string|null}
     */
    private static function leaks(array $result): array
    {
        $stats = null;
        // The last line, though not always at the start of one: a program's standard error needn't end in a newline
        if (preg_match('/gazvm: ([^\n]*)\n\z/', $result[1], $match, PREG_OFFSET_CAPTURE)) {
            $stats = $match[1][0];
            $result[1] = substr($result[1], 0, $match[0][1]);
        }

        return [...$result, $stats];
    }

    /**
     * What is wrong with a C result's GAZVM_STATS line, or null when nothing leaked (or the run
     * ended where leaks can't be checked: the loader refused it, it called exit(), or it was killed)
     *
     * @param  array{0: string, 1: string, 2: int, 3: string|null}  $c
     */
    public static function leak(array $c): ?string
    {
        return match (true) {
            $c[2] === -1 => null,   // killed for time: the output comparison says so
            $c[3] === null => 'the C VM printed no GAZVM_STATS line',
            str_starts_with($c[3], '0 values leaked'), str_starts_with($c[3], 'leaks not checked') => null,
            default => $c[3],
        };
    }

    /**
     * How many reference-counted values a program leaks on the C VM, and the most lists, maps,
     * objects and functions that were alive at once (GAZVM_STATS)
     *
     * @return array{0: int, 1: int}
     */
    public static function alive(string $file): array
    {
        $gzb = self::compile($file);
        [, $err] = self::process([self::binary(), '-f', $gzb], ['GAZVM_STATS' => '1']);
        if (! preg_match('/gazvm: (-?\d+) values leaked, at most (\d+) lists, maps, objects and functions alive at once/', $err, $match)) {
            throw new \RuntimeException("No statistics from the C VM:\n{$err}");
        }

        return [(int) $match[1], (int) $match[2]];
    }

    /**
     * The snippets the PHP tests run through executeCode(), by id, from tests/vm_snippets.txt, one
     * GazLang string literal per line
     * (vm/snippets.php collects them)
     *
     * @return array<string, string>
     */
    public static function snippets(): array
    {
        static $snippets = null;
        if ($snippets === null) {
            $snippets = [];
            foreach (file(self::ROOT.'/tests/vm_snippets.txt', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $text = json_decode($line, flags: JSON_THROW_ON_ERROR);
                $snippets[substr(sha1($text), 0, 12)] = $text;
            }
        }

        return $snippets;
    }

    /**
     * Compile a snippet, which has no file, as executeCode() does
     */
    private static function compileSnippet(string $id): ?string
    {
        $text = self::snippets()[$id] ?? null;
        if ($text === null) {
            return null;
        }
        $cwd = getcwd();
        chdir(self::ROOT);
        try {
            $code = (new CodeGenerator((new Parser(new Lexer($text)))->parse()))->compile()->write();
        } catch (GazLangError) {
            return null;
        } finally {
            chdir($cwd);
        }
        $gzb = "vm/build/gzb/snippets/{$id}.gzb";
        @mkdir(dirname(self::ROOT."/{$gzb}"), 0777, true);
        file_put_contents(self::ROOT."/{$gzb}", $code);

        return $gzb;
    }

    /**
     * Compile a program to vm/build/gzb/<file>.gzb, from the project root
     *
     * @return string|null The bytecode file, relative to the project root; null if it doesn't compile
     */
    public static function compile(string $file): ?string
    {
        $cwd = getcwd();
        chdir(self::ROOT);
        try {
            $text = (new CodeGenerator((new Parser(new Lexer((string) file_get_contents($file)), $file))->parse()))->compile()->write($file);
        } catch (GazLangError) {
            return null;
        } finally {
            chdir($cwd);
        }
        $gzb = "vm/build/gzb/{$file}.gzb";
        @mkdir(dirname(self::ROOT."/{$gzb}"), 0777, true);
        file_put_contents(self::ROOT."/{$gzb}", $text);

        return $gzb;
    }

    /**
     * Programs whose PHP side must run in a process of its own: to_string() printing itself
     * nests execute() on PHP's C stack as deep as the call depth limit, which with pcov on
     * segfaults the test runner (see "Under pcov" in CLAUDE.md). Add a program here if the
     * suite dies with exit code 139 and no test named.
     */
    private const DEEP = ['vm/build/gzb/tests/vm_corpus/depth.gaz.gzb'];

    /**
     * Run bytecode on the PHP VM as `gazlang -f` would: in-process, unless it reads standard
     * input, which in-process would be the test runner's, or is one of the DEEP ones. Given
     * $source, the file the bytecode was compiled from, it runs as `gazlang -f` runs the source,
     * whose paths it then shows
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private static function runPhp(string $gzb, array $args, ?string $source = null): array
    {
        $text = (string) file_get_contents(self::ROOT."/{$gzb}");
        if (in_array($gzb, self::DEEP, true) || str_contains($text, 'CALL_BUILTIN read_stdin') || str_contains($text, 'PUSH_FN read_stdin')) {
            return self::process([PHP_BINARY, '-d', 'pcov.enabled=0', 'bin/gazlang-php', '-f', $source ?? $gzb, '--', ...$args], ['GAZLANG_RESTARTED' => '1']);
        }

        $cwd = getcwd();
        chdir(self::ROOT);
        $stderr = fopen('php://memory', 'w+');
        Builtins::$error_stream = $stderr;
        ob_start();
        $code = 0;
        try {
            // A snippet has no file: its bytecode is read as if it were in the project root, the
            // working directory executeCode() resolves its paths against, so they show as there
            $at = $source ?? (str_starts_with($gzb, 'vm/build/gzb/snippets/') ? basename($gzb) : $gzb);
            (new VM(Program::read($text, $at), $args))->run();
        } catch (ExitSignal $e) {
            $code = $e->code;
        } catch (GazLangError $e) {
            fwrite($stderr, $e->report()."\n");
            $code = 1;
        } catch (Throwable $e) {
            fwrite($stderr, 'Error: '.$e->getMessage()."\n");
            $code = 1;
        } finally {
            $out = (string) ob_get_clean();
            Builtins::$error_stream = null;
            rewind($stderr);
            $err = (string) stream_get_contents($stderr);
            fclose($stderr);
            chdir($cwd);
        }

        return [$out, $err, $code];
    }

    /**
     * Run a command from the project root with no standard input, within the time limit
     *
     * @param  list<string>  $command  The command
     * @param  array<string, string>  $env  Extra environment variables
     * @return array{0: string, 1: string, 2: int} stdout, stderr and the exit code (-1 when killed)
     */
    public static function process(array $command, array $env = []): array
    {
        return self::processes([$command], $env)[0];
    }

    /**
     * Run commands, several at once, each within the time limit and with
     * no standard input unless $stdin gives it a file
     *
     * @param  array<array-key, list<string>>  $commands  The commands, keyed as the results are
     * @param  array<string, string>  $env  Extra environment variables
     * @param  callable|null  $between  Other work to do while they run, a piece at a time: false when there is none left
     * @param  array<array-key, string>  $stdin  The file each command reads as standard input, keyed as the commands, relative to $cwd
     * @param  string  $cwd  The working directory
     * @return array<array-key, array{0: string, 1: string, 2: int}> Each one's stdout, stderr and exit code (-1 when killed)
     */
    public static function processes(array $commands, array $env = [], ?callable $between = null, array $stdin = [], string $cwd = self::ROOT): array
    {
        $results = [];
        $running = [];
        $queue = $commands;
        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < 24) {
                $key = array_key_first($queue);
                $command = $queue[$key];
                unset($queue[$key]);
                $in = isset($stdin[$key]) ? (str_starts_with($stdin[$key], '/') ? $stdin[$key] : "{$cwd}/{$stdin[$key]}") : '/dev/null';
                $process = proc_open($command, [['file', $in, 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $cwd, $env + getenv());
                if ($process === false) {
                    throw new \RuntimeException('Cannot run '.implode(' ', $command));
                }
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $running[$key] = ['process' => $process, 'pipes' => $pipes, 'out' => '', 'err' => '', 'deadline' => microtime(true) + self::timeLimit()];
            }
            $read = [];
            foreach ($running as $job) {
                foreach ([1, 2] as $i) {
                    if (! feof($job['pipes'][$i])) {
                        $read[] = $job['pipes'][$i];
                    }
                }
            }
            if ($between !== null && $between()) {
                // Some other work was done meanwhile; the pipes are read below
            } elseif ($read !== []) {
                [$write, $except] = [null, null];
                stream_select($read, $write, $except, 0, 50000);
            }
            foreach ($running as $key => &$job) {
                $job['out'] .= (string) stream_get_contents($job['pipes'][1]);
                $job['err'] .= (string) stream_get_contents($job['pipes'][2]);
                $killed = microtime(true) > $job['deadline'];
                if ($killed) {
                    proc_terminate($job['process'], 9);
                    $job['err'] .= "\n[killed after ".self::timeLimit()."s]\n";
                }
                if ($killed || (feof($job['pipes'][1]) && feof($job['pipes'][2]))) {
                    fclose($job['pipes'][1]);
                    fclose($job['pipes'][2]);
                    $code = proc_close($job['process']);
                    $results[$key] = [$job['out'], $job['err'], $killed ? -1 : $code];
                    unset($running[$key]);
                }
            }
            unset($job);
        }

        return $results;
    }
}
