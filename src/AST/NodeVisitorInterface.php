<?php

namespace GazLang\AST;

/**
 * Interface for AST node visitors
 *
 * This interface defines the contract for classes that visit AST nodes
 * using the visitor pattern. Implementations must provide a visit method
 * that dispatches to appropriate node-specific visit methods.
 */
interface NodeVisitorInterface
{
    /**
     * Visit a node and dispatch to the appropriate node-specific visitor method
     *
     * @param  object  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visit(object $node);

    /**
     * Visit a BinOp node
     *
     * @param  BinOpAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitBinOp(BinOpAST $node);

    /**
     * Visit a UnaryOp node
     *
     * @param  UnaryOpAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitUnaryOp(UnaryOpAST $node);

    /**
     * Visit a Num node
     *
     * @param  NumAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitNum(NumAST $node);

    /**
     * Visit a Boolean node
     *
     * @param  BooleanAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitBoolean(BooleanAST $node);

    /**
     * Visit a String node
     *
     * @param  StringAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitString(StringAST $node);

    /**
     * Visit a Variable node
     *
     * @param  VariableAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitVariable(VariableAST $node);

    /**
     * Visit an Assign node
     *
     * @param  AssignAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitAssign(AssignAST $node);

    /**
     * Visit a Statement node
     *
     * @param  StatementAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitStatement(StatementAST $node);

    /**
     * Visit an EchoStatement node
     *
     * @param  EchoStatementAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitEchoStatement(EchoStatementAST $node);

    /**
     * Visit an IfStatement node
     *
     * @param  IfStatementAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitIfStatement(IfStatementAST $node);

    /**
     * Visit a WhileStatement node
     *
     * @param  WhileStatementAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitWhileStatement(WhileStatementAST $node);

    /**
     * Visit a TryStatement node
     *
     * @param  TryStatementAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitTryStatement(TryStatementAST $node);

    /**
     * Visit an Increment node
     *
     * @param  IncrementAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitIncrement(IncrementAST $node);

    /**
     * Visit a ForeachStatement node
     *
     * @param  ForeachStatementAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitForeachStatement(ForeachStatementAST $node);

    /**
     * Visit a LoopControl (break or continue) node
     *
     * @param  LoopControlAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitLoopControl(LoopControlAST $node);

    /**
     * Visit a Null node
     *
     * @param  NullAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitNull(NullAST $node);

    /**
     * Visit an ArrayLiteral node
     *
     * @param  ArrayLiteralAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitArrayLiteral(ArrayLiteralAST $node);

    /**
     * Visit an Index node
     *
     * @param  IndexAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitIndex(IndexAST $node);

    /**
     * Visit a Match node
     *
     * @param  MatchAST  $node  The node to visit
     * @return mixed
     */
    public function visitMatch(MatchAST $node);

    /**
     * Visit a Ternary node
     *
     * @param  TernaryAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitTernary(TernaryAST $node);

    /**
     * Visit a Lambda node
     *
     * @param  LambdaAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitLambda(LambdaAST $node);

    /**
     * Visit a FunctionDeclaration node
     *
     * @param  FunctionDeclarationAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitFunctionDeclaration(FunctionDeclarationAST $node);

    /**
     * Visit a FunctionCall node
     *
     * @param  FunctionCallAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitFunctionCall(FunctionCallAST $node);

    /**
     * Visit a FunctionRef node
     *
     * @param  FunctionRefAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitFunctionRef(FunctionRefAST $node);

    /**
     * Visit a CallValue node
     *
     * @param  CallValueAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitCallValue(CallValueAST $node);

    /**
     * Visit a ReturnStatement node
     *
     * @param  ReturnStatementAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitReturnStatement(ReturnStatementAST $node);

    /**
     * Visit a Compound node
     *
     * @param  CompoundAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitCompound(CompoundAST $node);
}
