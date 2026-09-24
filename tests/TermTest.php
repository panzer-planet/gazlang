<?php

namespace GazLang\Tests;

/**
 * The terminal builtins (vm/term.c): term_raw(), term_read(), term_size() and term_is_tty()
 *
 * Without a terminal they are recorded like any snippet, and term_read() is fed through a file.
 * Raw mode can only be seen on a terminal, which PHP can't make, so tests/fixtures/pty_run.py
 * runs a program on a pty and says what the terminal's modes were before, during and after; those
 * tests are skipped where there is no python3 to do it.
 */
class TermTest extends GazLangTestCase
{
    public function test_without_a_terminal_they_say_so_and_fall_back()
    {
        $this->assertSame(
            "false false false\n{\"cols\" => 80, \"rows\" => 24}\nterm_raw() needs a terminal on standard input\n\n",
            $this->executeCode('echo term_is_tty(0) .. " " .. term_is_tty(1) .. " " .. term_is_tty(2);'
                .' echo term_size();'
                .' try { term_raw(true); } catch (Error $e) { echo $e.message; }'
                .' term_raw(false);'
                .' echo term_read(0);')
        );
    }

    /**
     * @dataProvider argumentErrors
     */
    public function test_their_arguments_are_checked(string $code, string $message)
    {
        $this->assertSame($message."\n", $this->executeCode("try { {$code}; } catch (Error \$e) { echo \$e.message; }"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function argumentErrors(): array
    {
        return [
            'raw wants a bool' => ['term_raw(1)', 'term_raw() expects bool, got int'],
            'read wants a number' => ['term_read("x")', 'term_read() expects int or float or null, got string'],
            'read waits no less than nothing' => ['term_read(-1)', 'term_read() expects a timeout of 0 seconds or more'],
            'a stream is 0, 1 or 2' => ['term_is_tty(3)', 'term_is_tty() expects 0, 1 or 2, got 3'],
        ];
    }

    public function test_term_read_gives_the_bytes_as_they_arrived_and_an_empty_string_at_the_end()
    {
        [$out, $err, $code] = self::gazlang(['-f', 'tests/fixtures/term_read.gaz'], "ab\x1b[A\x03");

        $this->assertSame(["[\"ab\\e[A\\x03\"]\n", '', 0], [$out, $err, $code]);
    }

    public function test_raw_mode_is_on_while_the_program_runs_and_off_when_it_ends_however_it_ends()
    {
        $cases = [
            'returns' => ['term_raw(true); term_read(); term_raw(false); term_read(0.1);', '71', null, 0],
            'exit()' => ['term_raw(true); term_read(); exit(3);', '71', null, 3],
            'uncaught error' => ['term_raw(true); term_read(); echo 1 / 0;', '71', null, 1],
            'SIGTERM' => ['term_raw(true); term_read();', '', 'TERM', -15],
            'SIGINT' => ['term_raw(true); term_read();', '', 'INT', -2],
        ];
        foreach ($cases as $name => [$source, $keys, $signal, $exit]) {
            $ran = $this->onTerminal($source, $keys, $signal);

            $this->assertSame(['echo' => true, 'icanon' => true, 'isig' => true], $ran['before'], $name);
            $this->assertSame(['echo' => false, 'icanon' => false, 'isig' => false], $ran['during'], $name);
            $this->assertSame(['echo' => true, 'icanon' => true, 'isig' => true], $ran['after'], "{$name}: the terminal wasn't put back");
            $this->assertSame($exit, $ran['code'], $name);
        }
    }

    public function test_a_program_that_never_asks_leaves_the_terminal_as_it_was()
    {
        $ran = $this->onTerminal('echo 1;');

        $this->assertSame($ran['before'], $ran['during']);
        $this->assertSame($ran['before'], $ran['after']);
    }

    public function test_on_a_terminal_it_knows_it_is_one_and_how_big()
    {
        $ran = $this->onTerminal('echo term_is_tty(0) .. " " .. term_is_tty(1) .. " " .. term_is_tty(2); echo term_size();');

        $this->assertSame("true true true\r\n{\"cols\" => 132, \"rows\" => 40}\r\n", $ran['out']);
    }

    public function test_term_read_times_out_with_null_and_reads_keys_in_raw_mode()
    {
        $ran = $this->onTerminal('term_raw(true); echo term_read(0.1); echo to_string([term_read()]);', '1b5b41');

        // The first read waited 0.1s for nothing (the keys come later, after the start-up wait)
        $this->assertSame("null\r\n[\"\\e[A\"]\r\n", $ran['out']);
    }

    public function test_input_reads_keys_from_a_pipe_and_says_when_it_ends()
    {
        [$out] = self::gazlang(['-f', 'tests/fixtures/term_input.gaz'], "ab\x1b[A\x03\x1b");

        $this->assertSame("a\nb\nup\nctrl+c\nescape\neof\n", $out);
    }

    public function test_input_takes_a_sequence_that_the_input_ends_in_for_what_it_looks_like()
    {
        [$out] = self::gazlang(['-f', 'tests/fixtures/term_input.gaz'], "\x1b[1;");

        $this->assertSame("alt+[\n1\n;\neof\n", $out);
    }

    public function test_input_says_eof_again_and_again_after_the_end()
    {
        [$out] = self::gazlang(['-f', 'tests/fixtures/term_input.gaz'], '');

        $this->assertSame("eof\n", $out);
    }

    public function test_input_waits_for_a_lone_escape_and_for_no_key_at_all()
    {
        $root = self::ROOT;
        $program = "include \"{$root}/lib/term.gaz\"; term_raw(true); \$input = term::Input();"
            .' echo to_string([$input.read(0.1)]); echo term::name($input.read()); echo term::name($input.read());';

        // "escape" comes after the wait for more of a sequence; "up" is one whole read
        $ran = $this->onTerminal($program, '1b', null);
        $this->assertStringStartsWith("[null]\r\nescape\r\n", $ran['out']);

        $ran = $this->onTerminal($program, '1b5b411b5b42');
        $this->assertSame("[null]\r\nup\r\ndown\r\n", $ran['out']);
    }

    public function test_fullscreen_gives_the_terminal_back_when_the_program_inside_it_ends_or_fails()
    {
        $root = self::ROOT;
        $screen = "\e[?1049h";
        $back = "\e[?1049l";

        // it waits for a key inside, so the terminal can be looked at while it is in use
        $ran = $this->onTerminal("include \"{$root}/lib/term.gaz\"; echo term::fullscreen(() -> { term_read(); return 7; });", '71');
        $this->assertSame(0, $ran['code']);
        $this->assertStringContainsString("{$screen}\e[?25l", $ran['out']);
        $this->assertStringEndsWith("\e[0m\e[?25h{$back}7\r\n", $ran['out']);
        $this->assertSame(['echo' => false, 'icanon' => false, 'isig' => false], $ran['during']);
        $this->assertSame(['echo' => true, 'icanon' => true, 'isig' => true], $ran['after']);

        $ran = $this->onTerminal("include \"{$root}/lib/term.gaz\"; term::fullscreen(() -> { error(\"boom\"); });");
        $this->assertSame(1, $ran['code']);
        // the screen is left before the error is printed on the terminal's own
        $this->assertLessThan(strpos($ran['out'], 'Error: boom'), strpos($ran['out'], $back));
        $this->assertSame(['echo' => true, 'icanon' => true, 'isig' => true], $ran['after']);
    }

    /**
     * Run GazLang code on a pty, as tests/fixtures/pty_run.py does
     *
     * @return array{before: array<string, bool>, during: array<string, bool>, after: array<string, bool>, code: int|string, out: string}
     */
    private function onTerminal(string $source, string $keysHex = '', ?string $signal = null): array
    {
        $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
        if ($python === '') {
            $this->markTestSkipped('needs python3 to make a pty');
        }
        self::binary();
        $file = tempnam(sys_get_temp_dir(), 'gazterm').'.gaz';
        file_put_contents($file, $source);
        try {
            $args = [$python, 'tests/fixtures/pty_run.py', $file, $keysHex, ...($signal === null ? [] : [$signal])];
            $process = proc_open($args, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, self::ROOT);
            $this->assertNotFalse($process);
            $json = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            proc_close($process);
            $this->assertNotSame('', (string) $json, "pty_run.py printed nothing: {$error}");

            /** @var array{before: array<string, bool>, during: array<string, bool>, after: array<string, bool>, code: int|string, out: string} */
            return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            unlink($file);
        }
    }
}
