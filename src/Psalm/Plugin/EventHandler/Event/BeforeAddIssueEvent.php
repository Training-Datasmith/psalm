<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Psalm\Codebase;
use Psalm\Issue\Code_Issue;
final class Before_Add_Issue_Event
{
    /** @internal */
    public function __construct(private readonly Code_Issue $issue, private readonly bool $fixable, private readonly Codebase $codebase)
    {
    }
    public function get_issue(): Code_Issue
    {
        return $this->issue;
    }
    public function is_fixable(): bool
    {
        return $this->fixable;
    }
    public function get_codebase(): Codebase
    {
        return $this->codebase;
    }
}