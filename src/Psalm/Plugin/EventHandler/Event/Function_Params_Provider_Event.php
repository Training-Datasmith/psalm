<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Statements_Source;
final class Function_Params_Provider_Event
{
    /**
     * @param  list<PhpParser\Node\Arg>    $call_args
     * @internal
     */
    public function __construct(private readonly Statements_Source $statements_source, private readonly string $function_id, private readonly array $call_args, private readonly ?Context $context = null, private readonly ?Code_Location $code_location = null)
    {
    }
    public function get_statements_source(): Statements_Source
    {
        return $this->statements_source;
    }
    public function get_function_id(): string
    {
        return $this->function_id;
    }
    /**
     * @return list<PhpParser\Node\Arg>
     */
    public function get_call_args(): array
    {
        return $this->call_args;
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