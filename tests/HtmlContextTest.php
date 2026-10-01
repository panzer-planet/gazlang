<?php

namespace GazLang\Tests;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * web::html"..." (lib/web.gaz) writes each value for where it lands in the markup, which it finds
 * by reading the text as a browser would. This checks it against a browser's reading, PHP's
 * HTML5 parser (Dom\HTMLDocument), which shares no code with it: each template is written with a
 * list of hostile values, and the page the parser makes from each must have the same shape as
 * the one made from a harmless value (the same elements, attributes and comments, so a value
 * added none), have no URL that runs script, and, where a value is text, give it back exactly.
 * The templates the tag refuses must be refused for every value, and the others for none.
 */
class HtmlContextTest extends GazLangTestCase
{
    // The programs here are made by the test, so they run through succeed(), not executeCode(): the
    // C VM's harness would record each snippet that executeCode() runs, and hold their pages
    /**
     * What a value could try: closing what it is in, opening what it isn't, a script URL spelt
     * so a browser still runs it, and bytes that are special to a parser
     */
    private const HOSTILE = [
        '"><script>alert(1)</script>',
        "' onmouseover='alert(1)",
        '" onfocus="alert(1)" autofocus="',
        '" onclick=alert(1) x="',
        "'><img src=x onerror=alert(1)>",
        '</textarea><script>alert(1)</script>',
        '</title><script>alert(1)</script>',
        '</TEXTAREA ><b>',
        '--><script>alert(1)</script><!--',
        '--!><b>',
        '<!--',
        ']]>',
        '<svg onload=alert(1)>',
        '<![CDATA[x]]>',
        '&quot;&#x22;&amp;&lt;',
        '&#x6a;avascript:alert(1)',
        'javascript:alert(1)',
        'JaVaScRiPt:alert(1)',
        ' javascript:alert(1)',
        "\tjavascript:alert(1)",
        "\x01javascript:alert(1)",
        "java\tscript:alert(1)",
        "java\nscript:alert(1)",
        "java\r\nscript:alert(1)",
        'javascript&colon;alert(1)',
        'javascript%3Aalert(1)',
        'vbscript:msgbox(1)',
        'data:text/html,<script>alert(1)</script>',
        'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
        'file:///etc/passwd',
        'x:y',
        'https://example.com/a?b=c&d=e',
        'http://example.com',
        '//example.com/path',
        '/relative/path',
        '?q=1&r=2',
        '#fragment',
        'mailto:someone@example.com',
        'tel:+15555550100',
        '',
        ' ',
        "line one\nline two",
        "\u{e9}\u{2028}\u{1f600}",
        'a b&c=d/e?f#g',
        '0',
    ];

    /**
     * Templates a value is safe in, written with {$v}. Each is checked for every hostile value.
     * A value that is text is given back exactly (the second element: a way to read it back), or
     * null where it is a URL or markup.
     */
    private const SAFE = [
        'text' => ['<p>{$v}</p>', 'text'],
        'text among text' => ['<div>a {$v} b</div>', null],
        'text in a list' => ['<ul><li>{$v}</li><li>{$v}</li></ul>', null],
        'double quoted attribute' => ['<p title="{$v}">x</p>', 'title'],
        'single quoted attribute' => ["<p title='{\$v}'>x</p>", 'title'],
        'attribute with text around it' => ['<p class="a {$v} b">x</p>', null],
        'a data attribute' => ['<p data-x="{$v}">x</p>', 'data-x'],
        'input value' => ['<input value="{$v}">', 'value'],
        'image alt' => ['<img alt="{$v}" src="/x.png">', 'alt'],
        'a link' => ['<a href="{$v}">x</a>', null],
        'an image' => ['<img src="{$v}">', null],
        'a form action' => ['<form action="{$v}"><input></form>', null],
        'a link after a path' => ['<a href="/search?q={$v}">x</a>', null],
        'a link in the middle of a path' => ['<a href="/u/{$v}/x">x</a>', null],
        'a link to a fragment' => ['<a href="#{$v}">x</a>', null],
        'a link after a scheme' => ['<a href="mailto:{$v}">x</a>', null],
        'a link then a fragment' => ['<a href="{$v}#top">x</a>', null],
        'a link with two values' => ['<a href="{$v}/{$v}">x</a>', null],
        'a textarea' => ['<textarea>{$v}</textarea>', 'textarea'],
        'a title' => ['<title>{$v}</title><p>x</p>', 'title-element'],
        'a comment' => ['<!-- {$v} -->', null],
        'text after a comment' => ['<!-- c --><p>{$v}</p>', 'text'],
        'text after a comment ended by --!>' => ['<!-- c --!><p>{$v}</p>', 'text'],
        'a link after a comment ended by --!>' => ['<!-- c --!><a href="{$v}">x</a>', null],
        'text after a script' => ['<script>var a = 1;</script><p>{$v}</p>', 'text'],
        'text after a script with a comment in it' => ['<script><!-- var a = 1; //--></script><p>{$v}</p>', 'text'],
        'text after a script that compares with <' => ['<script>if (a < b) { x(); }</script><p>{$v}</p>', 'text'],
        'text after a style' => ['<style>p { color: red }</style><p>{$v}</p>', 'text'],
        'text after a self closing tag' => ['<br/><p>{$v}</p>', 'text'],
        'an svg title' => ['<svg><title>{$v}</title></svg>', null],
        'an svg link' => ['<svg><a href="{$v}"><text>x</text></a></svg>', null],
        'text after an svg' => ['<svg><circle/></svg><p>{$v}</p>', 'text'],
        'text after a doctype' => ['<!DOCTYPE html><p>{$v}</p>', 'text'],
        'text with a < in it' => ['<p>1 < 2 and {$v}</p>', null],
    ];

    /**
     * Templates a value is refused in, for any value
     */
    private const REFUSED = [
        'a script' => '<script>var x = {$v};</script>',
        'a style' => '<style>p { color: {$v} }</style>',
        'an unquoted attribute' => '<p title={$v}>x</p>',
        'an unquoted attribute after a quoted one' => '<p class="a" title={$v}>x</p>',
        'an event handler' => '<a onclick="{$v}">x</a>',
        'a style attribute' => '<p style="{$v}">x</p>',
        'an srcdoc' => '<iframe srcdoc="{$v}"></iframe>',
        'a srcset' => '<img srcset="{$v} 2x">',
        'a tag name' => '<{$v}>x</p>',
        'after a <' => 'a <{$v}>',
        'after a </' => 'a </{$v}>',
        'between attributes' => '<p {$v}>x</p>',
        'in an attribute name' => '<p {$v}="x">x</p>',
        'a script src' => '<script src="{$v}"></script>',
        'an iframe src' => '<iframe src="{$v}"></iframe>',
        'a link element' => '<link rel="stylesheet" href="{$v}">',
        'a base' => '<base href="{$v}">',
        'a URL finished by the value' => '<a href="java{$v}">x</a>',
        'two values together in a URL' => '<a href="{$v}{$v}">x</a>',
        'a script scheme in the text' => '<a href="javascript:{$v}">x</a>',
        'a doctype' => '<!DOCTYPE {$v}>',
        'text after a script that holds <!-- and <script' => '<script><!--<script></script><p>x</p></script><p>{$v}</p>',
        'text after a script that holds <!-- and <SCRIPT' => '<script><!-- <SCRIPT></script><p>{$v}</p>',
        'an svg script href' => '<svg><script href="{$v}"></script></svg>',
        'an svg script xlink:href' => '<svg><script xlink:href="{$v}"></script></svg>',
        'a script after a comment ended by --!>' => '<!-- c --!><script>var a = {$v}</script>',
        'a style after a comment ended by --!>' => '<!-- c --!><style>p { color: {$v} }</style>',
        'a value that is the scheme, with a colon after it' => '<a href="{$v}:{$v}">x</a>',
        'a value and a colon as a character reference' => '<a href="{$v}&colon;x">x</a>',
        'a value and a colon as a number' => '<a href="{$v}&#58;x">x</a>',
        'a value and a colon after text' => '<a href="{$v}:8080/x">x</a>',
        'an attribute name that starts with =' => '<p ="{$v}">x</p>',
        'an attribute name that starts with = after another attribute' => '<p class="a" ="{$v}">x</p>',
        'the end tag of a textarea' => '<textarea></text{$v}>',
        'a script among other markup' => '<p>a</p><script>{$v}</script>',
        'a style after an svg' => '<svg><circle/></svg><style>{$v}</style>',
    ];

    /**
     * What each template and value gives, from one program: [template, value index] => the page
     * text, or null if the tag refused it
     *
     * @var array<string, list<string|null>>|null
     */
    private static ?array $pages = null;

    /**
     * A GazLang string literal for any bytes, every one but a letter or digit as \xHH
     */
    private static function literal(string $bytes): string
    {
        return '"'.preg_replace_callback('/[^A-Za-z0-9]/', fn ($m) => sprintf('\x%02x', ord($m[0])), $bytes).'"';
    }

    /**
     * The pages every template gives for every hostile value, and for a harmless one, as [name =>
     * [one page, or null, for each value in HOSTILE and then the harmless x]]
     *
     * @return array<string, list<string|null>>
     */
    private function pages(): array
    {
        if (self::$pages !== null) {
            return self::$pages;
        }
        $values = [...self::HOSTILE, 'x'];
        $program = "include \"std/web.gaz\";\ninclude \"std/json.gaz\";\n"
            ."fn attempt(\$make) {\n    try {\n        return \$make().text;\n    } catch (\$e) {\n        return null;\n    }\n}\n"
            .'$values = ['.implode(', ', array_map(self::literal(...), $values))."];\n\$pages = {};\n";
        $templates = [...array_map(fn ($t) => $t[0], self::SAFE), ...self::REFUSED];
        foreach ($templates as $name => $template) {
            $tagged = 'web::html"'.str_replace('"', '\\"', $template).'"';
            $program .= '$pages['.self::literal((string) $name).'] = [];'."\n"
                .'foreach ($values as $v) { $pages['.self::literal((string) $name).'][] = attempt(() -> '.$tagged."); }\n";
        }
        $program .= "echo json::encode(\$pages);\n";

        return self::$pages = json_decode(trim(self::succeed([], $program)), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * What a page is made of, ignoring all its text: its elements with their attribute names,
     * and its comments, in order and depth
     *
     * @return list<string>
     */
    private static function shape(string $page): array
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><head></head><body>'.$page, LIBXML_NOERROR);
        $shape = [];
        $walk = function ($node, int $depth) use (&$walk, &$shape): void {
            foreach ($node->childNodes as $child) {
                if ($child instanceof Element) {
                    $names = [];
                    foreach ($child->attributes as $attribute) {
                        $names[] = $attribute->name;
                    }
                    sort($names);
                    $shape[] = $depth.' <'.$child->tagName.' '.implode(' ', $names).'>';
                    $walk($child, $depth + 1);
                } elseif ($child->nodeType === XML_COMMENT_NODE) {
                    $shape[] = $depth.' <!-->';
                }
            }
        };
        $walk($document->body, 0);

        return $shape;
    }

    /**
     * Every URL a page links to or loads, as its attribute holds it
     *
     * @return list<string>
     */
    private static function urls(string $page): array
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><head></head><body>'.$page, LIBXML_NOERROR);
        $urls = [];
        foreach ($document->querySelectorAll('*') as $element) {
            foreach ($element->attributes as $attribute) {
                if (in_array($attribute->name, ['href', 'src', 'action', 'formaction', 'cite', 'poster', 'data', 'xlink:href'], true)) {
                    $urls[] = $attribute->value;
                }
            }
        }

        return $urls;
    }

    /**
     * Whether a browser would take a URL for one with a scheme that runs or shows script: it
     * drops tabs and line breaks, skips what leads, and reads letters, digits and + - . up to a :
     */
    private static function runs_script(string $url): bool
    {
        $clean = ltrim(str_replace(["\t", "\n", "\r"], '', $url), implode('', array_map('chr', range(0, 32))));
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $clean, $match) !== 1) {
            return false;
        }

        return ! in_array(strtolower($match[1]), ['http', 'https', 'mailto', 'tel', 'about'], true);
    }

    /**
     * That no URL on a page runs script because of a value: the same URL on the page made with a
     * harmless value may (a program can write a javascript: link itself), and the others may not
     */
    private function assertNoScriptUrlFromAValue(string $harmless, string $page, string $label): void
    {
        $before = self::urls($harmless);
        $after = self::urls($page);
        $this->assertCount(count($before), $after, "{$label}: the page links to a different number of URLs: {$page}");
        foreach ($after as $i => $url) {
            if (self::runs_script($url)) {
                $this->assertTrue(self::runs_script($before[$i]), "{$label}: a URL a browser would run: {$url} in {$page}");
            }
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function safeTemplates(): array
    {
        return array_map(fn ($name) => [$name], array_combine(array_keys(self::SAFE), array_keys(self::SAFE)));
    }

    #[DataProvider('safeTemplates')]
    public function test_a_value_is_written_for_where_it_lands(string $name)
    {
        $pages = $this->pages()[$name];
        $harmless = $pages[count(self::HOSTILE)];
        $this->assertNotNull($harmless, "{$name}: a harmless value was refused");
        $expected = self::shape($harmless);
        [, $reading] = self::SAFE[$name];

        foreach (self::HOSTILE as $i => $value) {
            $page = $pages[$i];
            $label = "{$name} with ".json_encode($value);
            $this->assertNotNull($page, "{$label}: refused where a value is safe");
            $this->assertSame($expected, self::shape($page), "{$label}: the page's shape changed: {$page}");
            $this->assertNoScriptUrlFromAValue($harmless, $page, $label);
            if ($reading !== null && ! str_contains($value, "\r") && ! str_contains($value, "\x00")) {
                $this->assertSame($value, $this->read_back($page, $reading), "{$label}: not given back as it was: {$page}");
            }
        }
    }

    /**
     * What the page holds where the value is text, read the way a browser would
     */
    private function read_back(string $page, string $reading): string
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><head></head><body>'.$page, LIBXML_NOERROR);
        if ($reading === 'text') {
            return $document->querySelector('p')->textContent;
        }
        if ($reading === 'textarea') {
            return $document->querySelector('textarea')->textContent;
        }
        if ($reading === 'title-element') {
            return $document->querySelector('title')->textContent;
        }

        return $document->querySelector("[{$reading}]")->getAttribute($reading);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedTemplates(): array
    {
        return array_map(fn ($name) => [$name], array_combine(array_keys(self::REFUSED), array_keys(self::REFUSED)));
    }

    #[DataProvider('refusedTemplates')]
    public function test_a_value_is_refused_where_escaping_would_not_make_it_safe(string $name)
    {
        foreach ($this->pages()[$name] as $i => $page) {
            $value = [...self::HOSTILE, 'x'][$i];
            $this->assertNull($page, "{$name} with ".json_encode($value).': written, not refused: '.$page);
        }
    }

    /**
     * Pieces of markup, and of what is not quite markup, for building templates at random
     */
    private const PIECES = [
        'a', ' ', '1 < 2', 'x & y', '<p>', '</p>', '<div class="c">', '</div>', '<a href="', '<a href=\'', '">', "'>",
        '<img src=', '<img alt="', '"', "'", '=', ' title="t" ', ' class=x ', '>', '<', '</', '<!', '<!--', '-->', '--',
        '/>', '<br/>', '<!DOCTYPE html>', '<script>', '</script>', '<style>', '</style>', '<textarea>', '</textarea>',
        '<title>', '</title>', '<svg>', '</svg>', '<math>', '</math>', '<b ', '<b', 'onclick=', 'href=', 'src="/x"',
        '<iframe>', '</iframe>', '<xmp>', '</xmp>', '<noscript>', '</noscript>', '<plaintext>', '<select>', '<table>',
        '<tr>', '<td>', '</td>', '<form action="', '<input value="', '<p title=', 'javascript:', '/path?q=', '#',
        ' onclick="', ' style="', '<script src="', '<img src="', '<a href="javascript:', '<p srcdoc="', '<!-- ', ' -->', '<a href="/p/',
        '&amp;', '&lt;', "\n", "\t", '<![CDATA[', ']]>', '--!>', '<svg><script href="', '<use href="', ':', '&colon;', '&#58;', '="', ' =', '<?', '</p >', '</SCRIPT >', '</TeXtArEa',
    ];

    /**
     * The values to try in templates made at random: the ones that have broken each kind of context
     */
    private const TRIED = [
        '"><script>alert(1)</script>',
        "' onmouseover='alert(1)",
        '</textarea><script>alert(1)</script>',
        '</title><b>',
        '--><script>alert(1)</script>',
        '</script><script>alert(1)</script>',
        'javascript:alert(1)',
        "java\tscript:alert(1)",
        ' onclick=alert(1) ',
        '<svg onload=alert(1)>',
    ];

    public function test_templates_made_at_random_are_refused_or_written_safely()
    {
        mt_srand(20261001);
        $templates = [];
        for ($n = 0; $n < 1500; $n++) {
            $parts = [];
            for ($i = mt_rand(2, 9); $i > 0; $i--) {
                $parts[] = self::PIECES[mt_rand(0, count(self::PIECES) - 1)];
            }
            // One value or two, each between two pieces wherever that falls
            for ($i = mt_rand(0, 2) === 0 ? 2 : 1; $i > 0; $i--) {
                array_splice($parts, mt_rand(0, count($parts)), 0, ['{$v}']);
            }
            $templates[] = implode('', $parts);
        }

        $values = [...self::TRIED, 'x'];
        $program = "include \"std/web.gaz\";\ninclude \"std/json.gaz\";\n"
            ."fn attempt(\$make) {\n    try {\n        return \$make().text;\n    } catch (\$e) {\n        return null;\n    }\n}\n"
            .'$values = ['.implode(', ', array_map(self::literal(...), $values))."];\n\$pages = [];\n";
        foreach ($templates as $template) {
            $tagged = 'web::html"'.str_replace('"', '\\"', $template).'"';
            $program .= 'foreach ($values as $v) { $pages[] = attempt(() -> '.$tagged."); }\n";
        }
        $program .= "echo json::encode(\$pages);\n";
        $pages = json_decode(trim(self::succeed([], $program)), true, flags: JSON_THROW_ON_ERROR);

        $written = 0;
        foreach ($templates as $t => $template) {
            $row = array_slice($pages, $t * count($values), count($values));
            $harmless = $row[count($values) - 1];
            if ($harmless === null) {
                // Refused with a harmless value, so refused with them all
                $this->assertSame(array_fill(0, count($values), null), $row, "{$template}: refused for x and not for another value");

                continue;
            }
            $written++;
            $expected = self::shape($harmless);
            foreach (self::TRIED as $i => $value) {
                $label = json_encode($template).' with '.json_encode($value);
                $this->assertNotNull($row[$i], "{$label}: refused for a hostile value and not for x");
                $this->assertSame($expected, self::shape($row[$i]), "{$label}: the page's shape changed: {$row[$i]}");
                $this->assertNoScriptUrlFromAValue($harmless, $row[$i], $label);
            }
        }
        // Most of what is made is refused, and it is only worth anything if some is written
        $this->assertGreaterThan(30, $written, 'too few templates were written for this to check much');
    }

    /**
     * Pieces of a scheme, and of what a browser reads one out of, to put two at a time in a URL
     */
    private const SCHEME_PIECES = [
        'java', 'script', 'javascript', 'javascript:', 'JAVASCRIPT', "java\t", "scr\nipt:", 'script:', 'alert(1)',
        'alert%281%29', ':', 'x', '/', '', '&colon;', '%6a', ' ', "\t", 'data', 'text/html,<b>', 'http', 'https://e.com',
    ];

    /**
     * Templates with two values, to be filled with a piece each
     */
    private const TWO_VALUES = [
        '<a href="{$v}:{$w}">x</a>',
        '<a href="{$v}{$w}">x</a>',
        '<a href="{$v}/{$w}">x</a>',
        '<a href="{$v}?{$w}">x</a>',
        '<a href="{$v}#{$w}">x</a>',
        '<a href="x{$v}{$w}">x</a>',
        '<a href="/{$v}{$w}">x</a>',
        '<a href="{$v}x:{$w}">x</a>',
        '<a href="{$v}&colon;{$w}">x</a>',
        '<a href="{$v}&#x3a;{$w}">x</a>',
        '<a href="{$v} {$w}">x</a>',
        '<a href="{$v}&#9;{$w}">x</a>',
        '<img src="{$v}:{$w}">',
        '<a href=\'{$v}:{$w}\'>x</a>',
        '<a title="{$v}" href="{$w}:{$v}">x</a>',
    ];

    public function test_two_values_cannot_make_a_script_url_between_them()
    {
        $pieces = self::SCHEME_PIECES;
        $program = "include \"std/web.gaz\";\ninclude \"std/json.gaz\";\n"
            ."fn attempt(\$make) {\n    try {\n        return \$make().text;\n    } catch (\$e) {\n        return null;\n    }\n}\n"
            .'$pieces = ['.implode(', ', array_map(self::literal(...), $pieces))."];\n\$pages = [];\n";
        foreach (self::TWO_VALUES as $template) {
            $tagged = 'web::html"'.str_replace('"', '\\"', $template).'"';
            $program .= 'foreach ($pieces as $v) { foreach ($pieces as $w) { $pages[] = attempt(() -> '.$tagged."); } }\n";
        }
        $program .= "echo json::encode(\$pages);\n";
        $pages = json_decode(trim(self::succeed([], $program)), true, flags: JSON_THROW_ON_ERROR);

        $written = 0;
        foreach (self::TWO_VALUES as $t => $template) {
            foreach ($pieces as $i => $v) {
                foreach ($pieces as $j => $w) {
                    $page = $pages[($t * count($pieces) + $i) * count($pieces) + $j];
                    if ($page === null) {
                        continue;
                    }
                    $written++;
                    foreach (self::urls($page) as $url) {
                        $this->assertFalse(self::runs_script($url), json_encode($template).' with '.json_encode([$v, $w]).": a URL a browser would run: {$url} in {$page}");
                    }
                }
            }
        }
        $this->assertGreaterThan(500, $written, 'too few pairs were written for this to check much');
    }

    public function test_a_script_url_is_blocked_not_written()
    {
        $page = $this->pages()['a link'][array_search('javascript:alert(1)', self::HOSTILE, true)];
        $this->assertSame('<a href="about:invalid#blocked">x</a>', $page);
    }

    public function test_a_value_in_a_path_is_percent_encoded()
    {
        $page = $this->pages()['a link after a path'][array_search('a b&c=d/e?f#g', self::HOSTILE, true)];
        $this->assertSame('<a href="/search?q=a%20b%26c%3Dd%2Fe%3Ff%23g">x</a>', $page);
    }
}
