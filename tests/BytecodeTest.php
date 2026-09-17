<?php

namespace GazLang\Tests;

use GazLang\CodeGenerator\CodeGenerator;
use GazLang\CodeGenerator\Program;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use GazLang\VM\VM;
use ReflectionClass;

/**
 * The bytecode file format: what it looks like, that it reads back, and that the
 * instruction table, docs/bytecode.md and the VM name the same instructions
 */
class BytecodeTest extends GazLangTestCase
{
    /**
     * Instructions the VM never runs, so it has no case for them
     */
    private const NOT_RUN = ['LABEL'];

    public function test_a_program_is_written_as_the_example_file()
    {
        // The example is small but has a class, a lambda, a try/catch and a map literal
        $program = $this->compile('tests/fixtures/bytecode/example.gaz');

        $this->assertSame(
            file_get_contents(self::ROOT.'/tests/fixtures/bytecode/example.gzb'),
            $program->write('tests/fixtures/bytecode/example.gaz')
        );
    }

    public function test_every_instruction_is_documented_and_run_by_the_vm()
    {
        $documented = [];
        foreach (explode("\n", (string) file_get_contents(self::ROOT.'/docs/bytecode.md')) as $line) {
            if (str_starts_with($line, '| `')) {
                preg_match_all('/`([A-Z_]+)[^`]*`/', explode('|', $line)[1], $matches);
                array_push($documented, ...$matches[1]);
            }
        }

        $vm = (string) file_get_contents(self::ROOT.'/src/VM/VM.php');
        preg_match_all("/case '([A-Z_]+)':/", $vm, $cases);
        // The operators the loop's default case applies through Values
        $binary = array_keys((new ReflectionClass(VM::class))->getReflectionConstant('BINARY')->getValue());
        $handled = array_unique([...$cases[1], ...$binary]);

        $instructions = array_keys(Program::INSTRUCTIONS);
        sort($instructions);
        sort($documented);
        sort($handled);

        $this->assertSame($instructions, array_values(array_unique($documented)), 'docs/bytecode.md and Program::INSTRUCTIONS disagree');
        $this->assertSame(array_values(array_diff($instructions, self::NOT_RUN)), $handled, 'The VM and Program::INSTRUCTIONS disagree');
    }

    public function test_every_instruction_the_compiler_emits_is_in_the_table()
    {
        $emitted = [];
        foreach ($this->programs() as $file) {
            foreach ($this->compile($file)->blocks as $block) {
                foreach ($block['code'] as [$opcode]) {
                    $emitted[$opcode] = true;
                }
            }
        }

        $this->assertSame([], array_diff(array_keys($emitted), array_keys(Program::INSTRUCTIONS)));
        // The corpus is wide enough to be worth saying so: most of the table is exercised
        $this->assertGreaterThan(50, count($emitted));
    }

    public function test_writing_and_reading_a_program_gives_the_same_file()
    {
        foreach ($this->programs() as $file) {
            $text = $this->compile($file)->write($file);
            $this->assertSame($text, Program::read($text, $file)->write($file), "{$file} does not read back as it was written");
        }
    }

    public function test_a_file_of_another_version_is_refused()
    {
        $this->expectExceptionMessage('Bytecode version 2, but this is GazLang bytecode 1');
        Program::read("GAZLANG BYTECODE 2\nglobals\n\ntop\nlocals\n");
    }

    public function test_source_is_not_bytecode()
    {
        $this->expectExceptionMessage('Not a bytecode file');
        Program::read("echo 1;\n");
    }

    public function test_an_unknown_instruction_is_a_load_error()
    {
        $this->expectExceptionMessage("Unknown instruction 'PUSH_STR' on line 6");
        Program::read("GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nPUSH_STR 1\n");
    }

    public function test_an_undefined_label_is_a_load_error()
    {
        $this->expectExceptionMessage("Undefined label 'NOWHERE' in the top level");
        Program::read("GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nJMP NOWHERE\n");
    }

    public function test_a_label_of_another_block_is_a_load_error()
    {
        // Labels are scoped to their block
        $this->expectExceptionMessage("Undefined label 'HERE' in the top level");
        Program::read("GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nJMP HERE\n\nfn f 0 0\nlocals\nLABEL HERE\nPUSH null\nRET\n");
    }

    public function test_calling_a_function_that_is_not_there_is_a_load_error()
    {
        $this->expectExceptionMessage("Undefined function 'missing' in the top level");
        Program::read("GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nCALL missing 0\nPOP\n");
    }

    public function test_an_instruction_with_the_wrong_arguments_is_a_load_error()
    {
        $this->expectExceptionMessage('Unexpected end of line on line 6');
        Program::read("GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nCALL f\n");
    }

    public function test_popping_from_an_empty_stack_is_a_load_error()
    {
        $this->expectExceptionMessage('ADD needs 2 values but the stack is 1 deep at instruction 1 in the top level');
        Program::read("GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nPUSH 1\nADD\nPOP\n");
    }

    public function test_a_stack_that_differs_between_paths_is_a_load_error()
    {
        $this->expectExceptionMessage('The stack is 0 deep at LABEL END (instruction 3), but 1 on another path in the top level');
        Program::read("GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nPUSH 1\nJZ END\nPUSH 2\nLABEL END\nPOP\n");
    }

    public function test_a_file_that_was_read_runs_the_same_as_the_program_it_came_from()
    {
        $file = 'tests/fixtures/bytecode/example.gaz';
        $text = $this->compile($file)->write($file);

        $output = $this->capturing(fn () => (new VM(Program::read($text, $file)))->run());

        $this->assertSame("11\ncaught: Division by zero\n{\"a\" => [1, 2.5]}\n", $output);
    }

    public function test_an_error_from_a_file_that_was_read_says_where_in_the_source_it_was()
    {
        $file = 'tests/fixtures/bytecode/fails.gaz';
        $text = $this->compile($file)->write($file);

        $this->expectExceptionMessage('Division by zero at tests/fixtures/bytecode/fails.gaz:2');
        (new VM(Program::read($text, $file)))->run();
    }

    /**
     * Compile a program, from the project root, as the CLI does
     *
     * @param  string  $file  The file, relative to the project root
     */
    private function compile(string $file): Program
    {
        $cwd = getcwd();
        chdir(self::ROOT);

        try {
            return (new CodeGenerator((new Parser(new Lexer((string) file_get_contents($file)), $file))->parse()))->compile();
        } finally {
            chdir($cwd);
        }
    }

    /**
     * Every GazLang program in the repository that compiles on its own
     *
     * @return list<string> The files, relative to the project root
     */
    private function programs(): array
    {
        $cwd = getcwd();
        chdir(self::ROOT);

        try {
            $files = [...glob('examples/*.gaz'), ...glob('lib/*.gaz'), ...glob('tests/gaz/*/*.gaz'), ...glob('selfhost/*.gaz')];
        } finally {
            chdir($cwd);
        }

        return $files;
    }

    /**
     * Run something, returning what it printed
     *
     * @param  callable  $run  What to run
     */
    private function capturing(callable $run): string
    {
        $cwd = getcwd();
        chdir(self::ROOT);
        ob_start();

        try {
            $run();
        } finally {
            $output = ob_get_clean();
            chdir($cwd);
        }

        return (string) $output;
    }
}
