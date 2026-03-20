<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Statements_Source;
final class Method_Params_Provider_Event
{
    /**
     * @param  list<PhpParser\Node\Arg>    $call_args
     * @internal
     */
    public function __construct(private readonly string $fq_classlike_name, private readonly string $method_name_lowercase, private readonly ?array $call_args = null, private readonly ?Statements_Source $statements_source = null, private readonly ?Context $context = null, private readonly ?Code_Location $code_location = null)
    {
    }
    public function get_fq_classlike_name(): string
    {
        return $this->fq_classlike_name;
    }
    public function get_method_name_lowercase(): string
    {
        return $this->method_name_lowercase;
    }
    /**
     * @return list<PhpParser\Node\Arg>|null
     */
    public function get_call_args(): ?array
    {
        return $this->call_args;
    }
    public function get_statements_source(): ?Statements_Source
    {
        return $this->statements_source;
    }
    public function get_context(): ?Context
    {
        return $this->context;
    }
    public function get_code_location(): ?Code_Location
    {
        return $this->code_location;
    }
}