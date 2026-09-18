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
 * Runs a program on the PHP VM and on the C VM (vm/gazvm) and gives what each printed
 *
 * An entry is a path relative to the project root, optionally followed by the program's
 * arguments: "selfhost/compile.gaz examples/functions.gaz"; "snippet:<id>", a snippet the PHP
 * tests run (see snippets()); or a .gzb file under tests/bytecode_corpus, run as it is, which
 * tests the loaders on files no compiler writes (the ones named error_* must be refused). The PHP compiler writes the
 * bytecode to vm/build/gzb/<path>.gzb, and both VMs run that same file from the project root,
 * so both resolve its source paths the same way. vm/passing.txt lists the entries the C VM must
 * already match (CVMTest), and vm/progress.php finds the ones it has started to.
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
     * How long the C VM may run one program, in seconds; the sanitizers make it slower
     */
    private const TIME_LIMIT = 60;

    /**
     * Build the tested C VM, failing loudly if it doesn't compile
     */
    public static function build(): void
    {
        exec('make -s -C '.escapeshellarg(self::ROOT.'/vm').' test 2>&1', $output, $code);
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
     * Every entry worth trying: every .gaz file under the directories that hold programs, and
     * the self-hosted drivers on a few files
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
            foreach (['selfhost/tokens.gaz', 'selfhost/ast.gaz', 'selfhost/compile.gaz'] as $driver) {
                foreach (['examples/functions.gaz', 'examples/football.gaz', 'selfhost/codegen.gaz'] as $input) {
                    $files[] = "{$driver} {$input}";
                }
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
     * @return array<string, array{0: array{0: string, 1: string, 2: int}, 1: array{0: string, 1: string, 2: int}}|null>
     *                                                                                                                  By entry: [PHP, C], each [stdout, stderr, exit code];
     *                                                                                                                  null when the PHP compiler refuses the program
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
                $jobs[$entry] = [$gzb, $args];
            }
        }
        if ($isolated) {
            $php = self::processes(array_map(fn ($job) => [PHP_BINARY, '-d', 'pcov.enabled=0', 'bin/gazlang', '-f', $job[0], '--', ...$job[1]], $jobs), ['GAZLANG_RESTARTED' => '1']);
            $c = self::processes(array_map(fn ($job) => [self::binary(), $job[0], ...$job[1]], $jobs));
            foreach ($jobs as $entry => $_) {
                $results[$entry] = [$php[$entry], $c[$entry]];
            }

            return $results;
        }

        // The PHP side runs in this process while the C side's processes run
        $php = [];
        $pending = $jobs;
        $c = self::processes(array_map(fn ($job) => [self::binary(), $job[0], ...$job[1]], $jobs), [], function () use (&$pending, &$php) {
            if ($pending === []) {
                return false;
            }
            $entry = array_key_first($pending);
            [$gzb, $args] = $pending[$entry];
            unset($pending[$entry]);
            $php[$entry] = self::runPhp($gzb, $args);

            return true;
        });
        foreach ($pending as $entry => [$gzb, $args]) {
            $php[$entry] = self::runPhp($gzb, $args);
        }
        foreach ($jobs as $entry => $_) {
            $results[$entry] = [$php[$entry], $c[$entry]];
        }

        return $results;
    }

    /**
     * How many lists, maps, objects and functions a program leaves alive on the C VM once it ends
     * and its cycles are collected, and the most that were alive at once (GAZVM_STATS)
     *
     * @return array{0: int, 1: int}
     */
    public static function alive(string $file): array
    {
        $gzb = self::compile($file);
        [, $err] = self::process([self::binary(), $gzb], ['GAZVM_STATS' => '1']);
        if (! preg_match('/gazvm: (\d+) lists, maps, objects and functions alive at the end, at most (\d+) at once/', $err, $match)) {
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
                $text = (new Lexer($line))->get_next_token()->value;
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
    private static function compile(string $file): ?string
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
     * Run bytecode on the PHP VM as `gazlang -f` would: in-process, unless it reads standard
     * input, which in-process would be the test runner's
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private static function runPhp(string $gzb, array $args): array
    {
        $text = (string) file_get_contents(self::ROOT."/{$gzb}");
        if (str_contains($text, 'CALL_BUILTIN read_stdin') || str_contains($text, 'PUSH_FN read_stdin')) {
            return self::process([PHP_BINARY, '-d', 'pcov.enabled=0', 'bin/gazlang', '-f', $gzb, '--', ...$args], ['GAZLANG_RESTARTED' => '1']);
        }

        $cwd = getcwd();
        chdir(self::ROOT);
        $stderr = fopen('php://memory', 'w+');
        Builtins::$error_stream = $stderr;
        ob_start();
        $code = 0;
        try {
            (new VM(Program::read($text, $gzb), $args))->run();
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
    private static function process(array $command, array $env = []): array
    {
        return self::processes([$command], $env)[0];
    }

    /**
     * Run commands from the project root, several at once, each with no standard input and
     * within the time limit
     *
     * @param  array<array-key, list<string>>  $commands  The commands, keyed as the results are
     * @param  array<string, string>  $env  Extra environment variables
     * @param  callable|null  $between  Other work to do while they run, a piece at a time: false when there is none left
     * @return array<array-key, array{0: string, 1: string, 2: int}> Each one's stdout, stderr and exit code (-1 when killed)
     */
    private static function processes(array $commands, array $env = [], ?callable $between = null): array
    {
        $results = [];
        $running = [];
        $queue = $commands;
        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < 24) {
                $key = array_key_first($queue);
                $command = $queue[$key];
                unset($queue[$key]);
                $spec = [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']];
                $process = proc_open($command, $spec, $pipes, self::ROOT, $env + getenv());
                if ($process === false) {
                    throw new \RuntimeException('Cannot run '.implode(' ', $command));
                }
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $running[$key] = ['process' => $process, 'pipes' => $pipes, 'out' => '', 'err' => '', 'deadline' => microtime(true) + self::TIME_LIMIT];
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
                    $job['err'] .= "\n[killed after ".self::TIME_LIMIT."s]\n";
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
