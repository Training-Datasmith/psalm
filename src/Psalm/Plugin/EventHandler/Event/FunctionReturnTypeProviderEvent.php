<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Php_Parser;
use Php_Parser\Node\Expr\Func_Call;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Statements_Source;
final class Function_Return_Type_Provider_Event
{
    /**
     * Use this hook for providing custom return type logic. If this plugin does not know what a function should
     * return but another plugin may be able to determine the type, return null. Otherwise return a mixed union type
     * if something should be returned, but can't be more specific.
     *
     * @param non-empty-string $function_id
     * @internal
     */
    public function __construct(private readonly Statements_Source $statements_source, private readonly string $function_id, private readonly Func_Call $stmt, private readonly Context $context, private readonly Code_Location $code_location)
    {
    }
    public function get_statements_source(): Statements_Source
    {
        return $this->statements_source;
    }
    /**
     * @return non-empty-string
     */
    public function get_function_id(): string
    {
        return $this->function_id;
    }
    /**
     * @return list<PhpParser\Node\Arg>
     */
    public function get_call_args(): array
    {
        return $this->stmt->get_args();
    }
    public function get_context(): Context
    {
        return $this->context;
    }
    public function get_code_location(): Code_Location
    {
        return $this->code_location;
    }
    public function get_stmt(): Func_Call
    {
        return $this->stmt;
    }
}