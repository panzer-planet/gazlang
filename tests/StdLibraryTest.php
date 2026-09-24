<?php

namespace GazLang\Tests;

/**
 * The standard library built into the VM: `include "std/name.gaz"` reads a copy of lib/ that
 * bin/gazlang carries, so a program anywhere can use it with no path to this repository.
 */
class StdLibraryTest extends GazLangTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/gazlang_std_'.getmypid().'_'.bin2hex(random_bytes(3));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /**
     * Run gazlang on a file, from a directory that has nothing to do with the repository
     *
     * @param  array<string, string>  $env
     * @return array{0: string, 1: string, 2: int}
     */
    private function runElsewhere(string $source, array $env = [], bool $bytecode = false): array
    {
        self::binary();
        file_put_contents("{$this->dir}/app.gaz", $source);
        $process = proc_open([self::ROOT.'/bin/gazlang', '-f', 'app.gaz'], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $this->dir, $env === [] ? null : $env + getenv());
        $this->assertNotFalse($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [$out, $err, proc_close($process)];
    }

    public function test_a_program_elsewhere_uses_the_library_by_name()
    {
        [$out, $err, $code] = $this->runElsewhere("include \"std/json.gaz\";\ninclude \"std/format.gaz\" use pad_left;\necho json::encode({\"a\" => [1, 2]});\necho pad_left(42, 6);\n");

        $this->assertSame(['{"a":[1,2]}'."\n".'    42'."\n", '', 0], [$out, $err, $code]);
    }

    public function test_every_file_of_lib_can_be_included_and_is_a_copy_of_it()
    {
        $names = array_map('basename', glob(self::ROOT.'/lib/*.gaz') ?: []);
        $this->assertNotEmpty($names);
        // What the VM carries is what lib/ has: a stale build would show here
        $program = 'foreach ('.json_encode($names).' as $name) { echo $name .. " " .. (std_source($name) == read_file("'.self::ROOT.'/lib/" .. $name) ? "same" : "DIFFERENT"); }';
        [$out, , $code] = $this->runElsewhere($program);
        $this->assertSame(0, $code);
        $this->assertSame(implode('', array_map(fn ($n) => "{$n} same\n", $names)), $out);

        foreach ($names as $name) {
            [, $err, $code] = $this->runElsewhere("include \"std/{$name}\";\n");
            $this->assertSame([0, ''], [$code, $err], "std/{$name}");
        }
    }

    public function test_std_source_gives_a_file_by_its_name_only()
    {
        [$out] = $this->runElsewhere('echo type_of(std_source("json.gaz")); echo type_of(std_source("nothing.gaz")); echo type_of(std_source("../lib/json.gaz")); echo type_of(std_source("")); echo type_of(std_source(".json.gaz")); echo type_of(std_source("a/b"));');
        $this->assertSame("string\nnull\nnull\nnull\nnull\nnull\n", $out);
    }

    public function test_a_missing_file_is_named_and_located()
    {
        [, $err, $code] = $this->runElsewhere("\ninclude \"std/nothing.gaz\";\n");
        $this->assertSame([1, "Error: Cannot include file: std/nothing.gaz at app.gaz:2\n"], [$code, $err]);
    }

    public function test_the_library_includes_its_own_neighbours_and_each_file_once()
    {
        // csv.gaz and http.gaz include others of lib/ by their plain names; naming one twice is one copy
        [$out, $err, $code] = $this->runElsewhere("include \"std/chars.gaz\";\ninclude \"std/csv.gaz\";\ninclude \"std/chars.gaz\" use is_digit;\necho is_digit(\"7\") ? \"digit\" : \"no\";\necho chars::is_digit(\"x\") ? \"digit\" : \"no\";\n");
        $this->assertSame(['digit'."\n".'no'."\n", '', 0], [$out, $err, $code]);
    }

    public function test_an_error_in_the_library_is_located_in_the_library()
    {
        [, $err, $code] = $this->runElsewhere("include \"std/json.gaz\";\njson::decode(\"[1,\");\n");
        $this->assertSame(1, $code);
        $this->assertStringContainsString("Error: Invalid JSON: unexpected end of input at position 3\n", $err);
        $this->assertStringContainsString('at <std>/json.gaz:', $err);
        $this->assertStringContainsString("at app.gaz:2\n", $err);
    }

    public function test_bytecode_names_library_files_as_they_are_not_paths_and_runs_anywhere()
    {
        self::binary();
        file_put_contents("{$this->dir}/app.gaz", "include \"std/format.gaz\";\necho format::pad_left(7, 3);\n");
        [$bytecode, , $code] = self::gazlang(['-c', '-f', "{$this->dir}/app.gaz"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('@ "<std>/format.gaz" ', $bytecode);
        $this->assertStringNotContainsString('lib/format.gaz', $bytecode);
        // Run from a different directory, where no path in it would lead to the library
        file_put_contents("{$this->dir}/app.gzb", $bytecode);
        $other = sys_get_temp_dir();
        $process = proc_open([self::ROOT.'/bin/gazlang', '-f', "{$this->dir}/app.gzb"], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $other);
        $this->assertNotFalse($process);
        $this->assertSame('  7'."\n", stream_get_contents($pipes[1]));
        proc_close($process);
    }

    public function test_gazlib_reads_a_directory_instead_of_the_built_in_copy()
    {
        mkdir("{$this->dir}/mylib");
        file_put_contents("{$this->dir}/mylib/greet.gaz", "namespace greet;\npub fn hello() { return \"hello from a directory\"; }\n");
        [$out, $err, $code] = $this->runElsewhere("include \"std/greet.gaz\";\necho greet::hello();\n", ['GAZLIB' => "{$this->dir}/mylib"]);
        $this->assertSame(["hello from a directory\n", '', 0], [$out, $err, $code]);

        // Without it there is no such file
        [, $err, $code] = $this->runElsewhere("include \"std/greet.gaz\";\n");
        $this->assertSame([1, "Error: Cannot include file: std/greet.gaz at app.gaz:1\n"], [$code, $err]);

        // and a file it lacks is not found in the built-in copy either: the directory replaces it
        [, $err, $code] = $this->runElsewhere("include \"std/json.gaz\";\n", ['GAZLIB' => "{$this->dir}/mylib"]);
        $this->assertSame(1, $code);
    }

    public function test_gazlib_cannot_be_escaped_by_a_name()
    {
        mkdir("{$this->dir}/mylib");
        file_put_contents("{$this->dir}/secret.gaz", "fn secret() { return 1; }\n");
        file_put_contents("{$this->dir}/mylib/.hidden.gaz", "x\n");
        file_put_contents("{$this->dir}/mylib/fine.gaz", "x\n");
        [$out] = $this->runElsewhere('echo type_of(std_source("../secret.gaz")); echo type_of(std_source("mylib/fine.gaz")); echo type_of(std_source(".hidden.gaz")); echo type_of(std_source("fine.gaz"));', ['GAZLIB' => "{$this->dir}/mylib"]);
        $this->assertSame("null\nnull\nnull\nstring\n", $out);
    }

    public function test_a_file_in_a_directory_named_std_is_not_the_library()
    {
        // only a path that starts with std/ is: ./std/ is the directory
        mkdir("{$this->dir}/std");
        file_put_contents("{$this->dir}/std/mine.gaz", "fn mine() { return \"mine\"; }\n");
        [$out, $err, $code] = $this->runElsewhere("include \"./std/mine.gaz\";\necho mine();\n");
        $this->assertSame(["mine\n", '', 0], [$out, $err, $code]);
    }
}
