<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Closure;
use Psalm\Context;
use Psalm\Internal\Provider\Property_Type_Provider\Dom_Document_Property_Type_Provider;
use Psalm\Plugin\Event_Handler\Event\Property_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Property_Type_Provider_Interface;
use Psalm\Statements_Source;
use Psalm\Type\Union;
use function is_subclass_of;
use function strtolower;
/**
 * @internal
 */
final class Property_Type_Provider
{
    /**
     * @var array<
     *   lowercase-string,
     *   array<Closure(PropertyTypeProviderEvent): ?Union>
     * >
     */
    private static array $handlers = [];
    public function __construct()
    {
        self::$handlers = [];
        $this->register_class(Dom_Document_Property_Type_Provider::class);
    }
    /**
     * @param class-string $class
     */
    public function register_class(string $class): void
    {
        if (is_subclass_of($class, Property_Type_Provider_Interface::class, true)) {
            $callable = $class::get_property_type(...);
            foreach ($class::get_class_like_names() as $fq_classlike_name) {
                $this->register_closure($fq_classlike_name, $callable);
            }
        }
    }
    /**
     * @param Closure(PropertyTypeProviderEvent): ?Union $c
     */
    public function register_closure(string $fq_classlike_name, Closure $c): void
    {
        self::$handlers[strtolower($fq_classlike_name)][] = $c;
    }
    public function has(string $fq_classlike_name): bool
    {
        return isset(self::$handlers[strtolower($fq_classlike_name)]);
    }
    public function get_property_type(string $fq_classlike_name, string $property_name, bool $read_mode, ?Statements_Source $source = null, ?Context $context = null): ?Union
    {
        if ($source) {
            $source->add_suppressed_issues(['NonInvariantDocblockPropertyType']);
        }
        foreach (self::$handlers[strtolower($fq_classlike_name)] ?? [] as $property_handler) {
            $event = new Property_Type_Provider_Event($fq_classlike_name, $property_name, $read_mode, $source, $context);
            $property_type = $property_handler($event);
            if ($property_type !== null) {
                return $property_type;
            }
        }
        return null;
    }
}