<?php

namespace GazLang\Parser;

use Exception;
use GazLang\AST\AssignAST;
use GazLang\AST\AST;
use GazLang\AST\BinOpAST;
use GazLang\AST\BooleanAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\FunctionCallAST;
use GazLang\AST\FunctionDeclarationAST;
use GazLang\AST\IfStatementAST;
use GazLang\AST\LoopControlAST;
use GazLang\AST\NullAST;
use GazLang\AST\NumAST;
use GazLang\AST\ReturnStatementAST;
use GazLang\AST\StatementAST;
use GazLang\AST\StringAST;
use GazLang\AST\UnaryOpAST;
use GazLang\AST\VariableAST;
use GazLang\AST\WhileStatementAST;
use GazLang\Lexer\Lexer;
use GazLang\Lexer\Token;

/**
 * Parser class builds an AST from tokens
 */
class Parser
{
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
    private $functions = [];

    /**
     * @var FunctionCallAST[] Every call parsed, checked against the declared functions once the whole program is read
     */
    private $calls = [];

    /**
     * Constructor
     *
     * @param  Lexer  $lexer  The lexer to get tokens from
     */
    public function __construct(Lexer $lexer)
    {
        $this->lexer = $lexer;
        $this->current_token = $this->lexer->get_next_token();
    }

    /**
     * Raise an error for invalid syntax
     *
     * @throws Exception
     */
    public function error(): never
    {
        throw new Exception("Invalid syntax near token: {$this->current_token->type}({$this->current_token->value})");
    }

    /**
     * Compare the current token type with the passed token type and
     * if they match, "eat" the current token and get the next one
     *
     * @param  string  $token_type  The token type to match
     *
     * @throws Exception If the token types don't match
     */
    public function eat(string $token_type): void
    {
        if ($this->current_token->type === $token_type) {
            $this->current_token = $this->lexer->get_next_token();
        } else {
            $this->error();
        }
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
        $node = new VariableAST($this->current_token);
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

        return $this->calls[] = new FunctionCallAST($name, $args);
    }

    /**
     * Parse a primary (INTEGER | STRING | TRUE | FALSE | NULL | LPAREN expr RPAREN | variable | function_call)
     *
     * @return AST
     *
     * @throws Exception
     */
    public function primary()
    {
        $token = $this->current_token;

        if ($token->type === Token::INTEGER) {
            $this->eat(Token::INTEGER);

            return new NumAST($token);
        } elseif ($token->type === Token::STRING) {
            $this->eat(Token::STRING);

            return new StringAST($token);
        } elseif ($token->type === Token::TRUE || $token->type === Token::FALSE) {
            $this->eat($token->type);

            return new BooleanAST($token);
        } elseif ($token->type === Token::NULL) {
            $this->eat(Token::NULL);

            return new NullAST($token);
        } elseif ($token->type === Token::IDENTIFIER) {
            return $this->function_call();
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
     * Parse a unary expression ((MINUS | NOT) unary | primary)
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

            return new UnaryOpAST($token, $this->unary());
        }

        return $this->primary();
    }

    /**
     * Parse a multiplicative expression (unary ((MUL | DIV) unary)*)
     *
     * @return AST
     *
     * @throws Exception
     */
    public function multiplicative()
    {
        return $this->left_associative('unary', [Token::MULTIPLY, Token::DIVIDE]);
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
     * Parse an expression, the lowest precedence level (variable ASSIGN expr | logical_or)
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

        if ($this->current_token->type === Token::ASSIGN) {
            if (! $node instanceof VariableAST) {
                $this->error();
            }
            $token = $this->current_token;
            $this->eat(Token::ASSIGN);

            return new AssignAST($node, $token, $this->expr());
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
            $node = new BinOpAST($node, $token, $this->$operand());
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

        return new IfStatementAST($condition, $if_body, $else_if, $else_body);
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
        $this->eat(Token::WHILE);
        $this->eat(Token::LEFT_PAREN);
        $condition = $this->expr();
        $this->eat(Token::RIGHT_PAREN);

        return new WhileStatementAST($condition, $this->loop_body());
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
        $this->eat(Token::FOR);
        $this->eat(Token::LEFT_PAREN);
        $init = $this->expr();
        $this->eat(Token::SEMICOLON);
        $condition = $this->expr();
        $this->eat(Token::SEMICOLON);
        $step = $this->expr();
        $this->eat(Token::RIGHT_PAREN);

        $loop = new CompoundAST;
        $loop->statements = [
            new StatementAST($init),
            new WhileStatementAST($condition, $this->loop_body(), new StatementAST($step)),
        ];

        return $loop;
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
            throw new Exception("Cannot use {$token->value} outside of a loop");
        }

        $this->eat($token->type);
        $this->eat(Token::SEMICOLON);

        return new LoopControlAST($token);
    }

    /**
     * Parse a statement (expr SEMICOLON | echo_statement | if_statement | while_statement | for_statement
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
        } elseif ($this->current_token->type === Token::BREAK || $this->current_token->type === Token::CONTINUE) {
            return $this->loop_control();
        } elseif ($this->current_token->type === Token::RETURN) {
            return $this->return_statement();
        } elseif ($this->current_token->type === Token::FUNCTION) {
            throw new Exception('Functions can only be declared at the top level');
        }

        $expr = $this->expr();
        $this->eat(Token::SEMICOLON);

        return new StatementAST($expr);
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
            throw new Exception('Cannot use return outside of a function');
        }

        $this->eat(Token::RETURN);
        $expr = $this->current_token->type === Token::SEMICOLON ? null : $this->expr();
        $this->eat(Token::SEMICOLON);

        return new ReturnStatementAST($expr);
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
        $this->eat(Token::FUNCTION);
        $name = $this->current_token->value;
        $this->eat(Token::IDENTIFIER);
        if (isset($this->functions[$name])) {
            throw new Exception("Function {$name} is already declared");
        }

        $this->eat(Token::LEFT_PAREN);
        $params = [];
        while ($this->current_token->type !== Token::RIGHT_PAREN) {
            if ($params !== []) {
                $this->eat(Token::COMMA);
            }
            $param = $this->current_token->value;
            $this->eat(Token::VAR_IDENTIFIER);
            if (in_array($param, $params, true)) {
                throw new Exception("Duplicate parameter {$param} in function {$name}");
            }
            $params[] = $param;
        }
        $this->eat(Token::RIGHT_PAREN);

        // Declared before the body is parsed, so the function can call itself
        $this->functions[$name] = count($params);

        $this->in_function = true;
        $body = $this->block();
        $this->in_function = false;

        return new FunctionDeclarationAST($name, $params, $body);
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
        $this->eat(Token::ECHO);
        $expr = $this->expr();
        $this->eat(Token::SEMICOLON);

        return new EchoStatementAST($expr);
    }

    /**
     * Parse a program ((function_declaration | statement)*)
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

        while ($this->current_token->type !== Token::EOF) {
            $root->statements[] = $this->current_token->type === Token::FUNCTION
                ? $this->function_declaration()
                : $this->statement();
        }

        foreach ($this->calls as $call) {
            if (! isset($this->functions[$call->name])) {
                throw new Exception("Undefined function: {$call->name}");
            }
            if (count($call->args) !== $this->functions[$call->name]) {
                throw new Exception("Function {$call->name} expects {$this->functions[$call->name]} arguments, ".count($call->args).' given');
            }
        }

        return $root;
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
