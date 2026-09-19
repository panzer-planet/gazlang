<?php

namespace GazLang\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base test case for GazLang tests: every helper runs bin/gazlang, the C VM with the self-hosted
 * compiler built in, from the project root
 */
abstract class GazLangTestCase extends TestCase
{
    protected const ROOT = __DIR__.'/..';

    /**
     * The binary the helpers run: the optimised build, since they start it thousands of times
     * (CVMTest runs every snippet and program under the sanitizers)
     */
    private const GAZLANG = self::ROOT.'/bin/gazlang';

    /**
     * The path of gazlang, built first if it isn't yet, for a test that runs it through a shell
     */
    protected static function binary(): string
    {
        static $built = false;
        if (! $built) {
            CVM::build();
            $built = true;
        }

        return self::GAZLANG;
    }

    /**
     * Run gazlang with options and what it reads on standard input, from the project root
     *
     * @param  list<string>  $args  The command line after the binary
     * @param  string  $stdin  What it reads on standard input
     * @return array{0: string, 1: string, 2: int} Standard output, standard error and the exit code
     */
    protected static function gazlang(array $args, string $stdin = ''): array
    {
        self::binary();
        // Files rather than pipes, so a program that fills one stream can't block on it
        $files = [tempnam(sys_get_temp_dir(), 'gazin'), tempnam(sys_get_temp_dir(), 'gazout'), tempnam(sys_get_temp_dir(), 'gazerr')];
        file_put_contents($files[0], $stdin);
        try {
            $process = proc_open([self::GAZLANG, ...$args], [['file', $files[0], 'r'], ['file', $files[1], 'w'], ['file', $files[2], 'w']], $pipes, self::ROOT);
            if ($process === false) {
                throw new \RuntimeException('Cannot run '.self::GAZLANG);
            }
            $code = proc_close($process);

            return [(string) file_get_contents($files[1]), (string) file_get_contents($files[2]), $code];
        } finally {
            array_map('unlink', $files);
        }
    }

    /**
     * Run gazlang as a shell's exec() would with 2>&1: its output as lines, without their newlines
     *
     * @param  list<string>  $args  The command line after the binary
     * @param  string  $stdin  What it reads on standard input
     * @return array{0: list<string>, 1: int} Standard output then standard error, by line, and the exit code
     */
    protected static function cli(array $args, string $stdin = ''): array
    {
        [$out, $err, $code] = self::gazlang($args, $stdin);
        $text = rtrim($out.$err, "\n");

        return [$text === '' ? [] : explode("\n", $text), $code];
    }

    /**
     * Run a GazLang file as `bin/gazlang -f FILE -- ARGS` does, from the project root, so FILE
     * is relative to it and errors name files the same way
     *
     * @param  string  $file  Path relative to the project root
     * @param  string[]  $args  Arguments returned by args()
     * @return array{0: string, 1: int} Standard output then standard error, and the exit code
     */
    protected function runProgram(string $file, array $args = []): array
    {
        [$out, $err, $code] = self::gazlang(['-f', $file, '--', ...$args]);

        return [$out.$err, $code];
    }

    /**
     * Run GazLang code piped in, and give what it printed, standard error after standard output
     *
     * @param  string  $input  The GazLang code to run
     *
     * @throws ProgramError If it fails: to lex, parse or run
     */
    protected function executeCode(string $input): string
    {
        // vm/snippets.php collects every snippet for the C VM's harness
        if (($record = getenv('GAZLANG_RECORD_SNIPPETS')) !== false) {
            // with this checkout's paths relative to it, since the harness runs them from its root
            file_put_contents($record, json_encode(str_replace(dirname(__DIR__).'/', '', $input), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
        }

        return self::succeed([], $input);
    }

    /**
     * The tree `gazlang --ast` prints for code piped in
     *
     * @throws ProgramError If it doesn't parse
     */
    protected function parse(string $input): string
    {
        return self::succeed(['--ast'], $input);
    }

    /**
     * The tokens `gazlang --tokens` prints for code piped in, without the EOF token, each as
     * [type, value, line]: a string's value decoded, a number's the number, anything else as printed
     *
     * @return list<array{0: string, 1: mixed, 2: int}>
     *
     * @throws ProgramError If it doesn't lex, after the tokens before the error
     */
    protected function lex(string $input): array
    {
        $tokens = [];
        foreach (explode("\n", rtrim(self::succeed(['--tokens'], $input), "\n")) as $line) {
            [$number, $type, $value] = explode(' ', $line, 3) + [2 => ''];
            if ($type === 'EOF') {
                break;
            }
            $tokens[] = [$type, match ($type) {
                'STRING', 'STRING_START', 'STRING_MIDDLE', 'STRING_END' => self::unquote($value),
                'INTEGER' => (int) $value,
                'FLOAT' => (float) $value,
                default => $value,
            }, (int) $number];
        }

        return $tokens;
    }

    /**
     * A GazLang string literal for any bytes, as --tokens prints one: control bytes and quotes
     * escaped, and $ and { where they would start an interpolation
     */
    protected static function quote(string $value): string
    {
        $named = ["\n" => 'n', "\t" => 't', "\r" => 'r', "\v" => 'v', "\f" => 'f', "\e" => 'e', '\\' => '\\', '"' => '"', '$' => '$', '{' => '{'];

        return '"'.preg_replace_callback(
            '/[\x00-\x1F\x7F"\\\\]|\$(?=[A-Za-z_])|\{(?=[$@#])/',
            fn ($match) => isset($named[$match[0]]) ? '\\'.$named[$match[0]] : sprintf('\\x%02X', ord($match[0])),
            $value
        ).'"';
    }

    /**
     * A string literal as --tokens prints it (quote()), back to its bytes
     */
    private static function unquote(string $literal): string
    {
        $named = ['n' => "\n", 't' => "\t", 'r' => "\r", 'v' => "\v", 'f' => "\f", 'e' => "\e", '0' => "\0"];

        return preg_replace_callback('/\\\\(x[0-9A-Fa-f]{2}|.)/s', fn ($m) => $m[1][0] === 'x' && strlen($m[1]) === 3 ? chr(hexdec(substr($m[1], 1))) : ($named[$m[1]] ?? $m[1]), substr($literal, 1, -1));
    }

    /**
     * The bytecode `gazlang -c` writes for code piped in, as instructions only: without the
     * header, locations and the block records around them
     *
     * @throws ProgramError If it doesn't compile
     */
    protected function generateCode(string $input): string
    {
        $lines = explode("\n", trim(self::succeed(['-c'], $input)));
        $code = array_filter($lines, fn (string $line) => $line !== '' && $line !== 'top'
            && ! preg_match('/^(GAZLANG|globals|locals|@ |field |method |capture |self )/', $line));

        return implode("\n", $code);
    }

    /**
     * What gazlang printed with these options for code piped in (if any), standard error after
     * standard output, or its error as a ProgramError when it exits with a code other than 0
     *
     * @param  list<string>  $args  The options
     *
     * @throws ProgramError If it fails
     */
    protected static function succeed(array $args, string $input = ''): string
    {
        [$out, $err, $code] = self::gazlang($args, $input);
        if ($code === 0) {
            return $out.$err;
        }
        // The message is what follows the last "Error: " to start a line, which leaves out
        // what print_error() wrote before it; exit() prints nothing
        $at = preg_match_all('/^Error: /m', $err, $matches, PREG_OFFSET_CAPTURE) ? end($matches[0])[1] : null;
        throw new ProgramError($at === null ? "exit({$code})" : rtrim(substr($err, $at + 7), "\n"), $code, $out.substr($err, 0, $at ?? strlen($err)));
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
     * GAZLANG_RECORD set, what the port printed is recorded instead, for review as a diff:
     * GAZLANG_RECORD=1 vendor/bin/phpunit --filter SelfHosted
     *
     * @param  string  $expected_file  The expected file, relative to the project root
     * @param  array{0: string, 1: int}  $actual  What the port printed, and its exit code
     */
    protected function assertPortPrints(string $expected_file, array $actual, string $message): void
    {
        $path = self::ROOT.'/'.$expected_file;
        $output = CVM::portable($actual[0]);
        if (getenv('GAZLANG_RECORD') !== false) {
            file_put_contents($path, $output);
        }
        $this->assertFileExists($path, "{$message}: nothing recorded; GAZLANG_RECORD=1 vendor/bin/phpunit --filter SelfHosted");
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
}
