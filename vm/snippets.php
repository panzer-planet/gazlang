<?php

// Collects every snippet the PHP tests run through executeCode() into tests/vm_snippets.txt,
// one JSON string per line, for the C VM's harness (entries "snippet:<id>" in vm/passing.txt).
// Run it after adding tests: php vm/snippets.php

$root = dirname(__DIR__);
$record = tempnam(sys_get_temp_dir(), 'snippets');
passthru(sprintf('cd %s && GAZLANG_RECORD_SNIPPETS=%s %s -d pcov.enabled=0 vendor/bin/phpunit --exclude-group whole-repository > /dev/null',
    escapeshellarg($root), escapeshellarg($record), escapeshellarg(PHP_BINARY)));
$lines = array_values(array_unique(file($record, FILE_IGNORE_NEW_LINES)));
unlink($record);
file_put_contents("{$root}/tests/vm_snippets.txt", implode("\n", $lines)."\n");
echo count($lines)." snippets\n";
