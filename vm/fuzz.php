<?php

// Fuzzes the C VM with no oracle: generated programs, mutated corpus programs and mutated
// bytecode run on the sanitized build (as vm/progress.php runs entries), and a run fails on a
// sanitizer report, a crash, a leak, a time-out, an error raised inside the compiler, or
// bytecode the compiler wrote that the loader refuses. What they print isn't checked.
//   php vm/fuzz.php [--seed N] [--seconds S] [--runs N]
//   php vm/fuzz.php --shrink FILE   shrink a saved failure with no time limit (in a run, a minute)
// Everything follows from the seed (printed first) and the checkout, so `--seed N --runs M`
// replays a run. A failing program is saved in vm/build/fuzz/, shrunk to a small one that fails
// the same way, and printed; once fixed, add it to a corpus and record what it prints
// (php vm/progress.php --update). Programs never open sockets, start programs, exit, write
// files or read standard input: any whose text (or an included file's) names those builtins
// is skipped, which is sound because a builtin can only be reached by its name.

require __DIR__.'/../vendor/autoload.php';

use GazLang\Tests\CVM;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

const FORBIDDEN = '/\b(run|socket_\w*|exit|write_file|read_stdin)\b/';
const INTERESTING = ['0', '1', '-1', '2', '63', '64', '255', '256', '9223372036854775807', '-9223372036854775807', '4294967296', '0.0', '-0.0', '1.5', '1e308', '0.1', '10000', '""', '"a"', '[]', '{}', 'null', 'true', 'false'];
const BATCH = 48;
const WORK = 'vm/build/fuzz';

$options = getopt('', ['seed:', 'seconds:', 'runs:', 'shrink:']);
if (isset($options['shrink'])) {
    CVM::build();
    CVM::$timeLimit = 10;
    @mkdir(CVM::ROOT.'/'.WORK.'/work', 0777, true);
    $file = $options['shrink'];
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $entry = WORK."/work/replay.{$ext}";
    copy($file, CVM::ROOT.'/'.$entry);
    $c = CVM::runC([$entry])[$entry];
    $verdict = verdict($entry, $c) ?? exit("{$file}: nothing wrong\n");
    echo "{$file}: ".signature($verdict, $c)."\n".rtrim(shrink((string) file_get_contents($file), $ext, signature($verdict, $c), PHP_INT_MAX))."\n";
    exit(1);
}
$seed = (int) ($options['seed'] ?? random_int(1, PHP_INT_MAX));
$seconds = (int) ($options['seconds'] ?? 60);
$runs = isset($options['runs']) ? (int) $options['runs'] : null;
echo "fuzz: seed {$seed}\n";
$rng = new Randomizer(new Xoshiro256StarStar($seed));

CVM::build();
CVM::$timeLimit = 10;
@mkdir(CVM::ROOT.'/'.WORK.'/work', 0777, true);
array_map(unlink(...), glob(CVM::ROOT.'/'.WORK.'/work/*') ?: []);

$seeds = seeds();
$gzbSeeds = gzbSeeds($seeds, $rng);
$generator = new ProgramGenerator($rng, builtins());
printf("fuzz: %d programs and %d bytecode files to mutate\n", count($seeds), count($gzbSeeds));

$start = microtime(true);
$count = 0;
$failed = [];
$timeouts = 0;
while ($runs === null ? microtime(true) - $start < $seconds : $count < $runs) {
    $batch = [];
    for ($i = 0; $i < ($runs === null ? BATCH : min(BATCH, $runs - $count)); $i++) {
        $n = $count++;
        $roll = $rng->getInt(0, 9);
        // Named g (generated) or m (a mutant), since only a generated program is sure to end
        [$text, $name] = match (true) {
            $roll < 4 => [keep($generator->program()), "g{$n}.gaz"],
            $roll < 8 => [mutateSource($rng, $seeds), "m{$n}.gaz"],
            default => [mutateBytecode($rng, $gzbSeeds), "m{$n}.gzb"],
        };
        if ($text === null) {
            continue;
        }
        $entry = WORK."/work/{$name}";
        file_put_contents(CVM::ROOT.'/'.$entry, $text);
        $batch[$entry] = $n;
    }
    foreach (CVM::runC(array_keys($batch)) as $entry => $c) {
        $verdict = verdict($entry, $c);
        if ($verdict === null) {
            continue;
        }
        $signature = signature($verdict, $c);
        $ext = pathinfo($entry, PATHINFO_EXTENSION);
        $saved = WORK."/fail-{$seed}-{$batch[$entry]}.{$ext}";
        copy(CVM::ROOT.'/'.$entry, CVM::ROOT.'/'.$saved);
        // Nothing tells a mutant that loops by itself from one that hangs the VM, so it is
        // saved and counted but doesn't fail the run: a generated program's time-out does
        if ($verdict === 'time-out' && str_contains($entry, '/m')) {
            echo "time-out of a mutant, which may just loop: {$saved}\n";
            $timeouts++;

            continue;
        }
        echo "FAIL {$verdict} ({$signature}): {$saved}\n";
        if (isset($failed[$signature])) {
            continue;
        }
        $failed[$signature] = true;
        echo '  '.implode("\n  ", array_slice(explode("\n", trim($c[1])), 0, 12))."\n";
        // ponytail: a time-out isn't shrunk, since each try would take the whole time limit
        if ($verdict !== 'time-out') {
            $small = shrink((string) file_get_contents(CVM::ROOT.'/'.$saved), $ext, $signature);
            $min = WORK."/fail-{$seed}-{$batch[$entry]}.min.{$ext}";
            file_put_contents(CVM::ROOT.'/'.$min, $small);
            echo "  shrunk to {$min}:\n    ".str_replace("\n", "\n    ", rtrim($small))."\n";
        }
    }
}
printf("fuzz: %d programs in %ds, %d distinct failures%s (seed %d)\n", $count, microtime(true) - $start, count($failed),
    $timeouts === 0 ? '' : ", {$timeouts} mutant time-out".($timeouts === 1 ? '' : 's'), $seed);
exit($failed === [] ? 0 : 1);

/**
 * What is wrong with an entry's run, or null when nothing is
 *
 * @param  array{0: string, 1: string, 2: int, 3: string|null}  $c
 */
function verdict(string $entry, array $c): ?string
{
    $source = str_ends_with($entry, '.gaz');

    return match (true) {
        $c[2] === -1 => 'time-out',
        (bool) preg_match('/Sanitizer|runtime error:/', $c[1]) => 'sanitizer',
        $source && (bool) preg_match('/ at compiler\/\w+\.gaz:\d+/', $c[1]) => 'error in the compiler',
        $source && str_starts_with($c[3] ?? '', 'leaks not checked: the program did not load') => 'the loader refused the compiler\'s bytecode',
        // A source file the compiler refuses prints its error and no GAZVM_STATS line
        $source && $c[3] === null && $c[2] === 1 && str_starts_with($c[1], 'Error: ') => null,
        default => CVM::leak($c),
    };
}

/**
 * The part of a failure that stays the same while it is shrunk: the verdict, and for a sanitizer
 * the kind of report and where in the VM, for the compiler the error without its location
 *
 * @param  array{0: string, 1: string, 2: int, 3: string|null}  $c
 */
function signature(string $verdict, array $c): string
{
    if ($verdict === 'sanitizer') {
        preg_match('/(\S+:\d+):\d+: runtime error|Sanitizer: ([\w-]+)/', $c[1], $kind);
        preg_match('/#\d+ 0x[0-9a-f]+ in (?!__|_asan|asan|wrap_|malloc|free|realloc|calloc|mem|str)(\w+)/', $c[1], $frame);

        return 'sanitizer '.($kind[1] ?? '').($kind[2] ?? '').' in '.($frame[1] ?? '?');
    }
    if ($verdict === 'error in the compiler') {
        preg_match('/^Error: (.*?) at compiler\/(\w+\.gaz)/', $c[1], $m);

        return 'compiler: '.preg_replace('/"[^"]*"|\d+/', '_', $m[1] ?? '').' in '.($m[2] ?? '?');
    }

    return preg_replace('/\d+ values leaked.*/', 'leaked', $verdict) ?? $verdict;
}

/**
 * A smaller program that still fails with the same signature: whole blocks of lines removed
 * where that keeps the failure, then chunks of lines, then of tokens, the chunk halving once
 * none can go. Each try is a sanitized run, about 0.1s even run 24 at once, so it stops after
 * $seconds with what it has.
 */
function shrink(string $text, string $ext, string $signature, int $seconds = 60): string
{
    $deadline = microtime(true) + min($seconds, 1e9);
    // Again while that finds more, since a removal can free what an earlier pass had to keep
    for ($before = null; $before !== $text && microtime(true) < $deadline;) {
        $before = $text;
        $text = shrinkOnce($text, $ext, $signature, $deadline);
    }

    return $text;
}

function shrinkOnce(string $text, string $ext, string $signature, float $deadline): string
{
    foreach ($ext === 'gaz' ? ['blocks', 'lines', 'tokens'] : ['lines'] as $unit) {
        $parts = $unit === 'tokens' ? tokens($text) : explode("\n", $text);
        $glue = $unit === 'tokens' ? '' : "\n";
        // Each size from half down to 1, or for blocks each line that opens one, with its end
        for ($size = $unit === 'blocks' ? 0 : max(1, intdiv(count($parts), 2)); $size >= ($unit === 'blocks' ? 0 : 1); $size = $size === 0 ? -1 : intdiv($size, 2)) {
            // From the start on; after a removal the next try is at the same place
            for ($at = 0; $at < count($parts) && microtime(true) < $deadline;) {
                $tries = [];
                $next = $at;
                for (; $next < count($parts) && count($tries) < 24; $next += max(1, $size)) {
                    $length = $size === 0 ? blockLength($parts, $next) : $size;
                    if ($length > 0) {
                        $tries[$next] = [...array_slice($parts, 0, $next), ...array_slice($parts, $next + $length)];
                    }
                }
                $found = firstFailing(array_map(fn ($try) => implode($glue, $try), $tries), $ext, $signature);
                if ($found === null) {
                    $at = $next;
                } else {
                    $parts = $tries[$found];
                    $at = $found;
                }
            }
        }
        $text = implode($glue, $parts);
    }

    return $text;
}

/**
 * How many lines from this one the block it opens takes, up to the line that closes it, or 0
 * when it opens none (counting braces, strings or not: a wrong guess only makes a try fail)
 *
 * @param  list<string>  $lines
 */
function blockLength(array $lines, int $at): int
{
    $depth = 0;
    for ($i = $at; $i < count($lines); $i++) {
        $depth += substr_count($lines[$i], '{') - substr_count($lines[$i], '}');
        if ($depth <= 0) {
            return $i === $at ? 0 : $i - $at + 1;
        }
    }

    return 0;
}

/**
 * The key of the first of these programs, in order, to fail with the signature, or null
 *
 * @param  array<int, string>  $texts
 */
function firstFailing(array $texts, string $ext, string $signature): ?int
{
    $entries = [];
    foreach ($texts as $i => $text) {
        $entries[$i] = WORK."/work/shrink{$i}.{$ext}";
        file_put_contents(CVM::ROOT.'/'.$entries[$i], $text);
    }
    $results = CVM::runC(array_values($entries));
    foreach ($entries as $i => $entry) {
        $verdict = verdict($entry, $results[$entry]);
        if ($verdict !== null && signature($verdict, $results[$entry]) === $signature) {
            return $i;
        }
    }

    return null;
}

/**
 * GazLang source split into tokens, roughly (strings, comments, names with their sigils, single
 * characters), each with the whitespace before it, such that joining them gives the source back
 *
 * @return list<string>
 */
function tokens(string $text): array
{
    preg_match_all('/\s*(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\/\/[^\n]*|\/\*.*?\*\/|[$@#.]*\w+|\.\.\.|\.\.=?|[-+*\/%.<>=!?&|^]=|->|=>|<=>|\?\?=?|&&|\|\||<<|>>|\S)|\s+$/s', $text, $m);

    return $m[0];
}

/**
 * The programs to mutate: every GazLang file under tests/ and examples/, and the snippets, each
 * with the directory its includes resolve against, sorted so a seed picks the same ones
 *
 * @return list<array{0: string, 1: string}> The text and its directory
 */
function seeds(): array
{
    $files = [...glob(CVM::ROOT.'/examples/*.gaz') ?: []];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CVM::ROOT.'/tests', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $path) {
        if (str_ends_with((string) $path, '.gaz')) {
            $files[] = (string) $path;
        }
    }
    sort($files);
    $seeds = [];
    foreach ($files as $file) {
        $seeds[] = [(string) file_get_contents($file), dirname($file)];
    }
    foreach (CVM::snippets() as $text) {
        $seeds[] = [$text, CVM::ROOT];
    }

    return array_values(array_filter($seeds, fn ($s) => ! preg_match(FORBIDDEN, $s[0])));
}

/**
 * The bytecode to mutate: tests/bytecode_corpus, and some of the programs compiled
 *
 * @param  list<array{0: string, 1: string}>  $seeds
 * @return list<string>
 */
function gzbSeeds(array $seeds, Randomizer $rng): array
{
    $gzb = array_map(file_get_contents(...), glob(CVM::ROOT.'/tests/bytecode_corpus/*.gzb') ?: []);
    $commands = [];
    foreach ($rng->pickArrayKeys($seeds, min(60, count($seeds))) as $i) {
        $text = absoluteIncludes($seeds[$i][0], $seeds[$i][1]);
        if ($text === null) {
            continue;
        }
        $file = WORK."/work/compile{$i}.gaz";
        file_put_contents(CVM::ROOT.'/'.$file, $text);
        $commands[$i] = [CVM::ROOT.'/bin/gazlang', '-c', '-f', $file];
    }
    foreach (CVM::processes($commands) as $result) {
        if ($result[2] === 0) {
            $gzb[] = $result[0];
        }
    }

    return array_values(array_filter($gzb, fn ($g) => ! preg_match(FORBIDDEN, (string) $g)));
}

/**
 * The builtins a generated program may call, by name, with how many arguments they take
 *
 * @return array<string, array{0: int, 1: int}>
 */
function builtins(): array
{
    file_put_contents(CVM::ROOT.'/'.WORK.'/work/builtins.gaz', 'echo builtins();');
    [$out] = CVM::process([CVM::ROOT.'/bin/gazlang', '-f', WORK.'/work/builtins.gaz']);
    preg_match_all('/"(\w+)" => (?:(\d+)|\[(\d+), (\d+)\])/', $out, $m, PREG_SET_ORDER);
    $builtins = [];
    foreach ($m as $b) {
        if (! preg_match(FORBIDDEN, $b[1]) && $b[1] !== 'rand_seed' && $b[1] !== 'error') {
            $builtins[$b[1]] = isset($b[3]) ? [(int) $b[3], (int) $b[4]] : [(int) $b[2], (int) $b[2]];
        }
    }
    ksort($builtins);

    return $builtins;
}

/**
 * A program's includes made absolute, since the mutant runs from vm/build/fuzz/work, or null when
 * it includes a file that names a forbidden builtin (or includes one that does)
 */
function absoluteIncludes(string $text, string $dir): ?string
{
    $bad = false;
    $text = preg_replace_callback('/\binclude\s*"([^"\\\\]*)"/', function ($m) use ($dir, &$bad) {
        $path = realpath(str_starts_with($m[1], '/') ? $m[1] : "{$dir}/{$m[1]}");
        if ($path === false || is_dir($path)) {
            return $m[0];
        }
        $bad = $bad || includesForbidden($path);

        return 'include "'.$path.'"';
    }, $text);

    return $bad ? null : $text;
}

/**
 * Whether a file, or one it includes, names a forbidden builtin
 */
function includesForbidden(string $path, array &$seen = []): bool
{
    static $cache = [];
    if (isset($cache[$path]) || isset($seen[$path])) {
        return $cache[$path] ?? false;
    }
    $seen[$path] = true;
    $text = (string) file_get_contents($path);
    $bad = (bool) preg_match(FORBIDDEN, $text);
    preg_match_all('/\binclude\s*"([^"\\\\]*)"/', $text, $m);
    foreach ($m[1] as $include) {
        $inner = realpath(dirname($path).'/'.$include);
        $bad = $bad || ($inner !== false && includesForbidden($inner, $seen));
    }

    return $cache[$path] = $bad;
}

/**
 * A corpus program with one to four mutations: tokens deleted, duplicated, replaced by another
 * program's or by an interesting value, and spans of lines spliced in from another program
 *
 * @param  list<array{0: string, 1: string}>  $seeds
 */
function mutateSource(Randomizer $rng, array $seeds): ?string
{
    [$text, $dir] = $seeds[$rng->getInt(0, count($seeds) - 1)];
    $tokens = tokens($text);
    for ($n = $rng->getInt(1, 4); $n > 0 && $tokens !== []; $n--) {
        $at = $rng->getInt(0, count($tokens) - 1);
        [$other] = $seeds[$rng->getInt(0, count($seeds) - 1)];
        $theirs = tokens($other) ?: [''];
        $from = $rng->getInt(0, count($theirs) - 1);
        match ($rng->getInt(0, 5)) {
            0 => array_splice($tokens, $at, $rng->getInt(1, 3)),
            1 => array_splice($tokens, $at, 0, array_slice($tokens, $at, $rng->getInt(1, 8))),
            2 => $tokens[$at] = $theirs[$from],
            3 => $tokens[$at] = INTERESTING[$rng->getInt(0, count(INTERESTING) - 1)],
            4 => array_splice($tokens, $at, 0, array_slice($theirs, $from, $rng->getInt(1, 40))),
            default => array_splice($tokens, $at, $rng->getInt(0, 20), array_slice($tokens, $rng->getInt(0, count($tokens) - 1), $rng->getInt(1, 20))),
        };
    }

    return keep(absoluteIncludes(implode('', $tokens), $dir));
}

/**
 * A bytecode file with one to four mutations: lines deleted, duplicated or swapped, taken from
 * another file, or with an operand or instruction name replaced
 *
 * @param  list<string>  $seeds
 */
function mutateBytecode(Randomizer $rng, array $seeds): ?string
{
    $lines = explode("\n", $seeds[$rng->getInt(0, count($seeds) - 1)]);
    // The header and globals line stay, or nearly every mutant is refused at the first line
    $header = array_splice($lines, 0, 2);
    for ($n = $rng->getInt(1, 4); $n > 0 && $lines !== []; $n--) {
        $at = instructionLine($rng, $lines);
        $other = instructionLine($rng, $lines);
        $theirs = explode("\n", $seeds[$rng->getInt(0, count($seeds) - 1)]);
        $line = $theirs[instructionLine($rng, $theirs)];
        $words = explode(' ', $lines[$at]);
        $w = $rng->getInt(min(1, count($words) - 1), count($words) - 1);
        match ($rng->getInt(0, 5)) {
            0 => array_splice($lines, $at, 1),
            1 => array_splice($lines, $at, 0, [$lines[$at]]),
            2 => [$lines[$at], $lines[$other]] = [$lines[$other], $lines[$at]],
            3 => array_splice($lines, $at, 0, [$line]),
            4 => $words[$w] = INTERESTING[$rng->getInt(0, count(INTERESTING) - 1)],
            default => $words[0] = explode(' ', $line)[0],
        };
        if (isset($lines[$at]) && $words !== explode(' ', $lines[$at])) {
            $lines[$at] = implode(' ', $words);
        }
    }

    return keep(implode("\n", [...$header, ...$lines]));
}

/**
 * A line to mutate, usually an instruction: a block's header lines (top, locals, fn, class,
 * field, capture) are a good part of a small file, and damage to one is refused by the header
 * parser before an instruction is read at all, which is a check the corpus already covers
 *
 * @param  list<string>  $lines
 */
function instructionLine(Randomizer $rng, array $lines): int
{
    $at = $rng->getInt(0, count($lines) - 1);
    for ($tries = 0; $tries < 8 && ! preg_match('/^[A-Z_]+( |$)/', $lines[$at]); $tries++) {
        // One try in five stays wherever it landed, so a header is still mutated sometimes
        if ($rng->getInt(1, 5) === 1) {
            break;
        }
        $at = $rng->getInt(0, count($lines) - 1);
    }

    return $at;
}

/**
 * The program unless it names a forbidden builtin, seeded if it draws random numbers, so that
 * what it does can be replayed (on its first line, keeping the others where they were)
 */
function keep(?string $text): ?string
{
    if ($text === null || preg_match(FORBIDDEN, $text)) {
        return null;
    }
    if (str_contains($text, 'rand_') && ! str_starts_with($text, 'GAZLANG BYTECODE')) {
        $text = 'rand_seed(1); '.$text;
    }

    return $text;
}

/**
 * Generates well-formed programs that end: functions call only the ones before them, methods
 * the ones before them, loops run a few times and lambdas call nothing that calls back, so what
 * a program does is bounded however the pieces combine. Every top level statement is in a try,
 * so one error doesn't end the program. They lean on what the VM finds hard: reference counts
 * (copy on write, paths, closures' own variables), cycles for the collector, errors unwinding
 * through finally, and the builtins with arguments of every type.
 */
final class ProgramGenerator
{
    private int $depth = 0;

    private bool $finally = false;

    /** @var list<array{0: int, 1: int}> Each function's required and total parameters */
    private array $functions = [];

    /** @var list<array{parent: int|null, fields: int, methods: list<int>, arity: int}> */
    private array $classes = [];

    /** @var array{kind: string, index: int, class: int|null, params: int, loop: int} Where the code being generated is */
    private array $scope;

    /**
     * @param  array<string, array{0: int, 1: int}>  $builtins
     */
    public function __construct(private Randomizer $rng, private array $builtins) {}

    public function program(): string
    {
        $this->functions = [];
        $this->classes = [];
        $out = '';
        for ($i = $this->int(0, 4); $i >= 0; $i--) {
            $required = $this->int(0, 2);
            $this->functions[] = [$required, $required + $this->int(0, 1)];
        }
        for ($i = $this->int(0, 3); $i >= 0; $i--) {
            $k = count($this->classes);
            $this->classes[] = ['parent' => $k > 0 && $this->chance(2) ? $this->int(0, $k - 1) : null, 'fields' => $this->int(1, 3), 'methods' => [], 'arity' => $this->int(0, 2)];
            for ($m = $this->int(1, 3); $m > 0; $m--) {
                $this->classes[$k]['methods'][] = $this->int(0, 1);
            }
        }
        foreach ($this->functions as $f => [$required, $total]) {
            $this->scope = ['kind' => 'function', 'index' => $f, 'class' => null, 'params' => $total, 'loop' => 0];
            $params = [];
            for ($p = 0; $p < $total; $p++) {
                $params[] = "\$p{$p}".($p >= $required ? ' = '.$this->expr() : '');
            }
            $out .= "fn f{$f}(".implode(', ', $params).") {\n".$this->locals(4).$this->block(4)."}\n";
        }
        foreach ($this->classes as $k => $class) {
            $out .= $this->classDeclaration($k, $class);
        }
        $this->scope = ['kind' => 'top', 'index' => PHP_INT_MAX, 'class' => null, 'params' => 0, 'loop' => 0];
        $out .= $this->locals(0);
        for ($g = 0; $g < 3; $g++) {
            $out .= "@g{$g} = {$this->literal()};\n";
        }
        for ($i = $this->int(3, 25); $i > 0; $i--) {
            $out .= "try {\n".$this->statement()."} catch (\$e) { echo \$e; }\n";
        }

        return $out;
    }

    /**
     * @param  array{parent: int|null, fields: int, methods: list<int>, arity: int}  $class
     */
    private function classDeclaration(int $k, array $class): string
    {
        $out = "class C{$k}".($class['parent'] === null ? '' : " extends C{$class['parent']}")." {\n";
        for ($f = 0; $f < $class['fields']; $f++) {
            $out .= "    #c{$k}x{$f}".($this->chance(2) ? ' = '.$this->literal() : '').";\n";
        }
        $this->scope = ['kind' => 'method', 'index' => -1, 'class' => $k, 'params' => $class['arity'], 'loop' => 0];
        $params = implode(', ', array_map(fn ($p) => "\$p{$p}", range(0, $class['arity'] - 1)));
        $out .= '    fn _('.($class['arity'] > 0 ? $params : '').") {\n";
        if ($class['parent'] !== null) {
            $out .= '        ##_('.$this->args($this->classes[$class['parent']]['arity']).");\n";
        }
        $out .= $this->locals(8).$this->block(8)."    }\n";
        foreach ($class['methods'] as $m => $arity) {
            $this->scope = ['kind' => 'method', 'index' => $m, 'class' => $k, 'params' => $arity, 'loop' => 0];
            $out .= "    fn c{$k}m{$m}(".($arity > 0 ? '$p0' : '').") {\n".$this->locals(8).$this->block(8)."    }\n";
        }
        if ($this->chance(2)) {
            // One field, so printing an object that holds itself recurses once per level, not twice
            $out .= "    fn to_string() { return \"C{$k}<\" .. (#c{$k}x0 ?? \"\") .. \">\"; }\n";
        }

        return $out."}\n";
    }

    /**
     * $v0 to $v3 set, so that statements using them get further than an undefined variable
     */
    private function locals(int $indent): string
    {
        $out = '';
        for ($v = 0; $v < 4; $v++) {
            $out .= str_repeat(' ', $indent)."\$v{$v} = {$this->literal()};\n";
        }

        return $out;
    }

    private function block(int $indent): string
    {
        $out = '';
        for ($i = $this->int(1, 4); $i > 0; $i--) {
            $out .= preg_replace('/^/m', str_repeat(' ', $indent), $this->statement());
        }

        return $out;
    }

    private function statement(): string
    {
        $this->depth++;
        $nested = $this->depth < 3;
        $v = $this->target();
        $s = match ($this->int(0, $nested ? 17 : 10)) {
            0, 1 => "{$v} = {$this->expr()};",
            2 => "{$v} ".['+=', '-=', '..=', '??=', '*=', '|=', '<<='][$this->int(0, 6)]." {$this->expr()};",
            3 => "{$v}[] = {$this->expr()};",
            4 => "{$v}[{$this->expr()}] = {$this->expr()};",
            5 => "{$v}".$this->field()." = {$this->expr()};",
            6 => "[{$v}, {$this->target()}] = {$this->expr()};",
            7 => "delete {$v}[{$this->expr()}];",
            8 => "echo {$this->expr()};",
            9 => $this->scope['kind'] === 'top' || $this->scope['index'] === -1 || $this->finally ? "error({$this->expr()});" : "return {$this->expr()};",
            10 => "{$v}".['++', '--'][$this->int(0, 1)].';',
            11 => "if ({$this->expr()}) {\n{$this->block(4)}} else {\n{$this->block(4)}}",
            12, 13 => $this->loop(),
            14 => "foreach ({$this->expr()} as \$k{$this->depth} => \$v{$this->int(0, 3)}) {\n{$this->block(4)}}",
            15 => "try {\n{$this->block(4)}} catch (".($this->chance(2) ? 'Error ' : '')."\$e) {\n{$this->block(4)}}".($this->chance(2) ? " finally {\n{$this->finallyBlock()}}" : ''),
            16 => "match ({$this->expr()}) {\n    {$this->literal()} => { {$this->statement()} }\n    default => { {$this->statement()} }\n}",
            default => "{$v} = ".$this->lambda().';',
        };
        if ($this->scope['loop'] > 0 && ! $this->finally && $this->chance(8)) {
            $s .= "\n".['break;', 'continue;'][$this->int(0, 1)];
        }
        $this->depth--;

        return $s."\n";
    }

    /**
     * A finally block, which return, break and continue can't leave
     */
    private function finallyBlock(): string
    {
        [$saved, $this->finally] = [$this->finally, true];
        $block = $this->block(4);
        $this->finally = $saved;

        return $block;
    }

    private function loop(): string
    {
        // Loop counters are named apart from every variable, so nothing but the loop assigns one
        $i = "\$i{$this->depth}";
        $this->scope['loop']++;
        $body = $this->block(4);
        $this->scope['loop']--;

        return "for ({$i} = 0; {$i} < {$this->int(1, 3)}; {$i}++) {\n{$body}}";
    }

    private function expr(): string
    {
        $this->depth++;
        $leaf = $this->depth > 4 || $this->chance(3);
        $e = $leaf ? ($this->chance(2) ? $this->variable() : $this->literal()) : match ($this->int(0, 13)) {
            0 => '['.implode(', ', [...array_map(fn () => $this->expr(), range(0, $this->int(0, 2))), ...($this->chance(4) ? ['...'.$this->variable()] : [])]).']',
            1 => '{'.implode(', ', array_map(fn () => "{$this->literal()} => {$this->expr()}", range(0, $this->int(0, 2)))).'}',
            2, 3 => "({$this->expr()} ".$this->operator()." {$this->expr()})",
            4 => ['-', '!', '~'][$this->int(0, 2)]."({$this->expr()})",
            5 => "{$this->expr()}[{$this->expr()}]",
            6, 7 => $this->builtinCall(),
            8 => $this->functionCall(),
            9 => $this->classes === [] ? 'null' : $this->construct(),
            10 => "{$this->target()}".$this->field(),
            11 => "({$this->expr()} ? {$this->expr()} : {$this->expr()})",
            12 => $this->lambda(),
            default => "\"a{\$v{$this->int(0, 3)}}b\"",
        };
        $this->depth--;

        return $e;
    }

    private function exprs(int $n): string
    {
        return implode(', ', array_map(fn () => $this->expr(), $n > 0 ? range(1, $n) : []));
    }

    private function args(int $n): string
    {
        return $this->exprs($n);
    }

    private function operator(): string
    {
        $ops = ['+', '-', '*', '/', '%', '..', '==', '!=', '<', '<=', '>', '>=', '<=>', '&&', '||', '??', '&', '|', '^', '<<', '>>'];

        return $ops[$this->int(0, count($ops) - 1)];
    }

    private function builtinCall(): string
    {
        $names = array_keys($this->builtins);
        $name = $names[$this->int(0, count($names) - 1)];
        if ($this->scope['kind'] === 'lambda' && in_array($name, ['map', 'filter', 'reduce', 'sort'], true)) {
            return $this->literal();
        }
        [$min, $max] = $this->builtins[$name];
        // repeat() of any string a few times, or of a short one as many times as it will
        // take, which it refuses before allocating. A big count on a string of unknown length
        // is the one combination with nothing to learn: minutes of real allocating, then death
        if ($name === 'repeat') {
            $counts = ['-1', '1000', '100000', '4611686018427387904', '9223372036854775807'];

            return $this->chance(2)
                ? "repeat({$this->literal()}, {$this->int(0, 3)})"
                : 'repeat("ab", '.$counts[$this->int(0, count($counts) - 1)].')';
        }
        $args = [];
        for ($i = $this->int($min, $max); $i > 0; $i--) {
            // map, filter, reduce and sort take a function: usually give them one
            $args[] = $this->chance(2) && in_array($name, ['map', 'filter', 'reduce', 'sort'], true) && count($args) === 1 ? $this->lambda(2) : $this->expr();
        }

        return "{$name}(".implode(', ', $args).')';
    }

    private function functionCall(): string
    {
        if ($this->scope['kind'] === 'lambda') {
            return $this->literal();
        }
        if ($this->scope['kind'] === 'method' && $this->chance(2)) {
            $methods = $this->methodsBefore();
            if ($methods !== []) {
                [$name, $arity] = $methods[$this->int(0, count($methods) - 1)];

                return "#{$name}(".$this->args($arity).')';
            }
        }
        // Functions call only the ones before them; methods and the top level any, since no
        // function calls a method or makes an object
        $before = $this->scope['kind'] === 'function' ? $this->scope['index'] : count($this->functions);
        if ($before === 0) {
            return $this->literal();
        }
        $f = $this->int(0, $before - 1);
        [$required, $total] = $this->functions[$f];

        return "f{$f}(".$this->args($this->int($required, $total)).')';
    }

    /**
     * The methods of the current class and its parents a method may call: those numbered below
     * it, so calls only go down and end
     *
     * @return list<array{0: string, 1: int}>
     */
    private function methodsBefore(): array
    {
        $found = [];
        for ($k = $this->scope['class']; $k !== null; $k = $this->classes[$k]['parent']) {
            foreach ($this->classes[$k]['methods'] as $m => $arity) {
                if ($m < $this->scope['index']) {
                    $found[] = ["c{$k}m{$m}", $arity];
                }
            }
        }

        return $found;
    }

    private function construct(): string
    {
        // Constructors run other constructors only through ##_, so only the top level makes objects
        if ($this->scope['kind'] !== 'top') {
            return $this->literal();
        }
        $k = $this->int(0, count($this->classes) - 1);

        return "C{$k}(".$this->args($this->classes[$k]['arity']).')';
    }

    /**
     * A field access, usually one some class declares, or a method called on the object when it
     * is a method below the running code's (so, like everything, calls only go down)
     */
    private function field(): string
    {
        if ($this->classes === []) {
            return '.nothing';
        }
        $k = $this->int(0, count($this->classes) - 1);

        return ".c{$k}x".$this->int(0, $this->classes[$k]['fields'] - 1);
    }

    /**
     * A lambda whose body calls nothing but builtins that call nothing back, so calling one ends
     * wherever it was stored
     */
    private function lambda(int $params = -1): string
    {
        $n = $params >= 0 ? $params : $this->int(0, 2);
        $saved = $this->scope;
        $this->scope = ['kind' => 'lambda', 'index' => 0, 'class' => $saved['kind'] === 'method' ? $saved['class'] : null, 'params' => max($saved['params'], $n), 'loop' => 0];
        $this->depth += 2;
        $body = $this->chance(3) ? "{\n{$this->block(4)}}" : "({$this->expr()})";
        $this->depth -= 2;
        $this->scope = $saved;
        $names = implode(', ', array_map(fn ($p) => "\$p{$p}", $n > 0 ? range(0, $n - 1) : []));

        return "(({$names}) -> {$body})";
    }

    /**
     * A variable, a global, a parameter or one of the object's fields: what `=` can assign
     */
    private function target(): string
    {
        $v = $this->variable();

        return $v === '#' ? $this->ownField() : $v;
    }

    private function variable(): string
    {
        $scope = $this->scope;
        $pick = $this->int(0, 9);

        return match (true) {
            $pick < 2 && $scope['params'] > 0 => '$p'.$this->int(0, $scope['params'] - 1),
            $pick < 3 && $scope['class'] !== null => $this->chance(2) ? '#' : $this->ownField(),
            $pick < 4 => '@g'.$this->int(0, 2),
            default => '$v'.$this->int(0, 3),
        };
    }

    private function ownField(): string
    {
        $fields = [];
        for ($k = $this->scope['class']; $k !== null; $k = $this->classes[$k]['parent']) {
            for ($f = 0; $f < $this->classes[$k]['fields']; $f++) {
                $fields[] = "#c{$k}x{$f}";
            }
        }

        return $fields[$this->int(0, count($fields) - 1)];
    }

    private function literal(): string
    {
        return match ($this->int(0, 3)) {
            0 => INTERESTING[$this->int(0, count(INTERESTING) - 1)],
            1 => (string) $this->int(-3, 10),
            2 => '"'.['', 'x', 'ab', '\n', '\x00', '\u{1F600}', 'é', '10', '-3'][$this->int(0, 8)].'"',
            default => ['[1, [2, 3]]', '{"a" => [1], 2 => {}}', '[[], {}]', '"s"'][$this->int(0, 3)],
        };
    }

    private function int(int $min, int $max): int
    {
        return $this->rng->getInt($min, $max);
    }

    private function chance(int $oneIn): bool
    {
        return $this->rng->getInt(1, $oneIn) === 1;
    }
}
