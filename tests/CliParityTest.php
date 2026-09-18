<?php

namespace GazLang\Tests;

use GazLang\CodeGenerator\CodeGenerator;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;

/**
 * The C CLI (vm/gazvm) against the PHP one (bin/gazlang), which is the spec: every invocation in
 * the table runs through both, which must print the same standard output and standard error and
 * exit with the same code. This is what makes replacing one with the other safe (the bootstrap
 * plan's step 3, see CLAUDE.md).
 *
 * The C side is the sanitized build. The one difference on purpose is --interpreter, which only
 * PHP has: the C CLI refuses it, and its help says so on that line, which is the only change
 * made to what PHP prints before comparing.
 */
class CliParityTest extends GazLangTestCase
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
    private const CASES = [
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
        'a runtime error with a trace' => [['-f', 'runtime_error.gaz']],
        'a runtime error, piped' => [[], 'runtime_error.gaz'],
        'a syntax error' => [['-f', 'syntax_error.gaz']],
        'a syntax error, piped' => [[], 'syntax_error.gaz'],
        'a lexer error' => [['-f', 'error_lexer.gaz']],
        'exit' => [['-f', 'exit.gaz']],
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
        'the interpreter on bytecode' => [['--interpreter', '-f', 'broken.gzb']],
    ];

    /**
     * What each case gave, [PHP, C], each [stdout, stderr, exit code]
     *
     * @var array<string, array{0: array{0: string, 1: string, 2: int}, 1: array{0: string, 1: string, 2: int}}>
     */
    private static array $results = [];

    public static function cases(): array
    {
        return array_map(fn ($name) => [$name], array_combine(array_keys(self::CASES), array_keys(self::CASES)));
    }

    /**
     * @dataProvider cases
     */
    public function test_the_c_cli_matches_the_php_cli(string $name)
    {
        [$php, $c] = self::results()[$name];

        $this->assertSame($php, $c, $name);
    }

    public function test_the_c_cli_has_no_interpreter()
    {
        [, $c] = self::runBoth([[['--interpreter', '-f', 'args.gaz']]])[0];

        $this->assertSame(['', "Error: There is no interpreter here, only the VM: run bin/gazlang-php --interpreter\n", 1], $c);
    }

    /**
     * @return array<string, array{0: array{0: string, 1: string, 2: int}, 1: array{0: string, 1: string, 2: int}}>
     */
    private static function results(): array
    {
        if (self::$results === []) {
            @mkdir(self::ROOT.'/'.self::BUILD, 0777, true);
            // Written for where it is saved, so its paths lead back to the source (../../../tests/cli/...)
            $cwd = getcwd();
            chdir(self::ROOT);
            try {
                foreach (['args', 'runtime_error'] as $name) {
                    $tree = (new Parser(new Lexer((string) file_get_contents(self::DIR."/{$name}.gaz")), self::DIR."/{$name}.gaz"))->parse();
                    file_put_contents(self::BUILD."/{$name}.gzb", (new CodeGenerator($tree))->compile()->write(self::BUILD."/{$name}.gaz"));
                }
            } finally {
                chdir($cwd);
            }
            copy(self::ROOT.'/'.self::DIR.'/args.gaz', self::ROOT.'/'.self::BUILD.'/source.gzb');
            self::$results = array_combine(array_keys(self::CASES), self::runBoth(array_values(self::CASES)));
        }

        return self::$results;
    }

    /**
     * Run cases through both CLIs, all at once
     *
     * @param  list<array{0: list<string>, 1?: string|null, 2?: string}>  $cases
     * @return list<array{0: array{0: string, 1: string, 2: int}, 1: array{0: string, 1: string, 2: int}}>
     */
    private static function runBoth(array $cases): array
    {
        CVM::build();
        $results = [];
        // By working directory, since CVM::processes() runs a batch in one
        $by_dir = [];
        foreach ($cases as $i => $case) {
            $by_dir[$case[2] ?? self::DIR][$i] = $case;
        }
        foreach ($by_dir as $dir => $batch) {
            $commands = $stdin = [];
            foreach ($batch as $i => [$args]) {
                $commands["php{$i}"] = [PHP_BINARY, '-d', 'pcov.enabled=0', self::ROOT.'/bin/gazlang', ...$args];
                $commands["c{$i}"] = [self::ROOT.'/'.CVM::BINARY, ...$args];
                $stdin["php{$i}"] = $stdin["c{$i}"] = $batch[$i][1] ?? '/dev/null';
            }
            $out = CVM::processes($commands, ['GAZLANG_RESTARTED' => '1'], null, $stdin, self::ROOT."/{$dir}");
            foreach ($batch as $i => $_) {
                $php = $out["php{$i}"];
                $php[0] = str_replace('the tree-walking interpreter instead of the VM', 'the tree-walking interpreter instead of the VM (bin/gazlang-php only)', $php[0]);
                $results[$i] = [$php, $out["c{$i}"]];
            }
        }
        ksort($results);

        return $results;
    }
}
