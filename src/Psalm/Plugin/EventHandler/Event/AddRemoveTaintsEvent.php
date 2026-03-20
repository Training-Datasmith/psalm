<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Php_Parser\Node\Array_Item;
use Php_Parser\Node\Expr;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Statements_Source;
final class Add_Remove_Taints_Event
{
    /**
     * Called after an expression has been checked
     *
     * @internal
     */
    public function __construct(private readonly Array_Item|Expr $expr, private readonly Context $context, private readonly Statements_Source $statements_source, private readonly Codebase $codebase)
    {
    }
    public function get_expr(): Array_Item|Expr
    {
        return $this->expr;
    }
    public function get_context(): Context
    {
        return $this->context;
    }
    public function get_statements_source(): Statements_Source
    {
        return $this->statements_source;
    }
    public function get_codebase(): Codebase
    {
        return $this->codebase;
    }
}