<?php

namespace GazLang\Tests;

/**
 * The Language Server Protocol server (lsp/server.gaz): a session is a sequence of framed
 * JSON-RPC messages on standard input, and its replies and notifications the same on standard
 * output. Checked by decoding the messages, not by their exact bytes, since map key order
 * inside a value isn't the point of these tests.
 */
class LspTest extends GazLangTestCase
{
    private static function frame(array $message): string
    {
        $body = json_encode($message);

        return 'Content-Length: '.strlen($body)."\r\n\r\n".$body;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function messages(string $stream): array
    {
        $messages = [];
        while ($stream !== '') {
            if (! preg_match('/^Content-Length: (\d+)\r\n\r\n/', $stream, $header)) {
                break;
            }
            $length = (int) $header[1];
            $stream = substr($stream, strlen($header[0]));
            $messages[] = json_decode(substr($stream, 0, $length), true);
            $stream = substr($stream, $length);
        }

        return $messages;
    }

    /**
     * @param  list<array<string, mixed>>  $sent  The messages to send, initialize and exit added
     * @return list<array<string, mixed>> The messages the server sent back, in order
     */
    private function session(array $sent): array
    {
        $input = implode('', array_map(self::frame(...), $sent)).self::frame(['jsonrpc' => '2.0', 'method' => 'exit']);
        [$out, $err, $code] = self::gazlang(['lsp/server.gaz'], $input);
        $this->assertSame('', $err);
        $this->assertSame(0, $code);

        return self::messages($out);
    }

    public function test_initialize_announces_full_document_sync()
    {
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => new \stdClass]],
        ]);

        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['capabilities' => [
                'textDocumentSync' => 1, 'hoverProvider' => true, 'definitionProvider' => true, 'completionProvider' => [],
                'documentLinkProvider' => [],
            ]]],
            $messages[0]
        );
    }

    public function test_opening_a_document_with_a_syntax_error_publishes_a_diagnostic()
    {
        $source = "fn add(\$a, \$b) { return \$a + \$b\n}\n"; // the ; after the return is missing
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file:///a.gaz', 'text' => $source],
            ]],
        ]);

        $diagnostics = $messages[0]['params']['diagnostics'];
        $this->assertSame('file:///a.gaz', $messages[0]['params']['uri']);
        $this->assertCount(1, $diagnostics);
        $this->assertSame(1, $diagnostics[0]['range']['start']['line']); // 0-based: the second line
        $this->assertSame(1, $diagnostics[0]['severity']);
        $this->assertStringContainsString("Expected ';'", $diagnostics[0]['message']);
    }

    public function test_a_document_with_no_error_publishes_no_diagnostics()
    {
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file:///a.gaz', 'text' => "echo 1 + 1;\n"],
            ]],
        ]);

        $this->assertSame([], $messages[0]['params']['diagnostics']);
    }

    public function test_a_relative_import_resolves_against_the_documents_own_directory()
    {
        // Not against the server's own working directory: a document with an
        // `import "./helper.gaz";` opened from another checkout entirely (a game, say) must
        // still find its neighbour, as running it with bin/gaz would.
        $path = realpath(self::ROOT.'/tests/fixtures/lsp/importer.gaz');
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file://'.$path, 'text' => file_get_contents($path)],
            ]],
        ]);

        $this->assertSame([], $messages[0]['params']['diagnostics']);
    }

    public function test_an_error_in_an_imported_file_is_shown_at_the_import_that_leads_to_it()
    {
        // broken.gaz's error is on its line 4, which in the document is a blank line
        $path = realpath(self::ROOT.'/tests/fixtures/lsp/broken_importer.gaz');
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file://'.$path, 'text' => file_get_contents($path)],
            ]],
        ]);

        $diagnostics = $messages[0]['params']['diagnostics'];
        $this->assertCount(1, $diagnostics);
        $this->assertSame(2, $diagnostics[0]['range']['start']['line']); // 0-based: the import
        $this->assertStringStartsWith('in ', $diagnostics[0]['message']);
        $this->assertStringContainsString("broken.gaz:4: Expected ';'", $diagnostics[0]['message']);
    }

    /** An import's path is a link to the file it names; std/ has no file to open */
    public function test_an_import_path_is_a_link_to_its_file()
    {
        $path = realpath(self::ROOT.'/tests/fixtures/lsp/importer.gaz');
        $helper = realpath(self::ROOT.'/tests/fixtures/lsp/helper.gaz');
        $text = "import \"std/text.gaz\";\n  import \"./helper.gaz\";\nimport \"./missing.gaz\";\n";
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file://'.$path, 'text' => $text],
            ]],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'textDocument/documentLink', 'params' => [
                'textDocument' => ['uri' => 'file://'.$path],
            ]],
        ]);

        $this->assertSame([[
            'range' => ['start' => ['line' => 1, 'character' => 10], 'end' => ['line' => 1, 'character' => 22]],
            'target' => 'file://'.$helper,
        ]], $messages[1]['result']);
    }

    /** Inside an import's string, completion offers the files it could name, and the keywords nowhere near it */
    public function test_completion_inside_an_import_string_offers_files()
    {
        $path = realpath(self::ROOT.'/tests/fixtures/lsp/importer.gaz');
        $ask = fn (string $line, int $character) => $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file://'.$path, 'text' => $line],
            ]],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'textDocument/completion', 'params' => [
                'textDocument' => ['uri' => 'file://'.$path], 'position' => ['line' => 0, 'character' => $character],
            ]],
        ])[1]['result'];
        $labels = fn (array $items) => array_column($items, 'label');

        $beside = $labels($ask('import "./', 10));
        $this->assertContains('helper.gaz', $beside);
        $this->assertNotContains('echo', $beside);

        $begun = $labels($ask('import "', 8));
        $this->assertContains('./', $begun);
        $this->assertContains('std/', $begun);

        // std/ is built in: there is no directory of it to list
        $this->assertSame([], $labels($ask('import "std/', 12)));

        $this->assertContains('echo', $labels($ask('import "./helper.gaz"; ', 24)));
    }

    /** A comment that says an import is not the import: the error is shown at the real one */
    public function test_an_error_in_an_imported_file_is_not_shown_at_a_comment_that_names_it()
    {
        $path = realpath(self::ROOT.'/tests/fixtures/lsp/broken_importer.gaz');
        $text = "// import \"./broken.gaz\"; once, long ago\n\nimport \"./broken.gaz\";\n\necho broken();\n";
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file://'.$path, 'text' => $text],
            ]],
        ]);

        $diagnostics = $messages[0]['params']['diagnostics'];
        $this->assertCount(1, $diagnostics);
        $this->assertSame(2, $diagnostics[0]['range']['start']['line']);
    }

    /**
     * A module is checked as the compiler checks it alone: what it names resolves through its
     * own imports, so one opened on its own gets the answer it gets in any program
     */
    public function test_a_module_opened_alone_is_diagnosed_by_its_own_imports()
    {
        $fine = realpath(self::ROOT.'/tests/fixtures/import/lib/math.gaz');
        $missing = realpath(self::ROOT.'/tests/fixtures/import/lib/needs_math.gaz');
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file://'.$fine, 'text' => file_get_contents($fine)],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file://'.$missing, 'text' => file_get_contents($missing)],
            ]],
        ]);

        $this->assertSame([], $messages[0]['params']['diagnostics']);
        $this->assertCount(1, $messages[1]['params']['diagnostics']);
        $this->assertSame(3, $messages[1]['params']['diagnostics'][0]['range']['start']['line']); // 0-based
        $this->assertSame('Undefined function: square', $messages[1]['params']['diagnostics'][0]['message']);
    }

    public function test_a_change_is_diagnosed_from_its_last_content_change_the_whole_new_text()
    {
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didChange', 'params' => [
                'textDocument' => ['uri' => 'file:///a.gaz'],
                // Full sync: only the last entry is the document's new text
                'contentChanges' => [['text' => 'bad ('], ['text' => "echo 1;\n"]],
            ]],
        ]);

        $this->assertSame([], $messages[0]['params']['diagnostics']);
    }

    public function test_shutdown_answers_with_a_null_result()
    {
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown'],
        ]);

        $this->assertSame(['jsonrpc' => '2.0', 'id' => 2, 'result' => null], $messages[0]);
    }

    public function test_an_unknown_request_is_answered_method_not_found()
    {
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'workspace/symbol', 'params' => []],
        ]);

        $this->assertSame(3, $messages[0]['id']);
        $this->assertSame(-32601, $messages[0]['error']['code']);
    }

    private function hoverAt(int $id, int $line, int $character): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'textDocument/hover', 'params' => [
            'textDocument' => ['uri' => 'file:///a.gaz'],
            'position' => ['line' => $line, 'character' => $character],
        ]];
    }

    public function test_hovering_a_builtin_gives_its_arity()
    {
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file:///a.gaz', 'text' => "echo len(\"x\");\n"],
            ]],
            $this->hoverAt(1, 0, 6), // "len"
        ]);

        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['contents' => ['kind' => 'markdown', 'value' => '**len** — builtin, 1 argument']]],
            $messages[1]
        );
    }

    public function test_hovering_a_declared_function_gives_its_parameters()
    {
        $source = "fn add(\$a, \$b) { return \$a + \$b; }\necho add(1, 2);\n";
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file:///a.gaz', 'text' => $source],
            ]],
            $this->hoverAt(1, 1, 6), // "add" in the call, not the declaration
        ]);

        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['contents' => ['kind' => 'markdown', 'value' => "```gaz\nfn add(\$a, \$b)\n```"]]],
            $messages[1]
        );
    }

    public function test_hovering_a_documented_function_gives_its_signature_and_docblock()
    {
        $source = "/**\n * Doubles \$n\n */\nfn double(int \$n): int { return \$n * 2; }\necho double(2);\n";
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file:///a.gaz', 'text' => $source],
            ]],
            $this->hoverAt(1, 4, 6), // "double" in the call
        ]);

        $this->assertSame(
            ['kind' => 'markdown', 'value' => "```gaz\nfn double(int \$n): int\n```\n\nDoubles \$n"],
            $messages[1]['result']['contents']
        );
    }

    /**
     * What the document imports is read for hover as it is for go-to-definition: a function, a
     * kind and a constant, each with its docblock
     */
    public function test_hovering_a_name_from_an_imported_file_gives_its_docblock()
    {
        $uri = 'file://'.realpath(self::ROOT.'/tests/fixtures/lsp/importer.gaz');
        $text = "import \"./documented.gaz\";\necho add(1, 2);\necho Point(1, 2).x + SIDES;\n";
        $hover = fn (int $id, int $line, int $character) => ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'textDocument/hover', 'params' => [
            'textDocument' => ['uri' => $uri], 'position' => ['line' => $line, 'character' => $character],
        ]];
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => $uri, 'text' => $text]]],
            $hover(1, 1, 6),  // add
            $hover(2, 2, 6),  // Point
            $hover(3, 2, 22), // SIDES
        ]);
        $values = array_map(fn ($message) => $message['result']['contents']['value'] ?? null, array_slice($messages, 1));

        $this->assertSame([
            "```gaz\nfn add(int \$a, int \$b): int\n```\n\nAdds two numbers.\n\nBoth must be ints.",
            "```gaz\nkind Point\n```\n\nA point on a plane",
            "```gaz\nconst SIDES = 4;\n```\n\nHow many sides a square has",
        ], $values);
    }

    public function test_hovering_a_keyword_gives_no_result()
    {
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file:///a.gaz', 'text' => "echo 1;\n"],
            ]],
            $this->hoverAt(1, 0, 0), // "echo"
        ]);

        $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => null], $messages[1]);
    }

    public function test_hovering_an_unopened_document_gives_no_result()
    {
        $messages = $this->session([$this->hoverAt(1, 0, 0)]);

        $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => null], $messages[0]);
    }

    public function test_a_request_shaped_wrong_is_an_error_response_not_a_dead_server()
    {
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'textDocument/hover', 'params' => []], // no textDocument key
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown'],
        ]);

        $this->assertSame(1, $messages[0]['id']);
        $this->assertSame(-32603, $messages[0]['error']['code']);
        // The server is still alive for the next message
        $this->assertSame(['jsonrpc' => '2.0', 'id' => 2, 'result' => null], $messages[1]);
    }

    public function test_an_unknown_notification_is_ignored_rather_than_answered()
    {
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didClose', 'params' => []],
        ]);

        $this->assertSame([], $messages);
    }

    private function definitionAt(int $id, string $uri, int $line, int $character): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'textDocument/definition', 'params' => [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $line, 'character' => $character],
        ]];
    }

    public function test_go_to_definition_finds_a_function_in_the_same_document()
    {
        $source = "fn add(\$a, \$b) { return \$a + \$b; }\necho add(1, 2);\n";
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file:///a.gaz', 'text' => $source],
            ]],
            $this->definitionAt(1, 'file:///a.gaz', 1, 6), // "add" in the call
        ]);

        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => [
                'uri' => 'file:///a.gaz',
                'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 34]],
            ]],
            $messages[1]
        );
    }

    public function test_go_to_definition_follows_an_import_to_another_file()
    {
        $path = realpath(self::ROOT.'/tests/fixtures/lsp/importer.gaz');
        $uri = 'file://'.$path;
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => $uri, 'text' => file_get_contents($path)],
            ]],
            $this->definitionAt(1, $uri, 2, 6), // "helper" in "echo helper();"
        ]);

        $this->assertSame('file://'.realpath(self::ROOT.'/tests/fixtures/lsp/helper.gaz'), $messages[1]['result']['uri']);
        $this->assertSame(0, $messages[1]['result']['range']['start']['line']);
    }

    public function test_go_to_definition_on_a_builtin_gives_no_result()
    {
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file:///a.gaz', 'text' => "echo len(\"x\");\n"],
            ]],
            $this->definitionAt(1, 'file:///a.gaz', 0, 6), // "len"
        ]);

        $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => null], $messages[1]);
    }

    private function completionsFor(string $uri, string $text): array
    {
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => $uri, 'text' => $text],
            ]],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'textDocument/completion', 'params' => [
                'textDocument' => ['uri' => $uri], 'position' => ['line' => 0, 'character' => 0],
            ]],
        ]);

        return $messages[1]['result'];
    }

    public function test_completion_offers_keywords_and_builtins_with_their_arity()
    {
        $items = $this->completionsFor('file:///a.gaz', "echo 1;\n");
        $byLabel = array_column($items, null, 'label');

        $this->assertSame(['label' => 'fn', 'kind' => 14], $byLabel['fn']);
        $this->assertSame(['label' => 'len', 'kind' => 3, 'detail' => '1 argument'], $byLabel['len']);
        $this->assertSame(['label' => 'slice', 'kind' => 3, 'detail' => '2 to 3 arguments'], $byLabel['slice']);
        // A word that is gone isn't offered, nor another language's keyword
        $this->assertSame(['label' => 'final', 'kind' => 14], $byLabel['final']);
        $this->assertSame(['label' => 'interface', 'kind' => 14], $byLabel['interface']);
        $this->assertArrayNotHasKey('include', $byLabel);
        $this->assertSame(['label' => 'import', 'kind' => 14], $byLabel['import']);
        $this->assertArrayNotHasKey('function', $byLabel);
    }

    /**
     * Every keyword the lexer knows is offered, in its order, except the ones the parser only
     * refuses (reserved for what isn't built, or gone), so a new keyword can't be forgotten by
     * completion
     */
    public function test_completion_offers_every_keyword_of_the_lexer_but_the_hints()
    {
        preg_match('/pub const KEYWORDS = \{(.*?)\};/s', file_get_contents(self::ROOT.'/compiler/lexer.gaz'), $table);
        preg_match_all('/"(\w+)" =>/', $table[1], $words);
        $hints = ['include'];
        $offered = array_column(array_filter(
            $this->completionsFor('file:///a.gaz', "echo 1;\n"),
            fn ($item) => $item['kind'] === 14,
        ), 'label');

        $this->assertNotEmpty($words[1]);
        $this->assertSame(array_values(array_diff($words[1], $hints)), $offered);
    }

    public function test_completion_offers_a_function_declared_in_the_document()
    {
        $items = $this->completionsFor('file:///a.gaz', "fn total(\$a, \$b) { return \$a + \$b; }\n");
        $byLabel = array_column($items, null, 'label');

        $this->assertSame(['label' => 'total', 'kind' => 3], $byLabel['total']);
    }

    /**
     * An interface is a declaration as a kind is: completed as an Interface, hovered with its
     * signature and docblock, and found by go-to-definition
     */
    public function test_an_interface_is_completed_hovered_and_found()
    {
        $source = "/**\n * Has an area\n */\ninterface Shape {\n    fn area();\n}\nkind Square implements Shape { pub fn area() {} }\n";
        $byLabel = array_column($this->completionsFor('file:///a.gaz', $source), null, 'label');
        $this->assertSame(8, $byLabel['Shape']['kind']);
        $this->assertSame('Has an area', $byLabel['Shape']['detail']);

        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file:///a.gaz', 'text' => $source],
            ]],
            $this->hoverAt(1, 6, 26), // "Shape" after implements
            $this->definitionAt(2, 'file:///a.gaz', 6, 26),
        ]);
        $this->assertSame(['jsonrpc' => '2.0', 'method' => 'textDocument/publishDiagnostics', 'params' => ['uri' => 'file:///a.gaz', 'diagnostics' => []]], $messages[0]);
        $this->assertSame(['kind' => 'markdown', 'value' => "```gaz\ninterface Shape\n```\n\nHas an area"], $messages[1]['result']['contents']);
        $this->assertSame(['line' => 3, 'character' => 0], $messages[2]['result']['range']['start']);
    }

    public function test_completion_offers_a_function_from_an_imported_file()
    {
        $path = realpath(self::ROOT.'/tests/fixtures/lsp/importer.gaz');
        $items = $this->completionsFor('file://'.$path, file_get_contents($path));
        $byLabel = array_column($items, null, 'label');

        $this->assertArrayHasKey('helper', $byLabel);
    }

    /**
     * A documented name carries its docblock's first line as its detail and the whole docblock as
     * its documentation, from the document and from what it imports
     */
    public function test_completion_carries_a_docblock()
    {
        $path = realpath(self::ROOT.'/tests/fixtures/lsp/importer.gaz');
        $text = "import \"./documented.gaz\";\n/**\n * Here\n */\nfn here() {}\n";
        $byLabel = array_column($this->completionsFor('file://'.$path, $text), null, 'label');

        $this->assertSame([
            'label' => 'add', 'kind' => 3, 'detail' => 'Adds two numbers.',
            'documentation' => ['kind' => 'markdown', 'value' => "Adds two numbers.\n\nBoth must be ints."],
        ], $byLabel['add']);
        $this->assertSame('A point on a plane', $byLabel['Point']['detail']);
        $this->assertSame(7, $byLabel['Point']['kind']);
        $this->assertSame(21, $byLabel['SIDES']['kind']);
        $this->assertSame('Here', $byLabel['here']['detail']);
    }

    public function test_completion_lists_a_name_declared_reachably_more_than_once_only_once()
    {
        $path = realpath(self::ROOT.'/tests/fixtures/lsp/importer.gaz');
        // helper() is already declared in helper.gaz, which this imports; declaring it again
        // here too (its own mistake, not ours to judge) must still list it once, not twice
        $items = $this->completionsFor('file://'.$path, file_get_contents($path)."\nfn helper() { return \"shadowed\"; }\n");
        $labels = array_column($items, 'label');

        $this->assertSame(1, count(array_filter($labels, fn ($label) => $label === 'helper')));
    }
}
