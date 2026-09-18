<?php

namespace GazLang\Tests;

use GazLang\CodeGenerator\CodeGenerator;
use GazLang\CodeGenerator\Program;
use GazLang\GazLangError;
use GazLang\Interpreter\Interpreter;
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
     * Run a GazLang file as `php bin/gazlang -f FILE -- ARGS` would, but in-process (a CLI run costs ~0.5s)
     *
     * Runs from the project root, so FILE is relative to it and errors name files the
     * same way; errors are printed as "Error: <message>" and give exit code 1. Deep
     * recursion can segfault in-process while pcov is loaded, so test that through the CLI.
     *
     * @param  string  $file  Path relative to the project root
     * @param  string[]  $args  Arguments returned by args()
     * @return array{0: string, 1: int} The output and exit code
     */
    protected function runProgram(string $file, array $args = [], bool $vm = false): array
    {
        return $this->exitCodeOf(function () use ($file, $args, $vm) {
            $parser = new Parser(new Lexer(file_get_contents($file)), $file);
            if ($vm) {
                $this->machine($parser, $file, $args)->run();
            } else {
                (new Interpreter($parser, $args))->interpret();
            }
        });
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
     * Create an interpreter for the given input
     *
     * @param  string  $input  The GazLang code to interpret
     */
    protected function createInterpreter(string $input): Interpreter
    {
        $parser = $this->createParser($input);

        return new Interpreter($parser);
    }

    /**
     * Execute GazLang code and return the output
     *
     * @param  string  $input  The GazLang code to execute
     * @return string The output from echo statements
     */
    protected function executeCode(string $input): string
    {
        [$output, $error] = $this->capture(fn () => $this->createInterpreter($input)->interpret());

        // Every snippet also runs on the VM, which must print the same and fail the same way
        [$vm_output, $vm_error] = $this->capture(
            fn () => $this->machine($this->createParser($input))->run()
        );
        $this->assertSame($output, $vm_output, "The VM printed something else for:\n{$input}");
        $describe = fn (?Throwable $e) => $e === null ? null : [
            get_class($e), $e->getMessage(), $e instanceof GazLangError ? [$e->path, $e->line_number] : null,
        ];
        // Class and location too: error() messages carry no location, but catch sees it
        $this->assertSame($describe($error), $describe($vm_error), "The VM failed differently for:\n{$input}");

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
        // goes to a stream of its own and counts as output the two backends must agree on
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
