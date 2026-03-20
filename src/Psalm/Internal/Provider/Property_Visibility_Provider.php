<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Closure;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Plugin\Event_Handler\Event\Property_Visibility_Provider_Event;
use Psalm\Plugin\Event_Handler\Property_Visibility_Provider_Interface;
use Psalm\Statements_Source;
use function strtolower;
/**
 * @internal
 */
final class Property_Visibility_Provider
{
    /**
     * @var array<
     *   lowercase-string,
     *   array<Closure(PropertyVisibilityProviderEvent): ?bool>
     * >
     */
    private static array $handlers = [];
    public function __construct()
    {
        self::$handlers = [];
    }
    /**
     * @param class-string<PropertyVisibilityProviderInterface> $class
     */
    public function register_class(string $class): void
    {
        $callable = $class::is_property_visible(...);
        foreach ($class::get_class_like_names() as $fq_classlike_name) {
            $this->register_closure($fq_classlike_name, $callable);
        }
    }
    /**
     * @param Closure(PropertyVisibilityProviderEvent): ?bool $c
     */
    public function register_closure(string $fq_classlike_name, Closure $c): void
    {
        self::$handlers[strtolower($fq_classlike_name)][] = $c;
    }
    public function has(string $fq_classlike_name): bool
    {
        return isset(self::$handlers[strtolower($fq_classlike_name)]);
    }
    public function is_property_visible(Statements_Source $source, string $fq_classlike_name, string $property_name, bool $read_mode, Context $context, Code_Location $code_location): ?bool
    {
        foreach (self::$handlers[strtolower($fq_classlike_name)] ?? [] as $property_handler) {
            $event = new Property_Visibility_Provider_Event($source, $fq_classlike_name, $property_name, $read_mode, $context, $code_location);
            $property_visible = $property_handler($event);
            if ($property_visible !== null) {
                return $property_visible;
            }
        }
        return null;
    }
}