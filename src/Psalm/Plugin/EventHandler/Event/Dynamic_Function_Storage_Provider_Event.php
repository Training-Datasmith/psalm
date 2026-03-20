<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Plugin\Arg_Type_Inferer;
use Psalm\Plugin\Dynamic_Template_Provider;
use Psalm\Statements_Source;
final class Dynamic_Function_Storage_Provider_Event
{
    /**
     * @internal
     */
    public function __construct(private readonly Arg_Type_Inferer $arg_type_inferer, private readonly Dynamic_Template_Provider $template_provider, private readonly Statements_Source $statement_source, private readonly string $function_id, private readonly Php_Parser\Node\Expr\Func_Call $func_call, private readonly Context $context, private readonly Code_Location $code_location)
    {
    }
    public function get_arg_type_inferer(): Arg_Type_Inferer
    {
        return $this->arg_type_inferer;
    }
    public function get_template_provider(): Dynamic_Template_Provider
    {
        return $this->template_provider;
    }
    public function get_codebase(): Codebase
    {
        return $this->statement_source->get_codebase();
    }
    public function get_statement_source(): Statements_Source
    {
        return $this->statement_source;
    }
    public function get_function_id(): string
    {
        return $this->function_id;
    }
    /**
     * @return list<PhpParser\Node\Arg>
     */
    public function get_args(): array
    {
        return $this->func_call->get_args();
    }
    public function get_context(): Context
    {
        return $this->context;
    }
    public function get_code_location(): Code_Location
    {
        return $this->code_location;
    }
}