<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Psalm\Codebase;
final class String_Interpreter_Event
{
    /**
     * Called after a statement has been checked
     *
     * @psalm-external-mutation-free
     * @internal
     */
    public function __construct(private readonly string $value, private readonly Codebase $codebase)
    {
    }
    public function get_value(): string
    {
        return $this->value;
    }
    public function get_codebase(): Codebase
    {
        return $this->codebase;
    }
}