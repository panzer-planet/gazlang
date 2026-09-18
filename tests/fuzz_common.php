<?php

/**
 * What the two differential fuzzers share: a self-hosted driver compiled once, and run on a file as the CLI would
 */

use GazLang\CodeGenerator\CodeGenerator;
use GazLang\CodeGenerator\Program;
use GazLang\GazLangError;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use GazLang\VM\VM;

require __DIR__.'/../vendor/autoload.php';
chdir(__DIR__.'/..');

/**
 * The self-hosted front end, selfhost/gazlang.gaz, compiled once and read back from its bytecode as the tests' VM side is
 */
function compile_driver(): Program
{
    $driver = getcwd().'/selfhost/gazlang.gaz';
    $parser = new Parser(new Lexer(file_get_contents($driver)), $driver);

    return Program::read((new CodeGenerator($parser->parse()))->compile()->write($driver), $driver);
}

/**
 * What the front end prints for a file in a mode (code, tokens or ast), an uncaught error
 * included, as `gazlang -f selfhost/gazlang.gaz -- MODE PATH` would
 */
function run_driver(Program $program, string $mode, string $path): string
{
    ob_start();
    try {
        (new VM($program, [$mode, $path]))->run();
    } catch (GazLangError $e) {
        echo $e->report(), "\n";
    } catch (Throwable $e) {
        echo 'PHP '.get_class($e).": {$e->getMessage()}\n";
    }

    return ob_get_clean();
}
