<?php

namespace GazLang\Tests;

use GazLang\GazLangError;
use GazLang\Interpreter\Interpreter;
use GazLang\Runtime\Builtins;
use GazLang\Runtime\MapValue;
use GazLang\Runtime\Values;

class StdlibTest extends GazLangTestCase
{
    public function test_values_gives_a_map_s_values_in_order_and_a_list_as_it_is()
    {
        $this->assertSame(
            "[1, 2]\n[3, 4]\n[]\n",
            $this->executeCode('echo values({"b" => 1, "a" => 2}); echo values([3, 4]); echo values({});')
        );
    }

    public function test_values_needs_a_list_or_map()
    {
        $this->expectExceptionMessage('values() expects list or map, got int');
        $this->executeCode('values(5);');
    }

    public function test_last_gives_a_list_s_last_element()
    {
        $this->assertSame(
            "3\n[4]\nx\n",
            $this->executeCode('echo last([1, 2, 3]); echo last([[4]]); $f = last; echo $f(["x"]);')
        );
    }

    public function test_last_of_an_empty_list_is_an_error()
    {
        $this->expectExceptionMessage('last() expects a non-empty list on line 1');
        $this->executeCode('last([]);');
    }

    public function test_last_needs_a_list()
    {
        $this->expectExceptionMessage('last() expects list, got string');
        $this->executeCode('last("abc");');
    }

    public function test_reverse_turns_a_list_or_string_round()
    {
        $this->assertSame(
            "[\"three\", [2], 1]\n[]\ndesserts\n\n",
            $this->executeCode('echo reverse([1, [2], "three"]); echo reverse([]); echo reverse("stressed"); echo reverse("");')
        );
    }

    public function test_reverse_keeps_a_map_s_keys()
    {
        // 2 and "2" are different keys; a removed entry leaves nothing behind
        $this->assertSame(
            "{\"2\" => \"c\", 2 => \"b\", \"a\" => 1}\n{\"z\" => 3, \"x\" => 1}\n",
            $this->executeCode('echo reverse({"a" => 1, 2 => "b", "2" => "c"}); $m = {"x" => 1, "y" => 2, "z" => 3}; delete $m["y"]; echo reverse($m);')
        );
    }

    public function test_reverse_gives_a_new_value()
    {
        $this->assertSame("[[1, 2], [2, 1, 3]]\n", $this->executeCode('$l = [1, 2]; $r = reverse($l); $r[] = 3; echo [$l, $r];'));
    }

    public function test_reverse_needs_a_list_map_or_string()
    {
        $this->expectExceptionMessage('reverse() expects list or map or string, got int');
        $this->executeCode('reverse(5);');
    }

    public function test_print_writes_without_a_newline_and_converts_as_echo_does()
    {
        $this->assertSame(
            'ab[1, 2]truenull1.5',
            $this->executeCode('print("a"); print("b"); print([1, 2]); print(true); print(null); print(1.5);')
        );
    }

    public function test_print_uses_to_string_and_gives_null()
    {
        $this->assertSame(
            // print writes the object's to_string() with no newline, then echo prints what it gave
            "a pennynull\n",
            $this->executeCode('class Coin { fn to_string() { return "a penny"; } } echo print(Coin());')
        );
    }

    public function test_print_and_print_error_come_out_in_the_order_they_were_written()
    {
        // The CLI runs itself again to set pcov and the JIT, which must hand the program this
        // process's own streams: relaying its output would let standard error overtake it
        $program = 'print("1-out "); print_error("2-err "); print("3-out "); print_error("4-err ");';
        exec(sprintf(
            'echo %s | %s %s 2>&1',
            escapeshellarg($program),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::ROOT.'/bin/gazlang-php')
        ), $output);

        $this->assertSame(['1-out 2-err 3-out 4-err'], $output);
    }

    public function test_the_cli_runs_itself_again_at_most_once()
    {
        // A -d on the command line isn't in $argv, so a run that satisfied one of the settings
        // and not the other used to lose the flags it was given and restart forever
        $command = sprintf(
            '%s -d display_errors=stderr -d opcache.enable_cli=1 -d opcache.jit=1235 %s -f %s 2>/dev/null',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::ROOT.'/bin/gazlang-php'),
            escapeshellarg(self::ROOT.'/tests/fixtures/hello.gaz')
        );
        $process = proc_open($command, [1 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);

        $output = '';
        $deadline = microtime(true) + 10;
        stream_set_blocking($pipes[1], false);
        while (microtime(true) < $deadline && proc_get_status($process)['running']) {
            $output .= (string) stream_get_contents($pipes[1]);
            usleep(20000);
        }
        $running = proc_get_status($process)['running'];
        $output .= (string) stream_get_contents($pipes[1]);
        if ($running) {
            proc_terminate($process, 9);
        }
        fclose($pipes[1]);
        proc_close($process);

        $this->assertFalse($running, 'gazlang kept restarting itself instead of running the program');
        $this->assertSame("hello\n", $output);
    }

    public function test_print_error_writes_to_standard_error()
    {
        // Through the CLI, the only place the two streams are really separate
        $program = 'print("out"); print_error("problem"); print("put");';
        $gazlang = sprintf('echo %s | %s %s', escapeshellarg($program), escapeshellarg(PHP_BINARY), escapeshellarg(self::ROOT.'/bin/gazlang-php'));

        exec("{$gazlang} 2>/dev/null", $out);
        exec("{$gazlang} 2>&1 >/dev/null", $err);

        $this->assertSame(['output'], $out);
        $this->assertSame(['problem'], $err);
    }

    public function test_slice_strings_and_arrays()
    {
        $this->assertEquals("ell\nlo\n\n[2, 3]\n[3]\n", $this->executeCode(
            'echo slice("hello", 1, 3); echo slice("hello", -2, 5); echo slice("hi", 5, 1);'
            .' echo slice([1, 2, 3], 1, 2); echo slice([1, 2, 3], -1);'
        ));
    }

    public function test_lower()
    {
        $this->assertEquals("function\n", $this->executeCode('echo lower("FuncTION");'));
    }

    public function test_chr_and_ord()
    {
        $this->assertEquals("A\n97\ntrue\n255\n", $this->executeCode(
            'echo chr(65); echo ord("a"); echo chr(0) == "\x00"; echo ord(chr(255));'
        ));
    }

    /**
     * @dataProvider invalidBytes
     */
    public function test_chr_and_ord_reject_what_is_not_one_byte(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode($code);
    }

    public static function invalidBytes(): array
    {
        return [
            'chr above 255' => ['chr(256);', 'chr() expects a byte value from 0 to 255, got 256'],
            'chr negative' => ['chr(-1);', 'chr() expects a byte value from 0 to 255, got -1'],
            'chr string' => ['chr("A");', 'chr() expects int, got string'],
            'ord empty' => ['ord("");', 'ord() expects a one character string, got ""'],
            'ord two characters' => ['ord("ab");', 'ord() expects a one character string, got "ab"'],
            'ord multibyte' => ['ord("\u{e9}");', 'ord() expects a one character string, got "é"'],
        ];
    }

    /**
     * @dataProvider stringBuiltinErrors
     */
    public function test_string_builtins_reject_bad_arguments(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode($code);
    }

    public static function stringBuiltinErrors(): array
    {
        return [
            'upper on an int' => ['upper(1);', 'upper() expects string, got int'],
            'trim on null' => ['trim(null);', 'trim() expects string, got null'],
            'split separator' => ['split("a", 1);', 'split() expects string, got int'],
            'join non-array' => ['join("abc", ",");', 'join() expects list, got string'],
            'join separator' => ['join([], null);', 'join() expects string, got null'],
            'replace empty search' => ['replace("abc", "", "x");', 'replace() cannot search for an empty string'],
            'contains on an array' => ['contains(["a"], "a");', 'contains() expects string, got list'],
            'index_of needle' => ['index_of("abc", 1);', 'index_of() expects string, got int'],
            'repeat negative' => ['repeat("a", -1);', 'repeat() count must not be negative, got -1'],
            'repeat count' => ['repeat("a", "2");', 'repeat() expects int, got string'],
        ];
    }

    public function test_to_int()
    {
        $this->assertEquals("42\n-7\n8\n5\n", $this->executeCode(
            'echo to_int("42"); echo to_int("-7"); echo to_int("007") + 1; echo to_int(5);'
        ));
    }

    /**
     * @dataProvider invalidInts
     */
    public function test_to_int_rejects_anything_else(string $argument, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode("echo to_int({$argument});");
    }

    public static function invalidInts(): array
    {
        return [
            'letters' => ['"12a"', 'to_int() cannot convert "12a"'],
            'empty' => ['""', 'to_int() cannot convert ""'],
            'spaces' => ['" 1"', 'to_int() cannot convert " 1"'],
            'overflow' => ['"99999999999999999999"', 'to_int() cannot convert "99999999999999999999"'],
            'null' => ['null', 'to_int() cannot convert null'],
        ];
    }

    public function test_min_and_max()
    {
        $this->assertEquals("[1, 3, -2.5, 4]\n[\"apple\", \"pear\", \"B\"]\n[1, 1.0, \"int\", \"float\"]\n[9007199254740993, 9]\n", $this->executeCode(<<<'CODE'
            echo [min(1, 3), max(1, 3), min(-2.5, 4), max(-2.5, 4)];
            echo [min("pear", "apple"), max("pear", "apple"), min("a", "B")];
            echo [min(1, 1.0), max(1.0, 1), type_of(max(1, 1.0)), type_of(min(1.0, 1))];
            echo [max(9007199254740993, 9007199254740992.0), reduce([3, 9, 2], max, 0)];
            CODE));
    }

    public function test_to_string_matches_echo()
    {
        $this->assertEquals("42|true|null|[1, \"a\"]\n", $this->executeCode(
            'echo to_string(42) .. "|" .. to_string(true) .. "|" .. to_string(null) .. "|" .. to_string([1, "a"]);'
        ));
    }

    public function test_exit_stops_the_program_with_its_code_on_both_backends()
    {
        foreach ([false, true] as $vm) {
            $this->assertSame(['', 0], $this->runProgram('tests/fixtures/exit.gaz', [], $vm));
            $this->assertSame(["stopping\n", 3], $this->runProgram('tests/fixtures/exit.gaz', ['3'], $vm));
            $this->assertSame(["stopping\n", 0], $this->runProgram('tests/fixtures/exit.gaz', ['0'], $vm));
        }
    }

    public function test_exit_code_is_the_process_exit_code()
    {
        $command = sprintf('echo %s | %s %s', escapeshellarg('echo "bye"; exit(7);'), escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__.'/../bin/gazlang-php'));
        exec($command, $output, $exit_code);

        $this->assertSame(['bye'], $output);
        $this->assertSame(7, $exit_code);
    }

    /**
     * @dataProvider exitErrors
     */
    public function test_exit_argument_errors(string $code, string $message)
    {
        // A bad argument is an ordinary, catchable error
        $this->assertEquals("{$message}\n", $this->executeCode("try { {$code} } catch (\$e) { echo \$e.message; }"));
    }

    public static function exitErrors(): array
    {
        return [
            'too large' => ['exit(256);', 'exit() expects a code from 0 to 255, got 256'],
            'negative' => ['exit(-1);', 'exit() expects a code from 0 to 255, got -1'],
            'not an int' => ['exit("1");', 'exit() expects int, got string'],
        ];
    }

    public function test_in_array_compares_with_equals()
    {
        $this->assertEquals("true\nfalse\nfalse\ntrue\ntrue\nfalse\n", $this->executeCode(
            'echo in_array(1, [1, 2]); echo in_array("1", [1, 2]); echo in_array(true, [1]); echo in_array([1], [[1]]); echo in_array(1, [1.0]); echo in_array(null, [0]);'
        ));
    }

    public function test_has_key_and_keys()
    {
        $this->assertEquals("true\nfalse\nfalse\n[\"a\", 5]\ntrue\nfalse\nfalse\n[0, 1]\n", $this->executeCode(
            '$m = {"a" => null, 5 => 1}; echo has_key($m, "a"); echo has_key($m, "b"); echo has_key($m, "5"); echo keys($m);'
            .' $l = [1, 2]; echo has_key($l, 1); echo has_key($l, 2); echo has_key($l, -1); echo keys($l);'
        ));
    }

    public function test_type_of()
    {
        $this->assertEquals("int string bool null list map\n", $this->executeCode(
            'echo type_of(1) .. " " .. type_of("") .. " " .. type_of(false) .. " " .. type_of(null) .. " " .. type_of([]) .. " " .. type_of({});'
        ));
    }

    public function test_error_stops_the_program()
    {
        $this->expectExceptionMessage('Undefined variable: $x in 3');
        $this->executeCode('error("Undefined variable: \$x in " .. 3); echo "unreachable";');
    }

    public function test_builtins_is_the_runtimes_table()
    {
        $this->assertEquals(
            "true\n[1, [2, 3], 0]\nfalse\n",
            $this->executeCode('$b = builtins(); echo $b == builtins(); echo [$b["len"], $b["slice"], $b["builtins"]]; echo has_key($b, "print_r");')
        );
        $this->assertEquals(Values::toString(new MapValue(Builtins::ARITIES))."\n", $this->executeCode('echo builtins();'));
    }

    public function test_read_file()
    {
        $this->assertEquals("hello\n\n", $this->executeCode('echo read_file("'.__DIR__.'/fixtures/read_me.txt");'));
    }

    public function test_read_file_missing()
    {
        $this->expectExceptionMessage('Cannot read file: nope.txt');
        $this->executeCode('read_file("nope.txt");');
    }

    public function test_real_path_resolves_dots_and_symlinks()
    {
        // Under the checkout, which the snippet recorder strips, so the recorded snippet is the same every run
        $dir = dirname(__DIR__).'/tests/.tmp/real_path';
        mkdir("{$dir}/sub", 0777, true);
        touch("{$dir}/sub/a.gaz");
        symlink("{$dir}/sub/a.gaz", "{$dir}/link.gaz");
        $real = realpath($dir);

        try {
            $this->assertEquals(
                "{$real}/sub/a.gaz\n{$real}/sub/a.gaz\n{$real}/sub\ntrue\ntrue\nfalse\n",
                $this->executeCode(
                    "echo real_path(\"{$dir}/sub/./../sub//a.gaz\"); echo real_path(\"{$dir}/link.gaz\");"
                    ." echo real_path(\"{$dir}/sub/\"); echo file_exists(\"{$dir}/sub\"); echo file_exists(\"{$dir}/link.gaz\");"
                    // The system resolves ".." after the step before it, so a missing directory is missing
                    ." echo file_exists(\"{$dir}/nodir/../sub/a.gaz\");"
                )
            );
        } finally {
            unlink("{$dir}/link.gaz");
            unlink("{$dir}/sub/a.gaz");
            rmdir("{$dir}/sub");
            rmdir($dir);
        }
    }

    public function test_relative_paths_are_from_the_working_directory()
    {
        $this->assertEquals(
            getcwd()."\n".realpath('composer.json')."\ntrue\n",
            $this->executeCode('echo cwd(); echo real_path("composer.json"); echo file_exists(".");')
        );
    }

    /**
     * Nothing is there, however it is spelt: "" is not the working directory, as it is to PHP's
     * realpath(), and a NUL byte, which no name can hold, is not cut short, as a C string would be
     */
    public function test_file_exists_is_whether_real_path_succeeds()
    {
        $this->assertEquals(
            "false\nfalse\nfalse\nfalse\n",
            $this->executeCode('echo file_exists("nope.txt"); echo file_exists(""); echo file_exists("composer.json/"); echo file_exists("composer.json\\0");')
        );
    }

    public function test_real_path_of_nothing_is_an_error()
    {
        foreach (['nope.txt', '', 'composer.json/', 'composer.json\\0x'] as $path) {
            try {
                $this->executeCode("real_path(\"{$path}\");");
                $this->fail("real_path(\"{$path}\") should fail");
            } catch (GazLangError $e) {
                $this->assertStringStartsWith('No such file or directory: ', $e->getMessage());
            }
        }
        // Quoted, so a NUL byte doesn't reach the message
        $this->expectExceptionMessage('No such file or directory: "a\\x00b"');
        $this->executeCode('real_path("a\\0b");');
    }

    public function test_write_file_creates_and_overwrites()
    {
        // Under the checkout, as in test_real_path_resolves_dots_and_symlinks()
        @mkdir(dirname(__DIR__).'/tests/.tmp');
        $path = dirname(__DIR__).'/tests/.tmp/write_file.txt';

        try {
            $this->assertEquals("null\nsecond\n", $this->executeCode(
                'echo write_file("'.$path.'", "first"); write_file("'.$path.'", "second"); echo read_file("'.$path.'");'
            ));
        } finally {
            @unlink($path);
        }
    }

    public function test_write_file_error()
    {
        $this->expectExceptionMessage('Cannot write file: /no/such/dir/out.txt on line 1');
        $this->executeCode('write_file("/no/such/dir/out.txt", "x");');
    }

    public function test_write_file_only_writes_strings()
    {
        $this->expectExceptionMessage('write_file() expects string, got list');
        $this->executeCode('write_file("out.txt", [1]);');
    }

    public function test_read_stdin()
    {
        exec(sprintf(
            'printf %s | %s %s -f %s',
            escapeshellarg("two\nlines"),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__.'/../bin/gazlang-php'),
            escapeshellarg(__DIR__.'/fixtures/read_stdin.gaz')
        ), $output, $exit_code);

        $this->assertSame(['[two', 'lines]', '0'], $output);
        $this->assertSame(0, $exit_code);
    }

    public function test_args()
    {
        $interpreter = new Interpreter($this->createParser('echo args(); echo len(args());'), ['a', '-b']);

        ob_start();
        $interpreter->interpret();
        $this->assertEquals("[\"a\", \"-b\"]\n2\n", ob_get_clean());
    }

    public function test_cli_passes_remaining_arguments_to_the_program()
    {
        exec(sprintf(
            'echo %s | %s %s -- -x two',
            escapeshellarg('echo args();'),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__.'/../bin/gazlang-php')
        ), $output, $exit_code);

        $this->assertSame(['["-x", "two"]'], $output);
        $this->assertSame(0, $exit_code);
    }

    public function test_cli_rejects_a_file_it_cannot_read()
    {
        // What it says about the file goes to standard error, like every other diagnostic
        exec(sprintf('%s %s -f %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__.'/../bin/gazlang-php'), escapeshellarg(__DIR__)), $output, $exit_code);

        $this->assertSame(['Error: Cannot read file: '.__DIR__], $output);
        $this->assertSame(1, $exit_code);
    }

    public function test_cli_rejects_unknown_options_instead_of_dropping_them()
    {
        exec(sprintf(
            'echo %s | %s %s -n 5 2>&1',
            escapeshellarg('echo args();'),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__.'/../bin/gazlang-php')
        ), $output, $exit_code);

        $this->assertSame(['Error: Unknown option -n (put program arguments after --)'], $output);
        $this->assertSame(1, $exit_code);
    }

    /**
     * @dataProvider wrongArgumentTypes
     */
    public function test_builtins_check_argument_types(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode($code);
    }

    public static function wrongArgumentTypes(): array
    {
        return [
            'slice target' => ['slice(5, 0, 1);', 'slice() expects string or list, got int'],
            'slice start' => ['slice("abc", "0", 1);', 'slice() expects int, got string'],
            'lower' => ['lower(1);', 'lower() expects string, got int'],
            'round precision' => ['round(1.5, 1.0);', 'round() expects int, got float'],
            'round null precision' => ['round(1.5, null);', 'round() expects int, got null'],
            'index_of null offset' => ['index_of("abc", "c", null);', 'index_of() expects int, got null'],
            'in_array haystack' => ['in_array(1, "1");', 'in_array() expects list, got string'],
            'has_key array' => ['has_key("a", 1);', 'has_key() expects list or map, got string'],
            'has_key key' => ['has_key([], null);', 'Keys must be int or string, got null'],
            'keys' => ['keys(null);', 'keys() expects list or map, got null'],
            'read_file' => ['read_file(1);', 'read_file() expects string, got int'],
            'real_path' => ['real_path(null);', 'real_path() expects string, got null'],
            'file_exists' => ['file_exists([]);', 'file_exists() expects string, got list'],
            'min of a number and a string' => ['min(1, "2");', 'min() expects two numbers or two strings, got int and string'],
            'max of bools' => ['max(true, false);', 'max() expects two numbers or two strings, got bool and bool'],
            'max of lists' => ['max([1], [2]);', 'max() expects two numbers or two strings, got list and list'],
        ];
    }

    public function test_code_gen_for_builtins()
    {
        $this->assertEquals(
            "PUSH \"abc\"\nPUSH 0\nPUSH 1\nCALL_BUILTIN slice 3\nPRINT\nCALL_BUILTIN args 0\nPOP",
            $this->generateCode('echo slice("abc", 0, 1); args();')
        );
    }

    public function test_rand_seed_pins_the_numbers_in_both_runtimes()
    {
        // Worked out independently, with unsigned arithmetic (Python): xoshiro256** seeded through
        // SplitMix64, rand_int by masked rejection and rand_float from the top 53 bits
        $draws = 'echo map([1, 2, 3, 4, 5], $i -> rand_int(1, 6)); echo rand_int(5, 5);'
            .' echo rand_int(-9223372036854775807 - 1, 9223372036854775807);'
            .' echo rand_int(-9223372036854775807 - 1, -9223372036854775807 - 1);'
            .' echo rand_int(9223372036854775807, 9223372036854775807); echo rand_int(-1000, 1000);'
            .' echo rand_int(0, 9223372036854775807); echo rand_int(-9223372036854775807 - 1, -1);'
            .' echo rand_float(); echo rand_float();';
        $expected = [
            '0' => [[5, 3, 1, 5, 2], -1434944111878255464, -427, 2108416074180405844, -7983162549738583115, '0.10667465014124933', '0.6548331676545119'],
            '-1' => [[1, 6, 3, 6, 3], 2108038217347390602, 150, 8873532491084022193, -5697842235154423713, '0.7765256754275042', '0.18508067442820086'],
            '-9223372036854775807 - 1' => [[4, 3, 2, 5, 3], -4465951018770553547, 867, 8605345157329039497, -7438891730449967671, '0.4510790100773958', '0.7301394703154883'],
            '123456789' => [[3, 1, 1, 3, 2], -2734074844826621121, -779, 5490371242107294122, -462937021012621692, '0.06362182264572802', '0.13207336538611603'],
        ];
        foreach ($expected as $seed => [$dice, $full, $small, $upper, $lower, $first, $second]) {
            $this->assertSame(
                '['.implode(', ', $dice)."]\n5\n{$full}\n-9223372036854775808\n9223372036854775807\n{$small}\n{$upper}\n{$lower}\n{$first}\n{$second}\n",
                $this->executeCode("rand_seed({$seed}); {$draws}"),
                "seed {$seed}"
            );
        }
    }

    public function test_rand_seed_restarts_the_sequence_and_gives_null()
    {
        $this->assertSame(
            "true\nnull\n",
            $this->executeCode('rand_seed(42); $a = [rand_int(0, 100), rand_float()]; echo [rand_int(0, 100), rand_float()] != $a && ([rand_seed(42), rand_int(0, 100), rand_float()] == [null, ...$a]); echo rand_seed();')
        );
    }

    public function test_unseeded_numbers_are_in_range()
    {
        // Unseeded, the runtimes draw different numbers, so only the kind of result can be printed
        $this->assertSame(
            "true\ntrue\ntrue\n",
            $this->executeCode(
                '$ok = true; for ($i = 0; $i < 200; $i++) { $n = rand_int(-3, 3); $f = rand_float();'
                .' $ok = $ok && $n >= -3 && $n <= 3 && type_of($f) == "float" && $f >= 0.0 && $f < 1.0; }'
                .' echo $ok; rand_seed(); echo type_of(rand_int(0, 1)) == "int"; echo rand_int(7, 7) == 7;'
            )
        );
    }

    public function test_every_program_starts_unpredictable()
    {
        CVM::build();
        $file = tempnam(sys_get_temp_dir(), 'gaz');
        file_put_contents($file, 'echo rand_int(-9223372036854775807 - 1, 9223372036854775807);');
        try {
            foreach ([[CVM::BINARY], ['bin/gazlang-php'], ['bin/gazlang-php', '--interpreter']] as $gazlang) {
                [[$first], [$second]] = CVM::processes([[...$gazlang, '-f', $file], [...$gazlang, '-f', $file]]);
                $this->assertMatchesRegularExpression('/^-?\d+\n$/', $first);
                $this->assertNotSame($first, $second, implode(' ', $gazlang));
            }
        } finally {
            unlink($file);
        }
    }

    public function test_rand_int_needs_min_at_most_max()
    {
        $this->expectExceptionMessage('rand_int() expects min <= max, got 3 and 2 on line 1');
        $this->executeCode('rand_int(3, 2);');
    }

    public function test_rand_int_and_rand_seed_need_ints()
    {
        foreach (['rand_int(1.0, 2)' => 'rand_int() expects int, got float', 'rand_int(1, "2")' => 'rand_int() expects int, got string', 'rand_seed(1.5)' => 'rand_seed() expects int or null, got float'] as $call => $message) {
            try {
                $this->executeCode("{$call};");
                $this->fail("{$call} ran");
            } catch (GazLangError $e) {
                $this->assertStringStartsWith($message, $e->getMessage());
            }
        }
    }
}
