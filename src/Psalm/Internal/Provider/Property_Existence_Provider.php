<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Closure;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Plugin\Event_Handler\Event\Property_Existence_Provider_Event;
use Psalm\Plugin\Event_Handler\Property_Existence_Provider_Interface;
use Psalm\Statements_Source;
use function is_subclass_of;
use function strtolower;
/**
 * @internal
 */
final class Property_Existence_Provider
{
    /**
     * @var array<
     *   lowercase-string,
     *   array<Closure(PropertyExistenceProviderEvent): ?bool>
     * >
     */
    private static array $handlers = [];
    public function __construct()
    {
        self::$handlers = [];
    }
    /**
     * @param class-string<LegacyPropertyExistenceProviderInterface>
     *     |class-string<PropertyExistenceProviderInterface> $class
     */
    public function register_class(string $class): void
    {
        if (is_subclass_of($class, Property_Existence_Provider_Interface::class, true)) {
            $callable = $class::does_property_exist(...);
            foreach ($class::get_class_like_names() as $fq_classlike_name) {
                $this->register_closure($fq_classlike_name, $callable);
            }
        }
    }
    /**
     * @param Closure(PropertyExistenceProviderEvent): ?bool $c
     */
    public function register_closure(string $fq_classlike_name, Closure $c): void
    {
        self::$handlers[strtolower($fq_classlike_name)][] = $c;
    }
    public function has(string $fq_classlike_name): bool
    {
        return isset(self::$handlers[strtolower($fq_classlike_name)]);
    }
    public function does_property_exist(string $fq_classlike_name, string $property_name, bool $read_mode, ?Statements_Source $source = null, ?Context $context = null, ?Code_Location $code_location = null): ?bool
    {
        foreach (self::$handlers[strtolower($fq_classlike_name)] ?? [] as $property_handler) {
            $event = new Property_Existence_Provider_Event($fq_classlike_name, $property_name, $read_mode, $source, $context, $code_location);
            $property_exists = $property_handler($event);
            if ($property_exists !== null) {
                return $property_exists;
            }
        }
        return null;
    }
}