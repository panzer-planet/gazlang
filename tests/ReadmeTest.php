<?php

namespace GazLang\Tests;

/**
 * Every gaz code block in README.md prints exactly the output shown under it
 *
 * The README's examples went stale once without anything noticing: its flagship program
 * interpolated "{round($n, 2)}", which is literal text, and caught an error as a map after
 * errors became objects. A README is the first thing anyone runs, so its blocks are tests.
 *
 * A block is a ```gaz fence followed by a plain ``` fence holding what it prints. A gaz fence
 * with no output fence after it (the shell snippets, a fragment) is skipped, so a block only
 * has to be runnable if it claims an output.
 */
class ReadmeTest extends GazLangTestCase
{
    /**
     * The directory blocks are written to and run in, so error locations read as bare filenames
     */
    private const SCRATCH = self::ROOT.'/tests/.readme';

    /**
     * @dataProvider blocks
     */
    public function test_a_readme_block_prints_what_it_says(string $name, string $code, string $expected, array $args)
    {
        if (! is_dir(self::SCRATCH)) {
            mkdir(self::SCRATCH, 0777, true);
        }
        $file = self::SCRATCH."/{$name}.gaz";
        file_put_contents($file, $code);

        try {
            [$output] = $this->runProgram("tests/.readme/{$name}.gaz", $args);
            // A location is the path as it was given, and the README shows a program run from
            // its own directory, so the directory it was written to is not part of the claim
            $seen = fn (string $text) => rtrim(str_replace('tests/.readme/', '', $text), "\n");
            $this->assertSame($expected, $seen($output), "README block '{$name}'");
        } finally {
            unlink($file);
        }
    }

    /**
     * Each gaz block that is followed by an output block, as [name, code, expected output, args]
     */
    public static function blocks(): array
    {
        $readme = file_get_contents(self::ROOT.'/README.md');
        preg_match_all("/```gaz\n(.*?)```\n\n```\n(.*?)```/s", $readme, $matches, PREG_SET_ORDER);

        $blocks = [];
        foreach ($matches as $i => [, $code, $expected]) {
            // include paths in the README are written from the project root
            $code = str_replace('include "lib/', 'include "'.realpath(self::ROOT).'/lib/', $code);
            // The one block that reads a file names it in the prose above it
            $args = str_contains($code, 'sales.csv') ? [realpath(self::ROOT).'/examples/data/sales.csv'] : [];
            // The trace block prints its own filename, so it has to be written under that name
            $name = str_contains($expected, 'trace.gaz') ? 'trace' : "block_{$i}";
            $blocks["block {$i}"] = [$name, $code, rtrim($expected, "\n"), $args];
        }

        return $blocks;
    }
}
