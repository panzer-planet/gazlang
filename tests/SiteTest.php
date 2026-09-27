<?php

namespace GazLang\Tests;

/**
 * The website builds from the repository as it is, every page checked, and a problem fails it
 *
 * site/build.gaz is a tool, not a test program: what it writes changes with every edit to the
 * docs, so it is not recorded. What stays true is checked instead: the build succeeds and has a
 * page for each section of the reference and each file of lib/; a broken link planted in a copy
 * of the docs fails it with the page and line, and leaves the last good site alone; hostile text
 * in every place a page shows text comes out escaped; and the repository is found from git's
 * origin, or left out without one. Its parts are tested by tests/gaz/site/.
 */
class SiteTest extends GazLangTestCase
{
    /**
     * Where the tests build, so they don't touch site/dist
     */
    private const TMP = 'tests/.tmp/site';

    public function test_the_site_builds_with_every_page_checked()
    {
        $output = self::TMP.'/dist';
        $this->removeTree($output);
        // What an old build left, which a new one takes away
        mkdir(self::ROOT."/{$output}/reference", 0777, true);
        file_put_contents(self::ROOT."/{$output}/reference/gone.html", 'old');

        [$printed, $code] = $this->build([$output, '--repository', 'owner/name', '--release', 'v9.8.7']);

        $this->assertSame(0, $code, $printed);
        $this->assertMatchesRegularExpression('/^Wrote \d+ files to '.preg_quote($output, '/').'$/', trim($printed));
        $this->assertFileDoesNotExist(self::ROOT."/{$output}/reference/gone.html");

        $site = self::ROOT.'/'.$output;
        foreach (['index.html', 'style.css', 'reference/index.html', 'library/index.html', 'bytecode.html', 'internals.html'] as $page) {
            $this->assertFileExists("{$site}/{$page}");
        }
        // A page for every file of the standard library, however many there are
        foreach (glob(self::ROOT.'/lib/*.gaz') ?: [] as $file) {
            $this->assertFileExists("{$site}/library/".basename($file, '.gaz').'.html');
        }
        // And for every top section of the reference
        preg_match_all('/^## (.+)$/m', (string) file_get_contents(self::ROOT.'/docs/language.md'), $sections);
        $this->assertGreaterThan(10, count($sections[1]));
        $this->assertCount(count($sections[1]) + 1, glob("{$site}/reference/*.html") ?: []);

        // The download links name the release asked for, and the repository given
        $home = (string) file_get_contents("{$site}/index.html");
        $this->assertStringContainsString('https://github.com/owner/name/releases/download/v9.8.7/gaz-9.8.7-linux-x86_64.tar.gz', $home);
        $this->assertStringContainsString('https://github.com/owner/name/blob/HEAD/LICENSE.md', $home);

        // The pager goes on to the next section, and a sidebar holds third-level headings under the second
        $this->assertStringContainsString('<a class="next" href="../reference/values.html"><span>Next</span> Values</a>', (string) file_get_contents("{$site}/reference/index.html"));
        $this->assertMatchesRegularExpression('~<li><a href="#instructions">Instructions</a>\s*<ul>\s*<li><a href="#stack-and-variables">~', (string) file_get_contents("{$site}/bytecode.html"));
    }

    public function test_a_broken_link_fails_the_build_and_leaves_the_last_site_alone()
    {
        $root = $this->copyOfTheDocuments('broken');
        file_put_contents(self::ROOT."/{$root}/docs/internals.md", "\nSee [the loader](bytecode.md#no-such-anchor).\n", FILE_APPEND);
        $output = self::TMP.'/kept-dist';
        $this->removeTree($output);
        $this->removeTree("{$output}.failed");
        mkdir(self::ROOT."/{$output}", 0777, true);
        file_put_contents(self::ROOT."/{$output}/index.html", 'the last good site');

        [$printed, $code] = $this->build([$output, '--root', $root, '--repository', 'owner/name']);

        $this->assertSame(1, $code, $printed);
        $this->assertMatchesRegularExpression(
            '~^'.preg_quote($output, '~').'\.failed/internals\.html:\d+: broken link to bytecode\.html#no-such-anchor: no anchor #no-such-anchor in bytecode\.html$~m',
            $printed
        );
        $this->assertStringContainsString("1 problem: the site was not written to {$output}", $printed);
        $this->assertSame('the last good site', file_get_contents(self::ROOT."/{$output}/index.html"));
        $this->assertFileExists(self::ROOT."/{$output}.failed/internals.html");

        // A good build then replaces the site, and takes the failed one away
        [$printed, $code] = $this->build([$output, '--repository', 'owner/name']);
        $this->assertSame(0, $code, $printed);
        $this->assertNotSame('the last good site', file_get_contents(self::ROOT."/{$output}/index.html"));
        $this->assertDirectoryDoesNotExist(self::ROOT."/{$output}.failed");
    }

    public function test_hostile_text_is_escaped_everywhere_a_page_shows_it()
    {
        $root = $this->fixture('hostile', [
            'README.md' => "# Home <img src=x onerror=alert(1)>\n\n![a\"<b>](https://example.com/\"onerror=\"x.png)\n\n"
                ."## Part <script>alert(2)</script>\n\n### Sub <b>bold</b>\n\n[x](javascript:alert(5))\n",
            'docs/language.md' => "# The language\n\nIntro.\n\n## Types <script>alert(3)</script>\n\nText.\n\n## Next\n\nMore.\n",
            'docs/bytecode.md' => "# Bytecode\n\n## A\n",
            'docs/internals.md' => "# Internals\n\n## B\n",
            'lib/hostile.gaz' => "// Hostile <script>alert(4)</script> summary, `code` and *really*.\nnamespace hostile;\n\n"
                ."// Does <b>things</b> & \"quotes\"\npub fn f() {}\n",
        ]);
        $output = self::TMP.'/hostile-dist';

        [$printed, $code] = $this->build([$output, '--root', $root, '--repository', 'o"x/n<y', '--release', 'v1"<2']);

        // It builds, so every page passed the checks too
        $this->assertSame(0, $code, $printed);
        $pages = $this->pagesIn(self::ROOT."/{$output}");
        $this->assertGreaterThan(5, count($pages));
        foreach ($pages as $path => $html) {
            foreach (['<script', '<img src=x', '<b>', '"onerror="', 'o"x', 'n<y', '1"<2', 'javascript:'] as $hostile) {
                $this->assertStringNotContainsString($hostile, $html, "{$path} shows {$hostile} unescaped");
            }
        }
        // Escaped in the title, the sidebar, the pager, a download link and an image's src
        $types = $pages['reference/types-scriptalert3script.html'] ?? '';
        $this->assertStringContainsString('<title>Types &lt;script&gt;alert(3)&lt;/script&gt; · GazLang reference</title>', $types);
        $this->assertStringContainsString('>Part &lt;script&gt;alert(2)&lt;/script&gt;</a>', $pages['index.html']);
        $this->assertStringContainsString('<span>Next</span> Next</a>', $types);
        $this->assertStringContainsString('https://github.com/o&quot;x/n&lt;y/releases/download/v1&quot;&lt;2/', $pages['index.html']);
        $this->assertStringContainsString('src="https://example.com/&quot;onerror=&quot;x.png"', $pages['index.html']);
        // A library comment is Markdown, in the index's summary as on its page
        $this->assertStringContainsString('Hostile &lt;script&gt;alert(4)&lt;/script&gt; summary, <code>code</code> and <em>really</em>.', $pages['library/index.html']);
        $this->assertStringContainsString('Does &lt;b&gt;things&lt;/b&gt; &amp; &quot;quotes&quot;', $pages['library/hostile.html']);
    }

    public function test_an_id_given_twice_on_a_library_page_fails_the_build()
    {
        $root = $this->copyOfTheDocuments('twice');
        file_put_contents(self::ROOT."/{$root}/lib/twice.gaz", "// Twice.\nnamespace twice;\n\n// # f\npub fn f() {}\n");

        [$printed, $code] = $this->build([self::TMP.'/twice-dist', '--root', $root, '--repository', 'owner/name']);

        $this->assertSame(1, $code, $printed);
        $this->assertMatchesRegularExpression('~library/twice\.html:\d+: the id "f" is given twice~', $printed);
    }

    public function test_the_repository_is_found_from_git_origin()
    {
        $repository = self::ROOT.'/'.self::TMP.'/origin-repo';
        $this->removeTree(self::TMP.'/origin-repo');
        mkdir($repository, 0777, true);
        exec('git -C '.escapeshellarg($repository).' init -q 2>&1', $out, $status);
        $this->assertSame(0, $status, implode("\n", $out));
        exec('git -C '.escapeshellarg($repository).' remote add origin git@github.com:some/where.git 2>&1', $out, $status);
        $this->assertSame(0, $status, implode("\n", $out));
        $output = self::TMP.'/origin-dist';

        [$printed, $code] = $this->build([$output], ['GIT_DIR' => "{$repository}/.git", 'GITHUB_REPOSITORY' => '']);

        $this->assertSame(0, $code, $printed);
        $this->assertStringContainsString('https://github.com/some/where/blob/HEAD/LICENSE.md', (string) file_get_contents(self::ROOT."/{$output}/index.html"));
    }

    public function test_without_a_github_repository_the_links_that_need_one_are_left_out()
    {
        $output = self::TMP.'/local-dist';
        // No git repository to ask, and no GitHub Actions to say
        [$printed, $code] = $this->build([$output], ['GIT_DIR' => '/nonexistent', 'GITHUB_REPOSITORY' => '']);

        $this->assertSame(0, $code, $printed);
        $this->assertStringContainsString('no GitHub repository', $printed);
        $home = (string) file_get_contents(self::ROOT."/{$output}/index.html");
        // The README's own absolute links stay; the ones the site makes to its files go
        $this->assertStringNotContainsString('/blob/HEAD/', $home);
        $this->assertStringNotContainsString('>GitHub</a>', $home);
        $this->assertStringNotContainsString('class="download"', $home);
    }

    /**
     * Run the build, standard error after standard output
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     * @return array{0: string, 1: int}
     */
    private function build(array $args, array $env = []): array
    {
        if (! is_dir(self::ROOT.'/'.self::TMP)) {
            mkdir(self::ROOT.'/'.self::TMP, 0777, true);
        }
        [$out, $err, $code] = self::gazlang(['-f', 'site/build.gaz', '--', ...$args], '', $env);

        return [$out.$err, $code];
    }

    /**
     * The documents the site is made of, copied to a directory of their own, relative to the root
     */
    private function copyOfTheDocuments(string $name): string
    {
        $files = [];
        foreach (['README.md', ...glob(self::ROOT.'/docs/*.md') ?: [], ...glob(self::ROOT.'/lib/*.gaz') ?: []] as $file) {
            $relative = str_replace(self::ROOT.'/', '', $file);
            $files[$relative] = (string) file_get_contents(self::ROOT."/{$relative}");
        }

        return $this->fixture($name, $files);
    }

    /**
     * A directory of documents to build from, relative to the root
     *
     * @param  array<string, string>  $files  Each file's path in it and its text
     */
    private function fixture(string $name, array $files): string
    {
        $root = self::TMP."/{$name}-root";
        $this->removeTree($root);
        foreach ($files as $path => $text) {
            $file = self::ROOT."/{$root}/{$path}";
            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $text);
        }

        return $root;
    }

    /**
     * Every HTML page of a built site, by its path in it
     *
     * @return array<string, string>
     */
    private function pagesIn(string $site): array
    {
        $pages = [];
        foreach (['', 'reference/', 'library/'] as $directory) {
            foreach (glob("{$site}/{$directory}*.html") ?: [] as $file) {
                $pages[$directory.basename($file)] = (string) file_get_contents($file);
            }
        }

        return $pages;
    }

    /**
     * A directory under the root and everything in it, if it is there
     */
    private function removeTree(string $relative): void
    {
        $path = self::ROOT."/{$relative}";
        if (! is_dir($path)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir((string) $file) : unlink((string) $file);
        }
        rmdir($path);
    }
}
