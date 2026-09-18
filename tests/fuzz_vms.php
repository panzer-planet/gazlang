<?php

/**
 * Differential fuzzing of the two VMs: php tests/fuzz_vms.php [RUNS = 300] [SEED = 1] [values|programs]
 *
 * CVMTest says the C VM matches the PHP VM on the corpus; this looks for what the corpus never
 * tries. Each run writes a program, compiles it with PHP and runs the bytecode on both VMs (the C
 * one as the tests build it, with the sanitizers), and reports any difference in standard output,
 * standard error or exit code. Not part of the suite: run it by hand after changing either VM,
 * and put whatever it finds in tests/vm_corpus/.
 *
 * `values` (the default) writes programs that always compile: variables holding every kind of
 * value, then statements that combine them with every operator, builtin, index, member and write
 * path, each in a try so an error is printed and the run goes on. That is where the two runtimes'
 * rules for values live. `programs` instead mutates the repository's small programs a token at a
 * time, as tests/fuzz_parsers.php does, for control flow, closures and classes.
 *
 * Break the C VM on purpose before believing a run that finds nothing.
 */

use GazLang\Tests\CVM;

require __DIR__.'/../vendor/autoload.php';
chdir(__DIR__.'/..');
// A generated program can build big values; running out of memory finds nothing
ini_set('memory_limit', '8G');

const LITERALS = [
    '0', '1', '-1', '2', '7', '-7', '63', '64', '255', '256', '3037000500', '4611686018427387904', '9223372036854775807',
    '(-9223372036854775807 - 1)', '0.0', '-0.0', '0.5', '-1.5', '2.5', '3.0', '0.1', '1e15', '1e16', '1e308', '5e-324',
    '1.7976931348623157e308', '""', '"a"', '"abc"', '"1"', '"01"', '"-5"', '"1.5"', '"1e3"', '" x "', '"\n"', '"\0"', '"é"',
    '"a,b,,c"', '"\$"', '"\{"', '"\"q\""', '"9223372036854775808"', '"0x10"', '"ABC"', 'true', 'false', 'null', '[]', '[1]',
    '[1, 2, 3]', '["a", [1]]', '[1.0]', '[null]', '{}', '{"a" => 1}', '{1 => "x", "1" => "y"}', '{"k" => [1, {"n" => null}]}',
    'P(1)', 'P("s")', 'Q()', 'R(2)', 'len', 'twice', 'add', '($x -> $x * 2)', '(() -> null)', 'P', 'Q', 'R',
];

const OPERATORS = ['+', '-', '*', '/', '%', '..', '==', '!=', '<', '<=', '>', '>=', '<=>', '&', '|', '^', '<<', '>>', '&&', '||', '??'];

const BUILTINS = [
    'len' => 1, 'slice' => [2, 3], 'lower' => 1, 'upper' => 1, 'trim' => 1, 'split' => 2, 'join' => 2, 'replace' => 3,
    'contains' => 2, 'starts_with' => 2, 'ends_with' => 2, 'index_of' => [2, 3], 'chr' => 1, 'ord' => 1,
    'to_int' => 1, 'to_float' => 1, 'floor' => 1, 'ceil' => 1, 'round' => [1, 2], 'abs' => 1, 'intdiv' => 2, 'min' => 2,
    'max' => 2, 'to_string' => 1, 'in_array' => 2, 'has_key' => 2, 'keys' => 1, 'values' => 1, 'type_of' => 1, 'is_a' => 2,
    'class_of' => 1, 'fields' => 1, 'error' => 1,
];

const MEMBERS = ['x', 'y', 'm', 'to_string', '_', 'nope', 'X'];

const PRELUDE = <<<'GAZ'
class P {
    #x;
    #y = [];
    const X = "px";
    fn _($x) { #x = $x; }
    fn m($a = 1) { return [#x, $a]; }
    fn to_string() { return "P(" .. #x .. ")"; }
}
class Q extends P {
    fn _() { ##_("q"); }
    fn m($a = 2) { return ##m($a) .. "!"; }
}
class R { #x; fn _($x) { #x = $x; } }
fn twice($f, $v = 1) { return $f($f($v)); }
fn add($a, $b = 10) { return $a + $b; }
fn show($v) {
    try {
        echo type_of($v) .. " " .. to_string([$v]);
    } catch (Error $e) {
        echo "unprintable: " .. $e.message;
    }
}

GAZ;

function pick(array $from)
{
    return $from[mt_rand(0, count($from) - 1)];
}

/** A variable or a literal, most often a variable, since variables are what statements change */
function operand(): string
{
    return mt_rand(0, 2) > 0 ? '$v'.mt_rand(0, 9) : pick(LITERALS);
}

function expression(int $depth = 0): string
{
    if ($depth > 2 || mt_rand(0, 3) === 0) {
        return operand();
    }
    // A bare class name before .name is a constant, which the parser checks; in a list it is a value
    $e = fn () => in_array($x = expression($depth + 1), ['P', 'Q', 'R'], true) ? "[{$x}][0]" : $x;

    return match (mt_rand(0, 16)) {
        0, 1, 2, 3 => '('.$e().' '.pick(OPERATORS).' '.$e().')',
        4 => pick(['-', '!', '~']).'('.$e().')',
        5 => '('.$e().')['.$e().']',
        6 => '(('.$e().')['.$e().'] ?? "missing")',
        7 => '('.$e().').'.pick(MEMBERS),
        8 => '(('.$e().').'.pick(MEMBERS).' ?? "unset")',
        9, 10 => builtin_call($e),
        11 => '('.$e().' ? '.$e().' : '.$e().')',
        12 => '['.$e().', ...'.$e().']',
        13 => '{'.$e().' => '.$e().'}',
        14 => '('.$e().')('.implode(', ', array_map(fn () => $e(), range(1, mt_rand(0, 2)))).')',
        15 => '('.$e().').m('.(mt_rand(0, 1) ? $e() : '').')',
        default => '"<{$v'.mt_rand(0, 9).'}>"',
    };
}

/**
 * An expression that is usually of the given type, so it gets past the type checks to the rules
 * behind them: overflow, rounding, printing, slicing, keys, copies
 */
function typed(string $type, int $depth = 0, bool $variables = true): string
{
    $t = fn (string $of) => typed($of, $depth + 1, $variables);
    if ($depth > 3 || mt_rand(0, 2) === 0) {
        $variable = $variables && mt_rand(0, 1);

        return match ($type) {
            'int' => $variable ? pick(['$i'.mt_rand(0, 3), '$i'.mt_rand(0, 3), '-len($s'.mt_rand(0, 3).')', 'len($l'.mt_rand(0, 3).') - 1', '-len($l'.mt_rand(0, 3).')']) : pick(['0', '1', '-1', '2', '3', '7', '-7', '10', '63', '64', '255', '1000', '3037000500', '4611686018427387904', '9223372036854775807', '(-9223372036854775807 - 1)']),
            'float' => $variable ? '$f'.mt_rand(0, 3) : pick(['0.0', '-0.0', '0.1', '0.2', '0.5', '1.5', '-2.5', '3.0', '1e15', '1e16', '1e17', '123.456', '1e-5', '1e300', '1.7976931348623157e308', '5e-324', '2.675', '1.005']),
            'string' => $variable ? '$s'.mt_rand(0, 3) : pick(['""', '"a"', '"abc"', '"a,b,,c"', '" pad "', '"1"', '"-12"', '"007"', '"1.5"', '"1e3"', '"9223372036854775808"', '"é"', '"\n\t"', '"\0x"', '"$"', '"A1b2"']),
            'list' => $variable ? '$l'.mt_rand(0, 3) : pick(['[]', '[1]', '[1, 2, 3]', '["b", "a", "c"]', '[1.5, -0.0]', '[[1], [2, [3]]]', '[null, true]', '[{"k" => 1}]']),
            default => $variable ? '$m'.mt_rand(0, 3) : pick(['{}', '{"a" => 1}', '{1 => "x", "1" => "y"}', '{"k" => [1, 2], "j" => {"n" => null}}', '{-1 => 0.5}']),
        };
    }

    return match ($type) {
        'int' => match (mt_rand(0, 9)) {
            0, 1, 2 => '('.$t('int').' '.pick(['+', '-', '*', '%', '&', '|', '^', '<<', '>>', '<=>']).' '.$t('int').')',
            3 => pick(['-', '~']).'('.$t('int').')',
            4 => 'len('.$t(pick(['string', 'list', 'map'])).')',
            5 => 'intdiv('.$t('int').', '.$t('int').')',
            6 => 'to_int('.$t(pick(['float', 'string', 'int'])).')',
            7 => 'ord('.$t('string').'['.$t('int').'])',
            8 => 'abs('.$t('int').')',
            default => pick(['min', 'max']).'('.$t('int').', '.$t(pick(['int', 'float'])).')',
        },
        'float' => match (mt_rand(0, 6)) {
            0, 1, 2 => '('.$t(pick(['float', 'int'])).' '.pick(['+', '-', '*', '/']).' '.$t('float').')',
            3 => 'round('.$t(pick(['float', 'int'])).(mt_rand(0, 1) ? ', '.$t('int') : '').')',
            4 => pick(['floor', 'ceil', 'abs']).'('.$t('float').')',
            5 => 'to_float('.$t(pick(['string', 'int', 'float'])).')',
            default => '-('.$t('float').')',
        },
        'string' => match (mt_rand(0, 12)) {
            0, 1, 2 => '('.$t(pick(['string', 'int', 'float'])).' .. '.$t(pick(['string', 'int', 'float', 'list'])).')',
            3 => 'slice('.$t('string').', '.$t('int').(mt_rand(0, 1) ? ', '.$t('int') : '').')',
            4 => pick(['lower', 'upper', 'trim', 'to_string']).'('.$t('string').')',
            5 => 'join('.$t('list').', '.$t('string').')',
            6 => 'replace('.$t('string').', '.$t('string').', '.$t('string').')',
            7 => 'repeat('.$t('string').', '.pick(['0', '1', '3']).')',
            8 => 'chr('.$t('int').')',
            9 => $t('string').'['.$t('int').']',
            10 => 'to_string('.$t(pick(['float', 'list', 'map', 'int'])).')',
            11 => ($variables ? '"{$s'.mt_rand(0, 3).'}-{$i'.mt_rand(0, 3).'}"' : '"x"'),
            default => 'type_of('.($variables ? expression($depth + 1) : '1').')',
        },
        'list' => match (mt_rand(0, 7)) {
            0 => 'split('.$t('string').', '.$t('string').')',
            1 => 'slice('.$t('list').', '.$t('int').(mt_rand(0, 1) ? ', '.$t('int') : '').')',
            2 => 'keys('.$t(pick(['list', 'map'])).')',
            3 => 'values('.$t('map').')',
            4 => '['.$t(pick(['int', 'string', 'float'])).', ...'.$t('list').']',
            5 => '[...'.$t('list').', ...'.$t('list').']',
            6 => '['.$t('int').', '.$t('string').']',
            default => $t('list').'['.$t('int').']',
        },
        default => match (mt_rand(0, 3)) {
            0 => '{'.$t(pick(['int', 'string'])).' => '.$t(pick(['int', 'list', 'string'])).'}',
            1 => '{'.$t('string').' => '.$t('int').', '.$t('int').' => '.$t('map').'}',
            2 => 'fields('.pick($variables ? ['P(1)', 'Q()', 'R(null)', '$o'] : ['P(1)', 'Q()', 'R(null)']).')',
            default => $t('map'),
        },
    };
}

function builtin_call(callable $e): string
{
    $name = pick(array_keys(BUILTINS));
    $arity = BUILTINS[$name];
    $count = is_int($arity) ? $arity : mt_rand($arity[0], $arity[1]);

    return $name.'('.implode(', ', array_map(fn () => $e(), range(1, $count) ?: [])).')';
}

function statement(): string
{
    if (mt_rand(0, 2) > 0) {
        return typed_statement();
    }
    $v = '$v'.mt_rand(0, 9);
    $e = fn () => expression();

    return match (mt_rand(0, 13)) {
        0, 1, 2 => 'echo '.$e().';',
        3 => "{$v} = ".$e().';',
        4 => "{$v}[".$e().'] = '.$e().';',
        5 => "{$v}[] = ".$e().';',
        6 => "delete {$v}[".$e().'];',
        7 => "{$v} ..= ".$e().';',
        8 => $v.pick(['++', '--', ' += 1', ' -= 2.5', ' *= 3', ' %= 2', ' <<= 1', ' ??= 5']).';',
        9 => "[{$v}, \$v".mt_rand(0, 9).'] = '.$e().';',
        10 => "{$v}.".pick(MEMBERS).' = '.$e().';',
        11 => "foreach ({$v} as \$k => \$item) { echo [\$k, \$item]; }",
        12 => "{$v}[".$e().']['.$e().'] = '.$e().';',
        default => "echo match ({$v}) { 1, \"a\" => \"one\", null => \"nothing\", ".$e().' => "same" };',
    };
}

/** A statement on the typed variables, mostly keeping their types */
function typed_statement(): string
{
    $type = pick(['int', 'float', 'string', 'list', 'map']);
    $var = ['int' => '$i', 'float' => '$f', 'string' => '$s', 'list' => '$l', 'map' => '$m'][$type].mt_rand(0, 3);
    $any = fn () => typed(pick(['int', 'float', 'string', 'list', 'map']));

    return match (mt_rand(0, 12)) {
        0, 1, 2 => 'echo '.typed($type).';',
        3, 4 => "{$var} = ".typed($type).';',
        5 => match ($type) {
            'int' => $var.pick(['++', '--', ' += '.typed('int'), ' -= '.typed('int'), ' *= '.typed('int'), ' %= '.typed('int'), ' <<= 3', ' >>= 1', ' |= 6', ' &= 12', ' ^= 5']).';',
            'float' => $var.pick([' += '.typed('float'), ' /= '.typed('float'), ' *= '.typed('float'), '++']).';',
            'string' => "{$var} ..= ".typed(pick(['string', 'int', 'float'])).';',
            'list' => "{$var}[] = ".$any().';',
            default => "{$var}[".typed(pick(['string', 'int'])).'] = '.$any().';',
        },
        6 => $type === 'list' ? "{$var}[".typed('int').'] = '.$any().';' : "echo {$var} == ".typed($type).';',
        7 => in_array($type, ['list', 'map'], true) ? "delete {$var}[".typed(pick(['int', 'string'])).'];' : 'echo '.typed($type).' < '.typed($type).';',
        8 => in_array($type, ['list', 'map'], true) ? "{$var}[".typed(pick(['int', 'string'])).'][] = '.$any().';' : 'echo ['.typed($type).', '.typed($type).'];',
        9 => "\$copy = {$var}; \$copy[] = 1; echo [{$var}, \$copy];",
        10 => "foreach ({$var} as \$k => \$item) { echo \$k .. \"=\" .. to_string([\$item]); }",
        11 => '$o.y[] = '.$any().'; $o.x = '.$any().'; echo $o; echo fields($o);',
        default => 'echo in_array('.$any().', '.typed('list').') .. " " .. has_key('.typed(pick(['list', 'map'])).', '.typed(pick(['int', 'string'])).');',
    };
}

function program(): string
{
    $lines = [PRELUDE];
    for ($i = 0; $i < 10; $i++) {
        $lines[] = "\$v{$i} = ".pick(LITERALS).';';
    }
    foreach (['int' => '$i', 'float' => '$f', 'string' => '$s', 'list' => '$l', 'map' => '$m'] as $type => $prefix) {
        for ($i = 0; $i < 4; $i++) {
            $lines[] = "try { {$prefix}{$i} = ".typed($type, 2, false).'; } catch ($e) { '.$prefix.$i.' = '.typed($type, 9, false).'; }';
        }
    }
    $lines[] = '$o = P(0);';
    for ($count = mt_rand(20, 60); $count > 0; $count--) {
        $catch = mt_rand(0, 1) ? 'echo "E " .. $e.message;' : 'echo "E " .. $e.message .. " @" .. $e.line .. " " .. len($e.trace);';
        $lines[] = 'try { '.statement()." } catch (Error \$e) { {$catch} } catch (\$e) { echo \"thrown\"; show(\$e); }";
    }
    foreach (['$v', '$i', '$f', '$s', '$l', '$m'] as $prefix) {
        for ($i = 0; $i < ($prefix === '$v' ? 10 : 4); $i++) {
            $lines[] = "show({$prefix}{$i});";
        }
    }

    return implode("\n", $lines)."\n";
}

/** A small program with a piece or two changed (see tests/fuzz_parsers.php) */
function mutated(array $sources): string
{
    preg_match_all('/\s+|\/\/[^\n]*|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[$@#.]*[A-Za-z_][A-Za-z0-9_]*|\d+(?:\.\d+)?|[-+*\/%=<>!&|^?.:]+|./s', pick($sources), $m);
    $pieces = $m[0];
    for ($count = mt_rand(1, 3); $count > 0; $count--) {
        $at = mt_rand(0, count($pieces) - 1);
        $kind = fn (string $p) => ctype_space($p[0]) ? 's' : (ctype_alnum($p[0]) || str_contains('$@#_', $p[0]) ? 'w' : $p[0]);
        $alike = array_keys(array_filter($pieces, fn ($p) => $kind($p) === $kind($pieces[$at])));
        $pieces[$at] = $pieces[pick($alike)];
    }

    return implode('', $pieces);
}

$runs = (int) ($argv[1] ?? 300);
mt_srand((int) ($argv[2] ?? 1));
$mode = $argv[3] ?? 'values';

CVM::build();
$dir = 'vm/build/fuzz';
@mkdir($dir, 0777, true);
array_map('unlink', glob("{$dir}/*.gaz"));

$sources = array_map('file_get_contents', array_values(array_filter(
    [...glob('tests/gaz/*/*_test.gaz'), ...glob('tests/codegen_corpus/*.gaz'), ...glob('tests/vm_corpus/*.gaz'), ...glob('examples/*.gaz')],
    fn ($f) => filesize($f) < 6000 && ! str_contains(file_get_contents($f), 'include') && ! str_contains(file_get_contents($f), 'read_stdin'),
)));

$entries = [];
for ($run = 0; $run < $runs; $run++) {
    $file = "{$dir}/{$run}.gaz";
    file_put_contents($file, $mode === 'programs' ? mutated($sources) : program());
    $entries[] = $file;
}

$mismatches = 0;
$ran = 0;
foreach (array_chunk($entries, 50) as $chunk) {
    foreach (CVM::runAll($chunk, $mode === 'programs') as $entry => $result) {
        if ($result === null) {
            continue;
        }
        $ran++;
        [$php, $c] = $result;
        if ($php !== $c) {
            $mismatches++;
            echo "=== MISMATCH {$entry} ===\n";
            foreach (['stdout', 'stderr', 'exit code'] as $i => $what) {
                if ($php[$i] !== $c[$i]) {
                    $a = explode("\n", (string) $php[$i]);
                    $b = explode("\n", (string) $c[$i]);
                    $line = 0;
                    while (($a[$line] ?? null) === ($b[$line] ?? null)) {
                        $line++;
                    }
                    echo "{$what}, line ".($line + 1).":\n  php: ".($a[$line] ?? '(nothing)')."\n  c:   ".($b[$line] ?? '(nothing)')."\n";
                }
            }
        }
    }
}

echo "{$runs} runs, {$ran} compiled and ran, {$mismatches} mismatches\n";
exit($mismatches === 0 ? 0 : 1);
