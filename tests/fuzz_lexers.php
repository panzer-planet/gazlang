<?php

/**
 * Differential fuzzing of the two lexers: php tests/fuzz_lexers.php [RUNS = 4000] [SEED = 1]
 *
 * SelfHostedLexerTest says selfhost/lexer.gaz is right on the corpus; this looks for inputs
 * nobody thought to put in it. Each run makes an input one of two ways, lexes it with the PHP
 * lexer and with the GazLang one (on the VM, compiled once), and reports any difference in the
 * tokens or the error. It ends with how often each error was reached, since a fuzzer that
 * never gets past "Unexpected character" finds nothing. Not part of the suite: run it by hand
 * after changing either lexer, and put whatever it finds in tests/lexer_corpus/.
 */

use GazLang\GazLangError;
use GazLang\Lexer\Lexer;
use GazLang\Lexer\Token;

require __DIR__.'/fuzz_common.php';

/** The pieces inputs are made of: whatever starts, ends or changes the meaning of a token */
const ATOMS = [
    '"', "'", '\\', '$', '{', '}', '@', '#', '##', '[', ']', '-', '0', '1', '01', '9', 'a', 'x', 'e', 'E', '+', '.', '..', '...',
    '=', '<', '>', '/', '*', '//', '/*', '*/', "\n", ' ', "\t", "\r", '_', 'fn', 'u', '0x', '0X', 'F', 'g', '&', '|', '^',
    '~', '?', ':', ';', ',', '(', ')', '!', '%', "\xC3\xA9", "\0", "\x7F", "\v", "\f", '`', '$a', '$a[', '$b]', '{$', '\u{',
    '\x', '99999999999999999999', '1e999', '0xFFFFFFFFFFFFFFFFF', '9223372036854775807', '9223372036854775808', '1.5',
    'D800', '110000', '10FFFF', '-0', '"$a[0]"', '"{$a}"', '1.', '.5', '.a',
];

/**
 * What `gazlang --tokens` prints for a source text, from the PHP lexer
 */
function php_tokens(string $source): string
{
    $lexer = new Lexer($source);
    $lines = [];
    try {
        do {
            $token = $lexer->get_next_token();
            $lines[] = (string) $token;
        } while ($token->type !== Token::EOF);
    } catch (GazLangError $e) {
        $lines[] = "Error: {$e->getMessage()}";
    }

    return implode("\n", $lines);
}

/**
 * An input: atoms strung together, or a window of a real file with atoms spliced into it
 *
 * The first is dense in edge cases; the second reaches the long shapes real code has, like a
 * map literal inside an interpolation inside a string.
 *
 * @param  string[]  $files  The files to take windows of
 */
function input(array $files): string
{
    if (mt_rand(0, 1) === 0) {
        $source = '';
        for ($count = mt_rand(1, 40); $count > 0; $count--) {
            $source .= ATOMS[mt_rand(0, count(ATOMS) - 1)];
        }

        return $source;
    }

    $source = file_get_contents($files[mt_rand(0, count($files) - 1)]);
    $source = substr($source, mt_rand(0, max(0, strlen($source) - 1)), mt_rand(1, 200));
    for ($count = mt_rand(0, 6); $count > 0; $count--) {
        $at = mt_rand(0, strlen($source));
        $source = substr($source, 0, $at).ATOMS[mt_rand(0, count(ATOMS) - 1)].substr($source, $at + mt_rand(0, 2));
    }

    return $source;
}

$runs = (int) ($argv[1] ?? 4000);
mt_srand((int) ($argv[2] ?? 1));

$program = compile_driver('selfhost/tokens.gaz');

$files = [...glob('tests/lexer_corpus/*.gaz'), 'selfhost/lexer.gaz', 'examples/objects.gaz', 'lib/json.gaz'];
$path = tempnam(sys_get_temp_dir(), 'gazfuzz');
$mismatches = 0;
$errors = [];

for ($run = 0; $run < $runs; $run++) {
    $source = input($files);
    file_put_contents($path, $source);
    $expected = php_tokens($source);
    $actual = rtrim(run_driver($program, $path), "\n");

    if (preg_match('/^Error: ([A-Za-z ]+)/m', $expected, $match)) {
        $errors[trim($match[1])] = ($errors[trim($match[1])] ?? 0) + 1;
    }
    if ($expected !== $actual) {
        $mismatches++;
        echo "=== MISMATCH ===\n", var_export($source, true), "\n--- php\n{$expected}\n--- gaz\n{$actual}\n";
    }
}
unlink($path);

arsort($errors);
foreach ($errors as $reason => $count) {
    printf("%6d  %s\n", $count, $reason);
}
echo "{$runs} runs, {$mismatches} mismatches\n";
exit($mismatches === 0 ? 0 : 1);
