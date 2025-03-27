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
     * Visit a Num node
     *
     * @param  NumAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitNum(NumAST $node);

    /**
     * Visit a String node
     *
     * @param  StringAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitString(StringAST $node);

    /**
     * Visit a Bool node
     *
     * @param  BoolAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitBool(BoolAST $node);

    /**
     * Visit a UnaryOp node
     *
     * @param  UnaryOpAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitUnaryOp(UnaryOpAST $node);

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
     * Visit a Compound node
     *
     * @param  CompoundAST  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visitCompound(CompoundAST $node);
}
