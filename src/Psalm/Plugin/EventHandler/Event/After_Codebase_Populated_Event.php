<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Psalm\Codebase;
final class After_Codebase_Populated_Event
{
    /**
     * Called after codebase has been populated
     *
     * @internal
     */
    public function __construct(private readonly Codebase $codebase)
    {
    }
    public function get_codebase(): Codebase
    {
        return $this->codebase;
    }
}