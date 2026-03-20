<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Statements_Source;
final class Property_Visibility_Provider_Event
{
    /** @internal */
    public function __construct(private readonly Statements_Source $source, private readonly string $fq_classlike_name, private readonly string $property_name, private readonly bool $read_mode, private readonly Context $context, private readonly Code_Location $code_location)
    {
    }
    public function get_source(): Statements_Source
    {
        return $this->source;
    }
    public function get_fq_classlike_name(): string
    {
        return $this->fq_classlike_name;
    }
    public function get_property_name(): string
    {
        return $this->property_name;
    }
    public function is_read_mode(): bool
    {
        return $this->read_mode;
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