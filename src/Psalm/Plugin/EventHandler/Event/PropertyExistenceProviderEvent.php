<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Statements_Source;
final class Property_Existence_Provider_Event
{
    /**
     * Use this hook for informing whether or not a property exists on a given object. If you know the property does
     * not exist, return false. If you aren't sure if it exists or not, return null and the default analysis will
     * continue to determine if the property actually exists.
     *
     * @internal
     */
    public function __construct(private readonly string $fq_classlike_name, private readonly string $property_name, private readonly bool $read_mode, private readonly ?Statements_Source $source = null, private readonly ?Context $context = null, private readonly ?Code_Location $code_location = null)
    {
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
    public function get_source(): ?Statements_Source
    {
        return $this->source;
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