<?php

namespace GazLang\Parser;

use Exception;
use GazLang\AST\ArrayLiteralAST;
use GazLang\AST\AssignAST;
use GazLang\AST\AST;
use GazLang\AST\BinOpAST;
use GazLang\AST\BooleanAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\ForeachStatementAST;
use GazLang\AST\FunctionCallAST;
use GazLang\AST\FunctionDeclarationAST;
use GazLang\AST\IfStatementAST;
use GazLang\AST\IncrementAST;
use GazLang\AST\IndexAST;
use GazLang\AST\LoopControlAST;
use GazLang\AST\NullAST;
use GazLang\AST\NumAST;
use GazLang\AST\ReturnStatementAST;
use GazLang\AST\StatementAST;
use GazLang\AST\StringAST;
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
     * @var array<string, int> Declared function names mapped to their parameter counts
     */
    private $functions = Builtins::ARITIES;

    /**
     * @var FunctionCallAST[] Every call parsed, checked against the declared functions once the whole program is read
     */
    private $calls = [];

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
     * Parse a function call (IDENTIFIER LPAREN [expr (COMMA expr)*] RPAREN)
     *
     * @return FunctionCallAST
     *
     * @throws Exception
     */
    public function function_call()
    {
        $start = $this->current_token;
        $name = $this->current_token->value;
        $this->eat(Token::IDENTIFIER);
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

        return $this->calls[] = $this->at(new FunctionCallAST($name, $args), $start);
    }

    /**
     * Parse a primary (INTEGER | FLOAT | STRING | interpolated_string | TRUE | FALSE | NULL | LPAREN expr RPAREN
     *                  | variable | function_call | array_literal)
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
            return $this->function_call();
        } elseif ($token->type === Token::LEFT_BRACKET) {
            return $this->array_literal();
        } elseif ($token->type === Token::LEFT_PAREN) {
            $this->eat(Token::LEFT_PAREN);
            $node = $this->expr();
            $this->eat(Token::RIGHT_PAREN);

            return $node;
        } elseif ($token->type === Token::VAR_IDENTIFIER || $token->type === Token::GLOBAL_VAR_IDENTIFIER) {
            return $this->variable();
        }

        $this->error();
    }

    /**
     * Parse an interpolated string (STRING_START expr (STRING_MIDDLE expr)* STRING_END)
     *
     * Desugared into concatenation: "Hi {$name}!" is "Hi " + $name + "!". The first
     * operand is always a string, even an empty one, so every + concatenates and
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

            $plus = new Token(Token::PLUS, '+');
            $plus->line = $part->line;
            $node = $this->at(new BinOpAST($node, $plus, $value), $plus);
            if ($part->value !== '') {
                $node = $this->at(new BinOpAST($node, $plus, $this->at(new StringAST($part), $part)), $plus);
            }

            if ($part->type === Token::STRING_END) {
                return $node;
            }
        }
    }

    /**
     * Parse an array literal (LBRACKET [entry (COMMA entry)* [COMMA]] RBRACKET), entry: [expr DOUBLE_ARROW] expr
     *
     * @return ArrayLiteralAST
     *
     * @throws Exception
     */
    public function array_literal()
    {
        $start = $this->current_token;
        $this->eat(Token::LEFT_BRACKET);

        $entries = [];
        while ($this->current_token->type !== Token::RIGHT_BRACKET) {
            $value = $this->expr();
            $key = null;
            if ($this->current_token->type === Token::DOUBLE_ARROW) {
                $this->eat(Token::DOUBLE_ARROW);
                [$key, $value] = [$value, $this->expr()];
            }
            $entries[] = [$key, $value];

            // A trailing comma is allowed, so multi-line literals diff cleanly
            if ($this->current_token->type !== Token::RIGHT_BRACKET) {
                $this->eat(Token::COMMA);
            }
        }
        $this->eat(Token::RIGHT_BRACKET);

        return $this->at(new ArrayLiteralAST($entries), $start);
    }

    /**
     * Parse a postfix expression (primary (LBRACKET [expr] RBRACKET)* [INCREMENT | DECREMENT])
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

        while ($this->current_token->type === Token::LEFT_BRACKET) {
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
     * Parse a relational expression (additive ((< | <= | > | >=) additive)*)
     *
     * @return AST
     *
     * @throws Exception
     */
    public function relational()
    {
        return $this->left_associative('additive', [
            Token::LESS_THAN, Token::LESS_EQUALS, Token::GREATER_THAN, Token::GREATER_EQUALS,
        ]);
    }

    /**
     * Parse an equality expression (relational ((== | != | === | !==) relational)*)
     *
     * @return AST
     *
     * @throws Exception
     */
    public function equality()
    {
        return $this->left_associative('relational', [
            Token::EQUALS, Token::NOT_EQUALS, Token::STRICT_EQUALS, Token::STRICT_NOT_EQUALS,
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
     * Parse an expression, the lowest precedence level ((variable | index) (= | += | -= | *= | /= | %=) expr | logical_or)
     *
     * Assignment is right associative, so $a = $b = 1 assigns 1 to both.
     *
     * @return AST
     *
     * @throws Exception
     */
    public function expr()
    {
        $node = $this->logical_or();

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
     * Parse a function declaration (FUNCTION IDENTIFIER LPAREN [VAR_IDENTIFIER (COMMA VAR_IDENTIFIER)*] RPAREN block)
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
        while ($this->current_token->type !== Token::RIGHT_PAREN) {
            if ($params !== []) {
                $this->eat(Token::COMMA);
            }
            $param = $this->current_token->value;
            if (in_array($param, $params, true)) {
                $this->fail("Duplicate parameter {$param} in function {$name}");
            }
            $this->eat(Token::VAR_IDENTIFIER);
            $params[] = $param;
        }
        $this->eat(Token::RIGHT_PAREN);

        // Declared before the body is parsed, so the function can call itself
        $this->functions[$name] = count($params);

        $this->in_function = true;
        $body = $this->block();
        $this->in_function = false;

        return $this->at(new FunctionDeclarationAST($name, $params, $body), $start);
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
     * Calls are checked once everything is parsed, so a function can be called
     * before it is declared and both backends can trust every call is valid.
     *
     * @return CompoundAST
     *
     * @throws Exception If a call names an undeclared function or passes the wrong number of arguments
     */
    public function program()
    {
        $root = new CompoundAST;
        $root->statements = $this->top_level();

        foreach ($this->calls as $call) {
            if (! isset($this->functions[$call->name])) {
                throw new GazLangError("Undefined function: {$call->name}", $call->file, $call->line);
            }
            if (count($call->args) !== $this->functions[$call->name]) {
                throw new GazLangError(
                    "Function {$call->name} expects {$this->functions[$call->name]} arguments, ".count($call->args).' given',
                    $call->file,
                    $call->line
                );
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
