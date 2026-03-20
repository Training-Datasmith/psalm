<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Psalm\Codebase;
use Psalm\Internal\Analyzer\Issue_Data;
use Psalm\Source_Control\Source_Control_Info;
final class After_Analysis_Event
{
    /**
     * Called after analysis is complete
     *
     * @param array<string, list<IssueData>> $issues where string key is a filepath
     * @internal
     */
    public function __construct(private readonly Codebase $codebase, private readonly array $issues, private readonly array $build_info, private readonly ?Source_Control_Info $source_control_info = null)
    {
    }
    public function get_codebase(): Codebase
    {
        return $this->codebase;
    }
    /**
     * @return array<string, list<IssueData>> where string key is a filepath
     */
    public function get_issues(): array
    {
        return $this->issues;
    }
    public function get_build_info(): array
    {
        return $this->build_info;
    }
    public function get_source_control_info(): ?Source_Control_Info
    {
        return $this->source_control_info;
    }
}