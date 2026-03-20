<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Closure;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Plugin\Event_Handler\Event\Method_Visibility_Provider_Event;
use Psalm\Plugin\Event_Handler\Method_Visibility_Provider_Interface;
use Psalm\Statements_Source;
use function is_subclass_of;
use function strtolower;
/**
 * @internal
 */
final class Method_Visibility_Provider
{
    /**
     * @var array<
     *   lowercase-string,
     *   array<Closure(MethodVisibilityProviderEvent): ?bool>
     * >
     */
    private static array $handlers = [];
    public function __construct()
    {
        self::$handlers = [];
    }
    /**
     * @param class-string<LegacyMethodVisibilityProviderInterface>
     *     |class-string<MethodVisibilityProviderInterface> $class
     */
    public function register_class(string $class): void
    {
        if (is_subclass_of($class, Method_Visibility_Provider_Interface::class, true)) {
            $callable = $class::is_method_visible(...);
            foreach ($class::get_class_like_names() as $fq_classlike_name) {
                $this->register_closure($fq_classlike_name, $callable);
            }
        }
    }
    /**
     * @param Closure(MethodVisibilityProviderEvent): ?bool $c
     */
    public function register_closure(string $fq_classlike_name, Closure $c): void
    {
        self::$handlers[strtolower($fq_classlike_name)][] = $c;
    }
    public function has(string $fq_classlike_name): bool
    {
        return isset(self::$handlers[strtolower($fq_classlike_name)]);
    }
    public function is_method_visible(Statements_Source $source, string $fq_classlike_name, string $method_name, Context $context, ?Code_Location $code_location = null): ?bool
    {
        foreach (self::$handlers[strtolower($fq_classlike_name)] ?? [] as $method_handler) {
            $event = new Method_Visibility_Provider_Event($source, $fq_classlike_name, $method_name, $context, $code_location);
            $method_visible = $method_handler($event);
            if ($method_visible !== null) {
                return $method_visible;
            }
        }
        return null;
    }
}