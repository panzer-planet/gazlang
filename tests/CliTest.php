<?php

namespace GazLang\Tests;

/**
 * The command line (bin/gazlang): every invocation in the table (its arguments, what is piped in
 * and the working directory) must print what tests/cli/expected records for it, standard output,
 * standard error and exit code. The sanitized build runs them. GAZLANG_RECORD=1 records what it
 * prints instead, for review as a diff.
 */
class CliTest extends GazLangTestCase
{
    /**
     * Where the programs the table runs are, and its default working directory
     */
    private const DIR = 'tests/cli';

    /**
     * Where the bytecode the table runs is compiled to, from the fixtures
     */
    private const BUILD = 'vm/build/cli';

    /**
     * Each invocation: its arguments, the file piped into it (none is /dev/null, which is piped
     * but empty), and its working directory relative to the project root
     *
     * @var array<string, array{0: list<string>, 1?: string|null, 2?: string}>
     */
    public const CASES = [
        // Files and program arguments
        'a file' => [['-f', 'args.gaz']],
        'a file with arguments' => [['-f', 'args.gaz', 'x', 'y']],
        'a file glued to -f' => [['-fargs.gaz', 'x']],
        'a file after --file' => [['--file', 'args.gaz', 'x']],
        'a file after --file=' => [['--file=args.gaz', 'y']],
        'program arguments after --' => [['-f', 'args.gaz', '--', '-x', '--y', '--']],
        'an option after a program argument is the program\'s' => [['-f', 'args.gaz', 'x', '-c']],
        'a lone - ends the options' => [['-', '-f', 'args.gaz'], 'args.gaz'],
        '-- ends the options' => [['-c', '--', '-f', 'args.gaz'], 'args.gaz'],
        '-f takes the next argument, whatever it is' => [['-f', '--', 'args.gaz']],
        '-f with nothing after it' => [['-f'], 'args.gaz'],
        '--file with nothing after it' => [['--file'], 'args.gaz'],
        'two files' => [['-f', 'args.gaz', '-f', 'stdin.gaz']],
        'two files, -f and --file' => [['-f', 'args.gaz', '--file', 'stdin.gaz']],
        'a file named 0' => [['-f', '0'], 'args.gaz'],
        'an empty file name' => [['-f', ''], 'args.gaz'],
        'a missing file' => [['-f', 'missing.gaz']],
        'a directory' => [['-f', '.']],
        'a file by its path from elsewhere' => [['-f', 'tests/cli/runtime_error.gaz'], null, '.'],

        // Options
        'an unknown option' => [['-x', '-f', 'args.gaz']],
        'an unknown option combined with a known one' => [['-hx']],
        'an unknown long option' => [['--foo']],
        'a long option abbreviated' => [['--fil', 'args.gaz']],
        'a value given to a flag' => [['--ast=1']],
        'an empty --file=' => [['--file=']],
        'an unknown option beats help' => [['-h', '-x']],
        'help' => [['-h']],
        'version' => [['-v']],
        'version, long' => [['--version']],
        'help beats version' => [['-vh']],
        'help beats everything else' => [['-f', 'args.gaz', '-c', '--help']],
        'combined flags and a file' => [['-cfargs.gaz']],
        'combined flags, the file after them' => [['-cf', 'args.gaz']],
        'tokens beat ast and code' => [['-c', '--ast', '-t', '-f', 'args.gaz']],
        'ast beats code' => [['-c', '--ast', '-f', 'args.gaz']],

        // Running source, piped or from a file
        'nothing piped' => [[]],
        'piped source' => [[], 'args.gaz'],
        'piped source with arguments' => [['--', 'a', 'b'], 'args.gaz'],
        'a piped program reads nothing more' => [[], 'stdin.gaz'],
        'a program from a file reads what is piped' => [['-f', 'stdin.gaz'], 'args.gaz'],
        'read_line() reads what is piped a line at a time' => [['-f', 'read_line.gaz'], 'lines.txt'],
        'read_line() and then read_stdin() share it' => [['-f', 'read_line_then_stdin.gaz'], 'lines.txt'],
        'read_line() with nothing piped' => [['-f', 'read_line.gaz']],
        'read_line() in a piped program is null' => [[], 'read_line.gaz'],
        'a runtime error with a trace' => [['-f', 'runtime_error.gaz']],
        'a runtime error, piped' => [[], 'runtime_error.gaz'],
        'a syntax error' => [['-f', 'syntax_error.gaz']],
        'a syntax error, piped' => [[], 'syntax_error.gaz'],
        'a lexer error' => [['-f', 'error_lexer.gaz']],
        'exit' => [['-f', 'exit.gaz']],
        'cli: --help is written to standard output' => [['-f', 'todo.gaz', '--', '--help']],
        'cli: a subcommand has its own help' => [['-f', 'todo.gaz', '--', 'add', '-h']],
        'cli: a subcommand runs with what was parsed' => [['-f', 'todo.gaz', '--', '-vf', 'home.txt', 'add', 'milk', 'shop', 'today']],
        'cli: a mistake exits 2, on standard error' => [['-f', 'todo.gaz', '--', 'add']],
        'cli: an unknown option' => [['-f', 'todo.gaz', '--', '--nope', 'list']],
        'an uncaught value' => [['-f', 'thrown.gaz']],
        'an include' => [['-f', 'include.gaz', 'x']],
        'an include, piped, from the working directory' => [[], 'include.gaz'],
        'an include, piped, from elsewhere' => [[], 'tests/cli/include.gaz', '.'],
        'an error in an included file' => [['-f', 'tests/cli/include_error.gaz'], null, '.'],

        // The front end's modes
        'tokens' => [['-t', '-f', 'args.gaz']],
        'tokens, piped' => [['--tokens'], 'args.gaz'],
        'tokens up to a lexer error' => [['-t', '-f', 'error_lexer.gaz']],
        'tokens up to a lexer error, piped' => [['-t'], 'error_lexer.gaz'],
        'ast' => [['--ast', '-f', 'include.gaz']],
        'ast, piped' => [['--ast'], 'args.gaz'],
        'ast of a syntax error' => [['--ast', '-f', 'syntax_error.gaz']],
        'ast of a syntax error, piped' => [['--ast'], 'syntax_error.gaz'],
        'code' => [['-c', '-f', 'include.gaz']],
        'code, piped' => [['--code'], 'include.gaz'],
        'code of a syntax error' => [['-c', '-f', 'syntax_error.gaz']],
        'code of a syntax error, piped' => [['-c'], 'syntax_error.gaz'],

        // Bytecode
        'bytecode' => [['-f', self::BUILD.'/args.gzb', '--', 'a'], null, '.'],
        'bytecode, piped' => [['--', 'a'], self::BUILD.'/args.gzb', '.'],
        'bytecode with a runtime error' => [['-f', self::BUILD.'/runtime_error.gzb'], null, '.'],
        'bytecode with a runtime error, from elsewhere' => [['-f', '../../'.self::BUILD.'/runtime_error.gzb']],
        'bytecode with a runtime error, piped' => [[], self::BUILD.'/runtime_error.gzb', '.'],
        'code of bytecode prints it' => [['-c', '-f', self::BUILD.'/args.gzb'], null, '.'],
        'code of piped bytecode prints it' => [['-c'], self::BUILD.'/args.gzb', '.'],
        'broken bytecode' => [['-f', 'broken.gzb']],
        'broken bytecode, piped' => [[], 'broken.gzb'],
        'broken bytecode without the extension' => [['-f', 'broken_bytecode']],
        'a .gzb file that is not bytecode' => [['-f', self::BUILD.'/source.gzb'], null, '.'],
        'tokens of bytecode' => [['-t', '-f', 'broken.gzb']],
        'ast of piped bytecode' => [['--ast'], 'broken.gzb'],
        'an option that is gone' => [['--interpreter', '-f', 'args.gaz']],
    ];

    /**
     * What each case gave: stdout, stderr and the exit code
     *
     * @var array<string, array{0: string, 1: string, 2: int}>
     */
    private static array $results = [];

    public static function cases(): array
    {
        return array_map(fn ($name) => [$name], array_combine(array_keys(self::CASES), array_keys(self::CASES)));
    }

    /**
     * @dataProvider cases
     */
    public function test_the_cli_prints_what_is_recorded(string $name)
    {
        $result = self::results()[$name];
        $base = self::expected($name);
        if (getenv('GAZLANG_RECORD') !== false) {
            CVM::recordAt($base, $result);
        }
        $expected = CVM::recorded($base);
        $this->assertNotNull($expected, "{$name}: nothing recorded; GAZLANG_RECORD=1 vendor/bin/phpunit --filter CliTest");

        $this->assertSame($expected, [CVM::portable($result[0]), CVM::portable($result[1]), $result[2]], $name);
    }

    public function test_every_recording_belongs_to_a_case()
    {
        $bases = array_map(self::expected(...), array_keys(self::CASES));
        $this->assertSame(count($bases), count(array_unique($bases)), 'two cases share a name');
        $recorded = array_unique(array_map(fn ($path) => preg_replace('/\.(stdout|stderr|exit)$/', '', $path), glob(self::ROOT.'/'.self::DIR.'/expected/*')));
        $this->assertSame([], array_values(array_diff($recorded, $bases)));
    }

    /**
     * Where a case's output is recorded, without the suffix: its name as a file name
     */
    private static function expected(string $name): string
    {
        return self::ROOT.'/'.self::DIR.'/expected/'.trim(preg_replace('/[^a-z0-9=-]+/', '_', strtolower($name)), '_-');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    /**
     * The bytecode the table's cases run, which vm/coverage.php makes too: compiled next to a
     * copy of its source, so the locations in it lead to that copy
     */
    public static function fixtures(): void
    {
        @mkdir(self::ROOT.'/'.self::BUILD, 0777, true);
        foreach (['args', 'runtime_error'] as $name) {
            copy(self::ROOT.'/'.self::DIR."/{$name}.gaz", self::ROOT.'/'.self::BUILD."/{$name}.gaz");
            [$code, $err] = CVM::process([self::ROOT.'/bin/gazlang', '-c', '-f', self::BUILD."/{$name}.gaz"]);
            if ($err !== '') {
                throw new \RuntimeException($err);
            }
            file_put_contents(self::ROOT.'/'.self::BUILD."/{$name}.gzb", $code);
        }
        copy(self::ROOT.'/'.self::DIR.'/args.gaz', self::ROOT.'/'.self::BUILD.'/source.gzb');
    }

    private static function results(): array
    {
        if (self::$results === []) {
            CVM::build();
            self::fixtures();

            // By working directory, since CVM::processes() runs a batch in one
            $by_dir = [];
            foreach (self::CASES as $name => $case) {
                $by_dir[$case[2] ?? self::DIR][$name] = $case;
            }
            foreach ($by_dir as $dir => $batch) {
                $commands = array_map(fn ($case) => [self::ROOT.'/'.CVM::BINARY, ...$case[0]], $batch);
                $stdin = array_map(fn ($case) => $case[1] ?? '/dev/null', $batch);
                self::$results += CVM::processes($commands, [], $stdin, self::ROOT."/{$dir}");
            }
        }

        return self::$results;
    }
}
