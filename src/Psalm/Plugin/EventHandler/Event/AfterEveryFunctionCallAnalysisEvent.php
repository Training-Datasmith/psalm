<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Php_Parser\Node\Expr\Func_Call;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Statements_Source;
final class After_Every_Function_Call_Analysis_Event
{
    /** @internal */
    public function __construct(private readonly Func_Call $expr, private readonly string $function_id, private readonly Context $context, private readonly Statements_Source $statements_source, private readonly Codebase $codebase)
    {
    }
    public function get_expr(): Func_Call
    {
        return $this->expr;
    }
    public function get_function_id(): string
    {
        return $this->function_id;
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