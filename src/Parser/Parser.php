<?php

namespace GazLang\Parser;

use Exception;
use GazLang\AST\ArrayLiteralAST;
use GazLang\AST\AssignAST;
use GazLang\AST\AST;
use GazLang\AST\BinOpAST;
use GazLang\AST\BooleanAST;
use GazLang\AST\CallValueAST;
use GazLang\AST\ClassDeclarationAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\DeleteStatementAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\ForeachStatementAST;
use GazLang\AST\FunctionCallAST;
use GazLang\AST\FunctionDeclarationAST;
use GazLang\AST\FunctionRefAST;
use GazLang\AST\IfStatementAST;
use GazLang\AST\IncrementAST;
use GazLang\AST\IndexAST;
use GazLang\AST\LambdaAST;
use GazLang\AST\ListPatternAST;
use GazLang\AST\LoopControlAST;
use GazLang\AST\MatchAST;
use GazLang\AST\MethodCallAST;
use GazLang\AST\NullAST;
use GazLang\AST\NumAST;
use GazLang\AST\ParentMethodAST;
use GazLang\AST\PropertyAST;
use GazLang\AST\ReturnStatementAST;
use GazLang\AST\StatementAST;
use GazLang\AST\StringAST;
use GazLang\AST\TernaryAST;
use GazLang\AST\ThisAST;
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
        Token::BIT_AND_ASSIGN, Token::BIT_OR_ASSIGN, Token::BIT_XOR_ASSIGN, Token::SHIFT_LEFT_ASSIGN, Token::SHIFT_RIGHT_ASSIGN,
    ];

    /**
     * Source of the builtin classes, parsed into every program: Error is what runtime errors and error("message") throw
     */
    private const BUILTIN_CLASSES = <<<'GAZ'
        class Error {
            #message;
            #file;
            #line;
            #trace;
            fn _($message) { #message = $message; }
            fn to_string() { return "{#message}"; }
        }
        GAZ;

    /**
     * How errors name the builtin classes' source
     */
    private const BUILTIN_FILE = '<builtin>';

    /**
     * Keywords reserved for features that don't exist yet, so adding them never breaks a program
     */
    private const RESERVED = [
        Token::INTERFACE => true, Token::IMPLEMENTS => true, Token::FINAL => true,
        Token::PUBLIC => true, Token::PRIVATE => true, Token::PROTECTED => true,
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
     * @var bool Whether a finally block is being parsed, which return, break and continue can't leave
     */
    private $in_finally = false;

    /**
     * @var bool Whether a constructor's body is being parsed, where return can't give a value
     */
    private $in_constructor = false;

    /**
     * @var ClassDeclarationAST|null The class whose body is being parsed, where # can be used
     */
    private $class = null;

    /**
     * @var array<string, ClassDeclarationAST> Declared classes by name, resolved once the whole program is read
     */
    private $classes = [];

    /**
     * @var array<int, array{0: PropertyAST, 1: ClassDeclarationAST, 2: int|null, 3: bool}> Every #name, by node id, with
     *                                                                                      its class, the argument count if it is called and
     *                                                                                      whether it is assigned to, checked once the classes are resolved
     */
    private $member_uses = [];

    /**
     * @var list<array{0: string, 1: int, 2: string|null}> Every class a catch clause names, with its line and file
     */
    private $catch_types = [];

    /**
     * @var bool Whether any catch clause was parsed, so the program needs the Error class
     */
    private $catches = false;

    /**
     * @var list<array{0: ParentMethodAST, 1: ClassDeclarationAST}> Every ##name with the class it is written in,
     *                                                              checked once the classes are resolved
     */
    private $parent_uses = [];

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
        } elseif ($token->type === Token::MATCH) {
            return $this->match_expression(false);
        } elseif ($token->type === Token::LEFT_BRACKET) {
            return $this->list_literal();
        } elseif ($token->type === Token::LEFT_BRACE) {
            return $this->map_literal();
        } elseif ($token->type === Token::LEFT_PAREN) {
            return $this->parenthesised();
        } elseif ($token->type === Token::VAR_IDENTIFIER || $token->type === Token::GLOBAL_VAR_IDENTIFIER) {
            return $this->variable();
        } elseif ($token->type === Token::HASH || $token->type === Token::HASH_IDENTIFIER) {
            return $this->this_member();
        } elseif ($token->type === Token::PARENT_IDENTIFIER) {
            return $this->parent_method();
        } elseif ($token->type === Token::PARENT) {
            $this->fail("## alone is not allowed: write ##name for the parent's version of a method");
        } elseif (isset(self::RESERVED[$token->type])) {
            $this->fail("{$token->value} is reserved");
        }

        $this->error();
    }

    /**
     * Parse # (the object a method runs on) or #name (its member)
     *
     * Only inside a class: in a method, a field default, or a lambda in either. Whether the
     * class has the member is checked once the whole program is read.
     *
     * @return ThisAST|PropertyAST
     *
     * @throws GazLangError
     */
    private function this_member()
    {
        $token = $this->current_token;
        if ($this->class === null) {
            $this->fail("Cannot use {$token->value} outside a method");
        }
        $this->eat($token->type);
        if ($token->type === Token::HASH && $this->current_token->type === Token::PROPERTY) {
            $this->fail('Write #'.substr($this->current_token->value, 1).', not #'.$this->current_token->value);
        }

        $this_node = $this->at(new ThisAST, $token);
        if ($token->type === Token::HASH) {
            return $this_node;
        }

        $name = substr($token->value, 1);
        if ($name === '_') {
            $this->fail('Cannot use the constructor _ as a member: construct with '.$this->class->name."(...), or call ##_(...) in a child's constructor");
        }
        $node = $this->at(new PropertyAST($this_node, $name), $token);
        $this->member_uses[spl_object_id($node)] = [$node, $this->class, null, false];

        return $node;
    }

    /**
     * Parse ##name (a bound method) or ##name(args) (a call), the parent class's version of a method
     *
     * Only inside a class, and ##_(...) only in a constructor. That the parent has the method
     * is checked once the whole program is read.
     *
     * @return ParentMethodAST
     *
     * @throws GazLangError
     */
    private function parent_method()
    {
        $token = $this->current_token;
        if ($this->class === null) {
            $this->fail("Cannot use {$token->value} outside a method");
        }
        $name = substr($token->value, 2);
        if ($name === '_' && ! $this->in_constructor) {
            $this->fail("##_ can only be used in a constructor, to run the parent's");
        }
        $this->eat(Token::PARENT_IDENTIFIER);

        $args = $this->current_token->type === Token::LEFT_PAREN ? $this->arguments() : null;
        if ($name === '_' && $args === null) {
            $this->fail("Call the parent's constructor as ##_(...)");
        }
        $node = $this->at(new ParentMethodAST($name, $args), $token);
        $this->parent_uses[] = [$node, $this->class];

        return $node;
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

        // A lambda in a constructor isn't the constructor: it can return values, and can't run ##_;
        // one in a finally block can return too
        [$in_constructor, $in_finally, $this->in_constructor, $this->in_finally] = [$this->in_constructor, $this->in_finally, false, false];
        try {
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
        } finally {
            [$this->in_constructor, $this->in_finally] = [$in_constructor, $in_finally];
        }

        // The outer variables it uses, except those a plain = (or foreach or catch) makes local to each call
        $used = [];
        $assigned = [];
        foreach ([...$defaults, $body] as $node) {
            if ($node !== null) {
                self::collect_variables($node, $used, $assigned);
            }
        }
        $captures = array_values(array_diff(array_keys($used), $params, array_keys($assigned)));

        return $this->at(new LambdaAST($params, $defaults, $arity, $body, $captures), $start);
    }

    /**
     * Collect the $ variables a node uses, in source order, and those it assigns with a plain =
     *
     * A foreach or catch variable counts as assigned. A nested lambda contributes the
     * variables it captures, since those must be read when it is created, and nothing it
     * assigns, which is local to its own calls.
     *
     * @param  AST  $node  The node
     * @param  array<string, true>  $found  The names used so far, by reference
     * @param  array<string, true>  $assigned  The names assigned so far, by reference
     */
    private static function collect_variables(AST $node, array &$found, array &$assigned): void
    {
        if ($node instanceof VariableAST) {
            if (! $node->isGlobal()) {
                // The token's text, "$name"
                $found[(string) $node->value] = true;
            }

            return;
        }
        if ($node instanceof LambdaAST) {
            foreach ($node->captures as $name) {
                $found[$name] = true;
            }

            return;
        }
        $targets = match (true) {
            $node instanceof AssignAST && $node->left instanceof ListPatternAST => $node->left->targets,
            $node instanceof AssignAST && $node->token->type === Token::ASSIGN => [$node->left],
            $node instanceof ForeachStatementAST && $node->value instanceof ListPatternAST => [$node->key, ...$node->value->targets],
            $node instanceof ForeachStatementAST => [$node->key, $node->value],
            $node instanceof TryStatementAST => array_column($node->catches, 1),
            default => [],
        };
        foreach ($targets as $target) {
            if ($target instanceof VariableAST && ! $target->isGlobal()) {
                $assigned[(string) $target->value] = true;
            }
        }

        // Children sit at any depth of plain arrays: an array literal's [key, value] entries,
        // a match's arms, whose values are a list inside each arm. Recursing reaches them all,
        // where unrolling a fixed number of levels silently drops whatever is deeper.
        $children = get_object_vars($node);
        array_walk_recursive($children, function ($leaf) use (&$found, &$assigned): void {
            if ($leaf instanceof AST) {
                self::collect_variables($leaf, $found, $assigned);
            }
        });
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
     * Parse a postfix expression (primary (LBRACKET [expr] RBRACKET | arguments | PROPERTY)* [INCREMENT | DECREMENT])
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

        while (in_array($this->current_token->type, [Token::LEFT_BRACKET, Token::LEFT_PAREN, Token::PROPERTY], true)) {
            if ($this->current_token->type === Token::PROPERTY) {
                // $user.name: which members the object has is only known when it runs
                $token = $this->current_token;
                $this->eat(Token::PROPERTY);
                $node = $this->at(new PropertyAST($node, substr($token->value, 1)), $token);

                continue;
            }
            if ($this->current_token->type === Token::LEFT_PAREN) {
                if ($node instanceof PropertyAST) {
                    // #save($x): calling a member, located at its name like reading it
                    $call = new MethodCallAST($node, $this->arguments());
                    [$call->line, $call->file] = [$node->line, $node->file];
                    if (isset($this->member_uses[spl_object_id($node)])) {
                        $this->member_uses[spl_object_id($node)][2] = count($call->args);
                    }
                    $node = $call;

                    continue;
                }
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
     * Parse a unary expression ((MINUS | NOT | BIT_NOT) unary | (INCREMENT | DECREMENT) postfix | postfix)
     *
     * @return AST
     *
     * @throws Exception
     */
    public function unary()
    {
        $token = $this->current_token;

        if (in_array($token->type, [Token::MINUS, Token::NOT, Token::BIT_NOT], true)) {
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
     * Parse a shift (additive ((<< | >>) additive)*)
     *
     * Above .. and below + and -, so "n = " .. $x << 2 concatenates the shifted value and
     * $x << 2 + 1 shifts by 3.
     *
     * @return AST
     *
     * @throws Exception
     */
    public function shift()
    {
        return $this->left_associative('additive', [Token::SHIFT_LEFT, Token::SHIFT_RIGHT]);
    }

    /**
     * Parse a bitwise and (shift (& shift)*)
     *
     * &, ^ and | sit above the comparisons, as in Rust and Python and unlike C, so
     * $flags & MASK == 0 is ($flags & MASK) == 0.
     *
     * @return AST
     *
     * @throws Exception
     */
    public function bit_and()
    {
        return $this->left_associative('shift', [Token::BIT_AND]);
    }

    /**
     * Parse a bitwise exclusive or (bit_and (^ bit_and)*)
     *
     * @return AST
     *
     * @throws Exception
     */
    public function bit_xor()
    {
        return $this->left_associative('bit_and', [Token::BIT_XOR]);
    }

    /**
     * Parse a bitwise or (bit_xor (| bit_xor)*)
     *
     * @return AST
     *
     * @throws Exception
     */
    public function bit_or()
    {
        return $this->left_associative('bit_xor', [Token::BIT_OR]);
    }

    /**
     * Parse a concatenation (bit_or (CONCAT bit_or)*)
     *
     * Below + and - so "n = " .. $a + $b concatenates the sum, as in Lua and PHP 8, and
     * below the bitwise operators too, so "x = " .. $f & MASK concatenates the masked value.
     * Putting .. between them instead would make every unparenthesised mix of the two an
     * error, since either grouping hands a string to & or an int pair to nothing.
     *
     * @return AST
     *
     * @throws Exception
     */
    public function concat()
    {
        return $this->left_associative('bit_or', [Token::CONCAT]);
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
        if (in_array($token->type, self::ASSIGNMENTS, true) && $node instanceof ArrayLiteralAST && ! $node->map) {
            if ($token->type !== Token::ASSIGN) {
                $this->fail("Cannot use {$token->value} to take a list apart: only = can");
            }
            $pattern = $this->list_pattern($node, fn (AST $target) => $this->assignable($target, $token));
            $this->eat(Token::ASSIGN);

            return $this->at(new AssignAST($pattern, $token, $this->expr()), $token);
        }
        if (in_array($token->type, self::ASSIGNMENTS, true)) {
            $node = $this->assignable($node, $token);
            // Appending ($a[] = ...) is a plain assignment: there is no current element to combine with
            if ($token->type !== Token::ASSIGN && $node instanceof IndexAST && $node->index === null) {
                $this->fail("Cannot use {$token->value} to append");
            }
            $this->eat($token->type);
            $right = $this->expr();
            // $f = <lambda>: inside the lambda, $f is the lambda itself, so it can call itself
            if ($token->type === Token::ASSIGN && $node instanceof VariableAST && ! $node->isGlobal()
                && $right instanceof LambdaAST && in_array($node->value, $right->captures, true)) {
                $right->self = $node->value;
            }

            return $this->at(new AssignAST($node, $token, $right), $token);
        }

        return $node;
    }

    /**
     * Turn a list literal written where a pattern goes ([$a, $b] = ..., foreach (... as [$a, $b])) into a pattern
     *
     * @param  ArrayLiteralAST  $literal  The list literal
     * @param  callable(AST): (VariableAST|IndexAST|PropertyAST)  $target  Checks each element can be a target, failing if not
     *
     * @throws GazLangError If the pattern is empty or an element can't be a target
     */
    private function list_pattern(ArrayLiteralAST $literal, callable $target): ListPatternAST
    {
        if ($literal->entries === []) {
            throw new GazLangError('Nothing to take apart: write at least one target in [...]', $this->file, $literal->line);
        }
        $pattern = new ListPatternAST(array_map(fn ($entry) => $target($entry[1]), $literal->entries));
        [$pattern->line, $pattern->file] = [$literal->line, $literal->file];

        return $pattern;
    }

    /**
     * Check a node can be assigned to: a variable, or a path of elements and fields starting at a variable or #
     *
     * $a, $a[0], $rows[0].total, #count, #items[] and $user.tags[0] can be; # itself, and a
     * path starting anywhere else (make().x), can't.
     *
     * @param  AST  $node  The node
     * @param  Token  $operator  The assignment or ++/-- token, for the error message
     * @return VariableAST|IndexAST|PropertyAST The node
     *
     * @throws GazLangError If it can't be assigned to
     */
    private function assignable(AST $node, Token $operator): VariableAST|IndexAST|PropertyAST
    {
        if ($node instanceof VariableAST || (($node instanceof IndexAST || $node instanceof PropertyAST) && AST::pathRoot($node) !== null)) {
            if (isset($this->member_uses[spl_object_id($node)])) {
                $this->member_uses[spl_object_id($node)][3] = true;
            }

            return $node;
        }

        $this->fail("Can only use {$operator->value} on a variable, or an element or field of one");
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
     * Parse a match (MATCH [LPAREN expr RPAREN] LBRACE arm (COMMA arm)* [COMMA] RBRACE)
     *
     * An arm is one or more comma separated expressions, or DEFAULT, then => then its body.
     * A comma separates arms; it is optional after the last one and after a block arm, which
     * ends in a } of its own, as in Rust.
     *
     * The subject is optional: with one, an arm's values are compared to it with ==; without,
     * they are conditions, tested for truth as if does, so several to an arm read as "or".
     *
     * Only a match written as a statement may have block arms, so in one a { after => is a
     * block and a map is written ({...}), the same rule as a lambda body; in an expression a
     * { after => is a map literal. The default arm must be last, since anything after it is
     * dead, the way an untyped catch must be last.
     *
     * @param  bool  $statement  Whether this match is a statement, so its arms may be blocks
     *
     * @throws Exception
     */
    private function match_expression(bool $statement): MatchAST
    {
        $start = $this->current_token;
        $this->eat(Token::MATCH);
        $subject = null;
        if ($this->current_token->type === Token::LEFT_PAREN) {
            $this->eat(Token::LEFT_PAREN);
            $subject = $this->expr();
            $this->eat(Token::RIGHT_PAREN);
        }
        $this->eat(Token::LEFT_BRACE);

        $arms = [];
        $default = false;
        while ($this->current_token->type !== Token::RIGHT_BRACE) {
            if ($default) {
                $this->fail('default must be the last arm of a match');
            }

            $values = null;
            if ($this->current_token->type === Token::DEFAULT) {
                $this->eat(Token::DEFAULT);
                $default = true;
            } else {
                $values = [$this->expr()];
                while ($this->current_token->type === Token::COMMA) {
                    $this->eat(Token::COMMA);
                    $values[] = $this->expr();
                }
            }
            $this->eat(Token::DOUBLE_ARROW);

            $block = $statement && $this->current_token->type === Token::LEFT_BRACE;
            $arms[] = [$values, $block ? $this->block() : $this->expr(), $block];

            if ($this->current_token->type === Token::COMMA) {
                $this->eat(Token::COMMA);
            } elseif (! $block && $this->current_token->type !== Token::RIGHT_BRACE) {
                // Not the last arm and not brace terminated, so a comma is missing
                $this->eat(Token::COMMA);
            }
        }
        $this->eat(Token::RIGHT_BRACE);

        if ($arms === []) {
            throw new GazLangError('A match needs at least one arm', $this->file, $start->line);
        }

        return $this->at(new MatchAST($subject, $arms), $start);
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
        $value = $this->foreach_target();
        if ($this->current_token->type === Token::DOUBLE_ARROW) {
            if (! $value instanceof VariableAST) {
                $this->fail('A foreach key is a variable, not a pattern');
            }
            $this->eat(Token::DOUBLE_ARROW);
            [$key, $value] = [$value, $this->foreach_target()];
        }
        $this->eat(Token::RIGHT_PAREN);

        return $this->at(new ForeachStatementAST($iterable, $key, $value, $this->loop_body()), $start);
    }

    /**
     * Parse what foreach assigns each value to (variable | LBRACKET variable (COMMA variable)* [COMMA] RBRACKET)
     *
     * A pattern's targets are variables only, like foreach's own.
     *
     * @return VariableAST|ListPatternAST
     *
     * @throws GazLangError
     */
    private function foreach_target()
    {
        if ($this->current_token->type !== Token::LEFT_BRACKET) {
            return $this->variable();
        }

        return $this->list_pattern($this->list_literal(), function (AST $target) {
            if (! $target instanceof VariableAST) {
                throw new GazLangError('A foreach pattern takes variables only', $target->file, $target->line);
            }

            return $target;
        });
    }

    /**
     * Parse a try statement (TRY block (CATCH LPAREN [IDENTIFIER] variable RPAREN block)* [FINALLY block]), with a catch or a finally or both
     *
     * A catch naming a class catches objects of that class or its subclasses; one without a
     * class catches anything, so it must be the last. That the names are classes is checked
     * once the whole program is read.
     *
     * return, break and continue can't leave a finally block, so it never replaces a return
     * or swallows an error; a loop or lambda inside it can use them for itself.
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

        $catches = [];
        while ($this->current_token->type === Token::CATCH) {
            if ($catches !== [] && end($catches)[0] === null) {
                $this->fail('A catch without a class catches every error, so it must be the last');
            }
            $this->catches = true;
            $this->eat(Token::CATCH);
            $this->eat(Token::LEFT_PAREN);
            $class = null;
            if ($this->current_token->type === Token::IDENTIFIER) {
                $class = $this->current_token->value;
                $this->catch_types[] = [$class, $this->current_token->line, $this->file];
                $this->eat(Token::IDENTIFIER);
            }
            $variable = $this->variable();
            $this->eat(Token::RIGHT_PAREN);
            $catches[] = [$class, $variable, $this->block()];
        }

        $finally = null;
        if ($catches === [] && $this->current_token->type !== Token::FINALLY) {
            $this->fail("Expected 'catch' or 'finally' but found ".$this->describe($this->current_token));
        }
        if ($this->current_token->type === Token::FINALLY) {
            $this->eat(Token::FINALLY);
            [$in_finally, $loop_depth] = [$this->in_finally, $this->loop_depth];
            [$this->in_finally, $this->loop_depth] = [true, 0];
            try {
                $finally = $this->block();
            } finally {
                [$this->in_finally, $this->loop_depth] = [$in_finally, $loop_depth];
            }
        }

        return $this->at(new TryStatementAST($body, $catches, $finally), $start);
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
            $this->fail($this->in_finally ? "Cannot use {$token->value} in finally" : "Cannot use {$token->value} outside of a loop");
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
        } elseif ($this->current_token->type === Token::DELETE) {
            return $this->delete_statement();
        } elseif ($this->current_token->type === Token::MATCH) {
            // A match written as a statement ends at its }, like if and while, and its arms
            // may be blocks; its value is discarded
            $start = $this->current_token;

            return $this->at(new StatementAST($this->match_expression(true)), $start);
        } elseif ($this->current_token->type === Token::FN) {
            $this->fail('Functions can only be declared at the top level');
        } elseif ($this->current_token->type === Token::CLASS_KEYWORD || $this->current_token->type === Token::ABSTRACT) {
            $this->fail('Classes can only be declared at the top level');
        } elseif ($this->current_token->type === Token::FUNCTION) {
            $this->fail('Declare functions with fn, not function');
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
        if ($this->in_finally) {
            $this->fail('Cannot use return in finally');
        }
        if (! $this->in_function) {
            $this->fail('Cannot use return outside of a function');
        }

        $start = $this->current_token;
        $this->eat(Token::RETURN);
        if ($this->in_constructor && $this->current_token->type !== Token::SEMICOLON) {
            $this->fail("A constructor can't return a value: constructing gives the object");
        }
        $expr = $this->current_token->type === Token::SEMICOLON ? null : $this->expr();
        $this->eat(Token::SEMICOLON);

        return $this->at(new ReturnStatementAST($expr), $start);
    }

    /**
     * Parse a delete statement (DELETE postfix SEMICOLON), which removes an element of a list or map
     *
     * The target is written like an assignment's, a variable or # followed by steps, and
     * must end in an index: a list's element, which the later ones move down to fill, or a
     * map's key. Fields are declared, so a field is a parse error, and so is an append.
     *
     * @return DeleteStatementAST
     *
     * @throws GazLangError If the target is not an element of a list or map
     */
    public function delete_statement()
    {
        $start = $this->current_token;
        $this->eat(Token::DELETE);
        $target = $this->postfix();
        $this->eat(Token::SEMICOLON);

        if ($target instanceof PropertyAST) {
            $this->fail('Cannot delete a field: every object of a class has the fields it declares');
        }
        $root = $target instanceof IndexAST ? AST::pathRoot($target) : null;
        if (! $target instanceof IndexAST || $target->index === null || ! ($root instanceof VariableAST || $root instanceof ThisAST)) {
            $this->fail('delete needs an element of a list or map, like delete $a[0]');
        }

        return $this->at(new DeleteStatementAST($target), $start);
    }

    /**
     * Parse a function declaration (FN IDENTIFIER LPAREN [param (COMMA param)*] RPAREN block),
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
        $this->eat(Token::FN);
        $name = $this->current_token->value;
        $this->check_new_name($name);
        $this->eat(Token::IDENTIFIER);

        [$params, $defaults] = $this->parameters("function {$name}");
        // Declared before the body is parsed, so the function can call itself
        $arity = self::arity($params, $defaults);
        $this->functions[$name] = $arity;

        $this->in_function = true;
        $body = $this->block();
        $this->in_function = false;

        return $this->at(new FunctionDeclarationAST($name, $params, $defaults, $body, $arity), $start);
    }

    /**
     * Check a function or class name isn't taken: functions, classes and builtins share one namespace
     *
     * @param  string  $name  The name being declared
     *
     * @throws GazLangError If it is taken
     */
    private function check_new_name(string $name): void
    {
        if (isset(Builtins::ARITIES[$name])) {
            $this->fail("{$name} is a builtin function");
        } elseif (isset($this->classes[$name]) && $this->classes[$name]->file === self::BUILTIN_FILE) {
            $this->fail("{$name} is a builtin class");
        } elseif (isset($this->functions[$name])) {
            $this->fail("Function {$name} is already declared");
        } elseif (isset($this->classes[$name])) {
            $this->fail("Class {$name} is already declared");
        }
    }

    /**
     * Parse a function or method's parameter list (LPAREN [param (COMMA param)*] RPAREN), param: VAR_IDENTIFIER [ASSIGN expr]
     *
     * @param  string  $owner  What the parameters belong to, for errors: "function f", "method Point.move"
     * @return array{0: string[], 1: array<int, AST|null>} The names, and each one's default or null
     *
     * @throws GazLangError On a duplicate name or a required parameter after one with a default
     */
    private function parameters(string $owner): array
    {
        $this->eat(Token::LEFT_PAREN);
        $params = [];
        $defaults = [];
        while ($this->current_token->type !== Token::RIGHT_PAREN) {
            if ($params !== []) {
                $this->eat(Token::COMMA);
            }
            $param = $this->current_token->value;
            if (in_array($param, $params, true)) {
                $this->fail("Duplicate parameter {$param} in {$owner}");
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

        return [$params, $defaults];
    }

    /**
     * Parse a class declaration ([ABSTRACT] CLASS IDENTIFIER [EXTENDS IDENTIFIER] LBRACE (field | method)* RBRACE),
     * field: HASH_IDENTIFIER [ASSIGN expr] SEMICOLON, method: [ABSTRACT] FN name parameters (block | SEMICOLON)
     *
     * Only at the top level. The parent can be declared later; it is checked once the whole
     * program is read. A field's default is evaluated for each new object, with #
     * being that object and no local variables. Methods may be named with any word,
     * keywords included, since they are always reached through # or a dot.
     *
     * @return ClassDeclarationAST
     *
     * @throws Exception
     */
    public function class_declaration()
    {
        $start = $this->current_token;
        $abstract = $start->type === Token::ABSTRACT;
        if ($abstract) {
            $this->eat(Token::ABSTRACT);
        }
        $this->eat(Token::CLASS_KEYWORD);
        $name = $this->current_token->value;
        $this->check_new_name($name);
        $this->eat(Token::IDENTIFIER);
        $parent = null;
        if ($this->current_token->type === Token::EXTENDS) {
            $this->eat(Token::EXTENDS);
            $parent = $this->current_token->value;
            $this->eat(Token::IDENTIFIER);
        }

        $class = $this->at(new ClassDeclarationAST($name, $parent, $abstract), $start);
        $this->classes[$name] = $class;
        $this->eat(Token::LEFT_BRACE);
        $this->class = $class;
        try {
            while ($this->current_token->type !== Token::RIGHT_BRACE) {
                if ($this->current_token->type === Token::HASH_IDENTIFIER) {
                    $this->field_declaration($class);
                } elseif ($this->current_token->type === Token::FN || $this->current_token->type === Token::ABSTRACT) {
                    $this->method_declaration($class);
                } else {
                    $this->fail('Expected a field (#name) or a method (fn) but found '.$this->describe($this->current_token));
                }
            }
        } finally {
            $this->class = null;
        }
        $this->eat(Token::RIGHT_BRACE);

        return $class;
    }

    /**
     * Parse a field declaration (HASH_IDENTIFIER [ASSIGN expr] SEMICOLON) into its class
     *
     * @param  ClassDeclarationAST  $class  The class being declared
     *
     * @throws Exception
     */
    private function field_declaration(ClassDeclarationAST $class): void
    {
        $token = $this->current_token;
        $name = substr($token->value, 1);
        $this->check_new_member($class, $name);
        $this->eat(Token::HASH_IDENTIFIER);

        $default = null;
        if ($this->current_token->type === Token::ASSIGN) {
            $this->eat(Token::ASSIGN);
            $default = $this->expr();
            $used = [];
            $assigned = [];
            self::collect_variables($default, $used, $assigned);
            if ($used !== []) {
                throw new GazLangError("The default of #{$name} can't use ".array_key_first($used).': fields have no local variables', $this->file, $token->line);
            }
        }
        $this->eat(Token::SEMICOLON);

        $class->fields[$name] = $default;
        $class->field_lines[$name] = $token->line;
    }

    /**
     * Parse a method declaration ([ABSTRACT] FN name parameters (block | SEMICOLON)) into its class
     *
     * An abstract method has no body, and only an abstract class can declare one.
     *
     * @param  ClassDeclarationAST  $class  The class being declared
     *
     * @throws Exception
     */
    private function method_declaration(ClassDeclarationAST $class): void
    {
        $start = $this->current_token;
        $abstract = $start->type === Token::ABSTRACT;
        if ($abstract) {
            $this->eat(Token::ABSTRACT);
        }
        $this->eat(Token::FN);
        $token = $this->current_token;
        if ($token->type !== Token::IDENTIFIER && ! in_array($token->type, Lexer::KEYWORDS, true)) {
            $this->fail('Expected a name but found '.$this->describe($token));
        }
        $name = $token->value;
        $this->check_new_member($class, $name);
        $this->eat($token->type);

        [$params, $defaults] = $this->parameters("method {$class->name}.{$name}");
        if ($abstract) {
            if (! $class->abstract) {
                throw new GazLangError("Class {$class->name} has abstract method {$name}, so it must be abstract too", $this->file, $start->line);
            }
            if ($name === '_') {
                throw new GazLangError("A constructor can't be abstract", $this->file, $start->line);
            }
            if ($this->current_token->type === Token::LEFT_BRACE) {
                $this->fail('An abstract method has no body: end it with ;');
            }
            $this->eat(Token::SEMICOLON);
            $body = new CompoundAST;
        } else {
            [$this->in_function, $this->in_constructor] = [true, $name === '_'];
            try {
                $body = $this->block();
            } finally {
                [$this->in_function, $this->in_constructor] = [false, false];
            }
        }

        $method = $this->at(new FunctionDeclarationAST($name, $params, $defaults, $body, self::arity($params, $defaults)), $start);
        $method->class = $class->name;
        $method->abstract = $abstract;
        $class->methods[$name] = $method;
    }

    /**
     * Check a class doesn't already declare a member: fields and methods share one namespace
     *
     * @param  ClassDeclarationAST  $class  The class being declared
     * @param  string  $name  The member name
     *
     * @throws GazLangError If it does
     */
    private function check_new_member(ClassDeclarationAST $class, string $name): void
    {
        $field = $this->current_token->type === Token::HASH_IDENTIFIER;
        if (array_key_exists($name, $class->fields)) {
            $this->fail($field ? "{$class->name} already has a field #{$name}" : "{$class->name} already has a field #{$name}, and a method can't have a field's name: call the method something else");
        }
        if (isset($class->methods[$name])) {
            $this->fail($field ? "{$class->name} already has a method {$name}, and a field can't have a method's name: call the field something else, like #{$name}_value" : "{$class->name} already has a method {$name}");
        }
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
        $error_class = $this->builtin_classes();
        $root = new CompoundAST;
        $root->statements = $this->top_level();
        // Error is only compiled into programs that can catch, name or extend it
        $uses_error = $this->catches || array_filter($this->uses, fn ($use) => $use->name === 'Error') !== []
            || array_filter($this->classes, fn ($class) => $class->parent === 'Error') !== [];
        if ($uses_error) {
            array_unshift($root->statements, $error_class);
        }
        foreach ($this->catch_types as [$name, $line, $file]) {
            if (! isset($this->classes[$name])) {
                throw new GazLangError(isset($this->functions[$name]) ? "{$name} is a function, not a class" : "Undefined class: {$name}", $file, $line);
            }
        }

        foreach ($this->classes as $class) {
            $this->resolve_class($class, []);
        }

        // In source order, so the first mistake in the program is the one reported
        foreach ($this->member_uses as [$node, $class, $argc, $assigned]) {
            $this->check_member_use($node, $class, $argc, $assigned);
        }
        foreach ($this->parent_uses as [$node, $class]) {
            $this->check_parent_use($node, $class);
        }
        foreach ($this->uses as $use) {
            if (isset($this->classes[$use->name])) {
                $class = $this->classes[$use->name];
                if ($use instanceof FunctionCallAST) {
                    if ($class->abstract) {
                        throw new GazLangError("Cannot construct abstract class {$use->name}", $use->file, $use->line);
                    }
                    $error = Builtins::arityError("Class {$use->name}", self::constructor_arity($class), count($use->args));
                    if ($error !== null) {
                        throw new GazLangError($error, $use->file, $use->line);
                    }
                }

                continue;
            }
            if (! isset($this->functions[$use->name])) {
                throw new GazLangError("Undefined function: {$use->name}", $use->file, $use->line);
            }
            if ($use instanceof FunctionCallAST) {
                $error = Builtins::arityError("Function {$use->name}", $this->functions[$use->name], count($use->args));
                if ($error !== null) {
                    throw new GazLangError($error, $use->file, $use->line);
                }
            }
        }

        return $root;
    }

    /**
     * Work out a class's fields and methods from its own and its parent's, checking they fit together
     *
     * Fields and methods share one namespace, a field can't be declared again, and a method
     * may replace a parent's method but must accept every argument count the parent's accepts
     * (except the constructor, which each class defines for itself).
     *
     * @param  ClassDeclarationAST  $class  The class
     * @param  string[]  $chain  The classes being resolved that extend it, to find circular inheritance
     *
     * @throws GazLangError If the class doesn't fit its parent
     */
    private function resolve_class(ClassDeclarationAST $class, array $chain): void
    {
        if ($class->resolved) {
            return;
        }

        $parent = null;
        if ($class->parent !== null) {
            $parent = $this->classes[$class->parent] ?? null;
            if ($parent === null) {
                $message = isset($this->functions[$class->parent]) ? "{$class->parent} is a function, not a class" : "Undefined class: {$class->parent}";

                throw new GazLangError($message, $class->file, $class->line);
            }
            $chain[] = $class->name;
            if (in_array($parent->name, $chain, true)) {
                $cycle = implode(' extends ', [...array_slice($chain, array_search($parent->name, $chain, true)), $parent->name]);

                throw new GazLangError("Circular inheritance: {$cycle}", $class->file, $class->line);
            }
            $this->resolve_class($parent, $chain);
            [$class->layout, $class->members, $class->abstract_methods] = [$parent->layout, $parent->members, $parent->abstract_methods];
        }

        foreach ($class->fields as $name => $_) {
            $line = $class->field_lines[$name];
            if (isset($class->layout[$name])) {
                throw new GazLangError("Field #{$name} of {$class->name} is already declared in {$class->layout[$name]}", $class->file, $line);
            }
            $owner = $class->members[$name] ?? $class->abstract_methods[$name] ?? null;
            if ($owner !== null) {
                throw new GazLangError("Field #{$name} of {$class->name} has the name of a method of {$owner}: fields and methods share names, so call the field something else", $class->file, $line);
            }
            $class->layout[$name] = $class->name;
        }
        foreach ($class->methods as $name => $method) {
            if (isset($class->layout[$name])) {
                throw new GazLangError("Method {$class->name}.{$name} has the name of a field of {$class->layout[$name]}: fields and methods share names, so call the method something else", $method->file, $method->line);
            }
            $owner = $class->members[$name] ?? $class->abstract_methods[$name] ?? null;
            if ($owner !== null && $method->abstract && isset($class->members[$name])) {
                throw new GazLangError("Abstract method {$class->name}.{$name} can't replace {$owner}.{$name}", $method->file, $method->line);
            }
            // The constructor is each class's own: a child's can take different arguments
            if ($owner !== null && $name !== '_') {
                [$fewest, $most] = Builtins::bounds($method->arity);
                [$parent_fewest, $parent_most] = Builtins::bounds($this->classes[$owner]->methods[$name]->arity);
                if ($fewest > $parent_fewest || $most < $parent_most) {
                    $expected = $parent_fewest === $parent_most ? $parent_fewest : "{$parent_fewest} to {$parent_most}";

                    throw new GazLangError("Method {$class->name}.{$name} must accept every argument count {$owner}.{$name} does ({$expected})", $method->file, $method->line);
                }
            }
            if ($name === 'to_string' && Builtins::bounds($method->arity)[0] !== 0) {
                throw new GazLangError("Method {$class->name}.to_string must accept 0 arguments: printing calls it with none", $method->file, $method->line);
            }
            if ($method->abstract) {
                $class->abstract_methods[$name] = $class->name;
            } else {
                $class->members[$name] = $class->name;
                unset($class->abstract_methods[$name]);
            }
        }

        if (! $class->abstract && $class->abstract_methods !== []) {
            $name = array_key_first($class->abstract_methods);

            throw new GazLangError("Class {$class->name} must define abstract method {$name} of {$class->abstract_methods[$name]}, or be abstract", $class->file, $class->line);
        }
        $class->resolved = true;
    }

    /**
     * Check a ##name against the parent of the class it is written in, and record whose version runs
     *
     * @param  ParentMethodAST  $node  The ##name
     * @param  ClassDeclarationAST  $class  The class it is written in
     *
     * @throws GazLangError If there is no parent, or the parent has no such method to run
     */
    private function check_parent_use(ParentMethodAST $node, ClassDeclarationAST $class): void
    {
        $fail = fn (string $message) => throw new GazLangError($message, $node->file, $node->line);
        if ($class->parent === null) {
            $fail("Cannot use ##{$node->name}: {$class->name} has no parent class");
        }
        $parent = $this->classes[$class->parent];
        $name = $node->name;
        if (isset($parent->layout[$name])) {
            $fail("##{$name} can only reach a method, and {$name} is a field of {$parent->layout[$name]}");
        }
        if (isset($parent->abstract_methods[$name])) {
            $fail("Cannot use ##{$name}: {$name} is abstract in {$parent->abstract_methods[$name]}");
        }
        if (! isset($parent->members[$name])) {
            $fail($name === '_' ? "{$parent->name} has no constructor to call with ##_" : "{$parent->name} has no method {$name}");
        }

        $node->definer = $parent->members[$name];
        if ($node->args !== null) {
            $error = Builtins::arityError("Method {$node->definer}.{$name}", $this->classes[$node->definer]->methods[$name]->arity, count($node->args));
            if ($error !== null) {
                $fail($error);
            }
        }
    }

    /**
     * Parse the builtin classes (Error), before the program, so its classes can use them
     *
     * @return ClassDeclarationAST The Error class
     *
     * @throws Exception
     */
    private function builtin_classes(): ClassDeclarationAST
    {
        $outer = [$this->lexer, $this->current_token, $this->file];
        $this->lexer = new Lexer(self::BUILTIN_CLASSES);
        $this->file = self::BUILTIN_FILE;
        try {
            $this->current_token = $this->next_token();

            return $this->class_declaration();
        } finally {
            [$this->lexer, $this->current_token, $this->file] = $outer;
        }
    }

    /**
     * The number of arguments constructing a resolved class takes: its constructor's, or none
     *
     * @param  ClassDeclarationAST  $class  The class
     * @return int|array{0: int, 1: int}
     */
    private function constructor_arity(ClassDeclarationAST $class): int|array
    {
        return isset($class->members['_']) ? $this->classes[$class->members['_']]->methods['_']->arity : 0;
    }

    /**
     * Check a #name against its class: the member must exist, a method can't be assigned to, and a call must fit its arity
     *
     * Called methods are checked against the class the method is written in: a child's
     * version accepts every argument count this one does.
     *
     * @param  PropertyAST  $node  The #name
     * @param  ClassDeclarationAST  $class  The class it is written in
     * @param  int|null  $argc  How many arguments it is called with, or null if it isn't called
     * @param  bool  $assigned  Whether it is assigned to
     *
     * @throws GazLangError If the use doesn't fit
     */
    private function check_member_use(PropertyAST $node, ClassDeclarationAST $class, ?int $argc, bool $assigned): void
    {
        if (isset($class->layout[$node->name])) {
            $node->field = true;

            return;
        }
        $definer = $class->members[$node->name] ?? $class->abstract_methods[$node->name] ?? null;
        if ($definer === null) {
            throw new GazLangError("{$class->name} has no member #{$node->name}", $node->file, $node->line);
        }
        if ($assigned) {
            throw new GazLangError("Cannot assign to method #{$node->name}", $node->file, $node->line);
        }
        if ($argc !== null) {
            $error = Builtins::arityError("Method {$definer}.{$node->name}", $this->classes[$definer]->methods[$node->name]->arity, $argc);
            if ($error !== null) {
                throw new GazLangError($error, $node->file, $node->line);
            }
        }
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
            if ($this->current_token->type === Token::FN) {
                $statements[] = $this->function_declaration();
            } elseif ($this->current_token->type === Token::CLASS_KEYWORD || $this->current_token->type === Token::ABSTRACT) {
                $statements[] = $this->class_declaration();
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
