<?php

namespace GazLang\AST;

/**
 * TryStatement represents try { ... } catch (Type $error) { ... } catch ($error) { ... } finally { ... } in the AST
 *
 * There is at least one catch or a finally, and a catch without a class is the last.
 */
class TryStatementAST extends AST
{
    /**
     * @var CompoundAST The statements whose errors are caught
     */
    public $body;

    /**
     * @var list<array{0: string|null, 1: VariableAST, 2: CompoundAST}> Each catch clause: the class it catches (null for
     *                                                                  anything), the variable the error is assigned to, and its statements
     */
    public $catches;

    /**
     * @var CompoundAST|null The statements run however the try and catch blocks are left, or null
     */
    public $finally;

    /**
     * Constructor
     *
     * @param  CompoundAST  $body  The statements whose errors are caught
     * @param  list<array{0: string|null, 1: VariableAST, 2: CompoundAST}>  $catches  The catch clauses
     * @param  CompoundAST|null  $finally  The finally block, or null
     */
    public function __construct(CompoundAST $body, array $catches, ?CompoundAST $finally)
    {
        $this->body = $body;
        $this->catches = $catches;
        $this->finally = $finally;
    }
}
