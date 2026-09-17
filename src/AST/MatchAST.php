<?php

namespace GazLang\AST;

/**
 * Match represents match ($subject) { $values => $body, ... } in the AST
 *
 * The subject is evaluated once, then each arm's values are evaluated in order and compared
 * with ==, so an arm's value can be any expression and only the ones before the match runs.
 * There is no fallthrough: the matching arm's body is the value of the whole match, and a
 * break inside it belongs to the loop around the match. Nothing matching and no default arm
 * is an error.
 */
class MatchAST extends AST
{
    /**
     * @var AST The value being matched, evaluated once
     */
    public $subject;

    /**
     * @var list<array{0: list<AST>|null, 1: AST, 2: bool}> Each arm, in order: the values it
     *                                                      matches (null for the default arm, which the parser keeps last), its body, and
     *                                                      whether that body is a block, which only a match written as a statement may have
     */
    public $arms;

    /**
     * Constructor
     *
     * @param  AST  $subject  The value being matched
     * @param  list<array{0: list<AST>|null, 1: AST, 2: bool}>  $arms  The arms, in order
     */
    public function __construct(AST $subject, array $arms)
    {
        $this->subject = $subject;
        $this->arms = $arms;
    }
}
