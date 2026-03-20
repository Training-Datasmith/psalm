<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Psalm\Statements_Source;
final class Function_Existence_Provider_Event
{
    /**
     * Use this hook for informing whether or not a global function exists. If you know the function does
     * not exist, return false. If you aren't sure if it exists or not, return null and the default analysis
     * will continue to determine if the function actually exists.
     *
     * @internal
     */
    public function __construct(private readonly Statements_Source $statements_source, private readonly string $function_id)
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
}