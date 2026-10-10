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
                'documentLinkProvider' => [], 'codeActionProvider' => ['codeActionKinds' => ['quickfix']],
                'documentSymbolProvider' => true, 'signatureHelpProvider' => ['triggerCharacters' => ['(', ',']],
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
        preg_match('/pub const KEYWORDS = \{(.*?)\};/s', file_get_contents(self::ROOT.'/lib/syntax.gaz'), $table);
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

    /**
     * The quick fixes offered for a document's own diagnostics: one session to get them published,
     * a second to send them back with a codeAction request, as a client does
     *
     * @return list<array<string, mixed>>
     */
    private function quickFixesFor(string $uri, string $text): array
    {
        $open = ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
            'textDocument' => ['uri' => $uri, 'text' => $text],
        ]];
        $diagnostics = $this->session([$open])[0]['params']['diagnostics'];
        $this->assertCount(1, $diagnostics);

        return $this->session([$open, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'textDocument/codeAction', 'params' => [
            'textDocument' => ['uri' => $uri],
            'range' => $diagnostics[0]['range'],
            'context' => ['diagnostics' => $diagnostics],
        ]]])[1]['result'];
    }

    /** The import the compiler's message names is added below the document's last import */
    public function test_a_quick_fix_adds_the_import_the_error_names()
    {
        $uri = 'file://'.realpath(self::ROOT.'/tests/fixtures/lsp/importer.gaz');
        $actions = $this->quickFixesFor($uri, "// Greets\nimport \"./greeting.gaz\";\n\necho greeting() .. helper();\n");

        $this->assertCount(1, $actions);
        $this->assertSame('Add import "./helper.gaz"', $actions[0]['title']);
        $this->assertSame('quickfix', $actions[0]['kind']);
        $this->assertStringContainsString('add import "./helper.gaz";', $actions[0]['diagnostics'][0]['message']);
        $at = ['line' => 2, 'character' => 0];
        $this->assertSame(
            [$uri => [['range' => ['start' => $at, 'end' => $at], 'newText' => "import \"./helper.gaz\";\n"]]],
            $actions[0]['edit']['changes']
        );
    }

    /**
     * A miscapitalised keyword is replaced where it is written, its position counted in UTF-16
     * code units as the protocol's are: the emoji before it is four bytes but two units
     */
    public function test_a_quick_fix_writes_the_keyword_in_lowercase()
    {
        $actions = $this->quickFixesFor('file:///a.gaz', "\$s = \"\u{1F600}\"; IF (1) {\n    echo \$s;\n}\n");

        $this->assertCount(1, $actions);
        $this->assertSame("Write 'if'", $actions[0]['title']);
        $this->assertSame(
            ['file:///a.gaz' => [['range' => ['start' => ['line' => 0, 'character' => 11], 'end' => ['line' => 0, 'character' => 13]], 'newText' => 'if']]],
            $actions[0]['edit']['changes']
        );
    }

    public function test_a_quick_fix_writes_else_if_for_elseif()
    {
        $actions = $this->quickFixesFor('file:///a.gaz', "if (true) {\n} elseif (false) {\n}\n");

        $this->assertSame("Write 'else if'", $actions[0]['title']);
        $this->assertSame(
            [['range' => ['start' => ['line' => 1, 'character' => 2], 'end' => ['line' => 1, 'character' => 8]], 'newText' => 'else if']],
            $actions[0]['edit']['changes']['file:///a.gaz']
        );
    }

    /** An error whose message names no fix, or another tool's diagnostic, gets none */
    public function test_a_diagnostic_naming_no_fix_gets_no_quick_fix()
    {
        $this->assertSame([], $this->quickFixesFor('file:///a.gaz', "echo nothing();\n"));

        $messages = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file:///a.gaz', 'text' => "IF (1) {\n}\n"],
            ]],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'textDocument/codeAction', 'params' => [
                'textDocument' => ['uri' => 'file:///a.gaz'],
                'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]],
                'context' => ['diagnostics' => [[
                    'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 2]],
                    'source' => 'spelling', 'message' => "(keywords are lowercase: write 'if', not 'IF')",
                ]]],
            ]],
        ]);
        $this->assertSame([], $messages[1]['result']);
    }

    public function test_a_codeaction_request_without_its_context_is_an_error_response()
    {
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'textDocument/codeAction', 'params' => ['textDocument' => ['uri' => 'file:///a.gaz']]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown'],
        ]);

        $this->assertSame(-32603, $messages[0]['error']['code']);
        $this->assertSame(['jsonrpc' => '2.0', 'id' => 2, 'result' => null], $messages[1]);
    }

    /**
     * @param  array<string, mixed>  $request  The request after the document is opened
     */
    private function askAbout(string $text, array $request): mixed
    {
        $request['jsonrpc'] = '2.0';
        $request['id'] = 1;
        $request['params']['textDocument'] = ['uri' => 'file:///a.gaz'];

        return $this->session([
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file:///a.gaz', 'text' => $text],
            ]],
            $request,
        ])[1]['result'];
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    private static function range(int $line, int $start, int $endLine, int $end): array
    {
        return ['start' => ['line' => $line, 'character' => $start], 'end' => ['line' => $endLine, 'character' => $end]];
    }

    /**
     * The outline is hierarchical: a kind's members are its children, an enum's cases too; each
     * range covers the whole declaration, body included, and its selectionRange the name, counted
     * in UTF-16 code units past a non-ASCII string
     */
    public function test_document_symbols_are_the_outline_with_members_as_children()
    {
        $text = "const GREETING = \"h\u{e9}llo\"; const N = 1;\nkind Box {\n    #lid = 1;\n    fn open() {\n    }\n}\nenum Colour { Red; }\nfn main() {}\n";
        $symbols = $this->askAbout($text, ['method' => 'textDocument/documentSymbol', 'params' => []]);

        $this->assertSame(['GREETING', 'N', 'Box', 'Colour', 'main'], array_column($symbols, 'name'));
        $this->assertSame([14, 14, 5, 10, 12], array_column($symbols, 'kind'));
        $this->assertSame(['name' => 'N', 'detail' => 'const N = 1;', 'kind' => 14,
            'range' => self::range(0, 26, 0, 38), 'selectionRange' => self::range(0, 32, 0, 33)], $symbols[1]);
        $this->assertSame(self::range(1, 0, 5, 1), $symbols[2]['range']);
        $this->assertSame([
            ['name' => 'lid', 'detail' => '#lid = 1;', 'kind' => 8, 'range' => self::range(2, 4, 2, 13), 'selectionRange' => self::range(2, 4, 2, 8)],
            ['name' => 'open', 'detail' => 'fn open()', 'kind' => 6, 'range' => self::range(3, 4, 4, 5), 'selectionRange' => self::range(3, 7, 3, 11)],
        ], $symbols[2]['children']);
        $this->assertSame([['Red', 22]], array_map(fn ($c) => [$c['name'], $c['kind']], $symbols[3]['children']));
        $this->assertArrayNotHasKey('children', $symbols[4]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function signatureAt(string $text, int $line, int $character): ?array
    {
        return $this->askAbout($text, ['method' => 'textDocument/signatureHelp', 'params' => [
            'position' => ['line' => $line, 'character' => $character],
        ]]);
    }

    /**
     * Signature help names the call around the cursor, with the parameter it is in by the commas
     * at the call's own depth (not those inside a list or a nested call), each parameter's place in
     * the label, and the docblock
     */
    public function test_signature_help_shows_the_parameter_the_cursor_is_in()
    {
        $text = "/**\n * Adds\n */\nfn add(int \$a, \$b = [1, 2]): int { return \$a; }\necho add([3, 4], len(\"\u{e9}\"), );\n";
        $help = $this->signatureAt($text, 4, 25); // at the last comma, past the two-byte é: still in add

        $this->assertSame([
            'signatures' => [[
                'label' => 'fn add(int $a, $b = [1, 2]): int',
                'parameters' => [['label' => [7, 13]], ['label' => [15, 26]]],
                'documentation' => ['kind' => 'markdown', 'value' => 'Adds'],
            ]],
            'activeSignature' => 0,
            'activeParameter' => 1,
        ], $help);
        $this->assertSame(0, $this->signatureAt($text, 4, 13)['activeParameter']); // inside [3, 4]
        $this->assertSame('len', $this->signatureAt($text, 4, 21)['signatures'][0]['label']);
        $this->assertNull($this->signatureAt($text, 4, 4)); // before any call
    }

    /** Constructing a kind shows its constructor's parameters; #method a method of the kind the cursor is in */
    public function test_signature_help_for_a_constructor_and_a_method()
    {
        $text = "kind Base {\n    fn _(pub #w, pub #h) {}\n    fn grow(\$by) {}\n}\nkind Box extends Base {\n    fn more() { #grow(); }\n}\necho Box(1, );\n";

        $this->assertSame('Box(pub #w, pub #h)', $this->signatureAt($text, 7, 12)['signatures'][0]['label']);
        $this->assertSame(1, $this->signatureAt($text, 7, 12)['activeParameter']);
        $this->assertSame('fn grow($by)', $this->signatureAt($text, 5, 22)['signatures'][0]['label']);
    }

    public function test_a_signature_help_request_without_a_position_is_an_error_response()
    {
        $messages = $this->session([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'textDocument/signatureHelp', 'params' => ['textDocument' => ['uri' => 'file:///a.gaz']]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'textDocument/documentSymbol', 'params' => []],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'shutdown'],
        ]);

        $this->assertSame(-32603, $messages[0]['error']['code']);
        $this->assertSame(-32603, $messages[1]['error']['code']);
        $this->assertSame(['jsonrpc' => '2.0', 'id' => 3, 'result' => null], $messages[2]);
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
