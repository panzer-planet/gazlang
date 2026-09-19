<?php

namespace GazLang\Tests;

/**
 * The bytecode file format: what it looks like, that it reads back, and that the instruction
 * table (INFO in vm/load.c), docs/bytecode.md and the VM name the same instructions
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
        $this->assertSame(
            file_get_contents(self::ROOT.'/tests/fixtures/bytecode/example.gzb'),
            self::succeed(['-c', '-f', 'tests/fixtures/bytecode/example.gaz'])
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
        $vm = implode('', array_map('file_get_contents', glob(self::ROOT.'/vm/*.c')));
        preg_match_all('/case OP_([A-Z_]+):/', $vm, $cases);

        $instructions = array_keys(self::instructions());
        sort($instructions);
        sort($documented);

        $this->assertGreaterThan(80, count($instructions), 'Too few instructions were read from vm/load.c');
        $this->assertSame($instructions, array_values(array_unique($documented)), 'docs/bytecode.md and the instruction table disagree');
        $this->assertSame([], array_values(array_diff($instructions, self::NOT_RUN, $cases[1])), 'Instructions the VM has no case for');
    }

    public function test_the_documented_stack_effects_match_the_table()
    {
        $table = self::instructions();
        $checked = 0;
        foreach (explode("\n", (string) file_get_contents(self::ROOT.'/docs/bytecode.md')) as $line) {
            if (! str_starts_with($line, '| `')) {
                continue;
            }
            [, $instruction, $stack] = explode('|', $line);
            // Rows for a whole group of instructions, and the ones whose effect depends on an
            // argument or on which way they go, say so in words rather than as a b -- c
            if (! preg_match('/^((?:[a-z]+ )*)-- ?((?:[a-z]+ ?)*)$/', trim(str_replace('`', '', $stack)).' ', $matches)) {
                continue;
            }
            preg_match_all('/`([A-Z_]+)[^`]*`/', $instruction, $names);
            foreach ($names[1] as $name) {
                $effect = [count(array_filter(explode(' ', trim($matches[1])))), count(array_filter(explode(' ', trim($matches[2]))))];
                $this->assertSame($effect, $table[$name], "{$name}'s stack effect");
                $checked++;
            }
        }

        $this->assertGreaterThan(30, $checked, 'Too few stack effects were read from docs/bytecode.md');
    }

    public function test_every_instruction_the_compiler_emits_is_in_the_table()
    {
        $emitted = [];
        foreach ($this->programs() as $file) {
            foreach (explode("\n", self::succeed(['-c', '-f', $file])) as $line) {
                // Instructions are in capitals; the records around them (fn, locals, @...) aren't
                if (preg_match('/^([A-Z_]+)(?: |$)/', $line, $match) && $match[1] !== 'GAZLANG') {
                    $emitted[$match[1]] = true;
                }
            }
        }

        $this->assertSame([], array_values(array_diff(array_keys($emitted), array_keys(self::instructions()))));
        // The corpus is wide enough to be worth saying so: most of the table is exercised
        $this->assertGreaterThan(50, count($emitted));
    }

    public function test_compiling_is_deterministic()
    {
        // The same source always gives the same file
        $file = 'tests/fixtures/bytecode/example.gaz';

        $this->assertSame(self::succeed(['-c', '-f', $file]), self::succeed(['-c', '-f', $file]));
    }

    public function test_the_cli_compiles_to_a_file_and_runs_it()
    {
        $gazlang = escapeshellarg(self::binary());
        $bytecode = escapeshellarg(sys_get_temp_dir().'/gazlang_example.gzb');
        exec("cd {$this->root()} && {$gazlang} -c -f tests/fixtures/bytecode/example.gaz > {$bytecode} && {$gazlang} -f {$bytecode}", $output, $exit_code);

        $this->assertSame(['11', 'caught: Division by zero', '{"a" => [1, 2.5]}'], $output);
        $this->assertSame(0, $exit_code);
    }

    public function test_a_gzb_file_is_bytecode_even_when_it_is_broken()
    {
        $gazlang = escapeshellarg(self::binary());
        exec("cd {$this->root()} && {$gazlang} -f tests/bytecode_corpus/error_not_bytecode.gzb 2>&1", $output, $exit_code);

        $this->assertSame(['Error: Not a bytecode file at tests/bytecode_corpus/error_not_bytecode.gzb:1'], $output);
        $this->assertSame(1, $exit_code);
    }

    public function test_tokens_of_bytecode_are_refused()
    {
        $gazlang = escapeshellarg(self::binary());
        exec("cd {$this->root()} && {$gazlang} --tokens -f tests/fixtures/bytecode/example.gzb 2>&1", $output, $exit_code);

        $this->assertSame(['Error: tests/fixtures/bytecode/example.gzb is bytecode, which only the VM runs'], $output);
        $this->assertSame(1, $exit_code);

        // Piped in, there is no file to name
        exec("cd {$this->root()} && {$gazlang} --tokens < tests/fixtures/bytecode/example.gzb 2>&1", $piped, $piped_exit);

        $this->assertSame(['Error: standard input is bytecode, which only the VM runs'], $piped);
        $this->assertSame(1, $piped_exit);
    }

    /**
     * The project root, quoted for a shell
     */
    private function root(): string
    {
        return escapeshellarg((string) realpath(self::ROOT));
    }

    public function test_a_file_of_another_version_is_refused()
    {
        $this->expectExceptionMessage('Bytecode version 2, but this is GazLang bytecode 1');
        self::succeed([], "GAZLANG BYTECODE 2\nglobals\n\ntop\nlocals\n");
    }

    public function test_an_unknown_instruction_is_a_load_error()
    {
        $this->expectExceptionMessage("Unknown instruction 'PUSH_STR' on line 6");
        self::succeed([], "GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nPUSH_STR 1\n");
    }

    public function test_an_undefined_label_is_a_load_error()
    {
        $this->expectExceptionMessage("Undefined label 'NOWHERE' in the top level");
        self::succeed([], "GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nJMP NOWHERE\n");
    }

    public function test_a_label_of_another_block_is_a_load_error()
    {
        // Labels are scoped to their block
        $this->expectExceptionMessage("Undefined label 'HERE' in the top level");
        self::succeed([], "GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nJMP HERE\n\nfn f 0 0\nlocals\nLABEL HERE\nPUSH null\nRET\n");
    }

    public function test_calling_a_builtin_with_call_is_a_load_error()
    {
        // CALL is for the program's own functions; a builtin is CALL_BUILTIN
        $this->expectExceptionMessage("Undefined function 'len' in the top level");
        self::succeed([], "GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nPUSH \"x\"\nCALL len 1\nPOP\n");
    }

    public function test_a_class_record_naming_what_is_not_there_is_a_load_error()
    {
        $this->expectExceptionMessage("Undefined class 'Missing' in class C");
        self::succeed([], "GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\n\nclass C extends Missing\nlocals\nLOAD_THIS\nRET\n");
    }

    public function test_a_method_without_a_block_is_a_load_error()
    {
        $this->expectExceptionMessage('Method _ has no block C._ in class C');
        self::succeed([], "GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\n\nclass C\nmethod _ C\nlocals\nLOAD_THIS\nRET\n");
    }

    public function test_a_block_that_runs_off_its_end_is_a_load_error()
    {
        // Without this, a call to f would carry on into the block after it
        $this->expectExceptionMessage("The code runs off the end of the block, which must end in RET in 'f'");
        self::succeed([], "GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nCALL f 0\nPOP\n\nfn f 0 0\nlocals\nPUSH 1\nPOP\n");
    }

    public function test_a_slot_the_block_does_not_have_is_a_load_error()
    {
        $this->expectExceptionMessage("Slot 7 is not one of the block's 0 locals in the top level");
        self::succeed([], "GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nLOAD 7\nPOP\n");
    }

    public function test_calling_a_function_that_is_not_there_is_a_load_error()
    {
        $this->expectExceptionMessage("Undefined function 'missing' in the top level");
        self::succeed([], "GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nCALL missing 0\nPOP\n");
    }

    public function test_an_instruction_with_the_wrong_arguments_is_a_load_error()
    {
        $this->expectExceptionMessage('Unexpected end of line on line 6');
        self::succeed([], "GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nCALL f\n");
    }

    public function test_popping_from_an_empty_stack_is_a_load_error()
    {
        $this->expectExceptionMessage('ADD needs 2 values but the stack is 1 deep at instruction 1 in the top level');
        self::succeed([], "GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nPUSH 1\nADD\nPOP\n");
    }

    public function test_a_stack_that_differs_between_paths_is_a_load_error()
    {
        $this->expectExceptionMessage('The stack is 0 deep at LABEL END (instruction 3), but 1 on another path in the top level');
        self::succeed([], "GAZLANG BYTECODE 1\nglobals\n\ntop\nlocals\nPUSH 1\nJZ END\nPUSH 2\nLABEL END\nPOP\n");
    }

    public function test_a_file_that_was_read_runs_the_same_as_the_program_it_came_from()
    {
        $this->assertSame("11\ncaught: Division by zero\n{\"a\" => [1, 2.5]}\n", self::succeed(['-f', 'tests/fixtures/bytecode/example.gzb']));
    }

    public function test_an_error_from_a_file_that_was_read_says_where_in_the_source_it_was()
    {
        // Bytecode saved next to its source reports exactly what running the source does
        $gzb = self::ROOT.'/tests/fixtures/bytecode/fails.gzb';
        file_put_contents($gzb, self::succeed(['-c', '-f', 'tests/fixtures/bytecode/fails.gaz']));
        try {
            $this->expectExceptionMessage('Division by zero at tests/fixtures/bytecode/fails.gaz:2');
            self::succeed(['-f', 'tests/fixtures/bytecode/fails.gzb']);
        } finally {
            unlink($gzb);
        }
    }

    /**
     * The instruction table, INFO in vm/load.c: each instruction's name and, where it is a
     * number, its stack effect as [pops, pushes]
     *
     * @return array<string, array{0: int, 1: int}|null>
     */
    private static function instructions(): array
    {
        preg_match_all('/\[OP_\w+\] = \{"([A-Z_]+)", \d+, \{[^}]*\}, (-?\w+), (-?\w+)\}/', (string) file_get_contents(self::ROOT.'/vm/load.c'), $rows, PREG_SET_ORDER);
        $table = [];
        foreach ($rows as [, $name, $pops, $pushes]) {
            $table[$name] = is_numeric($pops) ? [(int) $pops, (int) $pushes] : null;
        }

        return $table;
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
}
