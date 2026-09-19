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
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Base test case for GazLang tests with helper methods
 */
abstract class GazLangTestCase extends TestCase
{
    protected const ROOT = __DIR__.'/..';

    /**
     * Run a GazLang file as `php bin/gazlang-php -f FILE -- ARGS` would, but in-process (a CLI run costs ~0.5s)
     *
     * Runs from the project root, so FILE is relative to it and errors name files the
     * same way; errors are printed as "Error: <message>" and give exit code 1.
     *
     * @param  string  $file  Path relative to the project root
     * @param  string[]  $args  Arguments returned by args()
     * @return array{0: string, 1: int} The output and exit code
     */
    protected function runProgram(string $file, array $args = []): array
    {
        return $this->exitCodeOf(fn () => $this->machine(new Parser(new Lexer(file_get_contents($file)), $file), $file, $args)->run());
    }

    /**
     * Compile a GazLang file once, for runCompiled() to run many times
     *
     * @param  string  $file  Path relative to the project root
     * @return Program The program, read back from its bytecode as every test's VM side is
     */
    protected static function compileProgram(string $file): Program
    {
        $path = self::ROOT.'/'.$file;

        return self::roundTrip(new Parser(new Lexer(file_get_contents($path)), $path), $path);
    }

    /**
     * assertSame() for long text, failing with the first line that differs: phpunit's own diff
     * is quadratic, and on a port's multi-megabyte tree dumps it runs for minutes
     */
    protected function assertSameText(string $expected, string $actual, string $message): void
    {
        if ($expected === $actual) {
            $this->addToAssertionCount(1);

            return;
        }
        $a = explode("\n", $expected);
        $b = explode("\n", $actual);
        for ($line = 0; ($a[$line] ?? null) === ($b[$line] ?? null); $line++);
        $this->fail("{$message}, first at line ".($line + 1).":\n  expected: ".($a[$line] ?? '(nothing)')."\n  actual:   ".($b[$line] ?? '(nothing)'));
    }

    /**
     * Assert a port printed what its expected file says, output and exit code
     *
     * The file holds the output as the driver prints it, the checkout's path as <root>; the exit
     * code is 1 when a line starts with "Error: ", as no line of tokens, a tree or bytecode does
     * (an error's message can itself hold a newline, so it needn't be the last line). With
     * GAZLANG_RECORD_PORTS set, what the port printed is recorded instead, for review as a diff:
     * GAZLANG_RECORD_PORTS=1 vendor/bin/phpunit --filter SelfHosted
     *
     * @param  string  $expected_file  The expected file, relative to the project root
     * @param  array{0: string, 1: int}  $actual  What the port printed, and its exit code
     */
    protected function assertPortPrints(string $expected_file, array $actual, string $message): void
    {
        $path = self::ROOT.'/'.$expected_file;
        $output = CVM::portable($actual[0]);
        if (getenv('GAZLANG_RECORD_PORTS') !== false) {
            file_put_contents($path, $output);
        }
        $this->assertFileExists($path, "{$message}: nothing recorded; GAZLANG_RECORD_PORTS=1 vendor/bin/phpunit --filter SelfHosted");
        $expected = (string) file_get_contents($path);
        $this->assertSameText($expected, $output, $message);
        $this->assertSame(preg_match('/^Error: /m', $expected), $actual[1], "{$message}: exit code");
    }

    /**
     * The expected files next to a corpus's .gaz files, which must each belong to one
     *
     * @param  string  $dir  The corpus, relative to the project root
     * @param  list<string>  $suffixes  What follows the name in an expected file, longest first: piped.ast, ast...
     * @return list<string> The expected files without a .gaz file of their own
     */
    protected static function strayExpectations(string $dir, array $suffixes): array
    {
        $stray = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT."/{$dir}", \FilesystemIterator::SKIP_DOTS)) as $path) {
            // The longest suffix first: X.piped.ast ends in .ast too
            foreach ($suffixes as $suffix) {
                if (str_ends_with((string) $path, ".{$suffix}")) {
                    if (! is_file(substr((string) $path, 0, -strlen($suffix)).'gaz')) {
                        $stray[] = substr((string) $path, strlen(self::ROOT) + 1);
                    }
                    break;
                }
            }
        }

        return $stray;
    }

    /**
     * Run a compiled program on the VM as runProgram() would
     *
     * @param  string[]  $args  Arguments returned by args()
     * @param  string  $cwd  The working directory to run it in
     * @return array{0: string, 1: int} The output and exit code
     */
    protected function runCompiled(Program $program, array $args = [], string $cwd = self::ROOT): array
    {
        return $this->exitCodeOf(fn () => (new VM($program, $args))->run(), $cwd);
    }

    /**
     * Run a program from the project root, giving its output and exit code as the CLI would
     *
     * @param  callable  $run  Runs the program, printing its output
     * @param  string  $in  The working directory to run it in
     * @return array{0: string, 1: int} The output and exit code
     */
    private function exitCodeOf(callable $run, string $in = self::ROOT): array
    {
        $cwd = getcwd();
        chdir($in);
        ob_start();

        try {
            $run();
            $exit_code = 0;
        } catch (ExitSignal $e) {
            $exit_code = $e->code;
        } catch (GazLangError $e) {
            // As the CLI prints it: the message, then the calls that were running
            echo $e->report(), "\n";
            $exit_code = 1;
        } catch (Throwable $e) {
            echo "Error: {$e->getMessage()}\n";
            $exit_code = 1;
        } finally {
            $output = ob_get_clean();
            chdir($cwd);
        }

        return [$output, $exit_code];
    }

    /**
     * Create a lexer for the given input
     *
     * @param  string  $input  The GazLang code to parse
     */
    protected function createLexer(string $input): Lexer
    {
        return new Lexer($input);
    }

    /**
     * Create a parser for the given input
     *
     * @param  string  $input  The GazLang code to parse
     */
    protected function createParser(string $input): Parser
    {
        $lexer = $this->createLexer($input);

        return new Parser($lexer);
    }

    /**
     * Create the VM for the given input, compiled as executeCode() compiles it
     *
     * @param  string  $input  The GazLang code to run
     */
    protected function createVM(string $input): VM
    {
        return $this->machine($this->createParser($input));
    }

    /**
     * Execute GazLang code and return the output
     *
     * @param  string  $input  The GazLang code to execute
     * @return string The output from echo statements
     */
    protected function executeCode(string $input): string
    {
        // vm/snippets.php collects every snippet for the C VM's harness
        if (($record = getenv('GAZLANG_RECORD_SNIPPETS')) !== false) {
            // with this checkout's paths relative to it, since the harness runs them from its root
            file_put_contents($record, Lexer::quote(str_replace(dirname(__DIR__).'/', '', $input))."\n", FILE_APPEND);
        }

        [$output, $error] = $this->capture(fn () => $this->createVM($input)->run());
        if ($error !== null) {
            throw $error;
        }

        return $output;
    }

    /**
     * The VM for a program, which runs it as a bytecode file would
     *
     * Compiling, writing and reading it back is what `gazlang -c -f x.gaz > x.gzb` and
     * `gazlang -f x.gzb` do, so every test that runs on the VM also tests the format and
     * everything the reader checks. BytecodeTest covers the file itself.
     *
     * @param  Parser  $parser  The parser for the program
     * @param  string|null  $file  The file it came from, so its paths are written and read back
     * @param  string[]  $args  Arguments returned by args()
     */
    private function machine(Parser $parser, ?string $file = null, array $args = []): VM
    {
        return new VM(self::roundTrip($parser, $file), $args);
    }

    /**
     * Compile a program, write it as bytecode and read that back, so the suite tests the format too
     */
    private static function roundTrip(Parser $parser, ?string $file): Program
    {
        return Program::read((new CodeGenerator($parser->parse()))->compile()->write($file), $file);
    }

    /**
     * Run a callable, capturing what it prints and what it throws
     *
     * @return array{0: string, 1: Throwable|null}
     */
    private function capture(callable $run): array
    {
        // print_error() writes to standard error, which output buffering doesn't catch, so it
        // goes to a stream of its own and counts as output
        $stderr = fopen('php://memory', 'w+');
        Builtins::$error_stream = $stderr;
        ob_start();
        try {
            $run();
            $error = null;
        } catch (Throwable $e) {
            $error = $e;
        } finally {
            $output = ob_get_clean();
            Builtins::$error_stream = null;
            rewind($stderr);
            $printed = (string) stream_get_contents($stderr);
            fclose($stderr);
        }

        return [$output.$printed, $error];
    }

    /**
     * Generate VM code for the given input, as the instructions of each block
     *
     * The bytecode file's header, locations and slot names are left out, so a test can say
     * what it is about; each block after the top level is introduced by its header line, as
     * the file writes it ("fn f 0 0"). BytecodeTest covers the file itself.
     *
     * @param  string  $input  The GazLang code
     * @return string The generated code, one instruction per line
     */
    protected function generateCode(string $input): string
    {
        $lines = explode("\n", trim((new CodeGenerator($this->createParser($input)->parse()))->generate()));
        $code = array_filter($lines, fn (string $line) => $line !== '' && $line !== 'top'
            && ! preg_match('/^(GAZLANG|globals|locals|@ |field |method |capture |self )/', $line));

        return implode("\n", $code);
    }
}
