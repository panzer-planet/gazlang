<?php

/**
 * Differential fuzzing of the two parsers: php tests/fuzz_parsers.php [RUNS = 2000] [SEED = 1] [ast|code]
 *
 * With `code` it compares the two compilers instead, selfhost/gazlang.gaz's code mode against `gazlang -c`,
 * on the same inputs: whatever parses is compiled by both, and must give the same bytecode.
 *
 * SelfHostedParserTest says selfhost/parser.gaz is right on the corpus; this looks for inputs
 * nobody thought to put in it. Each run makes an input one of two ways, parses it with the PHP
 * parser and with the GazLang one (on the VM, compiled once), and reports any difference in the
 * tree or the error. It ends with how often each error was reached, since a fuzzer that never
 * gets past "Unexpected" finds nothing. Not part of the suite: run it by hand after changing
 * either parser, and put whatever it finds in tests/parser_corpus/.
 *
 * Unlike the lexers' fuzzer it changes programs a token at a time: noise a character at a time
 * dies in the lexer, or at the first token, and never reaches a check made once a program is read.
 */

use GazLang\AST\Dumper;
use GazLang\CodeGenerator\CodeGenerator;
use GazLang\GazLangError;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;

require __DIR__.'/fuzz_common.php';

/** The pieces inputs are made of or have spliced into them: tokens, and the starts and ends of statements */
const ATOMS = [
    '$a', '$b', '$a', '@g', '#x', '#f', '#', '##f', '##_', '##', '.x', 'f', 'len', 'A', 'B', 'Error', 'If', 'Return', '_',
    '1', '1.5', '"s"', '"a {$b} c"', "'\$a'", 'true', 'null', '[', ']', '[]', '(', ')', '()', '{', '}', '{}', ';', ',', '=>', '->', '?', ':',
    '=', '+=', '..=', '??=', '++', '--', '+', '-', '*', '..', '...', '[...$a]', '==', '<', '&&', '||', '??', '!', '~', '&', '<<',
    'echo', 'if', 'else', 'while', 'for', 'foreach', 'as', 'break;', 'continue;', 'return', 'return;', 'delete', 'match', 'default',
    'fn', 'function', 'class', 'extends', 'abstract', 'try', 'catch', 'finally', 'include', 'interface', 'private',
    'fn f($a) {', 'fn g($a, $b = 1) {', 'fn _() {', 'fn _($x) {', 'fn to_string() {', 'abstract fn f();', 'class A {', 'class B extends A {',
    'abstract class A {', '#x;', '#x = 1;', 'try {', '} catch ($e) {', '} catch (Error $e) {', '} catch (A $e) {', '} finally {',
    'while ($a) {', 'foreach ($a as $b) {', 'foreach ($a as $k => [$b, $c]) {', 'for ($i = 0; $i < 1; $i++) {', 'if ($a) {', '} else {',
    'match ($a) {', 'match {', '1 =>', 'default =>', '$x ->', '($a, $b) ->', '() -> {', 'f(1)', 'g(1, 2)', 'A()', 'A(1)', '##f()', '#f()', '#f(1)',
    'const', 'const X = 1;', 'const Y = X + 1;', 'const X = Y;', 'const L = [X, {"k" => Y}];', 'X', 'Y', 'A.X', '#X', '#X = 1;', 'X()', 'A.X()',
    'include "lib/shapes.gaz";', 'include "lib/deep/helper.gaz";', 'include "nowhere.gaz";',
];

/**
 * What `gazlang --ast` prints for a source text, from the PHP parser
 */
function php_ast(string $source, string $path): string
{
    try {
        $tree = (new Parser(new Lexer($source), $path))->parse();

        return MODE === 'code' ? (new CodeGenerator($tree))->compile()->write($path) : Dumper::dump($tree);
    } catch (GazLangError $e) {
        return "Error: {$e->getMessage()}\n";
    } catch (Throwable $e) {
        // A bug in the PHP parser, which is a finding too
        return 'PHP '.get_class($e).": {$e->getMessage()}\n";
    }
}

/**
 * A source text as the pieces it is changed by: tokens near enough, with the space between them
 *
 * @return string[]
 */
function pieces(string $source): array
{
    preg_match_all('/\s+|\/\/[^\n]*|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[$@#.]*[A-Za-z_][A-Za-z0-9_]*|\d+(?:\.\d+)?|[-+*\/%=<>!&|^?.:]+|./s', $source, $matches);

    return $matches[0];
}

/**
 * What kind of piece this is, roughly: its first character says enough
 */
function kind(string $piece): string
{
    return match (true) {
        ctype_space($piece[0]) => 'space',
        ctype_alpha($piece[0]) || $piece[0] === '_' => 'word',
        ctype_digit($piece[0]) => 'number',
        str_contains('-+*/%=<>!&|^?.:', $piece[0]) && ! str_starts_with($piece, '//') && ! preg_match('/^\.[A-Za-z_]/', $piece) => 'operator',
        default => $piece[0],
    };
}

/**
 * An input: atoms strung together, or a program with a few of its pieces changed
 *
 * Atoms are dense in syntax errors. A program that fails already (most of the parser's cases)
 * fails some other way with a piece changed. A program that parses is the important one, and
 * most inputs are made from one: it is right but for one thing, which is what reaches the
 * checks made once the whole program is read, and when the change is one piece for another of
 * its kind (a variable for a variable, an operator for an operator) it usually still parses,
 * into a different tree. A port that groups operators wrongly or captures the wrong variables
 * fails no error case; only a tree shows it.
 *
 * @param  string[]  $valid  Programs that parse
 * @param  string[]  $failing  Programs that don't
 */
function input(array $valid, array $failing): string
{
    $choice = mt_rand(0, 99);
    if ($choice < 10) {
        $atoms = [];
        for ($count = mt_rand(1, 30); $count > 0; $count--) {
            $atoms[] = ATOMS[mt_rand(0, count(ATOMS) - 1)];
        }

        return implode(' ', $atoms);
    }

    $files = $choice < 30 ? $failing : $valid;
    $pieces = pieces(file_get_contents($files[mt_rand(0, count($files) - 1)]));
    for ($count = mt_rand(1, 3); $count > 0 && $pieces !== []; $count--) {
        $at = mt_rand(0, count($pieces) - 1);
        if (mt_rand(0, 2) > 0) {
            // Another piece of its kind, from anywhere in the file
            $alike = array_keys(array_filter($pieces, fn (string $piece) => kind($piece) === kind($pieces[$at])));
            $pieces[$at] = $pieces[$alike[mt_rand(0, count($alike) - 1)]];

            continue;
        }
        $atom = ATOMS[mt_rand(0, count(ATOMS) - 1)];
        match (mt_rand(0, 4)) {
            0 => array_splice($pieces, $at, 1),
            1 => array_splice($pieces, $at, 0, [$pieces[$at]]),
            2 => array_splice($pieces, $at, 1, [$atom]),
            3 => array_splice($pieces, $at, 0, [" {$atom} "]),
            4 => array_splice($pieces, $at, 1, [$pieces[mt_rand(0, count($pieces) - 1)]]),
        };
    }

    return implode('', $pieces);
}

/**
 * The first line two outputs differ on, which says more than two whole trees
 */
function first_difference(string $expected, string $actual): string
{
    $expected = explode("\n", $expected);
    $actual = explode("\n", $actual);
    foreach ($expected as $i => $line) {
        if ($line !== ($actual[$i] ?? null)) {
            return 'line '.($i + 1).":\n--- php\n{$line}\n--- gaz\n".($actual[$i] ?? '(nothing)');
        }
    }

    return 'line '.(count($expected) + 1).":\n--- php\n(nothing)\n--- gaz\n".$actual[count($expected)];
}

$runs = (int) ($argv[1] ?? 2000);
mt_srand((int) ($argv[2] ?? 1));
define('MODE', $argv[3] ?? 'ast');

$program = compile_driver();

// Small programs, so a run is tens of milliseconds: the parser's and code generator's own
// cases, which between them use all of the grammar, and a few real ones. Not the code
// generator's includes.gaz: its paths are relative to its own directory, not to where the
// input is written, so it never parses here.
$failing = [...glob('tests/parser_corpus/error_*.gaz'), ...glob('tests/parser_corpus/include/error_*.gaz'), ...glob('tests/codegen_corpus/error_*.gaz')];
$valid = array_values(array_filter(
    [...glob('tests/parser_corpus/*.gaz'), ...glob('tests/parser_corpus/include/*.gaz'), ...glob('tests/codegen_corpus/*.gaz'), 'examples/objects.gaz', 'examples/errors.gaz', 'lib/format.gaz', 'lib/sort.gaz'],
    fn (string $file) => filesize($file) < 8000 && ! in_array($file, $failing, true) && $file !== 'tests/codegen_corpus/includes.gaz',
));
// Beside the include cases, so their includes are found; not a .gaz, so a run that dies doesn't leave the suite a corpus file
$path = 'tests/parser_corpus/include/fuzz_input_'.getmypid().'.tmp';
$mismatches = 0;
$errors = [];
$parsed = 0;

for ($run = 0; $run < $runs; $run++) {
    $source = input($valid, $failing);
    file_put_contents($path, $source);
    $expected = php_ast($source, $path);
    // A port that loops forever on some input stops here, with that input still in the file
    set_time_limit(20);
    $actual = run_driver($program, MODE, $path);

    if (preg_match('/^(?:Error|PHP \w+): ([A-Za-z# ]+)/', $expected, $match)) {
        // Without the names in it, so the tally is of kinds of error
        $reason = trim(preg_replace(['/ at tests.*/', '/ [A-Z#$]\S*| \S+\.\S+/'], ['', ' X'], $match[1]));
        $errors[$reason] = ($errors[$reason] ?? 0) + 1;
    } else {
        $parsed++;
    }
    if ($expected !== $actual) {
        $mismatches++;
        echo "=== MISMATCH ===\n", var_export($source, true), "\n", first_difference($expected, $actual), "\n";
    }
}
unlink($path);

arsort($errors);
foreach ($errors as $reason => $count) {
    printf("%6d  %s\n", $count, $reason);
}
echo count($errors)." kinds of error, {$parsed} inputs parsed\n";
echo "{$runs} runs, {$mismatches} mismatches\n";
exit($mismatches === 0 ? 0 : 1);
