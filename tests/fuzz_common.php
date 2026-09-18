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
 * A selfhost/ driver, compiled once and read back from its bytecode as the tests' VM side is
 *
 * @param  string  $file  Path relative to the project root
 */
function compile_driver(string $file): Program
{
    $driver = getcwd().'/'.$file;
    $parser = new Parser(new Lexer(file_get_contents($driver)), $driver);

    return Program::read((new CodeGenerator($parser->parse()))->compile()->write($driver), $driver);
}

/**
 * What a driver prints for a file, an uncaught error included, as `gazlang -f DRIVER -- PATH` would
 */
function run_driver(Program $program, string $path): string
{
    ob_start();
    try {
        (new VM($program, [$path]))->run();
    } catch (GazLangError $e) {
        echo $e->report(), "\n";
    } catch (Throwable $e) {
        echo 'PHP '.get_class($e).": {$e->getMessage()}\n";
    }

    return ob_get_clean();
}
