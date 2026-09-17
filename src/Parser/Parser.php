<?php

namespace GazLang\Parser;

use Exception;
use GazLang\AST\ArrayLiteralAST;
use GazLang\AST\AssignAST;
use GazLang\AST\AST;
use GazLang\AST\BinOpAST;
use GazLang\AST\BooleanAST;
use GazLang\AST\CallValueAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\ForeachStatementAST;
use GazLang\AST\FunctionCallAST;
use GazLang\AST\FunctionDeclarationAST;
use GazLang\AST\FunctionRefAST;
use GazLang\AST\IfStatementAST;
use GazLang\AST\IncrementAST;
use GazLang\AST\IndexAST;
use GazLang\AST\LambdaAST;
use GazLang\AST\LoopControlAST;
use GazLang\AST\NullAST;
use GazLang\AST\NumAST;
use GazLang\AST\ReturnStatementAST;
use GazLang\AST\StatementAST;
use GazLang\AST\StringAST;
use GazLang\AST\TernaryAST;
use GazLang\AST\TryStatementAST;
use GazLang\AST\UnaryOpAST;
use GazLang\AST\VariableAST;
use GazLang\AST\WhileStatementAST;
use GazLang\GazLangError;
use GazLang\Lexer\Lexer;
use GazLang\Lexer\Token;
use GazLang\Runtime\Builtins;

/**
 * Parser class builds an AST from tokens
 */
class Parser
{
    /**
     * Assignment operator token types: = and the compound assignments
     */
    private const ASSIGNMENTS = [
        Token::ASSIGN, Token::PLUS_ASSIGN, Token::MINUS_ASSIGN, Token::MULTIPLY_ASSIGN, Token::DIVIDE_ASSIGN, Token::MODULO_ASSIGN,
        Token::CONCAT_ASSIGN, Token::COALESCE_ASSIGN,
    ];

    /**
     * How expected tokens are described in syntax errors; other types are keywords, shown quoted and lowercase
     */
    private const EXPECTED = [
        Token::LEFT_PAREN => "'('",
        Token::RIGHT_PAREN => "')'",
        Token::LEFT_BRACE => "'{'",
        Token::RIGHT_BRACE => "'}'",
        Token::LEFT_BRACKET => "'['",
        Token::RIGHT_BRACKET => "']'",
        Token::SEMICOLON => "';'",
        Token::COMMA => "','",
        Token::IDENTIFIER => 'a name',
        Token::VAR_IDENTIFIER => 'a $variable',
        Token::GLOBAL_VAR_IDENTIFIER => 'an @variable',
        Token::STRING => 'a string',
        Token::COLON => "':'",
        Token::ARROW => "'->'",
        Token::DOUBLE_ARROW => "'=>'",
    ];

    /**
     * @var Lexer The lexer that provides tokens
     */
    private $lexer;

    /**
     * @var Token The current token being processed
     */
    private $current_token;

    /**
     * @var int How many loops enclose the statement being parsed, so break and continue can be checked
     */
    private $loop_depth = 0;

    /**
     * @var bool Whether a function body is being parsed, so return can be checked
     */
    private $in_function = false;

    /**
     * @var array<string, int|array{0: int, 1: int}> Declared function names mapped to their parameter
     *                                               counts; builtins with optional parameters have [fewest, most]
     */
    private $functions = Builtins::ARITIES;

    /**
     * @var list<FunctionCallAST|FunctionRefAST> Every call by name and bare name used as a value, in source order,
     *                                           checked against the declared functions once the whole program is read
     */
    private $uses = [];

    /**
     * @var array<int, true> The ( tokens (by object id) that start an expression at the ternary's level, the only
     *                       place a parenthesised parameter list can start a lambda
     */
    private $lambda_heads = [];

    /**
     * @var string Directory that include paths in the file being parsed are relative to
     */
    private $base_dir;

    /**
     * @var string|null The file being parsed, as shown in errors
     */
    private $file;

    /**
     * @var array<string, true> Real paths of every file parsed so far, so each is only included once
     */
    private $included = [];

    /**
     * Constructor
     *
     * @param  Lexer  $lexer  The lexer to get tokens from
     * @param  string|null  $path  The file the source came from, if any; includes resolve relative to it
     */
    public function __construct(Lexer $lexer, ?string $path = null)
    {
        $real_path = $path === null ? false : realpath($path);
        if ($real_path !== false) {
            $this->included[$real_path] = true;
        }
        $this->base_dir = $real_path !== false ? dirname($real_path) : getcwd();
        // The main file is shown as the user gave it; included files relative to the working directory
        $this->file = $path;

        $this->lexer = $lexer;
        $this->current_token = $this->next_token();
    }

    /**
     * Raise an error for an unexpected token
     *
     * @throws GazLangError
     */
    public function error(): never
    {
        $this->fail('Unexpected '.$this->describe($this->current_token));
    }

    /**
     * Raise an error at the current token's line
     *
     * @param  string  $message  The error message, without the location
     *
     * @throws GazLangError
     */
    private function fail(string $message): never
    {
        throw new GazLangError($message, $this->file, $this->current_token->line);
    }

    /**
     * Describe a token the way it appears in the source, for error messages
     *
     * @param  Token  $token  The token
     */
    private function describe(Token $token): string
    {
        return match ($token->type) {
            Token::EOF => 'end of file',
            Token::FLOAT => "'".Lexer::format_float($token->value)."'",
            Token::STRING, Token::STRING_START, Token::STRING_MIDDLE, Token::STRING_END => 'string '.Lexer::quote($token->value),
            default => "'{$token->value}'",
        };
    }

    /**
     * Get the next token, adding this file's name to lexer errors
     *
     * @throws GazLangError
     */
    private function next_token(): Token
    {
        try {
            return $this->lexer->get_next_token();
        } catch (GazLangError $e) {
            throw new GazLangError($e->reason, $this->file, $e->line_number);
        }
    }

    /**
     * Record where a node came from, so errors about it can point at the source
     *
     * @template T of AST
     *
     * @param  T  $node  The node
     * @param  Token  $token  The token the node starts at
     * @return T The node
     */
    private function at(AST $node, Token $token): AST
    {
        $node->line = $token->line;
        $node->file = $this->file;

        return $node;
    }

    /**
     * Show a real path relative to the working directory when it is inside it
     *
     * @param  string  $real_path  An absolute path
     */
    private function display_path(string $real_path): string
    {
        $cwd = getcwd().'/';

        return str_starts_with($real_path, $cwd) ? substr($real_path, strlen($cwd)) : $real_path;
    }

    /**
     * Compare the current token type with the passed token type and
     * if they match, "eat" the current token and get the next one
     *
     * @param  string  $token_type  The token type to match
     *
     * @throws GazLangError If the token types don't match
     */
    public function eat(string $token_type): void
    {
        if ($this->current_token->type !== $token_type) {
            $expected = self::EXPECTED[$token_type] ?? "'".strtolower($token_type)."'";
            $this->fail("Expected {$expected} but found ".$this->describe($this->current_token));
        }

        $this->current_token = $this->next_token();
    }

    /**
     * Parse a local ($name) or global (@name) variable
     *
     * @return VariableAST
     *
     * @throws Exception
     */
    public function variable()
    {
        $node = $this->at(new VariableAST($this->current_token), $this->current_token);
        $this->eat($this->current_token->type === Token::GLOBAL_VAR_IDENTIFIER ? Token::GLOBAL_VAR_IDENTIFIER : Token::VAR_IDENTIFIER);

        return $node;
    }

    /**
     * Parse a call by name (IDENTIFIER arguments), the name having been eaten
     *
     * @param  Token  $name  The IDENTIFIER token
     * @return FunctionCallAST
     *
     * @throws Exception
     */
    public function function_call(Token $name)
    {
        return $this->uses[] = $this->at(new FunctionCallAST($name->value, $this->arguments()), $name);
    }

    /**
     * Parse a call's arguments (LPAREN [expr (COMMA expr)*] RPAREN)
     *
     * @return AST[] The argument expressions, in order
     *
     * @throws Exception
     */
    public function arguments(): array
    {
        $this->eat(Token::LEFT_PAREN);

        $args = [];
        if ($this->current_token->type !== Token::RIGHT_PAREN) {
            $args[] = $this->expr();
            while ($this->current_token->type === Token::COMMA) {
                $this->eat(Token::COMMA);
                $args[] = $this->expr();
            }
        }
        $this->eat(Token::RIGHT_PAREN);

        return $args;
    }

    /**
     * Parse a primary (INTEGER | FLOAT | STRING | interpolated_string | TRUE | FALSE | NULL | LPAREN expr RPAREN
     *                  | variable | function_call | IDENTIFIER | list_literal | map_literal)
     *
     * A bare IDENTIFIER not followed by ( is a function used as a value; that it names a
     * function is checked once the whole program is read, like calls.
     *
     * @return AST
     *
     * @throws Exception
     */
    public function primary()
    {
        $token = $this->current_token;

        if ($token->type === Token::INTEGER || $token->type === Token::FLOAT) {
            $this->eat($token->type);

            return $this->at(new NumAST($token), $token);
        } elseif ($token->type === Token::STRING) {
            $this->eat(Token::STRING);

            return $this->at(new StringAST($token), $token);
        } elseif ($token->type === Token::STRING_START) {
            return $this->interpolated_string();
        } elseif ($token->type === Token::TRUE || $token->type === Token::FALSE) {
            $this->eat($token->type);

            return $this->at(new BooleanAST($token), $token);
        } elseif ($token->type === Token::NULL) {
            $this->eat(Token::NULL);

            return $this->at(new NullAST($token), $token);
        } elseif ($token->type === Token::IDENTIFIER) {
            $this->eat(Token::IDENTIFIER);
            if ($this->current_token->type === Token::LEFT_PAREN) {
                return $this->function_call($token);
            }

            return $this->uses[] = $this->at(new FunctionRefAST($token->value), $token);
        } elseif ($token->type === Token::LEFT_BRACKET) {
            return $this->list_literal();
        } elseif ($token->type === Token::LEFT_BRACE) {
            return $this->map_literal();
        } elseif ($token->type === Token::LEFT_PAREN) {
            return $this->parenthesised();
        } elseif ($token->type === Token::VAR_IDENTIFIER || $token->type === Token::GLOBAL_VAR_IDENTIFIER) {
            return $this->variable();
        }

        $this->error();
    }

    /**
     * Parse a parenthesised expression, or a lambda whose parameters are in parentheses
     *
     * (LPAREN [expr (COMMA expr)*] RPAREN [ARROW lambda_body]): a comma list is parsed
     * either way, so no lookahead is needed to tell ($a, $b = 1) -> ... from ($a + 1).
     * Only a ( that starts an expression at the ternary's level (see ternary()) can head a
     * lambda, so 1 + ($x) -> 2 is a syntax error like 1 + $x -> 2, and anywhere else a
     * comma is the usual error at once. When -> follows, each element must have been
     * written as $parameter or $parameter = default, not (($a)) or $a[0] = 1.
     *
     * @return AST
     *
     * @throws Exception
     */
    public function parenthesised()
    {
        $paren = $this->current_token;
        $head = isset($this->lambda_heads[spl_object_id($paren)]);
        $this->eat(Token::LEFT_PAREN);

        // Each item with the token it starts at, for checking it as a parameter
        $items = [];
        $comma = null;
        if ($this->current_token->type !== Token::RIGHT_PAREN) {
            $items[] = [$this->current_token, $this->expr()];
            while ($this->current_token->type === Token::COMMA) {
                if (! $head) {
                    $this->error();
                }
                $comma ??= $this->current_token;
                $this->eat(Token::COMMA);
                $items[] = [$this->current_token, $this->expr()];
            }
        }
        $close = $this->current_token;
        $this->eat(Token::RIGHT_PAREN);

        if (! $head || $this->current_token->type !== Token::ARROW) {
            if ($comma !== null) {
                throw new GazLangError("Expected ')' but found ','", $this->file, $comma->line);
            }
            if ($items === []) {
                throw new GazLangError("Unexpected ')'", $this->file, $close->line);
            }

            return $items[0][1];
        }

        $params = [];
        $defaults = [];
        $lines = [];
        foreach ($items as [$first, $item]) {
            $default = null;
            if ($item instanceof AssignAST && $item->token->type === Token::ASSIGN) {
                $default = $item->right;
                $item = $item->left;
            }
            if ($first->type !== Token::VAR_IDENTIFIER || ! $item instanceof VariableAST || $item->value !== $first->value) {
                throw new GazLangError('Lambda parameters must be $variables', $this->file, $first->line);
            }
            $params[] = $item->value;
            $defaults[] = $default;
            $lines[] = $first->line;
        }

        return $this->lambda($paren, $params, $defaults, $lines);
    }

    /**
     * Parse a lambda's body after its parameters (ARROW (block | expr)) and build the node
     *
     * An expression body's value is returned; a block body returns only through return.
     * A block body is a function body: return is allowed, and break and continue can't
     * reach a loop around the lambda.
     *
     * @param  Token  $start  The token the lambda starts at
     * @param  string[]  $params  The parameter names
     * @param  array<int, AST|null>  $defaults  Each parameter's default, or null
     * @param  int[]  $lines  The line each parameter is on, for errors about it
     * @return LambdaAST
     *
     * @throws Exception
     */
    private function lambda(Token $start, array $params, array $defaults, array $lines)
    {
        $arity = $this->check_parameters($params, $defaults, $lines);
        $this->eat(Token::ARROW);

        if ($this->current_token->type === Token::LEFT_BRACE) {
            [$in_function, $loop_depth] = [$this->in_function, $this->loop_depth];
            [$this->in_function, $this->loop_depth] = [true, 0];
            try {
                $body = $this->block();
            } finally {
                [$this->in_function, $this->loop_depth] = [$in_function, $loop_depth];
            }
        } else {
            $body = $this->expr();
        }

        $free = [];
        foreach ([...$defaults, $body] as $node) {
            if ($node !== null) {
                self::collect_variables($node, $free);
            }
        }
        $free = array_values(array_diff(array_keys($free), $params));

        return $this->at(new LambdaAST($params, $defaults, $arity, $body, $free), $start);
    }

    /**
     * Collect the $ variables a node uses, in source order, as keys of $found
     *
     * A nested lambda contributes the variables it captures (its free variables), since
     * those must be present when it is created.
     *
     * @param  AST  $node  The node
     * @param  array<string, true>  $found  The names found so far, by reference
     */
    private static function collect_variables(AST $node, array &$found): void
    {
        if ($node instanceof VariableAST) {
            if (! $node->isGlobal()) {
                // The token's text, "$name"
                $found[(string) $node->value] = true;
            }

            return;
        }
        if ($node instanceof LambdaAST) {
            foreach ($node->free as $name) {
                $found[$name] = true;
            }

            return;
        }

        foreach (get_object_vars($node) as $child) {
            foreach (is_array($child) ? $child : [$child] as $item) {
                // Array literal entries are [key, value] pairs
                foreach (is_array($item) ? $item : [$item] as $leaf) {
                    if ($leaf instanceof AST) {
                        self::collect_variables($leaf, $found);
                    }
                }
            }
        }
    }

    /**
     * Check a lambda's parameter list, with the same rules as a function's, and work out its arity
     *
     * @param  string[]  $params  The parameter names
     * @param  array<int, AST|null>  $defaults  Each parameter's default, or null if required
     * @param  int[]  $lines  The line each parameter is on
     * @return int|array{0: int, 1: int} A count, or [fewest, most] when there are defaults
     *
     * @throws GazLangError On a duplicate name or a required parameter after one with a default
     */
    private function check_parameters(array $params, array $defaults, array $lines): int|array
    {
        $seen = [];
        $required = 0;
        foreach ($params as $i => $param) {
            if (isset($seen[$param])) {
                throw new GazLangError("Duplicate parameter {$param} in lambda", $this->file, $lines[$i]);
            }
            $seen[$param] = true;
            if ($defaults[$i] === null) {
                if ($required < $i) {
                    throw new GazLangError("Required parameter {$param} can't follow a parameter with a default", $this->file, $lines[$i]);
                }
                $required++;
            }
        }

        return self::arity($params, $defaults);
    }

    /**
     * A parameter list's arity: a count, or [fewest, most] when there are defaults
     *
     * @param  string[]  $params  The parameter names
     * @param  array<int, AST|null>  $defaults  Each parameter's default, or null if required
     * @return int|array{0: int, 1: int}
     */
    private static function arity(array $params, array $defaults): int|array
    {
        $required = count(array_filter($defaults, fn ($default) => $default === null));

        return $required === count($params) ? $required : [$required, count($params)];
    }

    /**
     * Parse an interpolated string (STRING_START expr (STRING_MIDDLE expr)* STRING_END)
     *
     * Desugared into concatenation: "Hi {$name}!" is "Hi " .. $name .. "!", which
     * converts values the way echo does; the backends need nothing new.
     *
     * @return AST
     *
     * @throws GazLangError
     */
    public function interpolated_string()
    {
        $start = $this->current_token;
        $this->eat(Token::STRING_START);
        $node = $this->at(new StringAST($start), $start);

        while (true) {
            $value = $this->expr();

            $part = $this->current_token;
            if ($part->type !== Token::STRING_MIDDLE && $part->type !== Token::STRING_END) {
                $this->fail("Expected '}' but found ".$this->describe($part));
            }
            $this->eat($part->type);

            $concat = new Token(Token::CONCAT, '..');
            $concat->line = $part->line;
            $node = $this->at(new BinOpAST($node, $concat, $value), $concat);
            if ($part->value !== '') {
                $node = $this->at(new BinOpAST($node, $concat, $this->at(new StringAST($part), $part)), $concat);
            }

            if ($part->type === Token::STRING_END) {
                return $node;
            }
        }
    }

    /**
     * Parse a list literal (LBRACKET [expr (COMMA expr)* [COMMA]] RBRACKET)
     *
     * @return ArrayLiteralAST
     *
     * @throws Exception
     */
    public function list_literal()
    {
        $start = $this->current_token;
        $this->eat(Token::LEFT_BRACKET);

        $entries = [];
        while ($this->current_token->type !== Token::RIGHT_BRACKET) {
            $entries[] = [null, $this->expr()];
            if ($this->current_token->type === Token::DOUBLE_ARROW) {
                $this->fail('A list has no keys: write a map as {key => value}');
            }

            // A trailing comma is allowed, so multi-line literals diff cleanly
            if ($this->current_token->type !== Token::RIGHT_BRACKET) {
                $this->eat(Token::COMMA);
            }
        }
        $this->eat(Token::RIGHT_BRACKET);

        return $this->at(new ArrayLiteralAST($entries), $start);
    }

    /**
     * Parse a map literal (LBRACE [entry (COMMA entry)* [COMMA]] RBRACE), entry: expr DOUBLE_ARROW expr
     *
     * Only where an expression is expected: a { that starts a statement is a block, and one
     * right after -> is a lambda's block body, so a lambda returning a map writes ({...}).
     *
     * @return ArrayLiteralAST
     *
     * @throws Exception
     */
    public function map_literal()
    {
        $start = $this->current_token;
        $this->eat(Token::LEFT_BRACE);

        $entries = [];
        while ($this->current_token->type !== Token::RIGHT_BRACE) {
            $key = $this->expr();
            $this->eat(Token::DOUBLE_ARROW);
            $entries[] = [$key, $this->expr()];

            if ($this->current_token->type !== Token::RIGHT_BRACE) {
                $this->eat(Token::COMMA);
            }
        }
        $this->eat(Token::RIGHT_BRACE);

        return $this->at(new ArrayLiteralAST($entries, true), $start);
    }

    /**
     * Parse a postfix expression (primary (LBRACKET [expr] RBRACKET | arguments)* [INCREMENT | DECREMENT])
     *
     * Empty brackets ($a[] = ...) append, so they are only allowed directly before an assignment.
     *
     * @return AST
     *
     * @throws Exception
     */
    public function postfix()
    {
        $node = $this->primary();

        while ($this->current_token->type === Token::LEFT_BRACKET || $this->current_token->type === Token::LEFT_PAREN) {
            if ($this->current_token->type === Token::LEFT_PAREN) {
                // A call on a value: $f(1), $h["save"]($doc), pick()(2), (add)(1), located at its (
                $paren = $this->current_token;
                $node = $this->at(new CallValueAST($node, $this->arguments()), $paren);

                continue;
            }
            $bracket = $this->current_token;
            $this->eat(Token::LEFT_BRACKET);
            if ($this->current_token->type === Token::RIGHT_BRACKET) {
                $this->eat(Token::RIGHT_BRACKET);
                if (! in_array($this->current_token->type, self::ASSIGNMENTS, true)) {
                    $this->fail('[] can only be used to append in an assignment');
                }

                return $this->at(new IndexAST($node, null), $bracket);
            }
            $node = $this->at(new IndexAST($node, $this->expr()), $bracket);
            $this->eat(Token::RIGHT_BRACKET);
        }

        $token = $this->current_token;
        if ($token->type === Token::INCREMENT || $token->type === Token::DECREMENT) {
            $this->eat($token->type);

            return $this->at(new IncrementAST($this->assignable($node, $token), $token, false), $token);
        }

        return $node;
    }

    /**
     * Parse a unary expression ((MINUS | NOT) unary | (INCREMENT | DECREMENT) postfix | postfix)
     *
     * @return AST
     *
     * @throws Exception
     */
    public function unary()
    {
        $token = $this->current_token;

        if (in_array($token->type, [Token::MINUS, Token::NOT], true)) {
            $this->eat($token->type);

            return $this->at(new UnaryOpAST($token, $this->unary()), $token);
        }

        if ($token->type === Token::INCREMENT || $token->type === Token::DECREMENT) {
            $this->eat($token->type);

            return $this->at(new IncrementAST($this->assignable($this->postfix(), $token), $token, true), $token);
        }

        return $this->postfix();
    }

    /**
     * Parse a multiplicative expression (unary ((MUL | DIV | MOD) unary)*)
     *
     * @return AST
     *
     * @throws Exception
     */
    public function multiplicative()
    {
        return $this->left_associative('unary', [Token::MULTIPLY, Token::DIVIDE, Token::MODULO]);
    }

    /**
     * Parse an additive expression (multiplicative ((PLUS | MINUS) multiplicative)*)
     *
     * @return AST
     *
     * @throws Exception
     */
    public function additive()
    {
        return $this->left_associative('multiplicative', [Token::PLUS, Token::MINUS]);
    }

    /**
     * Parse a concatenation (additive (CONCAT additive)*)
     *
     * Below + and - so "n = " .. $a + $b concatenates the sum, as in Lua and PHP 8.
     *
     * @return AST
     *
     * @throws Exception
     */
    public function concat()
    {
        return $this->left_associative('additive', [Token::CONCAT]);
    }

    /**
     * Parse a relational expression (concat ((< | <= | > | >=) concat)*)
     *
     * @return AST
     *
     * @throws Exception
     */
    public function relational()
    {
        return $this->left_associative('concat', [
            Token::LESS_THAN, Token::LESS_EQUALS, Token::GREATER_THAN, Token::GREATER_EQUALS,
        ]);
    }

    /**
     * Parse an equality expression (relational ((== | != | <=>) relational)*)
     *
     * @return AST
     *
     * @throws Exception
     */
    public function equality()
    {
        return $this->left_associative('relational', [
            Token::EQUALS, Token::NOT_EQUALS, Token::SPACESHIP,
        ]);
    }

    /**
     * Parse a logical and expression (equality (&& equality)*)
     *
     * @return AST
     *
     * @throws Exception
     */
    public function logical_and()
    {
        return $this->left_associative('equality', [Token::AND]);
    }

    /**
     * Parse a logical or expression (logical_and (|| logical_and)*)
     *
     * @return AST
     *
     * @throws Exception
     */
    public function logical_or()
    {
        return $this->left_associative('logical_and', [Token::OR]);
    }

    /**
     * Parse a null coalescing expression (logical_or [?? coalesce])
     *
     * Right associative, like PHP: $a ?? $b ?? $c is $a ?? ($b ?? $c).
     *
     * @return AST
     *
     * @throws GazLangError
     */
    public function coalesce()
    {
        $node = $this->logical_or();

        $token = $this->current_token;
        if ($token->type === Token::COALESCE) {
            $this->eat(Token::COALESCE);

            return $this->at(new BinOpAST($node, $token, $this->coalesce()), $token);
        }

        return $node;
    }

    /**
     * Parse a ternary (coalesce [? expr : ternary]), or a lambda with one bare parameter (VAR_IDENTIFIER ARROW lambda_body)
     *
     * Right associative, as in C and JS: $a ? 1 : $b ? 2 : 3 is $a ? 1 : ($b ? 2 : 3). The
     * middle is a full expression, so it can hold an assignment or another ternary.
     *
     * A lambda's body is an expression, so it extends as far right as it can: $x -> $x * 2
     * == 4 is $x -> ($x * 2 == 4), and $x -> $y -> $x + $y nests. Recognising $x -> here
     * (and the parenthesised form in parenthesised()) puts lambdas at the same level as the
     * ternary, so 1 + $x -> 2 is a syntax error.
     *
     * @return AST
     *
     * @throws Exception
     */
    public function ternary()
    {
        $start = $this->current_token;
        if ($start->type === Token::LEFT_PAREN) {
            $this->lambda_heads[spl_object_id($start)] = true;
        }
        $node = $this->coalesce();

        $token = $this->current_token;
        if ($token->type === Token::ARROW && $node instanceof VariableAST && ! $node->isGlobal() && $start->type === Token::VAR_IDENTIFIER) {
            return $this->lambda($start, [$node->value], [null], [$start->line]);
        }
        if ($token->type === Token::QUESTION) {
            $this->eat(Token::QUESTION);
            $then = $this->expr();
            $this->eat(Token::COLON);

            return $this->at(new TernaryAST($node, $then, $this->ternary()), $token);
        }

        return $node;
    }

    /**
     * Parse an expression, the lowest precedence level ((variable | index) (= | += | -= | *= | /= | %= | ..= | ??=) expr | ternary)
     *
     * Assignment is right associative, so $a = $b = 1 assigns 1 to both.
     *
     * @return AST
     *
     * @throws Exception
     */
    public function expr()
    {
        $node = $this->ternary();

        $token = $this->current_token;
        if (in_array($token->type, self::ASSIGNMENTS, true)) {
            $node = $this->assignable($node, $token);
            // Appending ($a[] = ...) is a plain assignment: there is no current element to combine with
            if ($token->type !== Token::ASSIGN && $node instanceof IndexAST && $node->index === null) {
                $this->fail("Cannot use {$token->value} to append");
            }
            $this->eat($token->type);

            return $this->at(new AssignAST($node, $token, $this->expr()), $token);
        }

        return $node;
    }

    /**
     * Check a node can be assigned to: a variable, or an element of one
     *
     * @param  AST  $node  The node
     * @param  Token  $operator  The assignment or ++/-- token, for the error message
     * @return VariableAST|IndexAST The node
     *
     * @throws GazLangError If it can't be assigned to
     */
    private function assignable(AST $node, Token $operator): VariableAST|IndexAST
    {
        if (! $node instanceof VariableAST && ! ($node instanceof IndexAST && $node->rootVariable() !== null)) {
            $this->fail("Can only use {$operator->value} on a variable or an element of one");
        }

        return $node;
    }

    /**
     * Parse a left associative binary level (operand (OP operand)*)
     *
     * @param  string  $operand  Name of the parser method for the next higher precedence level
     * @param  array  $types  Token types of the operators at this level
     * @return AST
     *
     * @throws Exception
     */
    private function left_associative(string $operand, array $types)
    {
        $node = $this->$operand();

        while (in_array($this->current_token->type, $types, true)) {
            $token = $this->current_token;
            $this->eat($token->type);
            $node = $this->at(new BinOpAST($node, $token, $this->$operand()), $token);
        }

        return $node;
    }

    /**
     * Parse an if statement (IF LPAREN expr RPAREN block [ELSE IF ...]* [ELSE block])
     *
     * @return IfStatementAST
     *
     * @throws Exception
     */
    public function if_statement()
    {
        $start = $this->current_token;
        $this->eat(Token::IF);
        $this->eat(Token::LEFT_PAREN);
        $condition = $this->expr();
        $this->eat(Token::RIGHT_PAREN);
        $if_body = $this->block();

        // Check for else-if or else clause
        $else_if = null;
        $else_body = null;

        if ($this->current_token->type === Token::ELSE) {
            $this->eat(Token::ELSE);

            // Check if this is an else-if or a regular else
            if ($this->current_token->type === Token::IF) {
                // This is an else-if, parse it as a nested if statement
                $else_if = $this->if_statement();
            } else {
                // This is a regular else
                $else_body = $this->block();
            }
        }

        return $this->at(new IfStatementAST($condition, $if_body, $else_if, $else_body), $start);
    }

    /**
     * Parse a block (LBRACE statement* RBRACE)
     *
     * @return CompoundAST
     *
     * @throws Exception
     */
    public function block()
    {
        $this->eat(Token::LEFT_BRACE);

        $block = new CompoundAST;
        while ($this->current_token->type !== Token::RIGHT_BRACE) {
            $block->statements[] = $this->statement();
        }
        $this->eat(Token::RIGHT_BRACE);

        return $block;
    }

    /**
     * Parse a while statement (WHILE LPAREN expr RPAREN block)
     *
     * @return WhileStatementAST
     *
     * @throws Exception
     */
    public function while_statement()
    {
        $start = $this->current_token;
        $this->eat(Token::WHILE);
        $this->eat(Token::LEFT_PAREN);
        $condition = $this->expr();
        $this->eat(Token::RIGHT_PAREN);

        return $this->at(new WhileStatementAST($condition, $this->loop_body()), $start);
    }

    /**
     * Parse a for statement (FOR LPAREN expr SEMICOLON expr SEMICOLON expr RPAREN block)
     *
     * Desugared into { init; while (condition) { body } } with step set on the
     * while node, so the backends only need to know about while loops.
     *
     * @return CompoundAST
     *
     * @throws Exception
     */
    public function for_statement()
    {
        $start = $this->current_token;
        $this->eat(Token::FOR);
        $this->eat(Token::LEFT_PAREN);
        $init = $this->expr();
        $this->eat(Token::SEMICOLON);
        $condition = $this->expr();
        $this->eat(Token::SEMICOLON);
        $step = $this->expr();
        $this->eat(Token::RIGHT_PAREN);

        $loop = $this->at(new CompoundAST, $start);
        $loop->statements = [
            $this->at(new StatementAST($init), $start),
            $this->at(new WhileStatementAST($condition, $this->loop_body(), $this->at(new StatementAST($step), $start)), $start),
        ];

        return $loop;
    }

    /**
     * Parse a foreach statement (FOREACH LPAREN expr AS variable [DOUBLE_ARROW variable] RPAREN block)
     *
     * @return ForeachStatementAST
     *
     * @throws GazLangError
     */
    public function foreach_statement()
    {
        $start = $this->current_token;
        $this->eat(Token::FOREACH);
        $this->eat(Token::LEFT_PAREN);
        $iterable = $this->expr();
        $this->eat(Token::AS);

        $key = null;
        $value = $this->variable();
        if ($this->current_token->type === Token::DOUBLE_ARROW) {
            $this->eat(Token::DOUBLE_ARROW);
            [$key, $value] = [$value, $this->variable()];
        }
        $this->eat(Token::RIGHT_PAREN);

        return $this->at(new ForeachStatementAST($iterable, $key, $value, $this->loop_body()), $start);
    }

    /**
     * Parse a try statement (TRY block CATCH LPAREN variable RPAREN block)
     *
     * @return TryStatementAST
     *
     * @throws GazLangError
     */
    public function try_statement()
    {
        $start = $this->current_token;
        $this->eat(Token::TRY);
        $body = $this->block();
        $this->eat(Token::CATCH);
        $this->eat(Token::LEFT_PAREN);
        $variable = $this->variable();
        $this->eat(Token::RIGHT_PAREN);

        return $this->at(new TryStatementAST($body, $variable, $this->block()), $start);
    }

    /**
     * Parse a loop body, a block in which break and continue are allowed
     *
     * @return CompoundAST
     *
     * @throws Exception
     */
    private function loop_body()
    {
        $this->loop_depth++;
        $body = $this->block();
        $this->loop_depth--;

        return $body;
    }

    /**
     * Parse a break or continue statement ((BREAK | CONTINUE) SEMICOLON)
     *
     * @return LoopControlAST
     *
     * @throws Exception If used outside of a loop
     */
    public function loop_control()
    {
        $token = $this->current_token;
        if ($this->loop_depth === 0) {
            $this->fail("Cannot use {$token->value} outside of a loop");
        }

        $this->eat($token->type);
        $this->eat(Token::SEMICOLON);

        return $this->at(new LoopControlAST($token), $token);
    }

    /**
     * Parse a statement (expr SEMICOLON | echo_statement | if_statement | while_statement | for_statement | foreach_statement | try_statement
     *                    | loop_control | return_statement)
     *
     * @return AST
     *
     * @throws Exception
     */
    public function statement()
    {
        if ($this->current_token->type === Token::ECHO) {
            return $this->echo_statement();
        } elseif ($this->current_token->type === Token::IF) {
            return $this->if_statement();
        } elseif ($this->current_token->type === Token::WHILE) {
            return $this->while_statement();
        } elseif ($this->current_token->type === Token::FOR) {
            return $this->for_statement();
        } elseif ($this->current_token->type === Token::FOREACH) {
            return $this->foreach_statement();
        } elseif ($this->current_token->type === Token::TRY) {
            return $this->try_statement();
        } elseif ($this->current_token->type === Token::BREAK || $this->current_token->type === Token::CONTINUE) {
            return $this->loop_control();
        } elseif ($this->current_token->type === Token::RETURN) {
            return $this->return_statement();
        } elseif ($this->current_token->type === Token::FUNCTION) {
            $this->fail('Functions can only be declared at the top level');
        } elseif ($this->current_token->type === Token::INCLUDE) {
            $this->fail('include can only be used at the top level');
        }

        $start = $this->current_token;
        $expr = $this->expr();
        $this->eat(Token::SEMICOLON);

        return $this->at(new StatementAST($expr), $start);
    }

    /**
     * Parse a return statement (RETURN [expr] SEMICOLON)
     *
     * @return ReturnStatementAST
     *
     * @throws Exception If used outside of a function
     */
    public function return_statement()
    {
        if (! $this->in_function) {
            $this->fail('Cannot use return outside of a function');
        }

        $start = $this->current_token;
        $this->eat(Token::RETURN);
        $expr = $this->current_token->type === Token::SEMICOLON ? null : $this->expr();
        $this->eat(Token::SEMICOLON);

        return $this->at(new ReturnStatementAST($expr), $start);
    }

    /**
     * Parse a function declaration (FUNCTION IDENTIFIER LPAREN [param (COMMA param)*] RPAREN block),
     * param: VAR_IDENTIFIER [ASSIGN expr]
     *
     * Only allowed at the top level, which is also why break and continue can
     * never reach a caller's loop: a declaration is never inside a loop.
     *
     * @return FunctionDeclarationAST
     *
     * @throws Exception
     */
    public function function_declaration()
    {
        $start = $this->current_token;
        $this->eat(Token::FUNCTION);
        $name = $this->current_token->value;
        if (isset($this->functions[$name])) {
            $this->fail(isset(Builtins::ARITIES[$name]) ? "{$name} is a builtin function" : "Function {$name} is already declared");
        }
        $this->eat(Token::IDENTIFIER);

        $this->eat(Token::LEFT_PAREN);
        $params = [];
        $defaults = [];
        while ($this->current_token->type !== Token::RIGHT_PAREN) {
            if ($params !== []) {
                $this->eat(Token::COMMA);
            }
            $param = $this->current_token->value;
            if (in_array($param, $params, true)) {
                $this->fail("Duplicate parameter {$param} in function {$name}");
            }
            $this->eat(Token::VAR_IDENTIFIER);

            $default = null;
            if ($this->current_token->type === Token::ASSIGN) {
                $this->eat(Token::ASSIGN);
                $default = $this->expr();
            } elseif (array_filter($defaults) !== []) {
                $this->fail("Required parameter {$param} can't follow a parameter with a default");
            }
            $params[] = $param;
            $defaults[] = $default;
        }
        $this->eat(Token::RIGHT_PAREN);

        // Declared before the body is parsed, so the function can call itself
        $arity = self::arity($params, $defaults);
        $this->functions[$name] = $arity;

        $this->in_function = true;
        $body = $this->block();
        $this->in_function = false;

        return $this->at(new FunctionDeclarationAST($name, $params, $defaults, $body, $arity), $start);
    }

    /**
     * Parse an echo statement (ECHO expr SEMICOLON)
     *
     * @return EchoStatementAST
     *
     * @throws Exception
     */
    public function echo_statement()
    {
        $start = $this->current_token;
        $this->eat(Token::ECHO);
        $expr = $this->expr();
        $this->eat(Token::SEMICOLON);

        return $this->at(new EchoStatementAST($expr), $start);
    }

    /**
     * Parse a program (top_level)
     *
     * Calls by name and bare names are checked once everything is parsed, so a function
     * can be used before it is declared and both backends can trust every call by name.
     * Calls on values ($f(1)) are checked when they run.
     *
     * @return CompoundAST
     *
     * @throws Exception If a name isn't a declared function or a call passes the wrong number of arguments
     */
    public function program()
    {
        $root = new CompoundAST;
        $root->statements = $this->top_level();

        // In source order, so the first mistake in the program is the one reported
        foreach ($this->uses as $use) {
            if (! isset($this->functions[$use->name])) {
                throw new GazLangError("Undefined function: {$use->name}", $use->file, $use->line);
            }
            if ($use instanceof FunctionCallAST) {
                $error = Builtins::arityError($use->name, $this->functions[$use->name], count($use->args));
                if ($error !== null) {
                    throw new GazLangError($error, $use->file, $use->line);
                }
            }
        }

        return $root;
    }

    /**
     * Parse top level items until the end of the current file ((function_declaration | include | statement)*)
     *
     * @return AST[] The statements, with included files spliced in where they are included
     *
     * @throws Exception
     */
    private function top_level(): array
    {
        $statements = [];
        while ($this->current_token->type !== Token::EOF) {
            if ($this->current_token->type === Token::FUNCTION) {
                $statements[] = $this->function_declaration();
            } elseif ($this->current_token->type === Token::INCLUDE) {
                array_push($statements, ...$this->include_statement());
            } else {
                $statements[] = $this->statement();
            }
        }

        return $statements;
    }

    /**
     * Parse an include (INCLUDE STRING SEMICOLON) and the top level of the included file
     *
     * The path is relative to the including file. The included file shares this
     * parser's functions, so everything is checked and hoisted as one program, and
     * a file already parsed (including the main file) is skipped, which also stops
     * include cycles.
     *
     * @return AST[] The included file's statements, or none if it was already included
     *
     * @throws GazLangError If the file can't be read, or it has a syntax error
     */
    private function include_statement(): array
    {
        $this->eat(Token::INCLUDE);
        $path_token = $this->current_token;
        if ($path_token->type === Token::STRING_START) {
            // Includes are resolved while parsing, before any variable has a value
            $this->fail('include paths cannot use interpolation');
        }
        $this->eat(Token::STRING);
        $this->eat(Token::SEMICOLON);

        $relative = $path_token->value;
        $path = realpath(str_starts_with($relative, '/') ? $relative : $this->base_dir.'/'.$relative);
        // Checked before reading: an unreadable file would otherwise read as empty and vanish silently
        if ($path === false || ! is_file($path) || ! is_readable($path)) {
            throw new GazLangError("Cannot include file: {$relative}", $this->file, $path_token->line);
        }
        if (isset($this->included[$path])) {
            return [];
        }
        $this->included[$path] = true;

        $outer = [$this->lexer, $this->current_token, $this->base_dir, $this->file];
        $this->lexer = new Lexer(file_get_contents($path));
        $this->base_dir = dirname($path);
        $this->file = $this->display_path($path);

        try {
            $this->current_token = $this->next_token();

            return $this->top_level();
        } finally {
            [$this->lexer, $this->current_token, $this->base_dir, $this->file] = $outer;
        }
    }

    /**
     * Parse the input and return an AST
     *
     * @return CompoundAST
     *
     * @throws Exception
     */
    public function parse()
    {
        return $this->program();
    }
}
